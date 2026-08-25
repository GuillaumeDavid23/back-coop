<?php

namespace App;

use App\DependencyInjection\Compiler\ExcludeSiteControllersFromGlobalRoutingPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        // Priorité positive obligatoire : à priorité égale, les passes des
        // bundles s'exécutent avant celles du noyau, et RoutingControllerPass
        // aurait déjà figé la liste des contrôleurs à router avant que nous
        // ayons retiré le tag des contrôleurs de site.
        $container->addCompilerPass(
            new ExcludeSiteControllersFromGlobalRoutingPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            10,
        );
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
