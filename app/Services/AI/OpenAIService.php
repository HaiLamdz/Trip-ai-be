<?php

namespace App\Services\AI;

use App\Services\AI\DTOs\ChatResponseDTO;
use App\Services\AI\DTOs\TimelineDTO;
use App\Services\AI\DTOs\TripDayDTO;
use App\Services\AI\DTOs\TripGenerationRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIService implements AIServiceInterface
{
    private string $apiKey;
    private string $endpoint;
    private string $model;

    public function __construct()
    {
        $this->apiKey   = (string) config('services.ai.openai.api_key', '');
        $this->endpoint = (string) config('services.ai.openai.endpoint', 'https://api.openai.com/v1/chat/completions');
        $this->model    = (string) config('services.ai.openai.model', 'gpt-4o-mini');
    }

    // ─────────────────────────────────────────────
    // generateTimeline
    // ─────────────────────────────────────────────

    public function generateTimeline(TripGenerationRequest $request): TimelineDTO
    {
        $prompt = $this->buildGenerationPrompt($request);
        $json   = $this->callWithRetry($prompt);

        return TimelineDTO::fromArray($json);
    }

    // ─────────────────────────────────────────────
    // generateDayTimeline — chỉ 1 ngày
    // ─────────────────────────────────────────────

    public function generateDayTimeline(
        TripGenerationRequest $request,
        int $dayNumber,
        string $date,
        array $weatherData = [],
        array $visitedPlaces = [],
    ): TripDayDTO {
        $preferences   = implode(', ', $request->preferences) ?: 'Không có';
        $transportMode = $request->transportMode ?? 'Không xác định';
        $budgetPerDay  = $request->numPeople > 0
            ? round($request->budget / $request->durationDays / $request->numPeople)
            : round($request->budget / $request->durationDays);
        $weatherSummary = empty($weatherData)
            ? 'Không có dữ liệu thời tiết'
            : json_encode($weatherData, JSON_UNESCAPED_UNICODE);

        $visitedSection = '';
        if (! empty($visitedPlaces)) {
            $list           = implode(', ', $visitedPlaces);
            $visitedSection = "\nĐịa điểm đã đến ở các ngày trước (KHÔNG lặp lại): {$list}";
        }

        $schema = '{"weather":{"summary":"","icon":"01d","temperature_high":0,"temperature_low":0,"rain_probability":0},"activities":[{"time":"HH:MM","title":"","description":"","place_name":"","place_type":"food|attraction|hotel|cafe|transport|other","estimated_cost":0,"duration_minutes":0,"transport_to_next":"","distance_to_next_km":0,"latitude":0.0,"longitude":0.0}]}';

        $prompt = <<<PROMPT
Bạn là một người lập kế hoạch du lịch địa phương giàu kinh nghiệm.
Mục tiêu của bạn là tạo ra một lịch trình du lịch thực tế, thú vị và gần gũi với người đi du lịch, thay vì chỉ đơn thuần liệt kê các địa điểm nổi tiếng.
Lịch trình cần phải tự nhiên, cân bằng và được tối ưu hóa cho du khách thực tế.

NGUYÊN TẮC:
- Tránh lên lịch quá nhiều hoạt động ăn uống liên tiếp
- Giữ khoảng cách ít nhất 2-3 giờ giữa các bữa ăn chính
- Lên nhóm các địa điểm gần nhau, tránh di chuyển ngoằn ngoèo khắp thành phố
- Kết hợp các hoạt động một cách tự nhiên: ăn uống, quán cà phê, mua sắm, tham quan, cuộc sống về đêm
- Bao gồm các khoảng thời gian nghỉ ngơi/thư giãn một cách tự nhiên
- Các hoạt động về đêm nên diễn ra sau 20:00
- Các quán bar trên tầng thượng nên diễn ra vào lúc hoàng hôn/ban đêm
- Tránh lặp lại các trải nghiệm tương tự trong cùng một ngày
- Giữ nhịp độ hàng ngày phù hợp với mức năng lượng của con người
- Ưu tiên các tuyến đường có thể đi bộ khi có thể

Dòng chảy cảm xúc mỗi ngày:
  Buổi sáng  → khám phá
  Buổi chiều → thư giãn / mua sắm
  Buổi tối   → ăn tối (có thể sang trọng hơn)
  Ban đêm    → cuộc sống về đêm / thư giãn

KHÔNG NÊN:
- Lên lịch 2 bữa ăn thịnh soạn trong vòng 2 giờ
- Đặt hoạt động về đêm trước bữa tối
- Quá tải lịch trình
- Di chuyển qua lại không cần thiết

Trước khi lập kế hoạch, hãy suy nghĩ từng bước:
1. Nhóm các địa điểm gần nhau theo khu vực
2. Tối ưu hóa lộ trình di chuyển
3. Cân bằng cường độ hoạt động trong ngày
4. Phân bổ ngân sách tự nhiên
5. Tạo nhịp độ thoải mái, không gấp gáp

---

THÔNG TIN CHUYẾN ĐI:
Điểm đến: {$request->destination}
Ngày: {$dayNumber}/{$request->durationDays} (ngày {$date})
Ngân sách/người/ngày: {$budgetPerDay} VND
Số người: {$request->numPeople}
Phương tiện: {$transportMode}
Sở thích: {$preferences}
Thời tiết ngày này: {$weatherSummary}{$visitedSection}

OUTPUT:
- Chỉ trả về JSON, KHÔNG thêm bất kỳ text nào ngoài JSON
- Tạo 5-7 hoạt động hợp lý từ sáng đến tối
- Tổng chi phí không vượt ngân sách/ngày
- Cung cấp tọa độ latitude/longitude chính xác cho từng địa điểm
- KHÔNG sử dụng lại bất kỳ địa điểm nào đã liệt kê ở trên

Schema JSON bắt buộc (chỉ 1 ngày):
{$schema}
PROMPT;

        $json = $this->callWithRetry($prompt, expectKey: 'activities');

        return TripDayDTO::fromArray([
            'date'       => $date,
            'weather'    => $json['weather'] ?? null,
            'activities' => $json['activities'] ?? [],
        ]);
    }

    // ─────────────────────────────────────────────
    // processChat
    // ─────────────────────────────────────────────

    public function processChat(
        string $userMessage,
        TimelineDTO $currentTimeline,
        array $conversationHistory
    ): ChatResponseDTO {
        $timelineJson = json_encode($currentTimeline->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $messages = [
            [
                'role'    => 'system',
                'content' => "Bạn là trợ lý lập kế hoạch du lịch. Lịch trình hiện tại:\n{$timelineJson}\n\nKhi cập nhật, trả về JSON: {\"message\":\"...\",\"updated_timeline\":{...},\"suggestions\":[...]}. Chỉ trả JSON.",
            ],
        ];

        foreach ($conversationHistory as $msg) {
            $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }

        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $raw = $this->callWithRetry('', messages: $messages, expectTimeline: false);

        $updatedTimeline = null;
        if (isset($raw['updated_timeline']) && is_array($raw['updated_timeline'])) {
            $updatedTimeline = TimelineDTO::fromArray($raw['updated_timeline']);
        }

        return new ChatResponseDTO(
            message:         $raw['message'] ?? 'Đã xử lý yêu cầu của bạn.',
            updatedTimeline: $updatedTimeline,
            suggestions:     $raw['suggestions'] ?? [],
        );
    }

    // ─────────────────────────────────────────────
    // healthCheck
    // ─────────────────────────────────────────────

    public function healthCheck(): bool
    {
        try {
            $response = Http::timeout(5)
                ->withToken($this->apiKey)
                ->post($this->endpoint, [
                    'model'      => $this->model,
                    'messages'   => [['role' => 'user', 'content' => 'ping']],
                    'max_tokens' => 5,
                ]);
            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    // ─────────────────────────────────────────────
    // generatePackingList
    // ─────────────────────────────────────────────

    public function generatePackingList(\App\Models\Trip $trip): array
    {
        $activities = $trip->days->flatMap(fn ($day) => $day->places)->map(fn ($p) => $p->place_type)->unique()->values()->implode(', ');

        $prompt = "Tạo danh sách đồ cần mang cho chuyến đi đến {$trip->destination}, {$trip->duration_days} ngày, {$trip->num_people} người, phương tiện: {$trip->transport_mode}, hoạt động: {$activities}.\n"
            . "Trả về JSON: {\"categories\":[{\"name\":\"...\",\"emoji\":\"...\",\"items\":[{\"name\":\"...\",\"quantity\":\"...\",\"essential\":true,\"note\":\"...\"}]}],\"tips\":[\"...\"]}";

        $raw = $this->callWithRetry($prompt, expectKey: 'categories');
        return $raw;
    }

    // ─────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────

    private function buildGenerationPrompt(TripGenerationRequest $request): string
    {
        $preferences   = implode(', ', $request->preferences) ?: 'Không có';
        $userPrefs     = implode(', ', $request->userPreferences) ?: 'Không có';
        $transportMode = $request->transportMode ?? 'Không xác định';
        $notes         = $request->notes ?? 'Không có';
        $weatherSummary = empty($request->weatherData)
            ? 'Không có dữ liệu thời tiết'
            : json_encode($request->weatherData, JSON_UNESCAPED_UNICODE);

        $schema = '{"days":[{"date":"YYYY-MM-DD","weather":{"summary":"","icon":"01d","temperature_high":0,"temperature_low":0,"rain_probability":0},"activities":[{"time":"HH:MM","title":"","description":"","place_name":"","place_type":"food|attraction|hotel|cafe|transport|other","estimated_cost":0,"duration_minutes":0,"transport_to_next":"","distance_to_next_km":0,"latitude":0.0,"longitude":0.0}]}]}';

        return "Bạn là chuyên gia lập kế hoạch du lịch Việt Nam. Tạo lịch trình cho:\n"
            . "Điểm đến: {$request->destination}\n"
            . "Thời gian: {$request->durationDays} ngày, bắt đầu {$request->startDate}\n"
            . "Ngân sách: {$request->budget} VND cho {$request->numPeople} người\n"
            . "Phương tiện: {$transportMode}\nSở thích: {$preferences}\nGhi chú: {$notes}\n"
            . "Thời tiết: {$weatherSummary}\nSở thích cá nhân: {$userPrefs}\n\n"
            . "Trả về ĐÚNG JSON schema sau, không thêm text:\n{$schema}";
    }

    /**
     * @param array<array{role:string,content:string}>|null $messages
     * @param string|null $expectKey  Key bắt buộc trong JSON trả về, null = không kiểm tra
     * @return array<string, mixed>
     */
    private function callWithRetry(string $prompt, ?array $messages = null, bool $expectTimeline = true, ?string $expectKey = 'days'): array
    {
        $delays  = [0, 2, 5];
        $lastErr = null;

        if ($messages === null) {
            $messages = [['role' => 'user', 'content' => $prompt]];
        }

        // Backward compat
        $checkKey = $expectTimeline ? $expectKey : null;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($delays[$attempt] > 0) {
                sleep($delays[$attempt]);
            }

            try {
                $response = Http::timeout(60)
                    ->withToken($this->apiKey)
                    ->post($this->endpoint, [
                        'model'       => $this->model,
                        'messages'    => $messages,
                        'temperature' => 0.7,
                        'max_tokens'  => 8192,
                    ]);

                if (! $response->successful()) {
                    throw new \RuntimeException('OpenAI API error: ' . $response->status() . ' ' . $response->body());
                }

                $text = $response->json('choices.0.message.content', '');
                $data = $this->extractJson($text);

                if ($checkKey !== null && ! isset($data[$checkKey])) {
                    throw new \RuntimeException("Invalid JSON response: missing \"{$checkKey}\" key");
                }

                return $data;

            } catch (\Throwable $e) {
                $lastErr = $e;
                Log::error('OpenAIService attempt ' . ($attempt + 1) . ' failed', [
                    'error'   => $e->getMessage(),
                    'attempt' => $attempt + 1,
                ]);
            }
        }

        throw new \RuntimeException('OpenAIService failed after 3 attempts: ' . $lastErr?->getMessage());
    }

    /** @return array<string, mixed> */
    private function extractJson(string $text): array
    {
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/\s*```$/m', '', $text);
        $text = trim($text);

        $data = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Failed to parse JSON: ' . json_last_error_msg());
        }

        return $data;
    }
}
