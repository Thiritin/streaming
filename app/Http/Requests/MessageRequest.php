<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MessageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:1', 'max:'.config('chat.default.maxMessageLength', 500)],
            'source_id' => ['required', 'integer', 'exists:sources,id'],
            'reply_to_id' => ['nullable', 'integer', 'exists:messages,id'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
