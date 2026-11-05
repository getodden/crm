<?php

declare(strict_types=1);

namespace Odden\Livewire\Search;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Odden\Core\Screens\Screens;
use Odden\Livewire\Contracts\SearchSource;

final class GlobalSearch extends Component
{
    public const int MIN_LENGTH = 2;

    public const int MAX_LENGTH = 100;

    public const int HITS_PER_SOURCE = 5;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->search = mb_substr($this->search, 0, self::MAX_LENGTH);
    }

    public function render(): View
    {
        $groups = $this->groups();

        return view('odden-livewire::search.global-search', [
            'groups' => $groups,
            'searched' => $this->term() !== '',
            'count' => array_sum(array_map(fn (array $group): int => count($group['hits']), $groups)),
        ]);
    }

    private function term(): string
    {
        $term = trim(mb_substr($this->search, 0, self::MAX_LENGTH));

        return mb_strlen($term) < self::MIN_LENGTH ? '' : $term;
    }

    /**
     * @return list<array{label: string, hits: list<SearchHit>}>
     */
    private function groups(): array
    {
        $term = $this->term();
        $user = Auth::user();

        if ($term === '' || $user === null) {
            return [];
        }

        $groups = [];

        foreach (app(SearchSources::class)->configured() as $source) {
            if (! $this->mayUse($source, $user)) {
                continue;
            }

            $hits = $source->search($term, $user, self::HITS_PER_SOURCE);

            if ($hits !== []) {
                $groups[] = ['label' => $source->label(), 'hits' => $hits];
            }
        }

        return $groups;
    }

    private function mayUse(SearchSource $source, Authenticatable $user): bool
    {
        return Screens::canOpen($source->screenKey(), $user);
    }
}
