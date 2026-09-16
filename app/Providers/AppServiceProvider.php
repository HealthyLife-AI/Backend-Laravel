<?php

namespace App\Providers;

use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\AiSummaries\WeeklySummaryService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
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

        // S6 security hardening: before this, only the 4 explicit
        // auth/invite routes had any throttle at all (routes/api.php) —
        // every other endpoint, including a real paid/quota-limited LLM
        // call (meal-plans/ai-draft), was reachable at unlimited rate by
        // any authenticated user. Keyed by user id (falls back to IP for
        // the few unauthenticated routes) so one abusive account can't
        // exhaust a limit shared with everyone else. 120/min is generous
        // for normal dashboard/mobile use — bootstrap/app.php's
        // `throttleApi()` call is what actually wires this into the
        // `api` middleware group; registering the limiter alone does
        // nothing.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // meal-plans/ai-draft (routes/api.php): a named limiter rather
        // than the bare `throttle:5,1` form, keyed explicitly and only by
        // user id — this route always runs behind `jwt` first, so
        // `$request->user()` is never null here, and a named limiter
        // avoids the bare numeric form's own key-resolution quirks
        // stacking unpredictably with the general 'api' limiter above.
        RateLimiter::for('ai-draft', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()->id);
        });
    }
}
