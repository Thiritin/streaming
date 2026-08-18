<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerMetric extends Model
{
    protected $guarded = [];

    protected $casts = [
        'recorded_at' => 'datetime',
        'cpu_percent' => 'float',
        'load_1' => 'float',
        'cpu_cores' => 'integer',
        'memory_used_bytes' => 'integer',
        'memory_total_bytes' => 'integer',
        'disk_used_bytes' => 'integer',
        'disk_total_bytes' => 'integer',
        'net_rx_bytes_per_sec' => 'integer',
        'net_tx_bytes_per_sec' => 'integer',
        'uptime_seconds' => 'integer',
        'viewer_count' => 'integer',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
