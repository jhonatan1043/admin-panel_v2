<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Project extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_UNPUBLISHED = 'unpublished';

    protected $fillable = [
        'operator_id',
        'name',
        'slug',
        'description',
        'status',
        'primary_view_id',
        'published_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Slug único a partir del nombre si no se indica uno.
        static::creating(function (Project $project) {
            if (blank($project->slug)) {
                $project->slug = static::uniqueSlug($project->name);
            }
        });
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'proyecto';
        $slug = $base;
        $i = 2;
        while (static::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /** Operador dueño/asignado del proyecto (null = sin asignar). */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    /** ¿Este usuario puede gestionar el proyecto? Admin siempre; operador si es el dueño. */
    public function managedBy(?User $user): bool
    {
        return $user !== null && ($user->role === 'admin' || $this->operator_id === $user->id);
    }

    public function views(): HasMany
    {
        return $this->hasMany(ProjectView::class);
    }

    /** Vistas de formulario (excluye la pantalla de carga). */
    public function formViews(): HasMany
    {
        return $this->hasMany(ProjectView::class)->where('role', ProjectView::ROLE_STANDARD);
    }

    /** Vista principal (index) elegida, si existe. */
    public function primaryView(): BelongsTo
    {
        return $this->belongsTo(ProjectView::class, 'primary_view_id');
    }

    /**
     * Vista que se sirve al entrar al proyecto por la raíz: la principal elegida
     * (si sigue siendo una vista de formulario válida) o, como respaldo, la primera.
     */
    public function indexView(): ?ProjectView
    {
        $views = $this->relationLoaded('views') ? $this->views : $this->views()->get();
        $forms = $views->where('role', ProjectView::ROLE_STANDARD);

        if ($this->primary_view_id) {
            $primary = $forms->firstWhere('id', $this->primary_view_id);
            if ($primary) {
                return $primary;
            }
        }

        return $forms->sortBy('id')->first() ?? $views->sortBy('id')->first();
    }

    /** Pantalla de espera del proyecto (una por proyecto), si existe. */
    public function loadingView(): ?ProjectView
    {
        return $this->views()->where('role', ProjectView::ROLE_LOADING)->first();
    }

    public function assets(): HasMany
    {
        return $this->hasMany(ProjectAsset::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(ProjectDomain::class);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
