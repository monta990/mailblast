<?php
namespace GlpiPlugin\Mailblast\Service;

final class ReportService
{
    public function generate(array $rows, string $subject): array
    {
        $rows = array_slice($rows, 0, 10000);
        $stamp = date('Y-m-d H:i');
        $spread = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spread->getProperties()
            ->setCreator('Mail Blast - GLPI plugin')
            ->setTitle(__('Mail Blast sending report', 'mailblast'));
        $ws = $spread->getActiveSheet();
        $ws->setTitle(__('Report', 'mailblast'));
        $headers = [
            __('Date', 'mailblast'),
            __('Subject', 'mailblast'),
            __('Email', 'mailblast'),
            __('Status', 'mailblast'),
            __('Reason', 'mailblast'),
        ];
        foreach ($headers as $i => $header) {
            $column = chr(ord('A') + $i);
            $ws->setCellValue($column . '1', $header);
        }
        $rowNumber = 2;
        foreach ($rows as $row) {
            $safeEmail = preg_replace('/^([=+\-@])/', "'$1", (string) ($row['email'] ?? ''));
            $safeReason = preg_replace('/^([=+\-@])/', "'$1", (string) ($row['reason'] ?? ''));
            $ws->setCellValue('A' . $rowNumber, $stamp);
            $ws->setCellValue('B' . $rowNumber, $subject);
            $ws->setCellValue('C' . $rowNumber, $safeEmail);
            $status = match ((string) ($row['status'] ?? '')) {
                'sent' => __('Sent status', 'mailblast'),
                'failed' => __('Failed status', 'mailblast'),
                'pending' => __('Pending status', 'mailblast'),
                default => (string) ($row['status'] ?? ''),
            };
            $ws->setCellValue('D' . $rowNumber, $status);
            $ws->setCellValue('E' . $rowNumber, $safeReason);
            ++$rowNumber;
        }
        foreach (range('A', 'E') as $column) {
            $ws->getColumnDimension($column)->setAutoSize(true);
        }
        ob_start();
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spread))->save('php://output');
        $xlsx = ob_get_clean();
        return [
            'data' => base64_encode((string) $xlsx),
            'filename' => 'mailblast_report_' . gmdate('Y-m-d_His') . '.xlsx',
        ];
    }
}
