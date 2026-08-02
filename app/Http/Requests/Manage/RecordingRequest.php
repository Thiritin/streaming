<?php

namespace App\Http\Requests\Manage;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordingRequest extends FormRequest
{
    /**
     * Authorization runs through RecordingPolicy in the controller.
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
        $recording = $this->route('recording');

        return [
            'show_id' => ['nullable', 'integer', 'exists:shows,id'],
            'title' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('recordings', 'slug')->ignore($recording?->id),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'date' => ['required', 'date'],
            // Left blank, ProcessRecordingJob reads it off the playlist.
            'duration' => ['nullable', 'integer', 'min:0'],
            'm3u8_url' => ['required', 'url', 'max:2048'],
            'thumbnail_path' => ['nullable', 'string', 'max:2048'],
            'is_published' => ['boolean'],
            'required_roles' => ['array'],
            'required_roles.*' => ['string', 'exists:roles,slug'],
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

    protected function prepareForValidation(): void
    {
        // An empty select posts "", which would fail the integer rule rather than
        // reading as "no show".
        if ($this->input('show_id') === '') {
            $this->merge(['show_id' => null]);
        }

        if ($this->input('duration') === '') {
            $this->merge(['duration' => null]);
        }
    }
}
