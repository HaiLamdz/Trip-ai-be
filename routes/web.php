<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\File;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok'
    ]);
});
Route::get('/debug-storage', function () {
    $publicPath = storage_path('app/public');

    return [
        'public_exists' => is_dir($publicPath),
        'public_contents' => is_dir($publicPath)
            ? array_slice(scandir($publicPath), 0, 50)
            : [],
        'checkins_exists' => is_dir($publicPath . '/checkins'),
        'checkins_contents' => is_dir($publicPath . '/checkins')
            ? array_slice(scandir($publicPath . '/checkins'), 0, 50)
            : [],
    ];
});