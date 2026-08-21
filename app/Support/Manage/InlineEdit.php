<?php

namespace App\Support\Manage;

use Illuminate\Contracts\Support\Arrayable;

/**
 * The few fields of one row that can be changed from the list, without opening it.
 *
 * Declared per record like everything else in this toolkit, so which fields a row offers
 * - and whether it offers any at all - is decided server-side and is assertable in a
 * feature test. A field carries its current value, so the client edits what the row
 * actually holds rather than re-parsing the formatted cell it renders.
 *
 * The client only turns these into controls when the operator switches inline editing on,
 * and each change is saved on its own: there is no form and no submit button, so a
 * mistyped date cannot sit unsaved next to a correct one.
 *
 * Field shape: ['key', 'type', 'value', 'options'?, 'label'?, 'disabled'?]
 * Types are the subset the client renders inline: 'select', 'datetime', 'text'.
 *
 * @implements Arrayable<string, mixed>
 */
final class InlineEdit implements Arrayable
{
    /** @var array<int, array<string, mixed>> */
    private array $fields = [];

    private function __construct(private readonly string $url, private readonly string $method) {}

    public static function patch(string $url): self
    {
        return new self($url, 'patch');
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     */
    public function fields(array $fields): self
    {
        $this->fields = $fields;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'method' => $this->method,
            'fields' => array_values($this->fields),
        ];
    }
}
