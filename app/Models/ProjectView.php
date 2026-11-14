<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ProjectView extends Model
{
    public const ROLE_STANDARD = 'standard';

    public const ROLE_LOADING = 'loading';

    protected $fillable = [
        'project_id',
        'name',
        'role',
        'slug',
        'source_path',
        'sort_order',
        'sends_data',
        'html',
        'css',
        'js',
        'integration_js',
        'analysis',
        'spec',
    ];

    protected $casts = [
        'analysis' => 'array',
        'spec' => 'array',
        'sends_data' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (ProjectView $view) {
            if (blank($view->slug)) {
                $view->slug = static::uniqueSlug($view->project_id, $view->name);
            }
        });
    }

    public static function uniqueSlug(int $projectId, string $name): string
    {
        $base = Str::slug($name) ?: 'vista';
        $slug = $base;
        $i = 2;
        while (static::where('project_id', $projectId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isLoading(): bool
    {
        return $this->role === self::ROLE_LOADING;
    }

    /** ¿La vista tiene integración generada y lista para publicar? */
    public function isIntegrated(): bool
    {
        // La vista de carga no recopila datos: basta su script de espera.
        if ($this->isLoading()) {
            return filled($this->integration_js);
        }

        return filled($this->integration_js) && filled($this->spec);
    }

    /** Campos detectados (resumen del análisis). */
    public function summary(): array
    {
        return $this->analysis['summary'] ?? ['forms' => 0, 'fields' => 0, 'buttons' => 0];
    }
}
