<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tìm địa điểm gần đó dùng Overpass API (OpenStreetMap) — hoàn toàn miễn phí.
 */
class NearbyService
{
    // Bán kính tìm kiếm mặc định: 500m
    private const RADIUS_METERS = 500;

    // Map place_type → OSM amenity/tourism tags
    private const TYPE_MAP = [
        'food'       => '["amenity"~"restaurant|food_court|fast_food"]',
        'cafe'       => '["amenity"="cafe"]',
        'attraction' => '["tourism"~"attraction|museum|viewpoint|monument"]',
        'hotel'      => '["tourism"~"hotel|hostel|guest_house"]',
        'shopping'   => '["shop"~"mall|supermarket|clothes|market"]',
        'nightlife'  => '["amenity"~"bar|pub|nightclub"]',
    ];

    /**
     * Tìm tối đa 5 địa điểm gần (lat, lng) theo loại.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findNearby(float $lat, float $lng, string $type): array
    {
        $osmFilter = self::TYPE_MAP[$type] ?? self::TYPE_MAP['food'];
        $radius    = self::RADIUS_METERS;

        // Overpass QL query
        $query = <<<OQL
[out:json][timeout:10];
(
  node{$osmFilter}(around:{$radius},{$lat},{$lng});
  way{$osmFilter}(around:{$radius},{$lat},{$lng});
);
out center 5;
OQL;

        try {
            $response = Http::timeout(12)
                ->withHeaders(['User-Agent' => 'TripAI/1.0 (travel planner app)'])
                ->post('https://overpass-api.de/api/interpreter', [
                    'data' => $query,
                ]);

            if (! $response->successful()) {
                Log::warning('NearbyService: Overpass API error', ['status' => $response->status()]);
                return [];
            }

            $elements = $response->json('elements', []);

            return collect($elements)
                ->take(5)
                ->map(function (array $el) use ($type): array {
                    // Way elements có center, node elements có lat/lon trực tiếp
                    $elLat  = $el['lat']            ?? $el['center']['lat'] ?? null;
                    $elLng  = $el['lon']            ?? $el['center']['lon'] ?? null;
                    $tags   = $el['tags']           ?? [];
                    $name   = $tags['name']         ?? $tags['name:vi'] ?? $tags['name:en'] ?? 'Không rõ tên';
                    $addr   = $tags['addr:street']  ?? $tags['addr:full'] ?? null;

                    return [
                        'osm_id'     => $el['id'],
                        'name'       => $name,
                        'place_type' => $type,
                        'latitude'   => $elLat,
                        'longitude'  => $elLng,
                        'address'    => $addr,
                        'opening_hours' => $tags['opening_hours'] ?? null,
                        'phone'      => $tags['phone'] ?? $tags['contact:phone'] ?? null,
                        'website'    => $tags['website'] ?? $tags['contact:website'] ?? null,
                        'cuisine'    => $tags['cuisine'] ?? null,
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
