<?php

namespace App\Http\Requests\Manage;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
{
    /**
     * Authorization runs through RolePolicy in the controller.
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
        $role = $this->route('role');

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('roles', 'slug')->ignore($role?->id),
            ],
            /*
             * The identifier the identity provider knows this role by. Unique,
             * because two roles claiming the same group would both be granted
             * and the sync would have no way to choose.
             */
            'external_id' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('roles', 'external_id')->ignore($role?->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            // Rendered as a chat badge, so it has to be a colour the browser accepts.
            'chat_color' => ['nullable', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'priority' => ['required', 'integer', 'min:0', 'max:999'],
            'is_visible' => ['boolean'],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug may only contain lowercase letters, numbers and dashes.',
            'external_id.unique' => 'Another role already syncs from that identifier.',
        ];
    }

    /**
     * An empty field posts "", and the unique index would let exactly one role
     * hold that. Every unsynced role has to be null instead.
     */
    protected function prepareForValidation(): void
    {
        if (trim((string) $this->input('external_id')) === '') {
            $this->merge(['external_id' => null]);
        }
    }
}
