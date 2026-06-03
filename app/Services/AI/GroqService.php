<?php

namespace App\Services\AI;

use App\Services\AI\DTOs\ChatResponseDTO;
use App\Services\AI\DTOs\TimelineDTO;
use App\Services\AI\DTOs\TripDayDTO;
use App\Services\AI\DTOs\TripGenerationRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GroqService implements AIServiceInterface
{
    private const ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';

    /** Fallback model order khi gặp lỗi */
    private const MODELS = [
        'llama-3.3-70b-versatile',
        'qwen/qwen3-32b',
    ];

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
Return only valid JSON. Do not use markdown. Do not wrap response in code blocks. Do not explain anything.
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
            $response = Http::withToken(config('services.groq.api_key'))
                ->timeout(5)
                ->post(self::ENDPOINT, [
                    'model'      => config('services.groq.model', self::MODELS[0]),
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
        $activities = $trip->days->flatMap(fn ($day) => $day->places)
            ->map(fn ($p) => $p->place_type)->unique()->values()->implode(', ');

        $weatherSummary = $trip->days->map(function ($day) {
            if (! $day->weather) return null;
            $w = is_array($day->weather) ? $day->weather : $day->weather->toArray();
            return "Ngày {$day->day_number}: {$w['summary']}, cao {$w['temperature_high']}°C, mưa "
                . round(($w['rain_probability'] ?? 0) * 100) . '%';
        })->filter()->implode('; ');

        $prompt = <<<PROMPT
Bạn là chuyên gia tư vấn du lịch. Hãy tạo danh sách đồ cần mang cho chuyến đi sau:

Điểm đến: {$trip->destination}
Thời gian: {$trip->duration_days} ngày
Số người: {$trip->num_people}
Phương tiện: {$trip->transport_mode}
Loại hoạt động: {$activities}
Thời tiết dự báo: {$weatherSummary}

Trả về JSON với format sau:
{
  "categories": [
    {
      "name": "Tên nhóm",
      "emoji": "emoji",
      "items": [
        { "name": "Tên đồ vật", "quantity": "số lượng hoặc ghi chú", "essential": true, "note": "ghi chú thêm nếu có" }
      ]
    }
  ],
  "tips": ["mẹo 1", "mẹo 2"]
}

Nhóm theo: Quần áo, Vệ sinh cá nhân, Điện tử & Sạc, Giấy tờ & Tiền, Thuốc & Sức khỏe, Đồ ăn vặt & Nước, Khác.
Đánh dấu essential=true cho những thứ bắt buộc phải mang.
Return only valid JSON. Do not use markdown. Do not wrap response in code blocks. Do not explain anything.
PROMPT;

        return $this->callWithRetry($prompt, expectKey: 'categories');
    }


    // ─────────────────────────────────────────────
    // generate() — helper đơn giản dùng HTTP client
    // ─────────────────────────────────────────────

    public function generate(string $prompt): string
    {
        $response = Http::withToken(config('services.groq.api_key'))
            ->timeout(120)
            ->post(self::ENDPOINT, [
                'model'       => config('services.groq.model', self::MODELS[0]),
                'messages'    => [
                    [
                        'role'    => 'user',
                        'content' => $prompt,
                    ],
                ],
                'temperature' => 0.7,
            ]);

        return $response->json('choices.0.message.content');
    }

    // ─────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────

    private function buildGenerationPrompt(TripGenerationRequest $request): string
    {
        $preferences       = implode(', ', $request->preferences) ?: 'Không có';
        $userPrefs         = implode(', ', $request->userPreferences) ?: 'Không có';
        $transportMode     = $request->transportMode ?? 'Không xác định';
        $notes             = $request->notes ?? 'Không có';
        $accommodationType = $request->accommodationType ?? 'khách sạn';
        $accommodationArea = $request->accommodationArea
            ? "Người dùng yêu cầu: \"{$request->accommodationArea}\" — PHẢI dùng đúng tên/địa điểm này nếu có thật, không được thay thế bằng nơi khác"
            : 'Người dùng không chỉ định — AI tự đề xuất 1 chỗ ở nổi tiếng, có thật, dễ tìm trên Google Maps, phù hợp ngân sách';
        $arrivalTime       = $request->arrivalTime ?? '14:00';

        $budgetPerDayTotal       = round($request->budget / max(1, $request->durationDays));
        $budgetPerDayPerPerson   = round($budgetPerDayTotal / max(1, $request->numPeople));
        $accommodationBudgetPerNight = match ($accommodationType) {
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

        $origin     = $request->origin ? "Xuất phát từ: {$request->origin}" : '';
        $travelType = match ($request->travelType) {
            'solo'   => 'Du lịch một mình — ưu tiên hoạt động tự do, linh hoạt, hostel/cafe gặp gỡ người mới',
            'couple' => 'Cặp đôi — ưu tiên không khí lãng mạn, nhà hàng view đẹp, hoạt động chung',
            'family' => 'Gia đình có trẻ em — ưu tiên hoạt động an toàn, phù hợp trẻ nhỏ, tránh leo trèo nguy hiểm, nghỉ sớm',
            'group'  => 'Nhóm bạn — ưu tiên hoạt động vui nhộn, nightlife, ăn uống đông người, BBQ/buffet',
            default  => '',
        };

        $budgetMinTotal = round($budgetPerDayTotal * 0.85);
        $budgetMaxTotal = round($budgetPerDayTotal * 1.15);
        $mealExampleCost = $request->numPeople * 100000;

        $schema = '{"days":[{"date":"YYYY-MM-DD","weather":{"summary":"","icon":"01d","temperature_high":0,"temperature_low":0,"rain_probability":0},"activities":[{"time":"HH:MM","title":"","description":"","place_name":"","place_type":"food|attraction|hotel|cafe|transport|nightlife|other","estimated_cost":0,"duration_minutes":0,"transport_to_next":"","distance_to_next_km":0,"latitude":0.0,"longitude":0.0}]}]}';

        return <<<PROMPT
Bạn là chuyên gia lập kế hoạch du lịch Việt Nam. Hãy tạo lịch trình chi tiết cho chuyến đi sau:

Điểm đến: {$request->destination}
{$origin}
Thời gian: {$request->durationDays} ngày, bắt đầu {$request->startDate}
Ngân sách: {$request->budget} VND cho {$request->numPeople} người (tức ~{$budgetPerDayPerPerson} VND/người/ngày)
Phương tiện di chuyển đến nơi: {$transportMode}
Giờ đến nơi ngày đầu: {$arrivalTime}
Loại chỗ ở: {$accommodationType} ({$accommodationArea})
Loại chuyến đi: {$travelType}
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
2. Chi phí check-in = ~{$accommodationBudgetPerNight} VND/người/đêm × {$request->numPeople} người (estimated_cost = tổng cho cả nhóm).
3. Mỗi ngày: Nhóm hoạt động theo khu vực địa lý gần chỗ ở — tối thiểu hóa di chuyển.
4. Tính distance_to_next_km từ tọa độ thực tế của từng địa điểm đến địa điểm tiếp theo.
5. Cuối mỗi ngày: Hoạt động cuối nên là ăn tối gần khách sạn hoặc về khách sạn.
6. Ngày cuối: Thêm "Check-out" buổi sáng (estimated_cost=0) trước khi ra sân bay/bến xe.

QUY TẮC NGÂN SÁCH — BẮT BUỘC TUYỆT ĐỐI:
- estimated_cost trong mỗi activity là số tiền THỰC TẾ cho TẤT CẢ {$request->numPeople} người (không phải/người)
- Tổng estimated_cost MỖI NGÀY phải nằm trong khoảng {$budgetMinTotal} – {$budgetMaxTotal} VND
- KHÔNG được để tổng ngày dưới {$budgetMinTotal} VND — đây là lỗi nghiêm trọng
- Ví dụ: bữa ăn {$request->numPeople} người × 100,000/người = {$mealExampleCost} VND trong estimated_cost
- Phân bổ mỗi ngày: lưu trú ~30-40%, ăn uống ~30%, tham quan ~20%, di chuyển ~10%

QUY TẮC TITLE & DESCRIPTION — BẮT BUỘC:
- title: Phải cụ thể, bao gồm tên địa điểm. KHÔNG được chỉ viết "Ăn trưa" hay "Tham quan".
  + Ăn uống: "Ăn [bữa] tại [tên quán cụ thể]" — ví dụ: "Ăn trưa tại Cơm Gà Bà Lan"
  + Cafe: "Cà phê tại [tên quán]" — ví dụ: "Cà phê sáng tại The Married Beans"
  + Tham quan: "Khám phá [tên địa điểm]" — ví dụ: "Khám phá Hồ Xuân Hương"
  + Lưu trú: "Check-in [tên khách sạn đầy đủ]"
  + Di chuyển: "Di chuyển đến [điểm đến]"
- description: 80–150 ký tự, MÔ TẢ CỤ THỂ:
  + Ăn uống: Tên 2-3 món đặc trưng, hương vị hoặc điểm nổi bật của quán
  + Cafe: Không gian, đồ uống signature, phong cách hoặc view
  + Tham quan: 2-3 hoạt động chính có thể làm tại đó, cảnh quan nổi bật
  + Lưu trú: Tiện nghi, vị trí, phong cách chỗ ở
  + Di chuyển: Phương tiện, thời gian ước tính, lưu ý hành trình

Yêu cầu khác:
- Mỗi ngày có 5-8 hoạt động hợp lý
- Ưu tiên hoạt động ngoài trời vào khung giờ không mưa
- Return only valid JSON. Do not use markdown. Do not wrap response in code blocks. Do not explain anything.

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
        $preferences       = implode(', ', $request->preferences) ?: 'Không có';
        $transportMode     = $request->transportMode ?? 'Không xác định';
        $notes             = $request->notes ?? 'Không có';
        $totalBudget       = $request->budget;
        $budgetPerDayTotal = round($totalBudget / max(1, $request->durationDays));
        $budgetPerDayPerPerson = round($budgetPerDayTotal / max(1, $request->numPeople));

        $accommodationType = $request->accommodationType ?? 'khách sạn';
        $accommodationBudgetPerNight = match ($accommodationType) {
            'hostel'   => round($budgetPerDayPerPerson * 0.15),
            'homestay' => round($budgetPerDayPerPerson * 0.25),
            'hotel'    => round($budgetPerDayPerPerson * 0.35),
            'resort'   => round($budgetPerDayPerPerson * 0.50),
            'airbnb'   => round($budgetPerDayPerPerson * 0.30),
            'villa'    => round($budgetPerDayPerPerson * 0.55),
            default    => round($budgetPerDayPerPerson * 0.30),
        };
        $budgetForActivities = $budgetPerDayTotal - ($accommodationBudgetPerNight * $request->numPeople);

        $accommodationArea = $request->accommodationArea
            ? "Người dùng yêu cầu: \"{$request->accommodationArea}\" — PHẢI dùng đúng tên/địa điểm này nếu có thật"
            : 'Người dùng không chỉ định — AI tự đề xuất 1 chỗ ở nổi tiếng, có thật, dễ tìm trên Google Maps';
        $arrivalTime = $request->arrivalTime ?? '14:00';

        $weatherSummary = 'Không có dữ liệu thời tiết';
        if (! empty($weatherData)) {
            $weatherSummary = json_encode($weatherData, JSON_UNESCAPED_UNICODE);
        }

        $visitedSection = '';
        if (! empty($visitedPlaces)) {
            $list           = implode(', ', $visitedPlaces);
            $visitedSection = "\nĐịa điểm đã đến ở các ngày trước (KHÔNG lặp lại): {$list}";
        }

        $travelTypeHint = match ($request->travelType) {
            'solo'   => 'Du lịch một mình — ưu tiên hoạt động tự do, linh hoạt, cafe gặp gỡ người mới',
            'couple' => 'Cặp đôi — ưu tiên không khí lãng mạn, nhà hàng view đẹp, hoạt động chung',
            'family' => 'Gia đình có trẻ em — ưu tiên an toàn, phù hợp trẻ nhỏ, nghỉ sớm',
            'group'  => 'Nhóm bạn — ưu tiên vui nhộn, nightlife, ăn uống đông người',
            default  => '',
        };
        $originHint = $request->origin ? "Xuất phát từ: {$request->origin}" : '';

        // Day-specific instructions
        $daySpecific = '';
        if ($dayNumber === 1) {
            $daySpecific = <<<DAY1

NGÀY ĐẦU TIÊN — QUY TẮC BẮT BUỘC:
- Hoạt động ĐẦU TIÊN phải là "Check-in [tên {$accommodationType} cụ thể]" lúc {$arrivalTime} với place_type="hotel".
- {$accommodationArea}.
- Chi phí check-in = {$accommodationBudgetPerNight} VND/người × {$request->numPeople} người.
- Tên chỗ ở phải là tên THẬT, NỔI TIẾNG, DỄ TÌM TRÊN GOOGLE MAPS với tọa độ chính xác.
DAY1;
        } elseif ($dayNumber === $request->durationDays) {
            $daySpecific = <<<LASTDAY

NGÀY CUỐI — QUY TẮC BẮT BUỘC:
- Hoạt động ĐẦU TIÊN phải là "Check-out khách sạn" vào buổi sáng (~10:00) với place_type="hotel", estimated_cost=0.
- Hoạt động cuối cùng là di chuyển ra sân bay/bến xe với place_type="transport".
LASTDAY;
        } else {
            $daySpecific = <<<MIDDAY

NGÀY GIỮA CHUYẾN:
- Xuất phát từ khách sạn/chỗ ở.
- Nhóm các địa điểm theo khu vực địa lý để tối thiểu hóa di chuyển.
- Hoạt động cuối ngày nên là ăn tối hoặc về gần khu vực khách sạn.
MIDDAY;
        }

        $budgetMin = round($budgetPerDayTotal * 0.85);
        $budgetMax = round($budgetPerDayTotal * 1.15);
        $mealExampleSingle = $request->numPeople * 120000;

        // Ví dụ phân bổ cụ thể để AI dễ hình dung
        $mealBudgetTotal    = round($budgetForActivities * 0.45); // ăn uống ~45% phần còn lại
        $attractionBudget   = round($budgetForActivities * 0.30); // tham quan ~30%
        $transportBudget    = round($budgetForActivities * 0.25); // di chuyển ~25%

        $schema = '{"weather":{"summary":"","icon":"01d","temperature_high":0,"temperature_low":0,"rain_probability":0},"activities":[{"time":"HH:MM","title":"","description":"","place_name":"","place_type":"food|attraction|hotel|cafe|transport|other","estimated_cost":0,"duration_minutes":0,"transport_to_next":"","distance_to_next_km":0,"latitude":0.0,"longitude":0.0}]}';

        return <<<PROMPT
Bạn là một người lập kế hoạch du lịch địa phương giàu kinh nghiệm.
Tạo lịch trình du lịch thực tế, thú vị và tự nhiên cho 1 ngày cụ thể.

NGUYÊN TẮC CHUNG:
- Tránh lên lịch quá nhiều hoạt động ăn uống liên tiếp
- Giữ khoảng cách ít nhất 2-3 giờ giữa các bữa ăn chính
- Nhóm địa điểm gần nhau, tránh di chuyển ngoằn ngoèo
- Kết hợp tự nhiên: ăn uống, cafe, mua sắm, tham quan, cuộc sống về đêm
- Tọa độ latitude/longitude phải CHÍNH XÁC cho từng địa điểm thực tế
- Chỉ dùng địa điểm NỔI TIẾNG, CÓ THẬT, DỄ TÌM TRÊN GOOGLE MAPS
{$daySpecific}

THÔNG TIN CHUYẾN ĐI:
Điểm đến: {$request->destination}
{$originHint}
Ngày: {$dayNumber}/{$request->durationDays} (ngày {$date})
Loại chuyến đi: {$travelTypeHint}
Loại chỗ ở: {$accommodationType} ({$accommodationArea})
Tổng ngân sách cả chuyến: {$totalBudget} VND cho {$request->numPeople} người ({$request->numPeople} người)
Ngân sách ngày này: {$budgetPerDayTotal} VND (cho {$request->numPeople} người, tức ~{$budgetPerDayPerPerson} VND/người)
  - Lưu trú: {$accommodationBudgetPerNight} VND/người/đêm × {$request->numPeople} người
  - Ăn uống (gộp tất cả bữa, {$request->numPeople} người): ~{$mealBudgetTotal} VND
  - Tham quan/vé vào cửa ({$request->numPeople} người): ~{$attractionBudget} VND
  - Di chuyển nội địa ({$request->numPeople} người): ~{$transportBudget} VND
Số người: {$request->numPeople}
Phương tiện: {$transportMode}
Sở thích: {$preferences}
Ghi chú: {$notes}
Thời tiết ngày này: {$weatherSummary}{$visitedSection}

QUY TẮC NGÂN SÁCH — BẮT BUỘC TUYỆT ĐỐI:
- estimated_cost trong mỗi activity là số tiền THỰC TẾ cho TẤT CẢ {$request->numPeople} người (không phải/người)
- Tổng tất cả estimated_cost trong ngày PHẢI nằm trong khoảng {$budgetMin} – {$budgetMax} VND
- KHÔNG được để tổng dưới {$budgetMin} VND — đây là lỗi nghiêm trọng
- Ví dụ: nếu ăn trưa cho {$request->numPeople} người tốn 120,000 VND/người thì estimated_cost = {$request->numPeople} × 120,000 = {$mealExampleSingle} VND

QUY TẮC TITLE & DESCRIPTION — BẮT BUỘC:
- title: Phải cụ thể, bao gồm tên địa điểm. KHÔNG được chỉ viết "Ăn trưa" hay "Tham quan".
  + Ăn uống: "Ăn trưa tại [tên quán cụ thể]" — ví dụ: "Ăn trưa tại Cơm Gà Bà Lan"
  + Cafe: "Cà phê sáng tại [tên quán]" — ví dụ: "Cà phê sáng tại The Married Beans"
  + Tham quan: "Khám phá [tên địa điểm]" — ví dụ: "Khám phá Hồ Xuân Hương"
  + Lưu trú: "Check-in [tên khách sạn]" — ví dụ: "Check-in TTC Hotel Ngọc Lan"
  + Di chuyển: "Di chuyển đến [điểm đến]" — ví dụ: "Di chuyển đến sân bay Liên Khương"
- description: 80–150 ký tự, MÔ TẢ CỤ THỂ nội dung hoạt động:
  + Ăn uống: Kể tên 2-3 món đặc trưng, hương vị, điểm nổi bật của quán
  + Cafe: Không gian, đồ uống nổi bật, view hoặc phong cách quán
  + Tham quan: 2-3 hoạt động chính có thể làm tại đó, cảnh quan đặc sắc
  + Lưu trú: Tiện nghi nổi bật, vị trí, phong cách chỗ ở
  + Di chuyển: Phương tiện, thời gian di chuyển, lưu ý

QUY TẮC KHÁC:
- Tạo 5-7 hoạt động hợp lý từ sáng đến tối
- Return only valid JSON. Do not use markdown. Do not wrap response in code blocks. Do not explain anything.

Schema JSON bắt buộc (chỉ 1 ngày):
{$schema}
PROMPT;
    }


    /**
     * Gọi Groq API với retry logic và model fallback.
     * Fallback: llama-3.3-70b-versatile → qwen/qwen3-32b
     *
     * @param  string|null $expectKey  Key bắt buộc trong JSON trả về, null = không kiểm tra
     * @return array<string, mixed>
     */
    private function callWithRetry(string $prompt, ?string $expectKey = 'days'): array
    {
        $configModel = config('services.groq.model', self::MODELS[0]);
        $apiKey      = config('services.groq.api_key');

        // Build model list: configured model first, then remaining fallbacks
        $models = array_unique(array_merge([$configModel], self::MODELS));

        $lastErr = null;

        foreach ($models as $modelIndex => $model) {
            $delays = [0, 2, 5];

            for ($attempt = 0; $attempt < 3; $attempt++) {
                if ($delays[$attempt] > 0) {
                    sleep($delays[$attempt]);
                }

                try {
                    $response = Http::withToken($apiKey)
                        ->timeout(120)
                        ->post(self::ENDPOINT, [
                            'model'       => $model,
                            'messages'    => [
                                [
                                    'role'    => 'user',
                                    'content' => $prompt,
                                ],
                            ],
                            'temperature' => 0.7,
                        ]);

                    if (! $response->successful()) {
                        $status = $response->status();
                        $body   = $response->body();

                        // 429 rate limit hoặc 503 → thử model tiếp theo
                        if (in_array($status, [429, 503, 529])) {
                            Log::warning("GroqService: model {$model} returned {$status}, trying next model", [
                                'attempt' => $attempt + 1,
                                'model'   => $model,
                            ]);
                            break; // thoát vòng attempt, chuyển sang model tiếp theo
                        }

                        throw new \RuntimeException("Groq API error: {$status} {$body}");
                    }

                    $text = $response->json('choices.0.message.content', '');
                    $data = $this->extractJson($text);

                    if ($expectKey !== null && ! isset($data[$expectKey])) {
                        throw new \RuntimeException("Invalid JSON response: missing \"{$expectKey}\" key");
                    }

                    if ($modelIndex > 0) {
                        Log::info("GroqService: succeeded with fallback model {$model}");
                    }

                    return $data;

                } catch (\Throwable $e) {
                    $lastErr = $e;
                    Log::error("GroqService model={$model} attempt=" . ($attempt + 1) . ' failed', [
                        'error'   => $e->getMessage(),
                        'model'   => $model,
                        'attempt' => $attempt + 1,
                    ]);
                }
            }

            Log::warning("GroqService: all attempts failed for model {$model}, trying fallback");
        }

        throw new \RuntimeException('GroqService failed after all models: ' . $lastErr?->getMessage());
    }

    /**
     * Trích xuất JSON từ text response của AI.
     *
     * @return array<string, mixed>
     */
    private function extractJson(string $text): array
    {
        $text = trim($text);

        // Strip markdown code fences nếu AI vẫn trả về
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```\s*$/i', '', $text);
        $text = trim($text);

        // Cắt phần text trước JSON object
        $start = strpos($text, '{');
        if ($start === false) {
            throw new \RuntimeException('No valid JSON object found in Groq response');
        }
        $text = substr($text, $start);

        // Xóa control characters gây lỗi json_decode
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text);

        try {
            return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Thử repair JSON bị cắt
            $repaired = $this->repairTruncatedJson($text);
            if ($repaired !== $text) {
                try {
                    return json_decode($repaired, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    // fall through
                }
            }

            Log::error('GroqService: JSON parse failed', [
                'error' => $e->getMessage(),
                'raw'   => mb_substr($text, 0, 3000),
            ]);

            throw new \RuntimeException('Failed to parse JSON from Groq response: ' . $e->getMessage());
        }
    }

    /**
     * Cố gắng phục hồi JSON bị cắt giữa chừng.
     */
    private function repairTruncatedJson(string $text): string
    {
        $text = trim($text);

        $decoded = json_decode($text, true);
        if ($decoded !== null) {
            return $text;
        }

        $lastCompleteActivity = strrpos($text, '},');
        if ($lastCompleteActivity === false) {
            $lastCompleteActivity = strrpos($text, '}');
        }

        if ($lastCompleteActivity !== false) {
            $text = substr($text, 0, $lastCompleteActivity + 1);
        }

        $opens  = substr_count($text, '[') - substr_count($text, ']');
        $braces = substr_count($text, '{') - substr_count($text, '}');

        for ($i = 0; $i < max(0, $opens); $i++)  $text .= ']';
        for ($i = 0; $i < max(0, $braces); $i++) $text .= '}';

        return $text;
    }
}
