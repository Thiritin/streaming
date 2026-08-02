<?php

namespace App\Support\Manage;

use Closure;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Declares one column of a manage table.
 *
 * The `type` decides how the client renders the cell value produced by the table's
 * row transformer:
 *
 *  text      string|null
 *  number    int|float, or ['display' => string, 'description' => ?string]
 *  badge     Status::make() triple
 *  image     url string|null
 *  bool      boolean
 *  datetime  string, or ['display' => string, 'title' => ?string]
 *  duration  preformatted string
 *  color     hex string
 *  copyable  string
 *  toggle    ['value' => bool, 'url' => string]  (writes immediately)
 *  icon      ['icon' => string, 'tone' => string, 'title' => ?string]
 *
 * @implements Arrayable<string, mixed>
 */
final class Column implements Arrayable
{
    private bool $sortable = false;

    private ?string $sortKey = null;

    private ?Closure $sortUsing = null;

    private bool $searchable = false;

    private ?string $searchKey = null;

    private bool $toggleable = false;

    private bool $hiddenByDefault = false;

    private string $align = 'left';

    private ?string $fallback = null;

    private ?string $width = null;

    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
    ) {}

    public static function make(string $key, ?string $label = null, string $type = 'text'): self
    {
        return new self($key, $label ?? str($key)->headline()->toString(), $type);
    }

    public static function text(string $key, ?string $label = null): self
    {
        return self::make($key, $label, 'text');
    }

    public static function number(string $key, ?string $label = null): self
    {
        return self::make($key, $label, 'number')->align('right');
    }

    public static function badge(string $key, ?string $label = null): self
    {
        return self::make($key, $label, 'badge');
    }

    public static function image(string $key, ?string $label = null): self
    {
        return self::make($key, $label, 'image');
    }

    public static function bool(string $key, ?string $label = null): self
    {
        return self::make($key, $label, 'bool')->align('center');
    }

    public static function datetime(string $key, ?string $label = null): self
    {
        return self::make($key, $label, 'datetime');
    }

    public static function duration(string $key, ?string $label = null): self
    {
        return self::make($key, $label, 'duration')->align('right');
    }

    public static function color(string $key, ?string $label = null): self
    {
        return self::make($key, $label, 'color');
    }

    public static function copyable(string $key, ?string $label = null): self
    {
        return self::make($key, $label, 'copyable');
    }

    public static function toggle(string $key, ?string $label = null): self
    {
        return self::make($key, $label, 'toggle')->align('center');
    }

    public static function icon(string $key, ?string $label = null): self
    {
        return self::make($key, $label, 'icon')->align('center');
    }

    /**
     * @param  string|null  $sortKey  Database column to sort by, when it differs from the cell key.
     */
    public function sortable(?string $sortKey = null): self
    {
        $this->sortable = true;
        $this->sortKey = $sortKey;

        return $this;
    }

    /**
     * Custom sort, required for columns that sort across a relation.
     *
     * @param  Closure(\Illuminate\Database\Eloquent\Builder, string): void  $callback
     */
    public function sortUsing(Closure $callback): self
    {
        $this->sortable = true;
        $this->sortUsing = $callback;

        return $this;
    }

    /**
     * @param  string|null  $searchKey  Column, or `relation.column` to search through a relation.
     */
    public function searchable(?string $searchKey = null): self
    {
        $this->searchable = true;
        $this->searchKey = $searchKey;

        return $this;
    }

    public function toggleable(bool $hiddenByDefault = false): self
    {
        $this->toggleable = true;
        $this->hiddenByDefault = $hiddenByDefault;

        return $this;
    }

    public function align(string $align): self
    {
        $this->align = $align;

        return $this;
    }

    /**
     * Rendered instead of an empty cell.
     */
    public function fallback(string $fallback): self
    {
        $this->fallback = $fallback;

        return $this;
    }

    public function width(string $width): self
    {
        $this->width = $width;

        return $this;
    }

    public function isSortable(): bool
    {
        return $this->sortable;
    }

    public function isSearchable(): bool
    {
        return $this->searchable;
    }

    public function isHiddenByDefault(): bool
    {
        return $this->hiddenByDefault;
    }

    public function resolvedSortKey(): ?string
    {
        return $this->sortKey ?? $this->key;
    }

    public function resolvedSearchKey(): string
    {
        return $this->searchKey ?? $this->key;
    }

    public function sortCallback(): ?Closure
    {
        return $this->sortUsing;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'align' => $this->align,
            'sortable' => $this->sortable,
            'sortKey' => $this->sortUsing ? $this->key : $this->resolvedSortKey(),
            'toggleable' => $this->toggleable,
            'hiddenByDefault' => $this->hiddenByDefault,
            'fallback' => $this->fallback,
            'width' => $this->width,
        ];
    }
}
