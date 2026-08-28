<?php

namespace App\Http\Requests\Manage;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Storage;
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
            // The key the upload endpoint returned. Pinned to the emote prefix and
            // checked against the bucket: this column is what a signed URL is minted
            // for and what the delete hook removes, so a free-text key would read and
            // destroy any object in the bucket.
            's3_key' => [
                'required',
                'string',
                'max:2048',
                'regex:/^emotes\\/[^\\/]+$/',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! Storage::disk('s3')->exists($value)) {
                        $fail('That image is no longer available. Upload it again.');
                    }
                },
            ],
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
            's3_key.regex' => 'Upload an image for the emote.',
        ];
    }
}
