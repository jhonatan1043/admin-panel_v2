<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class Button extends Model
{
    /**
     * Clave de caché del catálogo completo de botones.
     * (v2: ahora guarda arreglos; la v1 guardaba modelos y no se podía leer.)
     */
    public const CACHE_KEY = 'buttons.catalog.v2';

    protected $fillable = [
        'label',
        'text',
        'url',
        'project_view_id',
        'expires_seconds',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'expires_seconds' => 'integer',
    ];

    protected static function booted(): void
    {
        // Cualquier alta, cambio o baja invalida el catálogo cacheado.
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    /**
     * Catálogo completo ordenado, cacheado hasta que se modifique un botón.
     * Lo usan el tablero (en cada refresco), la API y la alarma.
     */
    public static function catalog(): Collection
    {
        // En caché solo se guardan arreglos (filas crudas), nunca objetos: la config
        // cache.serializable_classes = false impide deserializar clases PHP.
        $rows = Cache::rememberForever(
            self::CACHE_KEY,
            fn () => static::query()->orderBy('sort_order')->orderBy('id')->toBase()->get()
                ->map(fn ($row) => (array) $row)->all()
        );

        if (! is_array($rows)) { // entrada corrupta o de un formato viejo: reconstruir
            Cache::forget(self::CACHE_KEY);

            return static::catalog();
        }

        // hydrate() reconstruye los modelos con sus casts (is_active, expires_seconds).
        return static::hydrate($rows);
    }

    /** Botones activos del catálogo (desde caché), ordenados. */
    public static function active(): Collection
    {
        return static::catalog()->where('is_active', true)->values();
    }

    /** Mapa etiqueta => segundos de vencimiento (solo los que vencen), desde caché. */
    public static function expiryByLabel(): \Illuminate\Support\Collection
    {
        return static::catalog()
            ->whereNotNull('expires_seconds')
            ->pluck('expires_seconds', 'label');
    }

    /** Botones activos del catálogo, ordenados. */
    public function scopeActiveOrdered($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }

    /** Vista de proyecto que abre este botón (opcional). */
    public function projectView(): BelongsTo
    {
        return $this->belongsTo(ProjectView::class);
    }
}
