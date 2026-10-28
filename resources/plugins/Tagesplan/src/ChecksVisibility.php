<?php

declare(strict_types=1);

namespace Helfersystem\Tagesplan;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The day plan can be hidden from the helpers (config tagesplan_helpers_visible),
 * users with tagesplan.manage (admins, shift coordinators) can always see it
 */
trait ChecksVisibility
{
    public static function visibleForHelpers(): bool
    {
        return (bool) config('tagesplan_helpers_visible', true);
    }

    /**
     * @return bool|null null: the usual permission check (tagesplan.view)
     */
    public function hasPermission(ServerRequestInterface $request, string $method): ?bool
    {
        if (auth()->can('tagesplan.manage')) {
            return true;
        }

        return static::visibleForHelpers() ? null : false;
    }
}
