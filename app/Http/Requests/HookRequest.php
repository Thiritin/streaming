<?php

namespace App\Http\Requests;

use App\Models\Server;
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

    public function authorize(): bool
    {
        return true;
        if (app()->environment('local')) {
            return true;
        }
        return config('services.stream.origin_ip') ?? Server::where('ip', $this->ip())->exists();
    }
}
