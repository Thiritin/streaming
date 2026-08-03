<?php

namespace App\Http\Requests\Manage;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmoteRequest extends FormRequest
{
    /**
     * Authorization runs through EmotePolicy in the controller.
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
        $emote = $this->route('emote');

        return [
            // Typed in chat as :name:, so the character set is deliberately narrow.
            'name' => [
                'required',
                'string',
                'max:20',
                'regex:/^[a-z0-9_]+$/',
                Rule::unique('emotes', 'name')->ignore($emote?->id),
            ],
            // The key the upload endpoint returned; the file itself is already on S3.
            's3_key' => ['required', 'string', 'max:2048'],
            'is_global' => ['boolean'],
            'is_approved' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'The name may only contain lowercase letters, numbers and underscores.',
            's3_key.required' => 'Upload an image for the emote.',
        ];
    }
}
