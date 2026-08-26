<?php

namespace App\Service\Export;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Générateur Excel générique - utilisé pour tous les exports du BO
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

            // strictNullComparison : sans lui, fromArray() compare chaque valeur
            // à null de façon lâche et laisse donc la cellule vide pour un 0 ou
            // une chaîne vide. Un décompte nul disparaîtrait du tableau, sans
            // qu'on puisse distinguer « aucun » d'une donnée manquante.
            $worksheet->fromArray($sheet['headers'], null, 'A1', true);
            $headerStyle = $worksheet->getStyle('A1:'.$worksheet->getHighestColumn().'1');
            $headerStyle->getFont()->setBold(true);
            $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E4D8C6');

            if ($sheet['rows'] !== []) {
                $worksheet->fromArray($sheet['rows'], null, 'A2', true);
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
