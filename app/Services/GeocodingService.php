<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeocodingService
{
    private const TTL = 86400; // 24 hours

    /**
     * Geocode a place name to lat/lng using Google Geocoding API.
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
        $apiKey = env('GOOGLE_MAPS_API_KEY');

        if (!$apiKey) {
            Log::warning('GeocodingService: GOOGLE_MAPS_API_KEY not configured');
            return null;
        }

        try {
            $response = Http::timeout(5)
                ->get('https://maps.googleapis.com/maps/api/geocode/json', [
                    'address' => $placeName,
                    'key' => $apiKey,
                    'language' => 'vi',
                ]);

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();
            $results = $data['results'] ?? [];

            if (empty($results)) {
                return null;
            }

            $location = $results[0]['geometry']['location'] ?? null;

            if (!$location) {
                return null;
            }

            return [
                'lat' => (float) $location['lat'],
                'lng' => (float) $location['lng'],
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
