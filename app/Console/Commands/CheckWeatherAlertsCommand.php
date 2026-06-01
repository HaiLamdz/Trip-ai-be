<?php

namespace App\Console\Commands;

use App\Models\Trip;
use App\Services\NotificationService;
use App\Services\WeatherService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CheckWeatherAlertsCommand extends Command
{
    protected $signature   = 'tripai:check-weather-alerts';
    protected $description = 'Kiểm tra thời tiết cho trips trong 3 ngày tới và tạo notification nếu xấu';

    public function __construct(
        private readonly WeatherService $weatherService,
        private readonly NotificationService $notificationService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $trips = Trip::where('status', 'completed')
                ->whereBetween('start_date', [now()->toDateString(), now()->addDays(3)->toDateString()])
                ->whereNotNull('destination_lat')
                ->whereNotNull('destination_lng')
                ->get();

            foreach ($trips as $trip) {
                try {
                    $forecasts = $this->weatherService->getForecast(
                        (float) $trip->destination_lat,
                        (float) $trip->destination_lng
                    );

                    foreach ($forecasts as $index => $forecast) {
                        if ($forecast->rainProbability >= 0.5) {
                            $date = now()->addDays($index)->toDateString();
                            $this->notificationService->weatherAlert(
                                $trip->user_id,
                                $trip->id,
                                $trip->destination,
                                $date
                            );
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning("CheckWeatherAlertsCommand: failed for trip {$trip->id}: " . $e->getMessage());
                }
            }

            $this->info("Đã kiểm tra thời tiết cho {$trips->count()} trips.");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('CheckWeatherAlertsCommand failed: ' . $e->getMessage());
            $this->notifyAdmin('CheckWeatherAlertsCommand thất bại: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function notifyAdmin(string $message): void
    {
        $adminEmail = env('ADMIN_EMAIL');
        if ($adminEmail) {
            try {
                Mail::raw($message, fn ($m) => $m->to($adminEmail)->subject('[TripAI] Scheduled Job Failed'));
            } catch (\Throwable) {}
        }
    }
}
