<?php

declare(strict_types=1);

namespace Odden\Core\Screens;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Odden\Core\Exceptions\InvalidScreenRegistrationException;
use Odden\Core\Exceptions\ScreenNotAvailableException;
use Odden\Core\Exceptions\UnknownScreenException;

final class ScreenRegistry
{
    /** @var array<string, Screen> */
    private array $screens = [];

    /** @var array<string, int> */
    private array $groups = [];

    /** @var array<string, array<string, ScreenDrawer>> */
    private array $drawers = [];

    /** @var array<string, array{class: class-string<ScreenShell>, priority: int}> */
    private array $shells = [];

    /** @var array<class-string, class-string> */
    private array $replacements = [];

    /** @var list<string> */
    private array $exceptions = [];

    /** @var list<string> */
    private array $disabledModules = [];

    private bool $frozen = false;

    public function __construct(private readonly ScreenTenantParameters $tenantParameters) {}

    public function define(Screen ...$screens): void
    {
        $this->guardAgainstChanges();

        foreach ($screens as $screen) {
            if (isset($this->screens[$screen->key])) {
                throw new InvalidScreenRegistrationException("The screen [{$screen->key}] is already defined.");
            }

            $this->screens[$screen->key] = $screen;
        }
    }

    public function redefine(Screen ...$screens): void
    {
        $this->guardAgainstChanges();

        foreach ($screens as $screen) {
            if (! isset($this->screens[$screen->key])) {
                throw new UnknownScreenException("The screen [{$screen->key}] is not defined.");
            }

            $this->screens[$screen->key] = $screen;
        }
    }

    public function defineGroup(string $label, int $sort): void
    {
        $this->guardAgainstChanges();

        $this->groups[$label] = $sort;
    }

    public function draw(string $key, ScreenDrawer $drawer): void
    {
        $this->guardAgainstChanges();

        $this->drawers[$key][$drawer->shell] = $drawer;
    }

    /**
     * @param  class-string<ScreenShell>  $class
     */
    public function shell(string $name, string $class, int $priority = 0): void
    {
        $this->guardAgainstChanges();

        $this->shells[$name] = ['class' => $class, 'priority' => $priority];
    }

    /**
     * @param  class-string  $original
     * @param  class-string  $replacement
     */
    public function replace(string $original, string $replacement): void
    {
        $this->guardAgainstChanges();

        $this->replacements[$original] = $replacement;
    }

    public function except(string ...$patterns): void
    {
        $this->guardAgainstChanges();

        $this->exceptions = array_values(array_unique([...$this->exceptions, ...$patterns]));
    }

    public function disableModules(string ...$modules): void
    {
        $this->guardAgainstChanges();

        $this->disabledModules = array_values(array_unique([...$this->disabledModules, ...$modules]));
    }

    public function freeze(): void
    {
        $undefined = array_values(array_diff(array_keys($this->drawers), array_keys($this->screens)));

        if ($undefined !== []) {
            throw new UnknownScreenException('Screens are drawn but never defined: ['.implode(', ', $undefined).'].');
        }

        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    public function has(string $key): bool
    {
        return isset($this->screens[$key]);
    }

    public function get(string $key): Screen
    {
        return $this->screens[$key] ?? throw new UnknownScreenException("The screen [{$key}] is not defined.");
    }

    /**
     * @return list<Screen>
     */
    public function all(): array
    {
        return array_values($this->screens);
    }

    /**
     * @return array<class-string, class-string>
     */
    public function replacements(): array
    {
        return $this->replacements;
    }

    /**
     * @return list<string>
     */
    public function exceptions(): array
    {
        return $this->exceptions;
    }

    /**
     * @return list<string>
     */
    public function disabledModules(): array
    {
        return $this->disabledModules;
    }

    public function isExcepted(string $key): bool
    {
        return $this->exceptions !== [] && Str::is($this->exceptions, $key);
    }

    public function moduleEnabled(?string $module): bool
    {
        return $module === null || ! in_array($module, $this->disabledModules, true);
    }

    public function drawerFor(string $key, string $shell): ?ScreenDrawer
    {
        return $this->drawers[$key][$shell] ?? null;
    }

    /**
     * @param  class-string  $class
     * @return list<string>
     */
    public function keysDrawnBy(string $shell, string $class): array
    {
        $keys = [];

        foreach ($this->drawers as $key => $drawers) {
            if (isset($drawers[$shell]) && $drawers[$shell]->class === $class) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    public function available(string $key): bool
    {
        return $this->has($key) && $this->enabled($this->screens[$key]) && $this->resolveDrawing($this->screens[$key]) !== null;
    }

    public function can(string $key, ?Authenticatable $user, ?Model $record = null): bool
    {
        $screen = $this->get($key);

        if ($user === null) {
            return false;
        }

        if ($screen->ability === null) {
            return true;
        }

        $subject = $record ?? $screen->model;

        if ($subject === null) {
            return Gate::forUser($user)->allows($screen->ability);
        }

        $policy = Gate::getPolicyFor($subject);

        if ($policy === null || ! method_exists($policy, $screen->ability)) {
            return true;
        }

        return Gate::forUser($user)->allows($screen->ability, $subject);
    }

    /**
     * @return list<array{group: string|null, screens: list<Screen>}>
     */
    public function navigation(?Authenticatable $user = null): array
    {
        $visible = array_filter($this->screens, fn (Screen $screen): bool => $screen->navigation
            && $this->available($screen->key)
            && ($user === null || $this->can($screen->key, $user)));

        $byGroup = [];

        foreach ($visible as $screen) {
            $byGroup[$screen->group ?? ''][] = $screen;
        }

        uksort($byGroup, fn (string|int $a, string|int $b): int => $this->groupOrder((string) $a) <=> $this->groupOrder((string) $b)
            ?: strcmp((string) $a, (string) $b));

        $navigation = [];

        foreach ($byGroup as $group => $screens) {
            usort($screens, fn (Screen $a, Screen $b): int => ($a->sort ?? 0) <=> ($b->sort ?? 0) ?: strcmp($a->label, $b->label));

            $navigation[] = ['group' => $group === '' ? null : (string) $group, 'screens' => $screens];
        }

        return $navigation;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function url(string $key, Model|int|string|null $record = null, array $parameters = [], ?Model $tenant = null, bool $absolute = true): string
    {
        $screen = $this->get($key);

        if ($screen->record && $record === null) {
            throw new InvalidArgumentException("The screen [{$key}] needs a record.");
        }

        $drawing = $this->enabled($screen) ? $this->resolveDrawing($screen) : null;

        if ($drawing === null) {
            throw new ScreenNotAvailableException("The screen [{$key}] is not available.");
        }

        [$shell, $drawer, $class] = $drawing;

        $tenantParameters = $tenant === null ? [] : $this->tenantParameters->for($tenant);

        return $shell->url($screen, $drawer, $class, $record, [...$tenantParameters, ...$parameters], $tenant, $absolute);
    }

    private function guardAgainstChanges(): void
    {
        if ($this->frozen) {
            throw new InvalidScreenRegistrationException('The screens are frozen once the application has booted.');
        }
    }

    private function enabled(Screen $screen): bool
    {
        return $this->moduleEnabled($screen->module) && ! $this->isExcepted($screen->key);
    }

    private function groupOrder(string $group): int
    {
        if ($group === '') {
            return PHP_INT_MIN;
        }

        return $this->groups[$group] ?? PHP_INT_MAX;
    }

    /**
     * @return array{0: ScreenShell, 1: ScreenDrawer, 2: class-string}|null
     */
    private function resolveDrawing(Screen $screen): ?array
    {
        $names = array_keys($this->shells);
        usort($names, fn (string $a, string $b): int => $this->shells[$b]['priority'] <=> $this->shells[$a]['priority']);

        foreach ($names as $name) {
            $drawer = $this->drawers[$screen->key][$name] ?? null;

            if ($drawer === null) {
                continue;
            }

            $class = $this->replacements[$drawer->class] ?? $drawer->class;
            $shell = app($this->shells[$name]['class']);

            if ($shell->draws($screen, $drawer, $class)) {
                return [$shell, $drawer, $class];
            }
        }

        return null;
    }
}
