<?php

namespace App\Jobs;

use App\Models\Trip;
use App\Models\TripBudget;
use App\Models\TripDay;
use App\Models\TripPlace;
use App\Services\AI\AIServiceInterface;
use App\Services\AI\DTOs\TripDayDTO;
use App\Services\AI\DTOs\TripGenerationRequest;
use App\Services\GeocodingService;
use App\Services\NotificationService;
use App\Services\UnsplashService;
use App\Services\WeatherService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateTripJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // 5 phút — đủ cho trip 7 ngày (mỗi ngày ~30s)
    public int $tries   = 1;

    // Delay giữa các lần gọi AI (giây) — tránh 429 free tier
    private const DELAY_BETWEEN_DAYS = 5;

    public function __construct(
        private readonly int $tripId,
    ) {}

    public function handle(
        AIServiceInterface $aiService,
        WeatherService $weatherService,
        GeocodingService $geocodingService,
        NotificationService $notificationService,
        UnsplashService $unsplashService,
    ): void {
        $trip = Trip::with('user')->findOrFail($this->tripId);

        try {
            // 1. Lấy weather forecast cho toàn bộ trip
            $weatherByDate = $this->fetchWeatherByDate($trip, $weatherService);

            // 2. Build request object dùng chung cho tất cả các ngày
            $userPrefs = $trip->user->preferences?->preferences ?? [];

            $generationRequest = new TripGenerationRequest(
                destination:       $trip->destination,
                startDate:         $trip->start_date->format('Y-m-d'),
                durationDays:      $trip->duration_days,
                budget:            (float) $trip->budget,
                numPeople:         $trip->num_people,
                origin:            $trip->origin,
                travelType:        $trip->travel_type,
                transportMode:     $trip->transport_mode,
                accommodationType: $trip->accommodation_type,
                accommodationArea: $trip->accommodation_area,
                arrivalTime:       $trip->arrival_time,
                preferences:       $trip->preferences ?? [],
                notes:             $trip->notes,
                weatherData:       [],
                userPreferences:   $userPrefs,
            );

            // 3. Gọi AI từng ngày, gộp kết quả
            $days          = [];
            $visitedPlaces = []; // tích lũy địa điểm qua các ngày

            for ($dayIndex = 0; $dayIndex < $trip->duration_days; $dayIndex++) {
                $date        = Carbon::parse($trip->start_date)->addDays($dayIndex)->format('Y-m-d');
                $dayNumber   = $dayIndex + 1;
                $weatherData = $weatherByDate[$date] ?? [];

                Log::info("GenerateTripJob: generating day {$dayNumber}/{$trip->duration_days}", [
                    'trip_id'        => $this->tripId,
                    'date'           => $date,
                    'visited_count'  => count($visitedPlaces),
                ]);

                $dayDTO = $this->generateDayWithCache(
                    $aiService,
                    $generationRequest,
                    $dayNumber,
                    $date,
                    $weatherData,
                    $visitedPlaces,
                );

                $days[] = $dayDTO;

                // Tích lũy tên địa điểm để truyền vào ngày tiếp theo
                foreach ($dayDTO->activities as $activity) {
                    if ($activity->placeName) {
                        $visitedPlaces[] = $activity->placeName;
                    }
                }

                // Delay giữa các ngày để tránh rate limit free tier
                if ($dayIndex < $trip->duration_days - 1) {
                    sleep(self::DELAY_BETWEEN_DAYS);
                }
            }

            // 4. Persist toàn bộ vào DB trong 1 transaction
            DB::transaction(function () use ($trip, $days, $geocodingService) {
                $trip->days()->delete();

                $totalEstimated   = 0;
                $budgetByCategory = [
                    'food'          => 0,
                    'transport'     => 0,
                    'attraction'    => 0,
                    'accommodation' => 0,
                    'other'         => 0,
                ];

                // Track the first hotel/accommodation activity to save coords back to trip
                $accommodationName = null;
                $accommodationLat  = null;
                $accommodationLng  = null;

                foreach ($days as $dayIndex => $dayDTO) {
                    /** @var TripDayDTO $dayDTO */
                    $tripDay = TripDay::create([
                        'trip_id'    => $trip->id,
                        'day_number' => $dayIndex + 1,
                        'date'       => $dayDTO->date,
                        'weather'    => $dayDTO->weather?->toArray(),
                    ]);

                    foreach ($dayDTO->activities as $sortOrder => $actDTO) {
                        $lat = $actDTO->latitude;
                        $lng = $actDTO->longitude;

                        if ((! $lat || ! $lng) && $actDTO->placeName) {
                            $coords = $geocodingService->geocode($actDTO->placeName . ', ' . $trip->destination);
                            if ($coords) {
                                $lat = $coords['lat'];
                                $lng = $coords['lng'];
                            }
                        }

                        TripPlace::create([
                            'trip_day_id'         => $tripDay->id,
                            'trip_id'             => $trip->id,
                            'place_name'          => $actDTO->placeName,
                            'place_type'          => $actDTO->placeType,
                            'time'                => $actDTO->time,
                            'title'               => $actDTO->title,
                            'description'         => $actDTO->description,
                            'estimated_cost'      => $actDTO->estimatedCost,
                            'duration_minutes'    => $actDTO->durationMinutes,
                            'transport_to_next'   => $actDTO->transportToNext,
                            'distance_to_next_km' => $actDTO->distanceToNextKm,
                            'latitude'            => $lat,
                            'longitude'           => $lng,
                            'sort_order'          => $sortOrder,
                        ]);

                        $totalEstimated += $actDTO->estimatedCost;

                        $cat = match ($actDTO->placeType) {
                            'food', 'cafe' => 'food',
                            'transport'    => 'transport',
                            'attraction'   => 'attraction',
                            'hotel'        => 'accommodation',
                            default        => 'other',
                        };
                        $budgetByCategory[$cat] += $actDTO->estimatedCost;

                        // Capture first hotel activity as the base accommodation
                        if ($accommodationName === null && $actDTO->placeType === 'hotel' && $actDTO->placeName) {
                            $accommodationName = $actDTO->placeName;
                            $accommodationLat  = $lat;
                            $accommodationLng  = $lng;
                        }
                    }
                }

                TripBudget::updateOrCreate(
                    ['trip_id' => $trip->id],
                    array_merge($budgetByCategory, ['total_estimated' => $totalEstimated])
                );

                $timelineArray = [
                    'days' => array_map(fn (TripDayDTO $d) => $d->toArray(), $days),
                ];

                $trip->update([
                    'status'            => 'completed',
                    'timeline'          => $timelineArray,
                    'accommodation_name' => $accommodationName ?? $trip->accommodation_name,
                    'accommodation_lat'  => $accommodationLat  ?? $trip->accommodation_lat,
                    'accommodation_lng'  => $accommodationLng  ?? $trip->accommodation_lng,
                ]);
            });

            // 5. Fetch ảnh đại diện từ Unsplash — dùng query do AI sinh ra
            try {
                $tripWithDays   = Trip::with('days.places')->find($this->tripId);
                $coverQuery     = $aiService->generateCoverImageQuery($tripWithDays);
                $coverImageUrl  = $unsplashService->getPhotoByQuery($coverQuery);

                // Fallback: nếu Unsplash không trả kết quả, thử query đơn giản hơn
                if (! $coverImageUrl) {
                    $coverImageUrl = $unsplashService->getTravelPhoto($trip->destination);
                }

                if ($coverImageUrl) {
                    Trip::where('id', $this->tripId)->update(['cover_image_url' => $coverImageUrl]);
                    Log::info("GenerateTripJob: cover image saved", [
                        'trip_id' => $this->tripId,
                        'query'   => $coverQuery,
                        'url'     => $coverImageUrl,
                    ]);
                }
            } catch (\Throwable $e) {
                // Ảnh cover không quan trọng — không fail cả job
                Log::warning('GenerateTripJob: cover image fetch failed', [
                    'trip_id' => $this->tripId,
                    'error'   => $e->getMessage(),
                ]);
            }

            // 6. Thông báo hoàn thành
            $notificationService->tripCompleted($trip->user_id, $trip->id, $trip->destination);

            Log::info("GenerateTripJob completed", ['trip_id' => $this->tripId]);

        } catch (\Throwable $e) {
            Log::error('GenerateTripJob failed', [
                'trip_id' => $this->tripId,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            Trip::where('id', $this->tripId)->update(['status' => 'failed']);
            $notificationService->tripFailed($trip->user_id, $trip->id, $trip->destination);
        }
    }

    // ─────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────

    /**
     * Generate 1 ngày với cache.
     * Cache key bao gồm hash của visitedPlaces để mỗi ngày có context riêng.
     */
    private function generateDayWithCache(
        AIServiceInterface $aiService,
        TripGenerationRequest $request,
        int $dayNumber,
        string $date,
        array $weatherData,
        array $visitedPlaces = [],
    ): TripDayDTO {
        $cacheKey = 'day_timeline:' . md5(
            $request->destination . $date
            . json_encode($request->preferences)
            . json_encode($visitedPlaces)   // context khác nhau → cache key khác nhau
        );

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            Log::info("GenerateTripJob: cache hit for day {$dayNumber}", ['date' => $date]);
            return TripDayDTO::fromArray($cached);
        }

        $dayDTO = $aiService->generateDayTimeline($request, $dayNumber, $date, $weatherData, $visitedPlaces);

        // Cache 24 giờ
        Cache::put($cacheKey, $dayDTO->toArray(), 86400);

        return $dayDTO;
    }

    /**
     * Lấy weather forecast và index theo ngày (YYYY-MM-DD).
     * WeatherService trả về array indexed 0-based theo thứ tự ngày.
     *
     * @return array<string, array<string, mixed>>
     */
    private function fetchWeatherByDate(Trip $trip, WeatherService $weatherService): array
    {
        $result = [];

        if (! $trip->destination_lat || ! $trip->destination_lng) {
            return $result;
        }

        try {
            $forecasts = $weatherService->getForecast($trip->destination_lat, $trip->destination_lng);

            // Map từng forecast theo ngày tương ứng của trip
            foreach ($forecasts as $index => $weather) {
                $date          = Carbon::parse($trip->start_date)->addDays($index)->format('Y-m-d');
                $result[$date] = $weather->toArray();
            }
        } catch (\Throwable $e) {
            Log::warning('Weather fetch failed for trip ' . $trip->id, ['error' => $e->getMessage()]);
        }

        return $result;
    }
}
