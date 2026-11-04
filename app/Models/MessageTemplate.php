<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageTemplate extends Model
{
    protected $fillable = [
        'company_id',
        'label',
        'body',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Las plantillas viajan dentro de GET /api/clients: invalidar esa lista.
        static::saved(fn () => Company::bumpApiListVersion());
        static::deleted(fn () => Company::bumpApiListVersion());
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
