<?php

namespace App\Providers;

use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\AiSummaries\WeeklySummaryService;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // S5-03 follow-up: WeeklySummaryService gets its own optional
        // credential profile (config('ai.summary')) so the weekly job
        // doesn't have to share Sprint 3's Groq quota with
        // AiDraftPlanService — two features hitting one free-tier rate
        // limit made a draft's fallback-to-rule-based depend on whether
        // the weekly job happened to run recently. Every other consumer
        // of OpenAiCompatibleClient (AiDraftPlanService included) is
        // untouched by this and keeps reading config('ai.*') directly, as
        // it always has — this binding is scoped to one class.
        $this->app->when(WeeklySummaryService::class)
            ->needs(OpenAiCompatibleClient::class)
            ->give(fn () => new OpenAiCompatibleClient(config('ai.summary')));
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
