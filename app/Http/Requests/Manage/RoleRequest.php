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
            'description' => ['nullable', 'string', 'max:1000'],
            // Rendered as a chat badge, so it has to be a colour the browser accepts.
            'chat_color' => ['nullable', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'priority' => ['required', 'integer', 'min:0', 'max:999'],
            'is_visible' => ['boolean'],
            'assigned_at_login' => ['boolean'],
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
        ];
    }
}
