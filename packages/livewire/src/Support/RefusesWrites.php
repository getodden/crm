<?php

declare(strict_types=1);

namespace Odden\Livewire\Support;

use Closure;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\Features\SupportEvents\SupportEvents;
use Livewire\WithPagination;
use Odden\Livewire\Attributes\Reads;
use Odden\Livewire\Concerns\GuardsWrites;
use Odden\Livewire\Contracts\WriteGuard;
use Odden\Ui\Livewire\Concerns\WithConfirmation;
use Odden\Ui\Livewire\Concerns\WithRecordTable;
use ReflectionException;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;

use function Livewire\before;

final class RefusesWrites
{
    private const array MAGIC_READS = ['$refresh', '$set', '$sync', '$commit'];

    private const array FRAMEWORK_READS = [
        WithPagination::class => ['gotoPage', 'nextPage', 'previousPage', 'setPage', 'resetPage'],
        WithRecordTable::class => ['sortBy', 'resetFilters'],
        WithConfirmation::class => ['cancelConfirmation'],
    ];

    public static function listen(): void
    {
        before('call', function (mixed $component, mixed $method, mixed $params, mixed $context, mixed $returnEarly): void {
            if ($component instanceof Component && is_string($method) && is_array($params) && $returnEarly instanceof Closure) {
                self::guard($component, $method, $params, $returnEarly);
            }
        });
    }

    /**
     * @param  array<int|string, mixed>  $params
     */
    private static function guard(Component $component, string $method, array $params, Closure $returnEarly): void
    {
        if (! in_array(GuardsWrites::class, class_uses_recursive($component), true)) {
            return;
        }

        $user = Auth::user();
        $refusal = $user === null ? null : app(WriteGuard::class)->refusal($user);

        if ($refusal === null) {
            return;
        }

        $isEvent = $method === '__dispatch';
        $target = $isEvent ? self::listenerMethod($component, $params) : $method;

        if ($target === null || self::reads($component, $target)) {
            return;
        }

        if ($isEvent) {
            throw new HttpException(403, $refusal);
        }

        $component->dispatch('ui-toast', message: $refusal, variant: 'danger');
        $returnEarly();
    }

    /**
     * @param  array<int|string, mixed>  $params
     */
    private static function listenerMethod(Component $component, array $params): ?string
    {
        $event = $params[0] ?? null;

        if (! is_string($event) || ! in_array($event, SupportEvents::getListenerEventNames($component), true)) {
            return null;
        }

        return SupportEvents::getListenerMethodName($component, $event);
    }

    private static function reads(Component $component, string $method): bool
    {
        if (in_array($method, self::MAGIC_READS, true)) {
            return true;
        }

        try {
            $reflection = new ReflectionMethod($component, $method);
        } catch (ReflectionException) {
            return false;
        }

        return $reflection->getAttributes(Reads::class) !== [] || self::isFrameworkRead($component, $reflection);
    }

    private static function isFrameworkRead(Component $component, ReflectionMethod $method): bool
    {
        $traits = class_uses_recursive($component);

        foreach (self::FRAMEWORK_READS as $trait => $names) {
            if (in_array($trait, $traits, true) && in_array($method->getName(), $names, true) && $method->getFileName() === (new ReflectionMethod($trait, $method->getName()))->getFileName()) {
                return true;
            }
        }

        return false;
    }
}
