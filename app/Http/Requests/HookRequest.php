<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HookRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'server_id' => 'required|string',
            'action' => 'required|string',
            'client_id' => 'nullable|string',
            'ip' => 'required|ip',
            'vhost' => 'required|string',
            'app' => 'required|string',
            'tcUrl' => 'nullable|string',
            'stream' => 'required|string',
            'param' => 'nullable|string',
            'pageUrl' => 'nullable|string',
        ];
    }

    // The route is behind CheckSharedSecretMiddleware, which resolves the server
    // and its credential before anything reaches here.
    public function authorize(): bool
    {
        return true;
    }
}
