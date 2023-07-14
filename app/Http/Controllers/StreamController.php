<?php

namespace App\Http\Controllers;

use App\Enum\ServerStatusEnum;
use App\Enum\StreamStatusEnum;
use App\Events\UserWaitingForProvisioningEvent;
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
        $urls = Auth::user()->getUserStreamUrls();

        if (is_null($urls)) {
            if (!Auth::user()->is_provisioning)
                event(new UserWaitingForProvisioningEvent(Auth::user()));

            return Inertia::render('Dashboard', [
                'initialStatus' => StreamStatusEnum::PROVISIONING->value,
                'initialListeners' => \Cache::get('stream.listeners', static fn() => 0),
            ]);
        }

        return Inertia::render('Dashboard', [
            'initialOtherDevice' => Auth::user()->isStreamRunning(),
            'initialStreamUrls' => $urls,
            'initialStatus' => \Cache::get('stream.status', static fn() => StreamStatusEnum::OFFLINE->value),
            'initialListeners' => \Cache::get('stream.listeners', static fn() => 0),
        ]);
    }

    public function external()
    {
        $url = Auth::user()->getUserStreamUrls();
        return Inertia::render('ExternalStream', [
            'status' => \Cache::get('stream.status', static fn() => StreamStatusEnum::OFFLINE->value),
        ]);
    }


}
