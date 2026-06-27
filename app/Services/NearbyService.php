<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tìm địa điểm gần đó dùng Google Places API.
 */
class NearbyService
{
    // Bán kính tìm kiếm mặc định: 500m
    private const RADIUS_METERS = 500;

    // Map place_type → Google Places types
    private const TYPE_MAP = [
        'food'       => 'restaurant',
        'cafe'       => 'cafe',
        'attraction' => 'tourist_attraction',
        'hotel'      => 'lodging',
        'shopping'   => 'shopping_mall',
        'nightlife'  => 'bar',
    ];

    /**
     * Tìm tối đa 5 địa điểm gần (lat, lng) theo loại.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findNearby(float $lat, float $lng, string $type): array
    {
        $googleType = self::TYPE_MAP[$type] ?? self::TYPE_MAP['food'];
        $radius = self::RADIUS_METERS;
        $apiKey = env('GOOGLE_MAPS_API_KEY');

        if (!$apiKey) {
            Log::warning('NearbyService: GOOGLE_MAPS_API_KEY not configured');
            return [];
        }

        try {
            $response = Http::timeout(12)
                ->get('https://maps.googleapis.com/maps/api/place/nearbysearch/json', [
                    'location' => "{$lat},{$lng}",
                    'radius' => $radius,
                    'type' => $googleType,
                    'key' => $apiKey,
                    'language' => 'vi',
                ]);

            if (! $response->successful()) {
                Log::warning('NearbyService: Google Places API error', ['status' => $response->status()]);
                return [];
            }

            $data = $response->json();
            $results = $data['results'] ?? [];

            return collect($results)
                ->take(5)
                ->map(function (array $place) use ($type): array {
                    $location = $place['geometry']['location'] ?? null;

                    return [
                        'osm_id' => $place['place_id'] ?? null,
                        'name' => $place['name'] ?? 'Không rõ tên',
                        'place_type' => $type,
                        'latitude' => $location['lat'] ?? null,
                        'longitude' => $location['lng'] ?? null,
                        'address' => $place['vicinity'] ?? null,
                        'opening_hours' => $place['opening_hours']['weekday_text'][0] ?? null,
                        'phone' => $place['formatted_phone_number'] ?? null,
                        'website' => $place['website'] ?? null,
                        'cuisine' => null, // Google Places doesn't provide cuisine directly
                    ];
                })
                ->filter(fn ($p) => $p['latitude'] && $p['longitude'])
                ->values()
                ->toArray();

        } catch (\Throwable $e) {
            Log::error('NearbyService: exception', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
