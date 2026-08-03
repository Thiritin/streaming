<?php

namespace App\Http\Requests\Manage;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SourceRequest extends FormRequest
{
    /**
     * Authorization runs through SourcePolicy in the controller.
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
        $source = $this->route('source');

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            // Higher first on the public grid; the ceiling matches the Filament form.
            'priority' => ['required', 'integer', 'min:0', 'max:999'],
            'description' => ['nullable', 'string'],
        ];

        /*
         * The slug is the RTMP ingress path and the HLS route key. It is set once, on
         * create, and never accepted again: changing it disconnects the encoder and
         * breaks playback. `Source::updating()` reverts it as a second line of defence.
         *
         * `status` is not here at all. It is changed only through the Update Status
         * action, so there is exactly one way to do it.
         */
        if ($source === null) {
            $rules['slug'] = [
                'required',
                'string',
                'max:255',
                Rule::unique('sources', 'slug'),
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'slug' => 'stream name',
        ];
    }
}
