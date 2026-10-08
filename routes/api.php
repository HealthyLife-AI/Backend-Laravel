<?php

use App\Http\Controllers\Api\Account\AccountController;
use App\Http\Controllers\Api\Admin\AdminFoodController;
use App\Http\Controllers\Api\Admin\AdminNutritionistController;
use App\Http\Controllers\Api\Admin\AdminOverviewController;
use App\Http\Controllers\Api\AiSummaries\AiSummaryController;
use App\Http\Controllers\Api\Alerts\AlertController;
use App\Http\Controllers\Api\Appointments\MyAppointmentController;
use App\Http\Controllers\Api\Appointments\NutritionistAppointmentController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Clients\ClientController;
use App\Http\Controllers\Api\Clients\ClientFollowUpController;
use App\Http\Controllers\Api\Clients\ClientInviteController;
use App\Http\Controllers\Api\Clients\ClientPasswordResetController;
use App\Http\Controllers\Api\Clients\DashboardController;
use App\Http\Controllers\Api\Consent\ConsentController;
use App\Http\Controllers\Api\FollowUp\ClientReviewController;
use App\Http\Controllers\Api\FollowUp\MyReviewController;
use App\Http\Controllers\Api\Foods\FoodController;
use App\Http\Controllers\Api\HealthProfiles\BodyCompositionReadingController;
use App\Http\Controllers\Api\HealthProfiles\HealthProfileController;
use App\Http\Controllers\Api\HealthRecords\ClientHealthRecordsController;
use App\Http\Controllers\Api\HealthRecords\MyHealthRecordsController;
use App\Http\Controllers\Api\Logs\ClientMealLogController;
use App\Http\Controllers\Api\Logs\MealLogController;
use App\Http\Controllers\Api\Logs\MeasurementController;
use App\Http\Controllers\Api\MealPlans\ClientPlanController;
use App\Http\Controllers\Api\MealPlans\MealPlanController;
use App\Http\Controllers\Api\MealPlans\MealPlanTemplateController;
use App\Http\Controllers\Api\Notices\DeletionNoticeController;
use App\Http\Controllers\Api\Notifications\FcmTokenController;
use App\Http\Controllers\Api\Notifications\MyNotificationController;
use App\Http\Controllers\Api\Notifications\NotificationPreferenceController;
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

    // Change one's own password; ends every other session. Throttled like login.
    Route::put('me/password', [AuthController::class, 'changePassword'])
        ->middleware(['jwt', 'throttle:10,1'])
        ->name('me.password.update');

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

        // Part A: a new generated password (and the username, if missing or changed),
        // shown once for the nutritionist to send on WhatsApp. Ends every session.
        Route::post('clients/{subscriber}/reset-password', [ClientPasswordResetController::class, 'store'])
            ->middleware(['follow-up', 'throttle:credentials-reset'])
            ->name('clients.reset-password.store');

        // Phase 2: the patient's meal log day by day, with each log's kind.
        Route::get('clients/{subscriber}/meal-logs/daily', [ClientMealLogController::class, 'daily'])->name('clients.meal-logs.daily');

        // Phase 2: follow-up between sessions (rating, note, key points, tasks).
        Route::get('clients/{subscriber}/reviews', [ClientReviewController::class, 'index'])->name('clients.reviews.index');
        Route::post('clients/{subscriber}/reviews', [ClientReviewController::class, 'store'])->middleware('follow-up')->name('clients.reviews.store');
        Route::put('clients/{subscriber}/reviews/{review}', [ClientReviewController::class, 'update'])->whereNumber('review')->middleware('follow-up')->name('clients.reviews.update');
        Route::delete('clients/{subscriber}/reviews/{review}', [ClientReviewController::class, 'destroy'])->whereNumber('review')->middleware('follow-up')->name('clients.reviews.destroy');

        Route::get('dashboard/overview', [DashboardController::class, 'overview'])->name('dashboard.overview');

        // Step 2: appointments and the nutritionist's weekly availability.
        Route::get('me/availability', [NutritionistAppointmentController::class, 'availability'])->name('me.availability.show');
        Route::put('me/availability', [NutritionistAppointmentController::class, 'updateAvailability'])->name('me.availability.update');
        Route::get('appointments', [NutritionistAppointmentController::class, 'index'])->name('appointments.index');
        Route::patch('appointments/{appointment}', [NutritionistAppointmentController::class, 'update'])->whereNumber('appointment')->name('appointments.update');
        Route::post('appointments/{appointment}/cancel', [NutritionistAppointmentController::class, 'cancel'])->whereNumber('appointment')->name('appointments.cancel');
        Route::post('appointments/{appointment}/complete', [NutritionistAppointmentController::class, 'complete'])->whereNumber('appointment')->name('appointments.complete');
        Route::post('appointments/{appointment}/no-show', [NutritionistAppointmentController::class, 'noShow'])->whereNumber('appointment')->name('appointments.no-show');
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

        // Step 1: goal, medications, allergies, and deciding the patient's proposals.
        Route::get('clients/{subscriber}/health-records', [ClientHealthRecordsController::class, 'show'])->name('clients.health-records.show');
        Route::get('clients/{subscriber}/proposals', [ClientHealthRecordsController::class, 'proposals'])->name('clients.proposals.index');
        Route::middleware('follow-up')->group(function () {
            Route::put('clients/{subscriber}/goal', [ClientHealthRecordsController::class, 'updateGoal'])->name('clients.goal.update');
            Route::post('clients/{subscriber}/medications', [ClientHealthRecordsController::class, 'storeMedication'])->name('clients.medications.store');
            Route::put('clients/{subscriber}/medications/{medication}', [ClientHealthRecordsController::class, 'updateMedication'])->whereNumber('medication')->name('clients.medications.update');
            Route::delete('clients/{subscriber}/medications/{medication}', [ClientHealthRecordsController::class, 'destroyMedication'])->whereNumber('medication')->name('clients.medications.destroy');
            Route::post('clients/{subscriber}/medications/{medication}/review', [ClientHealthRecordsController::class, 'reviewMedication'])->whereNumber('medication')->name('clients.medications.review');
            Route::post('clients/{subscriber}/allergies', [ClientHealthRecordsController::class, 'storeAllergy'])->name('clients.allergies.store');
            Route::put('clients/{subscriber}/allergies/{allergy}', [ClientHealthRecordsController::class, 'updateAllergy'])->whereNumber('allergy')->name('clients.allergies.update');
            Route::delete('clients/{subscriber}/allergies/{allergy}', [ClientHealthRecordsController::class, 'destroyAllergy'])->whereNumber('allergy')->name('clients.allergies.destroy');
            Route::post('clients/{subscriber}/proposals/{proposal}/approve', [ClientHealthRecordsController::class, 'approve'])->whereNumber('proposal')->name('clients.proposals.approve');
            Route::post('clients/{subscriber}/proposals/{proposal}/reject', [ClientHealthRecordsController::class, 'reject'])->whereNumber('proposal')->name('clients.proposals.reject');
        });
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

    // Phase 2, the patient's side. Same gates as the other patient data
    // endpoints: follow_up_ended (jwt), then consent_required.
    Route::middleware(['jwt', 'consent', 'role:client'])->group(function () {
        Route::get('me/health-profile', [MyHealthRecordsController::class, 'show'])->name('me.health-profile.show');
        Route::post('me/proposals', [MyHealthRecordsController::class, 'propose'])->name('me.proposals.store');
        Route::delete('me/proposals/{proposal}', [MyHealthRecordsController::class, 'withdraw'])->whereNumber('proposal')->name('me.proposals.destroy');

        Route::get('me/appointments/slots', [MyAppointmentController::class, 'slots'])->name('me.appointments.slots');
        Route::get('me/appointments', [MyAppointmentController::class, 'index'])->name('me.appointments.index');
        Route::post('me/appointments', [MyAppointmentController::class, 'store'])->name('me.appointments.store');
        Route::patch('me/appointments/{appointment}', [MyAppointmentController::class, 'update'])->whereNumber('appointment')->name('me.appointments.update');
        Route::delete('me/appointments/{appointment}', [MyAppointmentController::class, 'destroy'])->whereNumber('appointment')->name('me.appointments.destroy');

        Route::get('me/reviews', [MyReviewController::class, 'index'])->name('me.reviews.index');
        Route::post('me/reviews/{review}/acknowledge', [MyReviewController::class, 'acknowledge'])->whereNumber('review')->name('me.reviews.acknowledge');
        Route::post('me/tasks/{task}/done', [MyReviewController::class, 'done'])->whereNumber('task')->name('me.tasks.done');
        Route::delete('me/tasks/{task}/done', [MyReviewController::class, 'undone'])->whereNumber('task')->name('me.tasks.undone');

        Route::get('me/notifications', [MyNotificationController::class, 'index'])->name('me.notifications.index');
        Route::get('me/notifications/unread-count', [MyNotificationController::class, 'unreadCount'])->name('me.notifications.unread-count');
        Route::post('me/notifications/read-all', [MyNotificationController::class, 'readAll'])->name('me.notifications.read-all');
        Route::post('me/notifications/{id}/read', [MyNotificationController::class, 'read'])->whereNumber('id')->name('me.notifications.read');
        Route::get('me/notification-preferences', [NotificationPreferenceController::class, 'show'])->name('me.notification-preferences.show');
        Route::put('me/notification-preferences', [NotificationPreferenceController::class, 'update'])->name('me.notification-preferences.update');
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
