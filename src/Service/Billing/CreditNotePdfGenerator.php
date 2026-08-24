<?php

namespace App\Service\Billing;

use App\Entity\CreditNote;
use Knp\Snappy\Pdf;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;
use Twig\Error\LoaderError;

final class CreditNotePdfGenerator
{
    public function __construct(
        private readonly Environment $twig,
        private readonly Pdf $snappyPdf,
        #[Autowire('%kernel.project_dir%/var/credit-notes')]
        private readonly string $storageDir,
        #[Autowire('%kernel.project_dir%/assets/images/logo.png')]
        private readonly string $logoPath,
        #[Autowire(service: 'monolog.logger.payment')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Génère le PDF de l'avoir (même moteur/pattern que InvoicePdfGenerator) et
     * retourne le chemin relatif à stocker sur CreditNote::pdfPath.
     */
    public function generate(CreditNote $creditNote): string
    {
        $site = $creditNote->getSite();
        $siteTemplate = sprintf('pdf/%s/credit_note.html.twig', $site->getCode());
        $template = $this->twig->getLoader()->exists($siteTemplate) ? $siteTemplate : 'pdf/default/credit_note.html.twig';

        $this->logger->debug('credit_note_pdf.generate.start', [
            'credit_note_id' => $creditNote->getId(),
            'credit_note_number' => $creditNote->getNumber(),
            'template' => $template,
        ]);

        $logo = is_file($this->logoPath) ? base64_encode(file_get_contents($this->logoPath)) : '';

        try {
            $html = $this->twig->render($template, ['creditNote' => $creditNote, 'logo' => $logo]);
        } catch (LoaderError $e) {
            $this->logger->error('credit_note_pdf.generate.template_error', [
                'credit_note_id' => $creditNote->getId(),
                'template' => $template,
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }

        $footer = $this->twig->render('pdf/footer.html.twig');

        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0775, true);
        }

        $relativePath = sprintf('%s.pdf', $creditNote->getNumber());
        $absolutePath = $this->storageDir.'/'.$relativePath;

        try {
            $this->snappyPdf->generateFromHtml($html, $absolutePath, [
                'footer-html' => $footer,
                'images' => true,
                'margin-bottom' => '23mm',
                'encoding' => 'UTF-8',
            ], true);
        } catch (\Throwable $e) {
            $this->logger->error('credit_note_pdf.generate.wkhtmltopdf_failed', [
                'credit_note_id' => $creditNote->getId(),
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }

        $this->logger->info('credit_note_pdf.generate.success', [
            'credit_note_id' => $creditNote->getId(),
            'credit_note_number' => $creditNote->getNumber(),
            'path' => $relativePath,
            'size_bytes' => filesize($absolutePath),
        ]);

        return $relativePath;
    }

    public function absolutePath(string $relativePath): string
    {
        return $this->storageDir.'/'.$relativePath;
    }
}
