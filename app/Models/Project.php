<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Project extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_UNPUBLISHED = 'unpublished';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'status',
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

    public function views(): HasMany
    {
        return $this->hasMany(ProjectView::class);
    }

    /** Vistas de formulario (excluye la pantalla de carga). */
    public function formViews(): HasMany
    {
        return $this->hasMany(ProjectView::class)->where('role', ProjectView::ROLE_STANDARD);
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
