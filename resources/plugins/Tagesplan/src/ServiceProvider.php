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

        // Defaults of plugin options are not set by the core, so the admin config would compare an unticked
        // checkbox (false) with null, see no change and not save it
        if ($config->get('tagesplan_helpers_visible') === null) {
            $config->set('tagesplan_helpers_visible', true);
        }

        // Adds the "Tagesplan" tab to the main navigation, only for the managers if it is hidden from the helpers
        $config->set('header_items', [
            ...$config->get('header_items', []),
            'tagesplan.menu' => [
                url('/tagesplan'),
                Controller::visibleForHelpers() ? 'tagesplan.view' : 'tagesplan.manage',
            ],
        ]);
    }
}
