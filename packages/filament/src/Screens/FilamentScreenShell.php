<?php

declare(strict_types=1);

namespace Odden\Filament\Screens;

use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Odden\Core\Screens\Screen;
use Odden\Core\Screens\ScreenDrawer;
use Odden\Core\Screens\ScreenShell;
use Odden\Filament\OddenPlugin;

final class FilamentScreenShell implements ScreenShell
{
    public function draws(Screen $screen, ScreenDrawer $drawer, string $class): bool
    {
        $panel = $this->panel($drawer);

        if ($panel === null) {
            return false;
        }

        $effective = $this->effectiveClass($panel, $drawer, $class);

        if (! is_subclass_of($effective, Resource::class)) {
            return in_array($effective, $panel->getPages(), true);
        }

        return in_array($effective, $panel->getResources(), true)
            && $drawer->page !== null
            && array_key_exists($drawer->page, $effective::getPages());
    }

    public function url(Screen $screen, ScreenDrawer $drawer, string $class, Model|int|string|null $record, array $parameters, ?Model $tenant, bool $absolute): string
    {
        $panel = $this->panel($drawer);

        if ($panel === null) {
            throw new LogicException("The Filament panel for the screen [{$screen->key}] does not exist.");
        }

        $effective = $this->effectiveClass($panel, $drawer, $class);
        $panelTenant = $panel->hasTenancy() ? $tenant : null;

        if (is_subclass_of($effective, Resource::class)) {
            return $effective::getUrl(
                $drawer->page ?? 'index',
                $record !== null ? ['record' => $record] + $parameters : $parameters,
                $absolute,
                $drawer->panel,
                $panelTenant,
            );
        }

        return $effective::getUrl($parameters, $absolute, $drawer->panel, $panelTenant);
    }

    private function panel(ScreenDrawer $drawer): ?Panel
    {
        if ($drawer->panel !== null) {
            return Filament::getPanel($drawer->panel);
        }

        $current = Filament::getCurrentPanel();

        if ($current !== null) {
            return $current;
        }

        foreach (Filament::getPanels() as $panel) {
            if ($panel->isDefault()) {
                return $panel;
            }
        }

        return null;
    }

    /**
     * @param  class-string  $class
     * @return class-string
     */
    private function effectiveClass(Panel $panel, ScreenDrawer $drawer, string $class): string
    {
        $plugin = $panel->hasPlugin('odden') ? $panel->getPlugin('odden') : null;

        return $plugin instanceof OddenPlugin ? ($plugin->getReplacements()[$drawer->class] ?? $class) : $class;
    }
}
