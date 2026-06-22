<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\CheckinController;
use App\Http\Controllers\Api\CommunityController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PlaceController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\TripController;
use App\Http\Controllers\Api\TripMemberController;
use Illuminate\Support\Facades\Route;

// ─────────────────────────────────────────────
// Health check
// ─────────────────────────────────────────────
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'app'    => config('app.name'),
        'env'    => config('app.env'),
    ]);
});

// ─────────────────────────────────────────────
// Auth (public)
// ─────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login',    [AuthController::class, 'login']);
});

// Public trip share (no auth required)
Route::get('/trips/share/{token}', [TripController::class, 'showPublic']);

// Community feed (public — không cần auth, nhưng nếu có auth thì biết user đã clone chưa)
Route::get('/community', [CommunityController::class, 'index']);

// ─────────────────────────────────────────────
// Protected routes (JWT required) — standard rate limit: 60/min
// ─────────────────────────────────────────────
Route::middleware(['jwt.auth', 'throttle:api'])->group(function () {

    // Auth
    Route::prefix('auth')->group(function () {
        Route::post('/logout',  [AuthController::class, 'logout']);
        Route::get('/me',       [AuthController::class, 'me']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
    });

    // Profile
    Route::prefix('profile')->group(function () {
        Route::get('/',            [ProfileController::class, 'show']);
        Route::put('/',            [ProfileController::class, 'update']);
        Route::post('/',           [ProfileController::class, 'update']); // POST alias for multipart/form-data uploads
        Route::put('/preferences', [ProfileController::class, 'updatePreferences']);
    });

    // Trips — standard CRUD
    Route::prefix('trips')->group(function () {
        Route::get('/suggestions',        [TripController::class, 'suggestions']);
        Route::get('/shared-with-me',     [TripMemberController::class, 'sharedWithMe']);
        Route::get('/pending-invites',    [TripMemberController::class, 'pendingInvites']);
        Route::post('/members/accept/{token}', [TripMemberController::class, 'accept']);
        Route::get('/',                   [TripController::class, 'index']);
        Route::get('/{id}',               [TripController::class, 'show']);
        Route::delete('/{id}',            [TripController::class, 'destroy']);
        Route::post('/{id}/duplicate',    [TripController::class, 'duplicate']);
        Route::get('/{id}/status',        [TripController::class, 'status']);
        Route::put('/{id}/budget/actual', [BudgetController::class, 'updateActual']);
        Route::post('/{id}/favorites',    [TripController::class, 'toggleFavorite']);
        Route::post('/{id}/share',        [TripController::class, 'share']);
        Route::put('/{id}/notes',         [TripController::class, 'updateNotes']);
        Route::get('/{id}/packing-list',  [TripController::class, 'packingList']);
        Route::get('/{id}/nearby',        [TripController::class, 'nearby']);
        Route::get('/{id}/cost-split',    [TripController::class, 'costSplit']);

        // Manual activity CRUD
        Route::post('/{tripId}/days/{dayId}/places',              [PlaceController::class, 'storeActivity']);
        Route::put('/{tripId}/days/{dayId}/places/{placeId}',     [PlaceController::class, 'updateActivity']);
        Route::delete('/{tripId}/days/{dayId}/places/{placeId}',  [PlaceController::class, 'destroyActivity']);

        // Check-in
        Route::post('/{tripId}/places/{placeId}/checkin',         [CheckinController::class, 'checkin']);
        Route::delete('/{tripId}/places/{placeId}/checkin',       [CheckinController::class, 'undo']);
        Route::get('/{tripId}/checkins',                          [CheckinController::class, 'index']);

        // Expenses
        Route::get('/{tripId}/expenses',                          [ExpenseController::class, 'index']);
        Route::post('/{tripId}/expenses',                         [ExpenseController::class, 'store']);
        Route::put('/{tripId}/expenses/{expenseId}',              [ExpenseController::class, 'update']);
        Route::delete('/{tripId}/expenses/{expenseId}',           [ExpenseController::class, 'destroy']);

        // Publish / Unpublish
        Route::post('/{id}/publish',                              [CommunityController::class, 'publish']);

        // Collaborative members
        Route::get('/{tripId}/members',                           [TripMemberController::class, 'index']);
        Route::post('/{tripId}/members/invite',                   [TripMemberController::class, 'invite']);
        Route::delete('/{tripId}/members/{memberId}',             [TripMemberController::class, 'remove']);
        Route::put('/{tripId}/members/{memberId}/role',           [TripMemberController::class, 'updateRole']);
    });

    // Community
    Route::prefix('community')->group(function () {
        Route::get('/{id}',       [CommunityController::class, 'show']);
        Route::post('/{id}/clone',[CommunityController::class, 'clone']);
    });

    // Favorites
    Route::get('/favorites', [TripController::class, 'favorites']);

    // Saved Places
    Route::prefix('places')->group(function () {
        Route::post('/save',         [PlaceController::class, 'save']);
        Route::get('/saved',         [PlaceController::class, 'index']);
        Route::delete('/saved/{id}', [PlaceController::class, 'destroy']);
    });

    // Notifications
    Route::prefix('notifications')->group(function () {
        Route::get('/',             [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::put('/read-all',     [NotificationController::class, 'markAllRead']);
        Route::put('/{id}/read',    [NotificationController::class, 'markRead']);
    });

    // Activity Logs
    Route::get('/activity-logs', [ActivityLogController::class, 'index']);

    // Cron trigger
    Route::post('/cron/run-schedule', function () {
        \Illuminate\Support\Facades\Artisan::call('schedule:run');
        return response()->json(['message' => 'Schedule triggered.']);
    });
});

// ─────────────────────────────────────────────
// AI endpoints — stricter rate limit: 10/min
// ─────────────────────────────────────────────
Route::middleware(['jwt.auth', 'throttle:ai'])->group(function () {
    Route::post('/trips',           [TripController::class, 'store']);
    Route::post('/trips/{id}/chat', [ChatController::class, 'chat']);
});
