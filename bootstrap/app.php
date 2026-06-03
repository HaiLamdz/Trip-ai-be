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
        // ─── Telegram bug reporting ──────────────────────────────────────
        $exceptions->report(function (\Throwable $e) {
            // Bỏ qua các lỗi HTTP bình thường (4xx) và một số exception không cần alert
            $ignore = [
                \Illuminate\Auth\AuthenticationException::class,
                \Illuminate\Auth\Access\AuthorizationException::class,
                \Illuminate\Validation\ValidationException::class,
                \Illuminate\Database\Eloquent\ModelNotFoundException::class,
                \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
                \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
                \Tymon\JWTAuth\Exceptions\TokenExpiredException::class,
                \Tymon\JWTAuth\Exceptions\TokenInvalidException::class,
                \Tymon\JWTAuth\Exceptions\JWTException::class,
            ];

            foreach ($ignore as $class) {
                if ($e instanceof $class) {
                    return false; // false = vẫn log vào file, chỉ skip custom report
                }
            }

            try {
                app(\App\Services\TelegramService::class)->sendException($e);
            } catch (\Throwable) {
                // Không làm gì để tránh vòng lặp lỗi
            }
        });

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
