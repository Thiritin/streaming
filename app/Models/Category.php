<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * What kind of thing a show is: a dance, a theatre piece, a musical performance.
 *
 * Deliberately not the source. A stage hosts a musical on Friday and a panel on
 * Saturday, so the category has to sit on what is programmed, not on where it
 * comes from. A show carries one; its recordings inherit it unless they say
 * otherwise.
 */
class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::saving(function (Category $category) {
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
        });
    }

    public function shows()
    {
        return $this->hasMany(Show::class);
    }

    public function recordings()
    {
        return $this->hasMany(Recording::class);
    }

    /**
     * Ordered the way an operator arranged them, then alphabetically for the ones
     * left at the default.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
