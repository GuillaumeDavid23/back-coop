<?php

namespace App\Service\Export;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Générateur Excel générique — utilisé pour tous les exports du BO
 * (factures, avoirs, participants...). Ne connaît rien du métier : on lui
 * passe des feuilles sous forme de tableaux simples (en-têtes + lignes).
 */
final class SpreadsheetExporter
{
    /**
     * @param array<string, array{headers: list<string>, rows: list<list<mixed>>}> $sheets
     *   Clé = nom de la feuille (max 31 caractères, contrainte Excel).
     */
    public function build(array $sheets): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        foreach ($sheets as $title => $sheet) {
            $worksheet = $spreadsheet->createSheet();
            $worksheet->setTitle(mb_substr($title, 0, 31));

            $worksheet->fromArray($sheet['headers'], null, 'A1');
            $headerStyle = $worksheet->getStyle('A1:'.$worksheet->getHighestColumn().'1');
            $headerStyle->getFont()->setBold(true);
            $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E4D8C6');

            if ($sheet['rows'] !== []) {
                $worksheet->fromArray($sheet['rows'], null, 'A2');
            }

            foreach (range('A', $worksheet->getHighestColumn()) as $column) {
                $worksheet->getColumnDimension($column)->setAutoSize(true);
            }
        }

        return $spreadsheet;
    }

    public function toResponse(Spreadsheet $spreadsheet, string $filename): StreamedResponse
    {
        $writer = new Xlsx($spreadsheet);

        $response = new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        });

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));
        $response->headers->set('Cache-Control', 'max-age=0');

        return $response;
    }
}
