<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiConversation;
use App\Models\Trip;
use App\Models\TripDay;
use App\Models\TripPlace;
use App\Services\AI\AIServiceInterface;
use App\Services\AI\DTOs\TimelineDTO;
use App\Services\AI\TimelineParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    private const CHAT_LIMIT = 50;

    public function __construct(
        private readonly AIServiceInterface $aiService,
        private readonly TimelineParser $timelineParser,
    ) {}

    // ─────────────────────────────────────────────
    // POST /api/trips/{id}/chat
    // ─────────────────────────────────────────────

    public function chat(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $user = Auth::guard('api')->user();
        $trip = Trip::find($id);

        if (! $trip) {
            return response()->json(['message' => 'Không tìm thấy tài nguyên'], 404);
        }

        if ($trip->user_id !== $user->id) {
            return response()->json(['message' => 'Bạn không có quyền truy cập tài nguyên này'], 403);
        }

        if ($trip->status !== 'completed') {
            return response()->json(['message' => 'Lịch trình chưa sẵn sàng để chỉnh sửa.'], 422);
        }

        // Check chat limit
        $chatCount = AiConversation::where('trip_id', $id)->count();
        if ($chatCount >= self::CHAT_LIMIT) {
            return response()->json([
                'message' => 'Đã đạt giới hạn chỉnh sửa cho lịch trình này',
            ], 429);
        }

        // Build conversation history
        $history = AiConversation::where('trip_id', $id)
            ->orderBy('created_at')
            ->get()
            ->flatMap(fn ($c) => [
                ['role' => 'user',      'content' => $c->user_message],
                ['role' => 'assistant', 'content' => $c->ai_response],
            ])
            ->toArray();

        // Get current timeline
        $currentTimeline = $trip->timeline
            ? TimelineDTO::fromArray($trip->timeline)
            : new TimelineDTO([]);

        // Call AI with 30s timeout
        set_time_limit(35);
        $response = $this->aiService->processChat(
            $request->message,
            $currentTimeline,
            $history
        );

        // Update timeline if AI returned changes
        $newTimeline = $response->updatedTimeline ?? $currentTimeline;

        // Save conversation
        AiConversation::create([
            'trip_id'           => $id,
            'user_id'           => $user->id,
            'user_message'      => $request->message,
            'ai_response'       => $response->message,
            'timeline_snapshot' => $newTimeline->toArray(),
            'created_at'        => now(),
        ]);

        // Update trip timeline — sync vào DB nếu AI có thay đổi
        $updatedDays = null;
        if ($response->updatedTimeline) {
            $trip->update(['timeline' => $newTimeline->toArray()]);
            $updatedDays = $this->syncTimelineToDB($trip, $newTimeline);
        }

        return response()->json([
            'message'       => $response->message,
            'updated_days'  => $updatedDays,
            'suggestions'   => $response->suggestions,
            'chat_count'    => $chatCount + 1,
            'chat_limit'    => self::CHAT_LIMIT,
        ]);
    }

    // ─────────────────────────────────────────────
    // Đồng bộ TimelineDTO vào bảng trip_days + trip_places
    // Giữ nguyên check-in data của user, chỉ cập nhật thông tin hoạt động
    // ─────────────────────────────────────────────

    private function syncTimelineToDB(Trip $trip, TimelineDTO $timeline): array
    {
        DB::transaction(function () use ($trip, $timeline) {
            foreach ($timeline->days as $dayIndex => $dayDTO) {
                $dayNumber = $dayIndex + 1;

                // Tìm TripDay tương ứng theo day_number
                $tripDay = TripDay::where('trip_id', $trip->id)
                    ->where('day_number', $dayNumber)
                    ->first();

                if (! $tripDay) {
                    continue; // Bỏ qua nếu ngày không tồn tại trong DB
                }

                // Cập nhật weather nếu có
                if ($dayDTO->weather) {
                    $tripDay->update(['weather' => $dayDTO->weather->toArray()]);
                }

                // Lưu check-in data hiện tại trước khi xóa places
                $checkinData = TripPlace::where('trip_day_id', $tripDay->id)
                    ->whereNotNull('checked_in_at')
                    ->get();

                // Xóa TOÀN BỘ places cũ (để insert lại hoàn toàn từ AI, tránh duplicate)
                TripPlace::where('trip_day_id', $tripDay->id)->delete();

                // Insert places mới từ AI
                foreach ($dayDTO->activities as $sortOrder => $activityDTO) {
                    TripPlace::create([
                        'trip_day_id'         => $tripDay->id,
                        'trip_id'             => $trip->id,
                        'time'                => $activityDTO->time,
                        'title'               => $activityDTO->title,
                        'description'         => $activityDTO->description,
                        'place_name'          => $activityDTO->placeName,
                        'place_type'          => $activityDTO->placeType,
                        'estimated_cost'      => $activityDTO->estimatedCost,
                        'duration_minutes'    => $activityDTO->durationMinutes,
                        'transport_to_next'   => $activityDTO->transportToNext,
                        'distance_to_next_km' => $activityDTO->distanceToNextKm,
                        'latitude'            => $activityDTO->latitude,
                        'longitude'           => $activityDTO->longitude,
                        'sort_order'          => $sortOrder,
                    ]);
                }

                // Restore check-in data vào places có cùng title hoặc place_name
                if ($checkinData->isNotEmpty()) {
                    $newPlaces = TripPlace::where('trip_day_id', $tripDay->id)->get();
                    foreach ($checkinData as $oldPlace) {
                        $match = $newPlaces->first(
                            fn ($p) => $p->title === $oldPlace->title || $p->place_name === $oldPlace->place_name
                        );
                        if ($match) {
                            $match->update([
                                'checked_in_at'  => $oldPlace->checked_in_at,
                                'checkin_photo'  => $oldPlace->checkin_photo,
                                'checkin_note'   => $oldPlace->checkin_note,
                                'actual_time'    => $oldPlace->actual_time,
                            ]);
                        }
                    }
                }
            }
        });

        // Reload trip days với places từ DB để trả về frontend
        $trip->load('days.places');

        return $trip->days->map(function (TripDay $day) {
            return [
                'id'         => $day->id,
                'day_number' => $day->day_number,
                'date'       => $day->date->toDateString(),
                'weather'    => $day->weather,
                'places'     => $day->places->map(function (TripPlace $place) {
                    return [
                        'id'                  => $place->id,
                        'time'                => $place->time,
                        'title'               => $place->title,
                        'description'         => $place->description,
                        'place_name'          => $place->place_name,
                        'place_type'          => $place->place_type,
                        'estimated_cost'      => (float) $place->estimated_cost,
                        'duration_minutes'    => $place->duration_minutes,
                        'transport_to_next'   => $place->transport_to_next,
                        'distance_to_next_km' => (float) $place->distance_to_next_km,
                        'latitude'            => $place->latitude ? (float) $place->latitude : null,
                        'longitude'           => $place->longitude ? (float) $place->longitude : null,
                        'sort_order'          => $place->sort_order,
                        'checked_in_at'       => $place->checked_in_at?->toISOString(),
                        'checkin_photo_url'   => $place->checkin_photo_url,
                        'checkin_note'        => $place->checkin_note,
                        'actual_time'         => $place->actual_time,
                    ];
                })->values()->toArray(),
            ];
        })->toArray();
    }
}
