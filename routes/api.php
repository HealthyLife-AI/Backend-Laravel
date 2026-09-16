<?php

use App\Http\Controllers\Api\AiSummaries\AiSummaryController;
use App\Http\Controllers\Api\Alerts\AlertController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Clients\ClientController;
use App\Http\Controllers\Api\Clients\ClientInviteController;
use App\Http\Controllers\Api\Clients\DashboardController;
use App\Http\Controllers\Api\Foods\FoodController;
use App\Http\Controllers\Api\HealthProfiles\BodyCompositionReadingController;
use App\Http\Controllers\Api\HealthProfiles\HealthProfileController;
use App\Http\Controllers\Api\Logs\MealLogController;
use App\Http\Controllers\Api\Logs\MeasurementController;
use App\Http\Controllers\Api\MealPlans\ClientPlanController;
use App\Http\Controllers\Api\MealPlans\MealPlanController;
use App\Http\Controllers\Api\MealPlans\MealPlanTemplateController;
use App\Http\Controllers\Api\Notifications\FcmTokenController;
use App\Http\Controllers\Api\Nutritionists\NutritionistProfileController;
use App\Http\Controllers\Api\Progress\AdherenceController;
use App\Http\Controllers\Api\Progress\ProgressController;
use App\Http\Controllers\Api\System\AiStatusController;
use App\Http\Controllers\Api\System\FcmStatusController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.')->group(function () {

    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('register', [AuthController::class, 'register'])
            ->middleware('throttle:10,1')
            ->name('register');

        Route::post('login', [AuthController::class, 'login'])
            ->middleware('throttle:10,1')
            ->name('login');

        Route::post('refresh', [AuthController::class, 'refresh'])
            ->middleware('throttle:20,1')
            ->name('refresh');

        Route::post('logout', [AuthController::class, 'logout'])->name('logout');

        Route::get('me', [AuthController::class, 'me'])
            ->middleware('jwt')
            ->name('me');
    });

    // FR-03: public — the invite token itself is the credential.
    Route::post('invites/{token}/activate', [ClientInviteController::class, 'activate'])
        ->middleware('throttle:10,1')
        ->name('invites.activate');

    Route::middleware(['jwt', 'permission:clients.manage'])->group(function () {
        Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
        Route::post('clients', [ClientController::class, 'store'])->name('clients.store');
        Route::get('clients/{subscriber}', [ClientController::class, 'show'])->name('clients.show');

        Route::get('dashboard/overview', [DashboardController::class, 'overview'])->name('dashboard.overview');
    });

    Route::middleware(['jwt', 'permission:health_profile.manage'])->group(function () {
        Route::get('clients/{subscriber}/health-profile', [HealthProfileController::class, 'show'])
            ->name('clients.health-profile.show');
        Route::put('clients/{subscriber}/health-profile', [HealthProfileController::class, 'update'])
            ->name('clients.health-profile.update');

        Route::get('clients/{subscriber}/body-composition-readings', [BodyCompositionReadingController::class, 'index'])
            ->name('clients.body-composition-readings.index');
        Route::post('clients/{subscriber}/body-composition-readings', [BodyCompositionReadingController::class, 'store'])
            ->name('clients.body-composition-readings.store');
    });

    // FR-25: read-only reference data — no permission gate beyond being
    // authenticated (see FoodController docblock).
    Route::get('foods/search', [FoodController::class, 'search'])
        ->middleware('jwt')
        ->name('foods.search');

    // Operational read-only check: whether this environment has an AI
    // provider configured at all (see AiStatusController). Authenticated
    // but ungated — it reports no client data and no credential.
    Route::get('system/ai-status', AiStatusController::class)
        ->middleware('jwt')
        ->name('system.ai-status');

    // S5-06 follow-up: the FCM twin of the check above — see
    // FcmStatusController for why this exists (fails closed by design,
    // so "not configured" and "configured but no pushes due yet" look
    // identical from outside without this).
    Route::get('system/fcm-status', FcmStatusController::class)
        ->middleware('jwt')
        ->name('system.fcm-status');

    Route::middleware(['jwt', 'permission:plans.manage'])->group(function () {
        Route::get('clients/{subscriber}/meal-plans', [MealPlanController::class, 'index'])->name('meal-plans.index');
        Route::post('clients/{subscriber}/meal-plans', [MealPlanController::class, 'store'])->name('meal-plans.store');
        Route::get('clients/{subscriber}/meal-plans/{mealPlan}', [MealPlanController::class, 'show'])->name('meal-plans.show');
        Route::put('clients/{subscriber}/meal-plans/{mealPlan}', [MealPlanController::class, 'update'])->name('meal-plans.update');
        Route::post('clients/{subscriber}/meal-plans/{mealPlan}/activate', [MealPlanController::class, 'activate'])->name('meal-plans.activate');
        // Stricter than the blanket 120/min api limit: this is a real,
        // paid/quota-limited external LLM call (Groq), not a DB read —
        // hammering it (accidental double-click loop, or a script) burns
        // shared quota the AiDraftPlanService fallback depends on staying
        // available. 5/min is generous for its actual use (review, maybe
        // regenerate once or twice) and blocks anything faster than that.
        Route::post('clients/{subscriber}/meal-plans/ai-draft', [MealPlanController::class, 'generateAiDraft'])
            ->middleware('throttle:ai-draft')
            ->name('meal-plans.ai-draft');

        Route::get('meal-plan-templates', [MealPlanTemplateController::class, 'index'])->name('meal-plan-templates.index');
        Route::post('clients/{subscriber}/meal-plans/{mealPlan}/save-as-template', [MealPlanTemplateController::class, 'store'])->name('meal-plan-templates.store');
        Route::post('meal-plan-templates/{mealPlan}/apply/{subscriber}', [MealPlanTemplateController::class, 'apply'])->name('meal-plan-templates.apply');
    });

    // FR-16 / plans.view.own: the CLIENT role's own current plan — no
    // route-bound subscriber id (see ClientPlanController docblock).
    Route::middleware(['jwt', 'permission:plans.view.own'])->group(function () {
        Route::get('me/meal-plan', [ClientPlanController::class, 'show'])->name('me.meal-plan.show');
    });

    // S4-01 / FR-17, BR-9 / logs.manage.own: the CLIENT logging what they
    // actually ate. Same no-route-bound-id shape as me/meal-plan above.
    Route::middleware(['jwt', 'permission:logs.manage.own'])->group(function () {
        Route::get('me/meal-logs', [MealLogController::class, 'index'])->name('me.meal-logs.index');
        Route::post('me/meal-logs', [MealLogController::class, 'store'])->name('me.meal-logs.store');

        // S4-02/S4-16 / FR-17, FR-29, BR-11: the client's own weight and
        // circumferences. Named `measurements`, not `weight-logs` — it
        // stopped being weight-only when S4-16 added the four tape-measure
        // fields. Writes to body_composition_readings (SRS Section 2.4).
        Route::post('me/measurements', [MeasurementController::class, 'store'])->name('me.measurements.store');
    });

    // S5-06 / FR-22: the client's own device push token. Gated on the
    // role directly, not logs.manage.own — registering a device has
    // nothing to do with logging, and the PRD permission matrix has no
    // entry for it, the same reasoning nutritionist-profile's role:
    // gate used (see routes above).
    Route::middleware(['jwt', 'role:client'])->group(function () {
        Route::put('me/fcm-token', [FcmTokenController::class, 'store'])->name('me.fcm-token.store');
    });

    // S4-00 / PRD Section 5.2: the nutritionist's own professional
    // profile. Gated on the role rather than a permission — the PRD
    // permission matrix has no entry for "edit my own profile", and
    // inventing one would put a permission in the seeder that no
    // document describes.
    Route::middleware(['jwt', 'role:nutritionist'])->group(function () {
        Route::get('me/nutritionist-profile', [NutritionistProfileController::class, 'show'])->name('me.nutritionist-profile.show');
        Route::put('me/nutritionist-profile', [NutritionistProfileController::class, 'update'])->name('me.nutritionist-profile.update');
    });

    // S4-03/S4-04 / FR-18, FR-19 / progress.view: plan-vs-actual and the
    // progress charts. Held by nutritionist AND client roles, so each
    // controller re-checks belongsToCaller() on the bound subscriber.
    // S5-02 / FR-20, alerts.view (nutritionist-only): list own-roster
    // alerts and mark one read. Isolation via whereHas('subscriber') —
    // see AlertController's docblock.
    Route::middleware(['jwt', 'permission:alerts.view'])->group(function () {
        Route::get('alerts', [AlertController::class, 'index'])->name('alerts.index');
        Route::patch('alerts/{alert}/read', [AlertController::class, 'markRead'])->name('alerts.mark-read');
    });

    Route::middleware(['jwt', 'permission:progress.view'])->group(function () {
        Route::get('clients/{subscriber}/adherence', [AdherenceController::class, 'show'])->name('clients.adherence.show');
        Route::get('clients/{subscriber}/progress', [ProgressController::class, 'show'])->name('clients.progress.show');
    });

    // S5-04 / FR-21, ai_summary.view (nutritionist-only): weekly
    // natural-language summaries for one client.
    Route::middleware(['jwt', 'permission:ai_summary.view'])->group(function () {
        Route::get('clients/{subscriber}/ai-summaries', [AiSummaryController::class, 'index'])->name('clients.ai-summaries.index');
    });

    Route::middleware(['jwt', 'permission:foods.suggest'])->group(function () {
        Route::post('foods', [FoodController::class, 'store'])->name('foods.store');
    });

    Route::middleware(['jwt', 'permission:foods.approve'])->group(function () {
        Route::get('foods/pending', [FoodController::class, 'pending'])->name('foods.pending');
        Route::post('foods/{food}/approve', [FoodController::class, 'approve'])->name('foods.approve');
        Route::post('foods/{food}/reject', [FoodController::class, 'reject'])->name('foods.reject');
    });

});
