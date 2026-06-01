<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeocodingService
{
    private const TTL = 86400; // 24 hours

    /**
     * Geocode a place name to lat/lng using Nominatim.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function geocode(string $placeName): ?array
    {
        $cacheKey = 'geocode:' . md5($placeName);

        return Cache::remember($cacheKey, self::TTL, function () use ($placeName) {
            return $this->fetchCoordinates($placeName);
        });
    }

    private function fetchCoordinates(string $placeName): ?array
    {
        try {
            $response = Http::timeout(5)
                ->withHeaders(['User-Agent' => 'TripAI/1.0 (contact@tripai.app)'])
                ->get('https://nominatim.openstreetmap.org/search', [
                    'q'      => $placeName,
                    'format' => 'json',
                    'limit'  => 1,
                ]);

            if (! $response->successful()) {
                return null;
            }

            $results = $response->json();
            if (empty($results)) {
                return null;
            }

            return [
                'lat' => (float) $results[0]['lat'],
                'lng' => (float) $results[0]['lon'],
            ];

        } catch (\Throwable $e) {
            Log::warning('GeocodingService::fetchCoordinates failed', [
                'place' => $placeName,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
