<?php

namespace App\Models;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Jobs\Server\Deprovision\ServerMoveClientsToOtherServerJob;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use LKDev\HetznerCloud\Models\Servers\Types\ServerType;

class Server extends Model
{
    protected $guarded = [];

    protected $casts = [
        'status' => ServerStatusEnum::class,
        'type' => ServerTypeEnum::class
    ];

    public function user()
    {
        return $this->belongsToMany(User::class)->withPivot(['start','stop','streamkey'])->using(ServerUser::class);
    }

    public function deprovision()
    {
        $this->status = ServerStatusEnum::DEPROVISIONING;
        $this->save();
        ServerMoveClientsToOtherServerJob::dispatch($this);
    }

    public function isReady(): bool
    {
        $proto = "https";
        $hostname = $this->hostname;

        if($this->type === ServerTypeEnum::ORIGIN) {
            $proto = "http";
            $hostname = $this->ip.":1985";
        }
        // catch client as server exceptions
        try {
            $request = Http::timeout(5)->get($proto."://".$hostname.'/ready');
        } catch (ClientException|ServerException|ConnectionException $e) {
            return false;
        }

        return $request->successful() && $request->json('code') === 0;
    }

    public function isInUse(): bool
    {
        return $this->user()->whereNull('stop')->count() > 0;
    }
}
