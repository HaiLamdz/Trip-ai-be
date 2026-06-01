<?php

namespace App\Providers;

use App\Services\AI\AIServiceInterface;
use App\Services\AI\GeminiService;
use App\Services\AI\OpenAIService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind AI service based on AI_PROVIDER config
        $this->app->bind(AIServiceInterface::class, function ($app) {
            return match (config('services.ai.provider', 'gemini')) {
                'openai' => new OpenAIService(),
                default  => new GeminiService(),
            };
        });
    }

    public function boot(): void
    {
        // Standard API: 60 req/min per user (or IP for guests)
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip())
                ->response(function () {
                    return response()->json(
                        ['message' => 'Quá nhiều yêu cầu. Vui lòng thử lại sau.', 'retry_after' => 60],
                        429,
                        ['Retry-After' => 60]
                    );
                });
        });

        // AI endpoints: 10 req/min per user
        RateLimiter::for('ai', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->user()?->id ?: $request->ip())
                ->response(function () {
                    return response()->json(
                        ['message' => 'Quá nhiều yêu cầu đến AI. Vui lòng thử lại sau.', 'retry_after' => 60],
                        429,
                        ['Retry-After' => 60]
                    );
                });
        });
    }
}
