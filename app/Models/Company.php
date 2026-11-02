<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    /** Umbrales (en minutos) para el cambio de estado por tiempo sin gestión. */
    public const PENDING_AFTER = 10;   // amarillo
    public const OFFLINE_AFTER = 30;   // gris
    public const DELETABLE_AFTER = 60; // rojo (borrable)

    protected $fillable = [
        'operator_id',
        'registro',
        'name',
        'telegram_bot_token',
        'telegram_chat_id',
        'loading_image_path',
        'status',
        'last_activity_at',
        'data',
    ];

    protected $hidden = [
        'telegram_bot_token',
    ];

    protected $casts = [
        'last_activity_at' => 'datetime',
        'data' => 'array',
    ];

    public function messageTemplates(): HasMany
    {
        return $this->hasMany(MessageTemplate::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    /** Minutos transcurridos desde la última actividad. */
    public function minutesSinceActivity(): int
    {
        if (! $this->last_activity_at) {
            return PHP_INT_MAX;
        }

        return (int) $this->last_activity_at->diffInMinutes(now());
    }

    /** Estado calculado por tiempo: verde → amarillo → gris → rojo. */
    protected function currentStatus(): Attribute
    {
        return Attribute::get(function (): string {
            $minutes = $this->minutesSinceActivity();

            return match (true) {
                $minutes >= self::DELETABLE_AFTER => 'deletable',
                $minutes >= self::OFFLINE_AFTER => 'offline',
                $minutes >= self::PENDING_AFTER => 'pending',
                default => 'active',
            };
        });
    }

    /** Registra actividad/gestión y reinicia el ciclo a verde. */
    public function touchActivity(): void
    {
        $this->forceFill(['last_activity_at' => now()])->save();
    }
}
