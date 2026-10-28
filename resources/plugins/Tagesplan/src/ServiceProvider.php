<?php

declare(strict_types=1);

namespace Helfersystem\Tagesplan;

use Engelsystem\Config\Config;
use Engelsystem\Container\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function boot(): void
    {
        /** @var Config $config */
        $config = $this->app->get('config');

        // Adds the "Tagesplan" tab to the main navigation
        $config->set('header_items', [
            ...$config->get('header_items', []),
            'tagesplan.menu' => [url('/tagesplan'), 'user_shifts'],
        ]);
    }
}
