<?php

namespace App\Http\Requests\Manage;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for creating and updating a manually managed server.
 *
 * `hetzner_id` and `type` are immutable after creation, exactly as the Filament form
 * disabled them on edit. They are dropped from the payload rather than rejected, so a
 * stale form cannot fail a save it was never allowed to change.
 *
 * Credentials are not a field here at all: only their hashes are stored, they are minted
 * by the model, and the plaintext is shown once on the install script page.
 */
class ServerRequest extends FormRequest
{
    /**
     * Authorization runs through ServerPolicy in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'hostname' => ['required', 'string', 'max:255'],
            'ip' => ['nullable', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'status' => ['required', Rule::enum(ServerStatusEnum::class)],
            'max_clients' => ['nullable', 'integer', 'min:0', 'max:99999'],
        ];

        if ($this->isCreating()) {
            $rules['hetzner_id'] = ['nullable', 'string', 'max:255'];
            $rules['type'] = ['required', Rule::enum(ServerTypeEnum::class)];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'hetzner_id' => 'Hetzner ID',
            'ip' => 'IP address',
            'max_clients' => 'max clients',
        ];
    }

    /**
     * The validated payload, with `max_clients` stripped for an origin server so a hidden
     * control cannot write a value the form never showed.
     *
     * @return array<string, mixed>
     */
    public function serverData(?ServerTypeEnum $type = null): array
    {
        $data = $this->validated();

        $type ??= ServerTypeEnum::from($data['type']);

        if ($type !== ServerTypeEnum::EDGE) {
            unset($data['max_clients']);
        }

        return $data;
    }

    private function isCreating(): bool
    {
        return $this->route('server') === null;
    }
}
