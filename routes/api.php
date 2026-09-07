<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Clients\ClientController;
use App\Http\Controllers\Api\Clients\ClientInviteController;
use App\Http\Controllers\Api\Clients\DashboardController;
use App\Http\Controllers\Api\Foods\FoodController;
use App\Http\Controllers\Api\HealthProfiles\BodyCompositionReadingController;
use App\Http\Controllers\Api\HealthProfiles\HealthProfileController;
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

});
