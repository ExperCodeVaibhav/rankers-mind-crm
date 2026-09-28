<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= e($title) ?></title>
    <style>
        @page{margin:25px}body{font-family:DejaVu Sans,Arial,sans-serif;color:#17213a;font-size:10px}header{border-bottom:3px solid #0794b5;margin-bottom:18px;padding-bottom:12px;overflow:hidden}h1{font-size:22px;margin:0;color:#101936}p{margin:5px 0 0;color:#64748b}table{width:100%;border-collapse:collapse;table-layout:fixed}th{background:#101936;color:#fff;text-align:left;padding:7px 6px;font-size:8px;text-transform:uppercase}td{border-bottom:1px solid #dbe3ed;padding:7px 6px;vertical-align:top;word-break:break-word}tbody tr:nth-child(even){background:#f7fafc}.empty{text-align:center;padding:30px;color:#64748b}.brand{float:left}.meta{float:right;text-align:right}
    </style>
</head>
<body>
<header><div class="brand"><h1>Rankers Mind</h1><p><?= e($title) ?></p></div><div class="meta"><strong><?= e(date('d M Y',strtotime($from))) ?> - <?= e(date('d M Y',strtotime($to))) ?></strong><p>Generated <?= e(date('d M Y, g:i A')) ?></p></div></header>
<?php if($rows):?><table><thead><tr><?php foreach(array_keys($rows[0]) as $column):?><th><?= e(ucwords(str_replace('_',' ',$column))) ?></th><?php endforeach;?></tr></thead><tbody><?php foreach($rows as $row):?><tr><?php foreach($row as $value):?><td><?= e($value===null?'—':(string)$value) ?></td><?php endforeach;?></tr><?php endforeach;?></tbody></table><?php else:?><div class="empty">No records found for the selected date range.</div><?php endif;?>
</body></html>
