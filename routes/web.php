<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok'
    ]);
});
Route::get('/debug-storage', function () {
    return [
        'storage_exists' => file_exists(public_path('storage')),
        'storage_link' => is_link(public_path('storage')),
        'checkins_exists' => file_exists(
            storage_path('app/public/checkins')
        ),
    ];
});