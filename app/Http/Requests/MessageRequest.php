<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MessageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:500', 'min:1'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
