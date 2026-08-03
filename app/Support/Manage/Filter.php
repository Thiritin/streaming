<?php

namespace App\Support\Manage;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Declares one filter of a manage table.
 *
 * Types:
 *  select   single or multiple choice; value is a string or array of strings
 *  ternary  three-state; value is '' (all), '1' or '0'
 *  boolean  checkbox; value is a bool, and `default` can make it on by default
 *
 * A filter with no `apply()` callback falls back to `where(key, value)`, or for a
 * boolean filter to nothing at all - a plain boolean filter must declare how it
 * narrows the query.
 *
 * @implements Arrayable<string, mixed>
 */
final class Filter implements Arrayable
{
    /** @var array<string, string> */
    private array $options = [];

    private bool $multiple = false;

    private ?string $placeholder = null;

    private ?string $trueLabel = null;

    private ?string $falseLabel = null;

    private mixed $default = null;

    private ?Closure $apply = null;

    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
    ) {}

    public static function select(string $key, ?string $label = null): self
    {
        return new self($key, $label ?? str($key)->headline()->toString(), 'select');
    }

    public static function ternary(string $key, ?string $label = null): self
    {
        return new self($key, $label ?? str($key)->headline()->toString(), 'ternary');
    }

    public static function boolean(string $key, ?string $label = null): self
    {
        return new self($key, $label ?? str($key)->headline()->toString(), 'boolean');
    }

    /**
     * @param  array<string, string>  $options  value => label
     */
    public function options(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    public function multiple(bool $multiple = true): self
    {
        $this->multiple = $multiple;

        return $this;
    }

    public function placeholder(string $placeholder): self
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    public function trueLabel(string $label): self
    {
        $this->trueLabel = $label;

        return $this;
    }

    public function falseLabel(string $label): self
    {
        $this->falseLabel = $label;

        return $this;
    }

    public function default(mixed $default): self
    {
        $this->default = $default;

        return $this;
    }

    /**
     * @param  Closure(Builder, mixed): void  $callback
     */
    public function apply(Closure $callback): self
    {
        $this->apply = $callback;

        return $this;
    }

    public function defaultValue(): mixed
    {
        return $this->default ?? match ($this->type) {
            'boolean' => false,
            'select' => $this->multiple ? [] : '',
            default => '',
        };
    }

    /**
     * Whether the given request value should narrow the query at all.
     */
    public function isActive(mixed $value): bool
    {
        return match ($this->type) {
            'boolean' => (bool) $value,
            'select' => $this->multiple ? ! empty($value) : $value !== '' && $value !== null,
            'ternary' => $value === '1' || $value === '0' || $value === true || $value === false,
            default => $value !== '' && $value !== null,
        };
    }

    /**
     * Coerce a raw request value into the shape the filter works with.
     */
    public function normalize(mixed $value): mixed
    {
        return match ($this->type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'ternary' => match (true) {
                $value === true, $value === '1', $value === 1 => '1',
                $value === false, $value === '0', $value === 0 => '0',
                default => '',
            },
            'select' => $this->multiple ? array_values(array_filter((array) $value, fn ($v) => $v !== '' && $v !== null)) : (string) $value,
            default => $value,
        };
    }

    public function applyTo(Builder $query, mixed $value): void
    {
        if ($this->apply) {
            ($this->apply)($query, $value);

            return;
        }

        match ($this->type) {
            'select' => $this->multiple
                ? $query->whereIn($this->key, $value)
                : $query->where($this->key, $value),
            'ternary' => $query->where($this->key, $value === '1'),
            default => null,
        };
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
            'options' => $this->options === [] ? null : collect($this->options)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
            'multiple' => $this->multiple,
            'placeholder' => $this->placeholder,
            'trueLabel' => $this->trueLabel,
            'falseLabel' => $this->falseLabel,
            'default' => $this->defaultValue(),
        ];
    }
}
