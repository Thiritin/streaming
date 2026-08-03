<?php

namespace App\Support\Manage;

use Illuminate\Contracts\Support\Arrayable;

/**
 * A button the client renders without knowing anything about the domain.
 *
 * Visibility is decided server-side (build the action or do not), which is what makes
 * "this action is offered only when the show is scheduled" assertable in a feature test.
 * A disabled action is still sent, carrying the reason, so the UI can explain itself
 * the way Filament's tooltips did.
 *
 * @implements Arrayable<string, mixed>
 */
final class Action implements Arrayable
{
    private ?string $icon = null;

    private string $tone = Status::INFO;

    /** @var array{heading: string, description: ?string, submit: string}|null */
    private ?array $confirm = null;

    /** @var array<int, array<string, mixed>> */
    private array $fields = [];

    private ?string $disabledReason = null;

    private bool $newTab = false;

    private function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $url,
        public readonly string $method,
    ) {}

    public static function link(string $name, string $label, string $url): self
    {
        return new self($name, $label, $url, 'get');
    }

    public static function post(string $name, string $label, string $url): self
    {
        return new self($name, $label, $url, 'post');
    }

    public static function put(string $name, string $label, string $url): self
    {
        return new self($name, $label, $url, 'put');
    }

    public static function delete(string $name, string $label, string $url): self
    {
        return new self($name, $label, $url, 'delete');
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function tone(string $tone): self
    {
        $this->tone = $tone;

        return $this;
    }

    public function confirm(string $heading, ?string $description = null, string $submit = 'Confirm'): self
    {
        $this->confirm = [
            'heading' => $heading,
            'description' => $description,
            'submit' => $submit,
        ];

        return $this;
    }

    /**
     * Fields to collect in a modal before submitting, e.g. the status select on
     * "Update Status" or the type select on "Provision Cloud Server".
     *
     * Shape per field: ['key', 'label', 'type', 'options'?, 'default'?, 'required'?, 'helper'?]
     *
     * @param  array<int, array<string, mixed>>  $fields
     */
    public function fields(array $fields): self
    {
        $this->fields = $fields;

        return $this;
    }

    public function disabled(?string $reason): self
    {
        $this->disabledReason = $reason;

        return $this;
    }

    public function newTab(bool $newTab = true): self
    {
        $this->newTab = $newTab;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'url' => $this->url,
            'method' => $this->method,
            'icon' => $this->icon,
            'tone' => $this->tone,
            'confirm' => $this->confirm,
            'fields' => $this->fields === [] ? null : $this->fields,
            'disabledReason' => $this->disabledReason,
            'newTab' => $this->newTab,
        ];
    }
}
