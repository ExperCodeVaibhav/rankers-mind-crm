<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class PayrollService
{
    public static function generate(string $month,int $workingDays):int
    {
        $periodMonth=date('Y-m-01',strtotime($month));$start=$periodMonth;$end=date('Y-m-t',strtotime($periodMonth));
        return Database::transaction(function(PDO $db)use($periodMonth,$start,$end,$workingDays):int{
            $q=$db->prepare('SELECT * FROM payroll_periods WHERE period_month=? FOR UPDATE');$q->execute([$periodMonth]);$period=$q->fetch();
            if($period&&in_array($period['status'],['Approved','Paid','Locked'],true))throw new \RuntimeException('Approved, paid or locked payroll cannot be recalculated.');
            if(!$period){$db->prepare("INSERT INTO payroll_periods(period_month,working_days,status,created_by) VALUES(?,?,'Draft',?)")->execute([$periodMonth,$workingDays,auth()['id']]);$periodId=(int)$db->lastInsertId();}else{$periodId=(int)$period['id'];$db->prepare('UPDATE payroll_periods SET working_days=? WHERE id=?')->execute([$workingDays,$periodId]);}
            $staff=$db->query("SELECT * FROM staff_profiles WHERE employment_status='active' AND deleted_at IS NULL")->fetchAll();
            $att=$db->prepare('SELECT status,COUNT(*) count,SUM(late_minutes>0) late_count,SUM(overtime_minutes) overtime FROM attendance WHERE staff_id=? AND attendance_date BETWEEN ? AND ? GROUP BY status');
            foreach($staff as $person){$att->execute([$person['id'],$start,$end]);$stats=[];$late=0;$overtime=0;foreach($att->fetchAll() as $row){$stats[$row['status']]=(float)$row['count'];$late+=(int)$row['late_count'];$overtime+=(int)$row['overtime'];}
                $present=($stats['Present']??0)+($stats['Late']??0)+($stats['Work From Home']??0);$paid=$stats['Paid Leave']??0;$unpaid=$stats['Unpaid Leave']??0;$half=$stats['Half Day']??0;$absent=max(0,$workingDays-$present-$paid-$unpaid-($half*.5));$daily=$workingDays>0?(float)$person['monthly_salary']/$workingDays:0;$earned=round($daily*max(0,$workingDays-$unpaid-$absent-($half*.5)),2);
                $rules=$db->query('SELECT * FROM attendance_rules ORDER BY id LIMIT 1')->fetch()?:[];$lateDeduction=0;if(($rules['late_fine_type']??'none')==='occurrence')$lateDeduction=min($late*(float)$rules['late_fine_amount'],(float)$rules['max_monthly_late_deduction']?:PHP_FLOAT_MAX);
                $advanceQ=$db->prepare("SELECT COALESCE(SUM(amount),0) FROM salary_advances WHERE staff_id=? AND status='Approved' AND (recovery_month IS NULL OR DATE_FORMAT(recovery_month,'%Y-%m')=DATE_FORMAT(?,'%Y-%m'))");$advanceQ->execute([$person['id'],$periodMonth]);$advance=(float)$advanceQ->fetchColumn();$gross=$earned;$deductions=$lateDeduction+$advance;$net=max(0,$gross-$deductions);
                $sql="INSERT INTO payroll_entries(payroll_period_id,staff_id,monthly_salary,payable_days,present_days,paid_leave_days,unpaid_leave_days,half_days,absent_days,late_count,overtime_minutes,basic_earned,late_deduction,advance_deduction,gross_pay,total_deduction,net_pay,calculation_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE monthly_salary=VALUES(monthly_salary),payable_days=VALUES(payable_days),present_days=VALUES(present_days),paid_leave_days=VALUES(paid_leave_days),unpaid_leave_days=VALUES(unpaid_leave_days),half_days=VALUES(half_days),absent_days=VALUES(absent_days),late_count=VALUES(late_count),overtime_minutes=VALUES(overtime_minutes),basic_earned=VALUES(basic_earned),late_deduction=VALUES(late_deduction),advance_deduction=VALUES(advance_deduction),gross_pay=VALUES(gross_pay),total_deduction=VALUES(total_deduction),net_pay=VALUES(net_pay),calculation_json=VALUES(calculation_json)";
                $db->prepare($sql)->execute([$periodId,$person['id'],$person['monthly_salary'],$workingDays,$present,$paid,$unpaid,$half,$absent,$late,$overtime,$earned,$lateDeduction,$advance,$gross,$deductions,$net,json_encode(['daily_rate'=>$daily,'attendance'=>$stats])]);
            }
            $db->prepare("UPDATE payroll_periods SET status='Calculated',calculated_at=NOW() WHERE id=?")->execute([$periodId]);AuditService::log('calculated','payroll_periods',$periodId,[],['month'=>$periodMonth]);return $periodId;
        });
    }
}
