<?php

namespace App\Http\Controllers;

use App\Enum\ServerStatusEnum;
use App\Enum\StreamStatusEnum;
use App\Events\UserWaitingForProvisioningEvent;
use App\Models\Client;
use App\Models\Server;
use App\Models\ServerUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class StreamController extends Controller
{
    public function online()
    {
        $user = Auth::user();
        $data = $user->getUserStreamUrls();
        $urls = $data['urls'];
        $client_id = $data['client_id'];

        return Inertia::render('Dashboard', [
            'initialProvisioning' => is_null($data['urls']),
            'initialClientId' => $client_id,
            'initialStreamUrls' => $urls,
            'initialStatus' => \Cache::get('stream.status', static fn() => StreamStatusEnum::OFFLINE->value),
            'initialListeners' => \Cache::get('stream.listeners', static fn() => 0),
        ]);
    }

    public function external()
    {
        $urls = Auth::user()->getUserStreamUrls()['urls'];
        return Inertia::render('ExternalStream', [
            'status' => \Cache::get('stream.status', static fn() => StreamStatusEnum::OFFLINE->value),
            'streamUrls' => $urls,
        ]);
    }


}
