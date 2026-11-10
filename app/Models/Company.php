<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Company extends Model
{
    /** Umbrales (en minutos) para el cambio de estado por tiempo sin gestión. */
    public const PENDING_AFTER = 10;   // amarillo

    public const OFFLINE_AFTER = 30;   // gris

    public const DELETABLE_AFTER = 60; // rojo (borrable)

    /**
     * Versión de la lista de clientes de la API (GET /api/clients).
     * Cada página se cachea bajo la versión actual; al cambiar un cliente se
     * sube la versión y todas las páginas viejas dejan de usarse (expiran solas).
     */
    public const CACHE_KEY_API_VERSION = 'api.clients.version';

    protected static function booted(): void
    {
        // Cualquier cambio de un cliente (alta, edición, actividad, borrado) invalida la lista.
        static::saved(fn () => self::bumpApiListVersion());
        static::deleted(fn () => self::bumpApiListVersion());
    }

    public static function apiListVersion(): string
    {
        return (string) Cache::get(self::CACHE_KEY_API_VERSION, '0');
    }

    public static function bumpApiListVersion(): void
    {
        Cache::forever(self::CACHE_KEY_API_VERSION, (string) hrtime(true));
    }

    protected $fillable = [
        'operator_id',
        'registro',
        'name',
        'empresa',
        'fecha',
        'telegram_bot_token',
        'telegram_chat_id',
        'messenger_id',
        'loading_image_path',
        'status',
        'last_activity_at',
        'last_seen_at',
        'next_view_id',
        'next_view_at',
        'data',
    ];

    /** Segundos desde el último heartbeat para considerar al cliente "en línea". */
    public const ONLINE_WITHIN_SECONDS = 60;

    protected $hidden = [
        'telegram_bot_token',
        'loading_token',
    ];

    protected $casts = [
        'last_activity_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'fecha' => 'datetime',
        'next_view_at' => 'datetime',
        'data' => 'array',
    ];

    /** ¿El navegador del cliente avisó presencia hace poco? (tiene la página abierta). */
    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subSeconds(self::ONLINE_WITHIN_SECONDS));
    }

    /** Siguiente vista de proyecto que el operador pidió para este cliente. */
    public function nextView(): BelongsTo
    {
        return $this->belongsTo(ProjectView::class, 'next_view_id');
    }

    public function messageTemplates(): HasMany
    {
        return $this->hasMany(MessageTemplate::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function logs(): HasMany
    {
        // Desempate por id: mensajes del mismo segundo conservan su orden real.
        return $this->hasMany(ClientLog::class)->latest()->orderByDesc('id');
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
