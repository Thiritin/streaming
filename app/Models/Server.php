<?php

namespace App\Models;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Jobs\Server\DeleteServerJob;
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
        'type' => ServerTypeEnum::class,
        'port' => 'integer',
    ];
    
    protected $attributes = [
        'port' => 8080,
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }
    public function clients()
    {
        return $this->hasMany(Client::class);
    }

    public function deprovision()
    {
        DeleteServerJob::dispatch($this);
    }

    public function isReady(): bool
    {
        // For manual/local servers, assume they're ready if active
        if ($this->hetzner_id === 'manual' && $this->status === ServerStatusEnum::ACTIVE) {
            return true;
        }
        
        $proto = "https";
        $hostname = $this->hostname;

        if($this->type === ServerTypeEnum::ORIGIN) {
            $proto = "http";
            $hostname = $this->ip.":1985";
        }
        
        // For local Docker containers, use http
        if ($this->hetzner_id === 'manual') {
            $proto = "http";
            // Use the hostname directly for Docker containers
            if ($this->type === ServerTypeEnum::EDGE) {
                $hostname = $this->hostname . ":8080";
            }
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
        return $this->clients()->connected()->count() > 0;
    }
    
    /**
     * Get the hostname with port for URL generation.
     * Omits port for standard HTTP (80) and HTTPS (443) ports.
     */
    public function getHostWithPort(): string
    {
        // Don't append port for standard HTTP/HTTPS ports
        if ($this->port === 80 || $this->port === 443) {
            return $this->hostname;
        }
        
        return $this->hostname . ':' . $this->port;
    }
}
