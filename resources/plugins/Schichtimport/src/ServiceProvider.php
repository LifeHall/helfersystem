<?php

declare(strict_types=1);

namespace Helfersystem\Schichtimport;

use Engelsystem\Config\Config;
use Engelsystem\Container\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function boot(): void
    {
        /** @var Config $config */
        $config = $this->app->get('config');

        // The admin menu can not be extended by plugins, so the import gets its own tab for those who create shifts
        $config->set('header_items', [
            ...$config->get('header_items', []),
            'schichtimport.menu' => [url('/schichtimport'), 'admin_shifts'],
        ]);
    }
}
