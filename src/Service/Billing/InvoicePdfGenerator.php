<?php

namespace App\Service\Billing;

use App\Entity\Invoice;
use Knp\Snappy\Pdf;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;
use Twig\Error\LoaderError;

final class InvoicePdfGenerator
{
    public function __construct(
        private readonly Environment $twig,
        private readonly Pdf $snappyPdf,
        #[Autowire('%kernel.project_dir%/var/invoices')]
        private readonly string $storageDir,
        #[Autowire('%kernel.project_dir%/assets/images/logo.png')]
        private readonly string $logoPath,
        #[Autowire(service: 'monolog.logger.payment')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Génère le PDF (via wkhtmltopdf, identique au projet backoffice-clcom)
     * et retourne le chemin relatif stocké sur Invoice::pdfPath. Cherche
     * d'abord un template propre au site, sinon retombe sur le template
     * par défaut (voir templates/pdf/{site_code}/invoice.html.twig).
     */
    public function generate(Invoice $invoice): string
    {
        $site = $invoice->getSite();
        $siteTemplate = sprintf('pdf/%s/invoice.html.twig', $site->getCode());
        $template = $this->twig->getLoader()->exists($siteTemplate) ? $siteTemplate : 'pdf/default/invoice.html.twig';

        $this->logger->debug('invoice_pdf.generate.start', [
            'invoice_id' => $invoice->getId(),
            'invoice_number' => $invoice->getNumber(),
            'template' => $template,
        ]);

        $logo = is_file($this->logoPath) ? base64_encode(file_get_contents($this->logoPath)) : '';

        try {
            $html = $this->twig->render($template, ['invoice' => $invoice, 'logo' => $logo]);
        } catch (LoaderError $e) {
            $this->logger->error('invoice_pdf.generate.template_error', [
                'invoice_id' => $invoice->getId(),
                'template' => $template,
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }

        $footer = $this->twig->render('pdf/footer.html.twig');

        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0775, true);
        }

        $relativePath = sprintf('%s.pdf', $invoice->getNumber());
        $absolutePath = $this->storageDir.'/'.$relativePath;

        try {
            $this->snappyPdf->generateFromHtml($html, $absolutePath, [
                'footer-html' => $footer,
                'images' => true,
                'margin-bottom' => '23mm',
                'encoding' => 'UTF-8',
            ], true);
        } catch (\Throwable $e) {
            $this->logger->error('invoice_pdf.generate.wkhtmltopdf_failed', [
                'invoice_id' => $invoice->getId(),
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }

        $this->logger->info('invoice_pdf.generate.success', [
            'invoice_id' => $invoice->getId(),
            'invoice_number' => $invoice->getNumber(),
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
