<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Apply CORS globally
        $middleware->prepend(HandleCors::class);

        // Register JWT middleware alias for use in routes
        $middleware->alias([
            'jwt.auth'    => \Tymon\JWTAuth\Http\Middleware\Authenticate::class,
            'jwt.refresh' => \Tymon\JWTAuth\Http\Middleware\RefreshToken::class,
        ]);

        // Configure API rate limiting
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // JWT token invalid/expired → return 401 JSON instead of redirect
        $exceptions->render(function (\Tymon\JWTAuth\Exceptions\TokenExpiredException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Token không hợp lệ hoặc đã hết hạn'], 401);
            }
        });

        $exceptions->render(function (\Tymon\JWTAuth\Exceptions\TokenInvalidException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Token không hợp lệ hoặc đã hết hạn'], 401);
            }
        });

        $exceptions->render(function (\Tymon\JWTAuth\Exceptions\JWTException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Token không hợp lệ hoặc đã hết hạn'], 401);
            }
        });

        // Unauthenticated → 401 JSON for API routes
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Token không hợp lệ hoặc đã hết hạn'], 401);
            }
        });
    })->create();
