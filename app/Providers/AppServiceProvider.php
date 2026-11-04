<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Límites de peticiones de la API pública (por IP).
     *
     *  - clients-read:  GET /api/clients                    → 60 por minuto.
     *  - clients-write: POST /api/clients (crea registros)  → 10 por minuto
     *                   y 100 por hora, para frenar altas masivas.
     */
    private function configureRateLimiting(): void
    {
        $tooMany = fn (Request $request, array $headers) => response()->json([
            'ok' => false,
            'message' => 'Demasiadas solicitudes. Intenta de nuevo en unos segundos.',
            'retry_after' => (int) ($headers['Retry-After'] ?? 60),
        ], 429, $headers);

        RateLimiter::for('clients-read', fn (Request $request) => Limit::perMinute(60)
            ->by($request->ip())
            ->response($tooMany));

        RateLimiter::for('clients-write', fn (Request $request) => [
            Limit::perMinute(10)->by('min:'.$request->ip())->response($tooMany),
            Limit::perHour(100)->by('hour:'.$request->ip())->response($tooMany),
        ]);
    }
}
