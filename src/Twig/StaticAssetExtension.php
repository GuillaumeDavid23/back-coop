<?php

namespace App\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Horodate les fichiers statiques des sites publics (CSS, JS), servis
 * directement depuis public/ sans passer par AssetMapper : sans cela, le
 * navigateur d'un relecteur garde l'ancienne feuille de style après une
 * livraison et voit un site qui semble ne pas avoir été corrigé.
 */
final class StaticAssetExtension extends AbstractExtension
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/public')]
        private readonly string $publicDir,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('static_asset', $this->version(...))];
    }

    public function version(string $path): string
    {
        $mtime = @filemtime($this->publicDir.'/'.ltrim($path, '/'));

        return false === $mtime ? $path : $path.'?v='.$mtime;
    }
}
