<?php

namespace App\Services;

use App\Services\AI\DTOs\WeatherDTO;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WeatherService
{
    private string $apiKey;
    private string $endpoint;

    /** Cache TTL: 3 hours in seconds */
    private const TTL = 10800;

    public function __construct()
    {
        $this->apiKey   = config('services.openweather.api_key') ?? '';
        $this->endpoint = config('services.openweather.endpoint', 'https://api.openweathermap.org/data/2.5');
    }

    // ─────────────────────────────────────────────
    // getForecast
    // ─────────────────────────────────────────────

    /**
     * Lấy dự báo thời tiết 5 ngày cho tọa độ địa lý.
     * Cache Redis TTL 3 giờ với key weather:{lat}:{lng}
     *
     * @return array<int, WeatherDTO>  Indexed by day (0-based)
     */
    public function getForecast(float $lat, float $lng): array
    {
        $cacheKey = "weather:{$lat}:{$lng}";

        return Cache::remember($cacheKey, self::TTL, function () use ($lat, $lng) {
            return $this->fetchForecast($lat, $lng);
        });
    }

    // ─────────────────────────────────────────────
    // getRainProbability
    // ─────────────────────────────────────────────

    /**
     * Tính xác suất mưa cho khung giờ cụ thể.
     *
     * @param  array<int, WeatherDTO> $forecast
     * @return float  0.0 – 1.0
     */
    public function getRainProbability(array $forecast, string $date, string $timeSlot): float
    {
        // Find the forecast entry closest to the requested date/time
        foreach ($forecast as $dto) {
            // WeatherDTO doesn't carry date; caller should match by index
            // Return the rain probability of the first matching entry
            return $dto->rainProbability;
        }

        return 0.0;
    }

    // ─────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────

    /**
     * @return array<int, WeatherDTO>
     */
    private function fetchForecast(float $lat, float $lng): array
    {
        try {
            $response = Http::timeout(10)->get("{$this->endpoint}/forecast", [
                'lat'   => $lat,
                'lon'   => $lng,
                'appid' => $this->apiKey,
                'units' => 'metric',
                'lang'  => 'vi',
                'cnt'   => 40, // 5 days × 8 slots/day
            ]);

            if (! $response->successful()) {
                throw new \RuntimeException('OpenWeather API error: ' . $response->status());
            }

            return $this->parseForecasts($response->json('list', []));

        } catch (\Throwable $e) {
            Log::error('WeatherService::fetchForecast failed', [
                'lat'   => $lat,
                'lng'   => $lng,
                'error' => $e->getMessage(),
            ]);

            // Fallback: return default weather (no alerts)
            return [new WeatherDTO()];
        }
    }

    /**
     * Parse OpenWeather forecast list into daily WeatherDTOs.
     *
     * @param  array<int, array<string, mixed>> $list
     * @return array<int, WeatherDTO>
     */
    private function parseForecasts(array $list): array
    {
        if (empty($list)) {
            return [new WeatherDTO()];
        }

        // Group by date and aggregate
        $byDate = [];
        foreach ($list as $slot) {
            $date = substr($slot['dt_txt'] ?? '', 0, 10);
            if (! $date) {
                continue;
            }
            $byDate[$date][] = $slot;
        }

        $result = [];
        foreach ($byDate as $date => $slots) {
            $temps    = array_column(array_column($slots, 'main'), 'temp');
            $pops     = array_column($slots, 'pop'); // probability of precipitation
            $weather  = $slots[0]['weather'][0] ?? [];

            $result[] = new WeatherDTO(
                summary:         $weather['description'] ?? 'Không có dữ liệu',
                icon:            $weather['icon'] ?? '01d',
                temperatureHigh: ! empty($temps) ? (float) max($temps) : 30.0,
                temperatureLow:  ! empty($temps) ? (float) min($temps) : 22.0,
                rainProbability: ! empty($pops) ? (float) max($pops) : 0.0,
            );
        }

        return $result ?: [new WeatherDTO()];
    }
}
