<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Button extends Model
{
    protected $fillable = [
        'label',
        'text',
        'url',
        'expires_seconds',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'expires_seconds' => 'integer',
    ];

    /** Botones activos del catálogo, ordenados. */
    public function scopeActiveOrdered($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }
}
