<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiConversation;
use App\Models\Trip;
use App\Services\AI\AIServiceInterface;
use App\Services\AI\DTOs\TimelineDTO;
use App\Services\AI\TimelineParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

        // Update trip timeline
        if ($response->updatedTimeline) {
            $trip->update(['timeline' => $newTimeline->toArray()]);
        }

        return response()->json([
            'message'          => $response->message,
            'updated_timeline' => $response->updatedTimeline ? $newTimeline->toArray() : null,
            'suggestions'      => $response->suggestions,
            'chat_count'       => $chatCount + 1,
            'chat_limit'       => self::CHAT_LIMIT,
        ]);
    }
}
