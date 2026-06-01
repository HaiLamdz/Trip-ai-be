<?php

namespace App\Services\AI;

use App\Services\AI\DTOs\ChatResponseDTO;
use App\Services\AI\DTOs\TimelineDTO;
use App\Services\AI\DTOs\TripDayDTO;
use App\Services\AI\DTOs\TripGenerationRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService implements AIServiceInterface
{
    private string $apiKey;
    private string $endpoint;

    public function __construct()
    {
        $this->apiKey   = (string) config('services.ai.gemini.api_key', '');
        $this->endpoint = (string) config('services.ai.gemini.endpoint', 'https://generativelanguage.googleapis.com/v1/models/gemini-1.5-flash:generateContent');
    }

    // ─────────────────────────────────────────────
    // generateTimeline — toàn bộ trip trong 1 request (legacy)
    // ─────────────────────────────────────────────

    public function generateTimeline(TripGenerationRequest $request): TimelineDTO
    {
        $prompt = $this->buildGenerationPrompt($request);
        $json   = $this->callWithRetry($prompt, expectKey: 'days');

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
        $prompt = $this->buildSingleDayPrompt($request, $dayNumber, $date, $weatherData, $visitedPlaces);
        $json   = $this->callWithRetry($prompt, expectKey: 'activities');

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

        $historyText = '';
        foreach ($conversationHistory as $msg) {
            $role         = $msg['role'] === 'user' ? 'Người dùng' : 'AI';
            $historyText .= "{$role}: {$msg['content']}\n";
        }

        $prompt = <<<PROMPT
Bạn là trợ lý lập kế hoạch du lịch. Dưới đây là lịch trình hiện tại:

{$timelineJson}

Lịch sử hội thoại:
{$historyText}

Yêu cầu mới của người dùng: {$userMessage}

Hãy phân tích yêu cầu và cập nhật lịch trình nếu cần. Trả về JSON với format:
{
  "message": "Mô tả những thay đổi đã thực hiện",
  "updated_timeline": { ... timeline JSON theo schema chuẩn ... },
  "suggestions": ["gợi ý 1", "gợi ý 2"]
}

Nếu không cần thay đổi timeline, set "updated_timeline" = null.
Chỉ trả về JSON, không thêm text ngoài JSON.
PROMPT;

        $raw = $this->callWithRetry($prompt, expectKey: null);

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
            $response = Http::timeout(5)->post(
                $this->endpoint . '?key=' . $this->apiKey,
                ['contents' => [['parts' => [['text' => 'ping']]]]]
            );

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
        // Tóm tắt lịch trình để AI hiểu context
        $activities = $trip->days->flatMap(fn ($day) => $day->places)->map(fn ($p) => $p->place_type)->unique()->values()->implode(', ');
        $weatherSummary = $trip->days->map(function ($day) {
            if (! $day->weather) return null;
            $w = is_array($day->weather) ? $day->weather : $day->weather->toArray();
            return "Ngày {$day->day_number}: {$w['summary']}, cao {$w['temperature_high']}°C, mưa " . round(($w['rain_probability'] ?? 0) * 100) . '%';
        })->filter()->implode('; ');

        $prompt = <<<PROMPT
Bạn là chuyên gia tư vấn du lịch. Hãy tạo danh sách đồ cần mang cho chuyến đi sau:

Điểm đến: {$trip->destination}
Thời gian: {$trip->duration_days} ngày
Số người: {$trip->num_people}
Phương tiện: {$trip->transport_mode}
Loại hoạt động: {$activities}
Thời tiết dự báo: {$weatherSummary}

Trả về JSON với format sau (chỉ JSON, không thêm text):
{
  "categories": [
    {
      "name": "Tên nhóm",
      "emoji": "emoji",
      "items": [
        { "name": "Tên đồ vật", "quantity": "số lượng hoặc ghi chú", "essential": true/false, "note": "ghi chú thêm nếu có" }
      ]
    }
  ],
  "tips": ["mẹo 1", "mẹo 2"]
}

Nhóm theo: Quần áo, Vệ sinh cá nhân, Điện tử & Sạc, Giấy tờ & Tiền, Thuốc & Sức khỏe, Đồ ăn vặt & Nước, Khác.
Đánh dấu essential=true cho những thứ bắt buộc phải mang.
PROMPT;

        $raw = $this->callWithRetry($prompt, expectKey: 'categories');
        return $raw;
    }

    // ─────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────

    private function buildGenerationPrompt(TripGenerationRequest $request): string
    {
        $preferences      = implode(', ', $request->preferences) ?: 'Không có';
        $userPrefs        = implode(', ', $request->userPreferences) ?: 'Không có';
        $transportMode    = $request->transportMode ?? 'Không xác định';
        $notes            = $request->notes ?? 'Không có';
        $accommodationType = $request->accommodationType ?? 'khách sạn';
        $accommodationArea = $request->accommodationArea
            ? "Người dùng yêu cầu: \"{$request->accommodationArea}\" — PHẢI dùng đúng tên/địa điểm này nếu có thật, không được thay thế bằng nơi khác"
            : 'Người dùng không chỉ định — AI tự đề xuất 1 chỗ ở nổi tiếng, có thật, dễ tìm trên Google Maps, phù hợp ngân sách';
        $arrivalTime      = $request->arrivalTime ?? '14:00';

        $budgetPerDayTotal = round($request->budget / max(1, $request->durationDays));
        $budgetPerDayPerPerson = round($budgetPerDayTotal / max(1, $request->numPeople));
        $accommodationBudgetPerNight = match($accommodationType) {
            'hostel'   => round($budgetPerDayPerPerson * 0.15),
            'homestay' => round($budgetPerDayPerPerson * 0.25),
            'hotel'    => round($budgetPerDayPerPerson * 0.35),
            'resort'   => round($budgetPerDayPerPerson * 0.50),
            'airbnb'   => round($budgetPerDayPerPerson * 0.30),
            'villa'    => round($budgetPerDayPerPerson * 0.55),
            default    => round($budgetPerDayPerPerson * 0.30),
        };

        $weatherSummary = 'Không có dữ liệu thời tiết';
        if (! empty($request->weatherData)) {
            $weatherSummary = json_encode($request->weatherData, JSON_UNESCAPED_UNICODE);
        }

        $schema = '{"days":[{"date":"YYYY-MM-DD","weather":{"summary":"","icon":"01d","temperature_high":0,"temperature_low":0,"rain_probability":0},"activities":[{"time":"HH:MM","title":"","description":"","place_name":"","place_type":"food|attraction|hotel|cafe|transport|nightlife|other","estimated_cost":0,"duration_minutes":0,"transport_to_next":"","distance_to_next_km":0,"latitude":0.0,"longitude":0.0}]}]}';

        return <<<PROMPT
Bạn là chuyên gia lập kế hoạch du lịch Việt Nam. Hãy tạo lịch trình chi tiết cho chuyến đi sau:

Điểm đến: {$request->destination}
Thời gian: {$request->durationDays} ngày, bắt đầu {$request->startDate}
Ngân sách: {$request->budget} VND cho {$request->numPeople} người
Phương tiện di chuyển đến nơi: {$transportMode}
Giờ đến nơi ngày đầu: {$arrivalTime}
Loại chỗ ở: {$accommodationType} ({$accommodationArea})
Sở thích: {$preferences}
Ghi chú: {$notes}
Dự báo thời tiết: {$weatherSummary}
Sở thích cá nhân từ lịch sử: {$userPrefs}

QUY TẮC VỀ ĐỊA ĐIỂM & TỌA ĐỘ (BẮT BUỘC):
- Chỉ dùng địa điểm NỔI TIẾNG, CÓ THẬT, DỄ TÌM TRÊN GOOGLE MAPS — không bịa tên địa điểm.
- Tọa độ latitude/longitude phải CHÍNH XÁC tuyệt đối (lấy từ kiến thức thực tế), không ước lượng.
- Tên place_name phải là tên chính thức đầy đủ để người dùng có thể tìm ngay trên Google Maps.

QUY TẮC VỀ CHỖ Ở & LỘ TRÌNH:
1. Ngày 1: Hoạt động đầu tiên phải là "Check-in [tên chỗ ở cụ thể]" lúc {$arrivalTime} với place_type="hotel". {$accommodationArea}.
2. Chi phí check-in = ~{$accommodationBudgetPerNight} VND/người/đêm (tính vào estimated_cost của activity check-in).
3. Mỗi ngày: Nhóm hoạt động theo khu vực địa lý gần chỗ ở — tối thiểu hóa di chuyển.
4. Tính distance_to_next_km từ tọa độ thực tế của từng địa điểm đến địa điểm tiếp theo.
5. Cuối mỗi ngày: Hoạt động cuối nên là ăn tối gần khách sạn hoặc về khách sạn.
6. Ngày cuối: Thêm "Check-out" buổi sáng (estimated_cost=0) trước khi ra sân bay/bến xe.

QUY TẮC NGÂN SÁCH BẮT BUỘC:
- Tổng estimated_cost mỗi ngày PHẢI nằm trong khoảng 80%–120% của {$budgetPerDayTotal} VND
- Tức là: từ {$budgetPerDayTotal} × 0.8 đến {$budgetPerDayTotal} × 1.2 VND/ngày
- KHÔNG để tổng chi phí thấp hơn 80% ngân sách (quá rẻ = không thực tế)
- Phân bổ: lưu trú ~30-40%, ăn uống ~30%, tham quan ~20%, di chuyển ~10%

Yêu cầu khác:
- Mỗi ngày có 5-8 hoạt động hợp lý
- Ưu tiên hoạt động ngoài trời vào khung giờ không mưa
- Trả về ĐÚNG định dạng JSON sau, không thêm text ngoài JSON

Schema JSON bắt buộc:
{$schema}
PROMPT;
    }

    private function buildSingleDayPrompt(
        TripGenerationRequest $request,
        int $dayNumber,
        string $date,
        array $weatherData = [],
        array $visitedPlaces = [],
    ): string {
        $preferences      = implode(', ', $request->preferences) ?: 'Không có';
        $transportMode    = $request->transportMode ?? 'Không xác định';
        $notes            = $request->notes ?? 'Không có';

        // Tính ngân sách/ngày bao gồm cả lưu trú
        $totalBudget      = $request->budget;
        $budgetPerDayTotal = round($totalBudget / max(1, $request->durationDays)); // tổng/ngày (tất cả người)
        $budgetPerDayPerPerson = round($budgetPerDayTotal / max(1, $request->numPeople)); // /người/ngày

        // Ước tính chi phí lưu trú/đêm dựa trên loại chỗ ở (chiếm ~30-40% ngân sách)
        $accommodationType = $request->accommodationType ?? 'khách sạn';
        $accommodationBudgetPerNight = match($accommodationType) {
            'hostel'   => round($budgetPerDayPerPerson * 0.15),
            'homestay' => round($budgetPerDayPerPerson * 0.25),
            'hotel'    => round($budgetPerDayPerPerson * 0.35),
            'resort'   => round($budgetPerDayPerPerson * 0.50),
            'airbnb'   => round($budgetPerDayPerPerson * 0.30),
            'villa'    => round($budgetPerDayPerPerson * 0.55),
            default    => round($budgetPerDayPerPerson * 0.30),
        };
        // Ngân sách còn lại cho ăn uống + tham quan + di chuyển
        $budgetForActivities = $budgetPerDayTotal - ($accommodationBudgetPerNight * $request->numPeople);

        $accommodationArea = $request->accommodationArea
            ? "Người dùng yêu cầu: \"{$request->accommodationArea}\" — PHẢI dùng đúng tên/địa điểm này nếu có thật, không được thay thế bằng nơi khác"
            : 'Người dùng không chỉ định — AI tự đề xuất 1 chỗ ở nổi tiếng, có thật, dễ tìm trên Google Maps, phù hợp ngân sách';
        $arrivalTime      = $request->arrivalTime ?? '14:00';

        $weatherSummary = 'Không có dữ liệu thời tiết';
        if (! empty($weatherData)) {
            $weatherSummary = json_encode($weatherData, JSON_UNESCAPED_UNICODE);
        }

        $visitedSection = '';
        if (! empty($visitedPlaces)) {
            $list           = implode(', ', $visitedPlaces);
            $visitedSection = "\nĐịa điểm đã đến ở các ngày trước (KHÔNG lặp lại): {$list}";
        }

        // Day-specific instructions
        $daySpecific = '';
        if ($dayNumber === 1) {
            $daySpecific = <<<DAY1

NGÀY ĐẦU TIÊN — QUY TẮC BẮT BUỘC:
- Hoạt động ĐẦU TIÊN phải là "Check-in [tên {$accommodationType} cụ thể]" lúc {$arrivalTime} với place_type="hotel".
- {$accommodationArea}.
- Chi phí check-in (estimated_cost) = {$accommodationBudgetPerNight} VND/người × {$request->numPeople} người = tổng {$accommodationBudgetPerNight} VND (đây là chi phí lưu trú 1 đêm).
- Tên chỗ ở phải là tên THẬT, NỔI TIẾNG, DỄ TÌM TRÊN GOOGLE MAPS — cung cấp tọa độ chính xác.
- Các hoạt động sau check-in phải ở gần {$accommodationType} (bán kính hợp lý, tránh di chuyển xa sau khi vừa đến).
- Tính distance_to_next_km từ {$accommodationType} đến từng điểm tiếp theo.
DAY1;
        } elseif ($dayNumber === $request->durationDays) {
            $daySpecific = <<<LASTDAY

NGÀY CUỐI — QUY TẮC BẮT BUỘC:
- Hoạt động ĐẦU TIÊN phải là "Check-out khách sạn" vào buổi sáng (khoảng 10:00-11:00) với place_type="hotel", estimated_cost=0.
- Chỉ lên lịch các hoạt động gần sân bay/bến xe hoặc trên đường về.
- Hoạt động cuối cùng là di chuyển ra sân bay/bến xe với place_type="transport".
LASTDAY;
        } else {
            $daySpecific = <<<MIDDAY

NGÀY GIỮA CHUYẾN — QUY TẮC:
- Xuất phát từ khách sạn/chỗ ở (đã check-in ngày 1).
- Nhóm các địa điểm theo khu vực địa lý để tối thiểu hóa di chuyển.
- Tính distance_to_next_km từ tọa độ thực tế của từng địa điểm.
- Hoạt động cuối ngày nên là ăn tối hoặc về gần khu vực khách sạn.
MIDDAY;
        }

        $schema = '{"weather":{"summary":"","icon":"01d","temperature_high":0,"temperature_low":0,"rain_probability":0},"activities":[{"time":"HH:MM","title":"","description":"","place_name":"","place_type":"food|attraction|hotel|cafe|transport|other","estimated_cost":0,"duration_minutes":0,"transport_to_next":"","distance_to_next_km":0,"latitude":0.0,"longitude":0.0}]}';

        return <<<PROMPT
Bạn là một người lập kế hoạch du lịch địa phương giàu kinh nghiệm.
Mục tiêu của bạn là tạo ra một lịch trình du lịch thực tế, thú vị và gần gũi với người đi du lịch, thay vì chỉ đơn thuần liệt kê các địa điểm nổi tiếng.
Lịch trình cần phải tự nhiên, cân bằng và được tối ưu hóa cho du khách thực tế.

NGUYÊN TẮC CHUNG:
- Tránh lên lịch quá nhiều hoạt động ăn uống liên tiếp
- Giữ khoảng cách ít nhất 2-3 giờ giữa các bữa ăn chính
- Lên nhóm các địa điểm gần nhau, tránh di chuyển ngoằn ngoèo khắp thành phố
- Kết hợp các hoạt động một cách tự nhiên: ăn uống, quán cà phê, mua sắm, tham quan, cuộc sống về đêm
- Bao gồm các khoảng thời gian nghỉ ngơi/thư giãn một cách tự nhiên
- Các hoạt động về đêm nên diễn ra sau 20:00
- Tránh lặp lại các trải nghiệm tương tự trong cùng một ngày
- Giữ nhịp độ hàng ngày phù hợp với mức năng lượng của con người
- Ưu tiên các tuyến đường có thể đi bộ khi có thể
- Tọa độ latitude/longitude phải CHÍNH XÁC cho từng địa điểm thực tế
- Chỉ dùng địa điểm NỔI TIẾNG, CÓ THẬT, DỄ TÌM TRÊN GOOGLE MAPS — không bịa tên địa điểm
- Tên place_name phải là tên chính thức đầy đủ để người dùng tìm được ngay trên Google Maps
{$daySpecific}

THÔNG TIN CHUYẾN ĐI:
Điểm đến: {$request->destination}
Ngày: {$dayNumber}/{$request->durationDays} (ngày {$date})
Loại chỗ ở: {$accommodationType} ({$accommodationArea})
Tổng ngân sách cả chuyến: {$totalBudget} VND cho {$request->numPeople} người
Ngân sách ngày này (tổng {$request->numPeople} người): {$budgetPerDayTotal} VND
  - Trong đó lưu trú: ~{$accommodationBudgetPerNight} VND/người/đêm
  - Còn lại cho ăn uống + tham quan + di chuyển: ~{$budgetForActivities} VND
Số người: {$request->numPeople}
Phương tiện: {$transportMode}
Sở thích: {$preferences}
Ghi chú: {$notes}
Thời tiết ngày này: {$weatherSummary}{$visitedSection}

OUTPUT — QUY TẮC NGÂN SÁCH BẮT BUỘC:
- Tổng estimated_cost của TẤT CẢ hoạt động trong ngày PHẢI nằm trong khoảng 80%–120% của {$budgetPerDayTotal} VND
- Tức là: tổng phải từ {$budgetForActivities} VND đến {$budgetPerDayTotal} VND (bao gồm cả lưu trú nếu ngày 1)
- KHÔNG được để tổng chi phí thấp hơn 80% hoặc cao hơn 120% ngân sách ngày
- Phân bổ hợp lý: lưu trú ~30-40%, ăn uống ~30%, tham quan ~20%, di chuyển ~10%
- Chỉ trả về JSON, KHÔNG thêm bất kỳ text nào ngoài JSON
- Tạo 5-7 hoạt động hợp lý từ sáng đến tối
- Cung cấp tọa độ latitude/longitude chính xác cho từng địa điểm
- KHÔNG sử dụng lại bất kỳ địa điểm nào đã liệt kê ở trên

Schema JSON bắt buộc (chỉ 1 ngày):
{$schema}
PROMPT;
    }

    /**
     * Gọi Gemini API với retry logic (3 lần, delay 2s/5s).
     *
     * @param  string|null $expectKey  Key bắt buộc trong JSON trả về, null = không kiểm tra
     * @return array<string, mixed>
     */
    private function callWithRetry(string $prompt, ?string $expectKey = 'days'): array
    {
        $delays  = [0, 2, 5];
        $lastErr = null;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($delays[$attempt] > 0) {
                sleep($delays[$attempt]);
            }

            try {
                $response = Http::timeout(60)->post(
                    $this->endpoint . '?key=' . $this->apiKey,
                    [
                        'contents'         => [
                            ['parts' => [['text' => $prompt]]],
                        ],
                        'generationConfig' => [
                            'temperature'     => 0.7,
                            'maxOutputTokens' => 8192,
                        ],
                    ]
                );

                if (! $response->successful()) {
                    throw new \RuntimeException('Gemini API error: ' . $response->status() . ' ' . $response->body());
                }

                $text = $response->json('candidates.0.content.parts.0.text', '');
                Log::error($text);
                $data = $this->extractJson($text);

                if ($expectKey !== null && ! isset($data[$expectKey])) {
                    throw new \RuntimeException("Invalid JSON response: missing \"{$expectKey}\" key");
                }

                return $data;

            } catch (\Throwable $e) {
                $lastErr = $e;
                Log::error('GeminiService attempt ' . ($attempt + 1) . ' failed', [
                    'error'   => $e->getMessage(),
                    'attempt' => $attempt + 1,
                ]);
            }
        }

        throw new \RuntimeException('GeminiService failed after 3 attempts: ' . $lastErr?->getMessage());
    }

    /**
     * Trích xuất JSON từ text response của AI (có thể có markdown code block).
     *
     * @return array<string, mixed>
     */
    private function extractJson(string $text): array
    {
        $text = trim($text);

        // Bỏ markdown ```json ... ```
        $text = preg_replace('/^```(?:json)?\s*/', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $text = trim($text);

        // Nếu Gemini có nói thêm trước/sau JSON thì cắt phần JSON chính
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            throw new \RuntimeException('No valid JSON object found in AI response');
        }

        $text = substr($text, $start, $end - $start + 1);

        // Xóa control characters gây lỗi json_decode
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text);

        try {
            return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::error('AI raw response parse failed', [
                'error' => $e->getMessage(),
                'raw' => mb_substr($text, 0, 3000),
            ]);

            throw new \RuntimeException('Failed to parse JSON from AI response: ' . $e->getMessage());
        }
    }
}
