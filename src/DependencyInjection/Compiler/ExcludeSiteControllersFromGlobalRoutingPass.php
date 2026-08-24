<?php

namespace App\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Symfony tague automatiquement "routing.controller" toute classe utilisant
 * #[Route] (voir FrameworkExtension::registerAttributeForAutoconfiguration),
 * et `controllers: { resource: routing.controllers }` (config/routes.yaml)
 * importe ensuite TOUTES ces routes sans distinction, y compris celles des
 * contrôleurs de site (App\Site\*\Controller\*).
 *
 * Sans ce correctif, les routes d'un événement seraient donc accessibles
 * sur n'importe quel domaine en plus de leur domaine dédié, cassant
 * l'isolation par host définie dans config/routes/sites/*.yaml — deux
 * contrôleurs pourraient même finir par se disputer le même chemin ("/").
 *
 * On retire donc ce tag précisément pour ces classes : leurs routes ne
 * doivent être chargées QUE via l'import explicite avec contrainte "host".
 */
final class ExcludeSiteControllersFromGlobalRoutingPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->findTaggedServiceIds('routing.controller') as $id => $tags) {
            if (str_starts_with($id, 'App\\Site\\')) {
                $container->getDefinition($id)->clearTag('routing.controller');
            }
        }
    }
}
