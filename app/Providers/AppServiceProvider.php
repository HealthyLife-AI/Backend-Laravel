<?php

namespace App\Providers;

use Illuminate\Http\Resources\Json\JsonResource;
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
        // API is REST/JSON only (no Blade/Inertia consumer of resources
        // that wants the "data" envelope). Without this, a resource
        // returned directly from a route (e.g. AuthController::me) comes
        // back as {"data": {...}}, while the same resource embedded in a
        // larger array (e.g. AuthController::login's ['user' => ...])
        // comes back flat — an inconsistency that broke the frontend's
        // /me handling. Every resource response is flat now.
        JsonResource::withoutWrapping();
    }
}
