<?php

namespace App\Service\Export;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Regroupe une sélection de PDF (factures ou avoirs) dans une archive ZIP
 * téléchargeable en un clic — utilisé par l'action de sélection (case à
 * cocher) des écrans Factures et Avoirs, qui fait du téléchargement de masse
 * plutôt qu'un export Excel (réservé au bouton "Exporter tout").
 */
final class PdfZipDownloader
{
    /** @param array<string, string> $files Nom de fichier dans le zip => chemin absolu du PDF source */
    public function toResponse(array $files, string $zipFilename): BinaryFileResponse
    {
        $tmpZipPath = tempnam(sys_get_temp_dir(), 'pdfzip_').'.zip';

        $zip = new \ZipArchive();
        $zip->open($tmpZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($files as $name => $path) {
            if (is_file($path)) {
                $zip->addFile($path, $name);
            }
        }
        $zip->close();

        $response = new BinaryFileResponse($tmpZipPath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $zipFilename);
        $response->deleteFileAfterSend(true);

        return $response;
    }
}
