<?php

declare(strict_types=1);

use Dompdf\Dompdf;
use PHPUnit\Framework\TestCase;

final class PdfTest extends TestCase
{
    public function testPatchedDependencyRendersAnInvoiceWithoutRemoteResources(): void
    {
        $pdf = new Dompdf(['isRemoteEnabled' => false]);
        $pdf->loadHtml('<h1>Invoice</h1><table><tr><td>Consulting</td><td>100.00</td></tr></table>');
        $pdf->setPaper('A4');
        $pdf->render();
        self::assertStringStartsWith('%PDF-', $pdf->output());
        self::assertGreaterThan(500, strlen($pdf->output()));
    }
}
