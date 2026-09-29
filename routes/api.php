<?php

use App\Http\Controllers\Api\Account\AccountController;
use App\Http\Controllers\Api\Admin\AdminFoodController;
use App\Http\Controllers\Api\Admin\AdminNutritionistController;
use App\Http\Controllers\Api\Admin\AdminOverviewController;
use App\Http\Controllers\Api\AiSummaries\AiSummaryController;
use App\Http\Controllers\Api\Alerts\AlertController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Clients\ClientController;
use App\Http\Controllers\Api\Clients\ClientFollowUpController;
use App\Http\Controllers\Api\Clients\ClientInviteController;
use App\Http\Controllers\Api\Clients\DashboardController;
use App\Http\Controllers\Api\Consent\ConsentController;
use App\Http\Controllers\Api\Foods\FoodController;
use App\Http\Controllers\Api\HealthProfiles\BodyCompositionReadingController;
use App\Http\Controllers\Api\HealthProfiles\HealthProfileController;
use App\Http\Controllers\Api\Logs\MealLogController;
use App\Http\Controllers\Api\Logs\MeasurementController;
use App\Http\Controllers\Api\MealPlans\ClientPlanController;
use App\Http\Controllers\Api\MealPlans\MealPlanController;
use App\Http\Controllers\Api\MealPlans\MealPlanTemplateController;
use App\Http\Controllers\Api\Notices\DeletionNoticeController;
use App\Http\Controllers\Api\Notifications\FcmTokenController;
use App\Http\Controllers\Api\Nutritionists\MyNutritionistController;
use App\Http\Controllers\Api\Nutritionists\NutritionistProfileController;
use App\Http\Controllers\Api\Progress\AdherenceController;
use App\Http\Controllers\Api\Progress\OwnProgressController;
use App\Http\Controllers\Api\Progress\ProgressController;
use App\Http\Controllers\Api\System\AiStatusController;
use App\Http\Controllers\Api\System\FcmStatusController;
use App\Http\Controllers\Api\System\SchedulerStatusController;
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

        // "Continue with Google": the browser hands over a Google access
        // token, the API verifies it with Google (GoogleAuthService).
        Route::post('google', [AuthController::class, 'google'])
            ->middleware('throttle:10,1')
            ->name('google');

        // Forgot / reset password. Forgot always answers 200 so the
        // endpoint can't be used to probe which e-mails have accounts.
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])
            ->middleware('throttle:5,1')
            ->name('forgot-password');
        Route::post('reset-password', [AuthController::class, 'resetPassword'])
            ->middleware('throttle:10,1')
            ->name('reset-password');

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
        Route::delete('clients/{subscriber}', [ClientController::class, 'destroy'])->name('clients.destroy');
        Route::post('clients/{subscriber}/archive', [ClientFollowUpController::class, 'archive'])->name('clients.archive');
        Route::post('clients/{subscriber}/resume', [ClientFollowUpController::class, 'resume'])->name('clients.resume');

        Route::get('dashboard/overview', [DashboardController::class, 'overview'])->name('dashboard.overview');
    });

    Route::middleware(['jwt', 'permission:health_profile.manage'])->group(function () {
        Route::get('clients/{subscriber}/health-profile', [HealthProfileController::class, 'show'])
            ->name('clients.health-profile.show');
        Route::put('clients/{subscriber}/health-profile', [HealthProfileController::class, 'update'])
            ->middleware('follow-up')
            ->name('clients.health-profile.update');

        Route::get('clients/{subscriber}/body-composition-readings', [BodyCompositionReadingController::class, 'index'])
            ->name('clients.body-composition-readings.index');
        Route::post('clients/{subscriber}/body-composition-readings', [BodyCompositionReadingController::class, 'store'])
            ->middleware('follow-up')
            ->name('clients.body-composition-readings.store');
    });

    // FR-25: read-only reference data — no permission gate beyond being
    // authenticated (see FoodController docblock).
    Route::get('foods/search', [FoodController::class, 'search'])
        ->middleware(['jwt', 'consent'])
        ->name('foods.search');

    // Operational diagnostics — admin only. They report which credentials
    // and jobs are configured on this deployment (never the secrets, never
    // client data), which is the operator's business: a nutritionist or a
    // patient has no use for them, and "is a provider configured, from which
    // host, is cron running" is not something to hand every account.
    Route::middleware(['jwt', 'role:admin'])->group(function () {
        // Whether this environment has an AI provider configured at all
        // (see AiStatusController).
        Route::get('system/ai-status', AiStatusController::class)->name('system.ai-status');

        // When each scheduled job last ran, and what the 06:00 run did — the
        // way to confirm on Taqat that cron (or the self-trigger) actually
        // runs them.
        Route::get('system/scheduler-status', SchedulerStatusController::class)->name('system.scheduler-status');

        // S5-06 follow-up: the FCM twin of ai-status — see FcmStatusController
        // for why this exists (fails closed by design, so "not configured"
        // and "configured but no pushes due yet" look identical from outside
        // without this).
        Route::get('system/fcm-status', FcmStatusController::class)->name('system.fcm-status');
    });

    Route::middleware(['jwt', 'permission:plans.manage'])->group(function () {
        Route::get('clients/{subscriber}/meal-plans', [MealPlanController::class, 'index'])->name('meal-plans.index');
        Route::post('clients/{subscriber}/meal-plans', [MealPlanController::class, 'store'])->middleware('follow-up')->name('meal-plans.store');
        Route::get('clients/{subscriber}/meal-plans/{mealPlan}', [MealPlanController::class, 'show'])->name('meal-plans.show');
        Route::put('clients/{subscriber}/meal-plans/{mealPlan}', [MealPlanController::class, 'update'])->middleware('follow-up')->name('meal-plans.update');
        Route::post('clients/{subscriber}/meal-plans/{mealPlan}/activate', [MealPlanController::class, 'activate'])->middleware('follow-up')->name('meal-plans.activate');
        // Stricter than the blanket 120/min api limit: this is a real,
        // paid/quota-limited external LLM call (Groq), not a DB read —
        // hammering it (accidental double-click loop, or a script) burns
        // shared quota the AiDraftPlanService fallback depends on staying
        // available. 5/min is generous for its actual use (review, maybe
        // regenerate once or twice) and blocks anything faster than that.
        Route::post('clients/{subscriber}/meal-plans/ai-draft', [MealPlanController::class, 'generateAiDraft'])
            ->middleware(['follow-up', 'throttle:ai-draft'])
            ->name('meal-plans.ai-draft');

        Route::get('meal-plan-templates', [MealPlanTemplateController::class, 'index'])->name('meal-plan-templates.index');
        Route::post('clients/{subscriber}/meal-plans/{mealPlan}/save-as-template', [MealPlanTemplateController::class, 'store'])->name('meal-plan-templates.store');
        Route::post('meal-plan-templates/{mealPlan}/apply/{subscriber}', [MealPlanTemplateController::class, 'apply'])->middleware('follow-up')->name('meal-plan-templates.apply');
    });

    // FR-16 / plans.view.own: the CLIENT role's own current plan — no
    // route-bound subscriber id (see ClientPlanController docblock).
    Route::middleware(['jwt', 'consent', 'permission:plans.view.own'])->group(function () {
        Route::get('me/meal-plan', [ClientPlanController::class, 'show'])->name('me.meal-plan.show');
    });

    // S4-01 / FR-17, BR-9 / logs.manage.own: the CLIENT logging what they
    // actually ate. Same no-route-bound-id shape as me/meal-plan above.
    Route::middleware(['jwt', 'consent', 'permission:logs.manage.own'])->group(function () {
        Route::get('me/meal-logs', [MealLogController::class, 'index'])->name('me.meal-logs.index');
        Route::post('me/meal-logs', [MealLogController::class, 'store'])->name('me.meal-logs.store');
        // BR-15: edit or delete one's own log within the edit window.
        Route::patch('me/meal-logs/{id}', [MealLogController::class, 'update'])->whereNumber('id')->name('me.meal-logs.update');
        Route::delete('me/meal-logs/{id}', [MealLogController::class, 'destroy'])->whereNumber('id')->name('me.meal-logs.destroy');

        // S4-02/S4-16 / FR-17, FR-29, BR-11: the client's own weight and
        // circumferences. Named `measurements`, not `weight-logs` — it
        // stopped being weight-only when S4-16 added the four tape-measure
        // fields. Writes to body_composition_readings (SRS Section 2.4).
        Route::post('me/measurements', [MeasurementController::class, 'store'])->name('me.measurements.store');
        Route::get('me/measurements', [MeasurementController::class, 'index'])->name('me.measurements.index');
        Route::delete('me/measurements/{id}', [MeasurementController::class, 'destroy'])->whereNumber('id')->name('me.measurements.destroy');
    });

    // S5-06 / FR-22: the client's own device push token. Gated on the
    // role directly, not logs.manage.own — registering a device has
    // nothing to do with logging, and the PRD permission matrix has no
    // entry for it, the same reasoning nutritionist-profile's role:
    // gate used (see routes above).
    Route::middleware(['jwt', 'role:client'])->group(function () {
        Route::put('me/fcm-token', [FcmTokenController::class, 'store'])->name('me.fcm-token.store');

        // BR-17: the consent screen. Exempt from the `consent` gate — it is
        // how a patient gets through it.
        Route::get('me/consent', [ConsentController::class, 'show'])->name('me.consent.show');
        Route::post('me/consent', [ConsentController::class, 'store'])->name('me.consent.store');

        // BR-18: delete my own account (password required, rate-limited,
        // not behind the consent gate).
        Route::delete('me/account', [AccountController::class, 'destroy'])
            ->middleware('throttle:account-deletion')
            ->name('me.account.destroy');

        // The patient's own nutritionist: name, gender, clinic, specialty,
        // WhatsApp number — for the app's "my nutritionist" card.
        Route::get('me/nutritionist', [MyNutritionistController::class, 'show'])->name('me.nutritionist.show');
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
        // BR-18: notices that a patient deleted their own account.
        Route::get('notices', [DeletionNoticeController::class, 'index'])->name('notices.index');
        Route::delete('notices/{id}', [DeletionNoticeController::class, 'destroy'])->whereNumber('id')->name('notices.destroy');

        Route::get('alerts', [AlertController::class, 'index'])->name('alerts.index');
        Route::patch('alerts/{alert}/read', [AlertController::class, 'markRead'])->name('alerts.mark-read');
    });

    // FR-18/FR-19: the same two views for the PATIENT'S OWN data. The
    // routes below can't serve a patient (see OwnProgressController).
    Route::middleware(['jwt', 'consent', 'role:client', 'permission:progress.view'])->group(function () {
        Route::get('me/adherence', [OwnProgressController::class, 'adherence'])->name('me.adherence.show');
        Route::get('me/progress', [OwnProgressController::class, 'progress'])->name('me.progress.show');
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
        Route::get('foods/mine', [FoodController::class, 'mine'])->name('foods.mine');
    });

    Route::middleware(['jwt', 'permission:foods.approve'])->group(function () {
        Route::get('foods/pending', [FoodController::class, 'pending'])->name('foods.pending');
        Route::post('foods/{food}/approve', [FoodController::class, 'approve'])->name('foods.approve');
        Route::post('foods/{food}/reject', [FoodController::class, 'reject'])->name('foods.reject');
    });

    // Admin panel: platform overview, the full food catalog, nutritionist accounts.
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::middleware(['jwt', 'permission:foods.manage'])->group(function () {
            Route::get('foods', [AdminFoodController::class, 'index'])->name('foods.index');
            Route::post('foods', [AdminFoodController::class, 'store'])->name('foods.store');
            Route::put('foods/{food}', [AdminFoodController::class, 'update'])->name('foods.update');
            Route::delete('foods/{food}', [AdminFoodController::class, 'destroy'])->name('foods.destroy');
        });

        Route::middleware(['jwt', 'permission:users.manage'])->group(function () {
            Route::get('overview', AdminOverviewController::class)->name('overview');
            Route::get('nutritionists', [AdminNutritionistController::class, 'index'])->name('nutritionists.index');
        });
    });

});
