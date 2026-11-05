<?php

declare(strict_types=1);

namespace Odden\Core\Screens;

use Illuminate\Database\Eloquent\Model;

final readonly class Screen
{
    /**
     * @param  class-string<Model>|null  $model
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $group = null,
        public ?int $sort = null,
        public ?string $icon = null,
        public ?string $model = null,
        public ?string $ability = null,
        public bool $record = false,
        public ?string $module = null,
        public bool $navigation = false,
    ) {}

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $pages
     * @return list<self>
     */
    public static function forModel(
        string $prefix,
        string $model,
        string $label,
        ?string $group = null,
        ?int $sort = null,
        ?string $icon = null,
        ?string $module = null,
        array $pages = ['index', 'create', 'view', 'edit'],
    ): array {
        return array_map(fn (string $page): self => new self(
            key: "{$prefix}.{$page}",
            label: $label,
            group: $group,
            sort: $sort,
            icon: $icon,
            model: $model,
            ability: match ($page) {
                'create' => 'create',
                'view' => 'view',
                'edit' => 'update',
                default => 'viewAny',
            },
            record: in_array($page, ['view', 'edit'], true),
            module: $module,
            navigation: $page === 'index',
        ), $pages);
    }

    public static function page(string $key, string $label, ?string $group = null, ?int $sort = null, ?string $icon = null, ?string $module = null, ?string $ability = null): self
    {
        return new self(
            key: $key,
            label: $label,
            group: $group,
            sort: $sort,
            icon: $icon,
            ability: $ability,
            module: $module,
            navigation: true,
        );
    }

    public function with(?string $label = null, ?string $group = null, ?int $sort = null, ?string $icon = null, ?bool $navigation = null): self
    {
        return new self(
            key: $this->key,
            label: $label ?? $this->label,
            group: $group ?? $this->group,
            sort: $sort ?? $this->sort,
            icon: $icon ?? $this->icon,
            model: $this->model,
            ability: $this->ability,
            record: $this->record,
            module: $this->module,
            navigation: $navigation ?? $this->navigation,
        );
    }
}
