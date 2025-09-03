<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Recording extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'date',
        'duration',
        'm3u8_url',
        'thumbnail_url',
        'views',
        'is_published',
    ];

    protected $casts = [
        'date' => 'datetime',
        'duration' => 'integer',
        'views' => 'integer',
        'is_published' => 'boolean',
    ];
}