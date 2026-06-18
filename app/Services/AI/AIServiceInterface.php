<?php

namespace App\Services\AI;

use App\Services\AI\DTOs\ChatResponseDTO;
use App\Services\AI\DTOs\TimelineDTO;
use App\Services\AI\DTOs\TripDayDTO;
use App\Services\AI\DTOs\TripGenerationRequest;

interface AIServiceInterface
{
    /**
     * Sinh lịch trình từ thông tin chuyến đi (toàn bộ trip trong 1 request).
     */
    public function generateTimeline(TripGenerationRequest $request): TimelineDTO;

    /**
     * Sinh lịch trình cho đúng 1 ngày cụ thể.
     *
     * @param array<string, mixed> $weatherData   Dữ liệu thời tiết của ngày đó
     * @param string[]             $visitedPlaces Danh sách tên địa điểm đã xuất hiện ở các ngày trước
     */
    public function generateDayTimeline(
        TripGenerationRequest $request,
        int $dayNumber,
        string $date,
        array $weatherData = [],
        array $visitedPlaces = [],
    ): TripDayDTO;

    /**
     * Xử lý tin nhắn chat để chỉnh sửa lịch trình.
     *
     * @param array<array{role: string, content: string}> $conversationHistory
     */
    public function processChat(
        string $userMessage,
        TimelineDTO $currentTimeline,
        array $conversationHistory
    ): ChatResponseDTO;

    /**
     * Kiểm tra kết nối và quota.
     */
    public function healthCheck(): bool;

    /**
     * Sinh danh sách đồ cần mang dựa trên lịch trình.
     *
     * @return array<string, array<string, mixed>>  Grouped by category
     */
    public function generatePackingList(\App\Models\Trip $trip): array;

    /**
     * Sinh search query tiếng Anh để tìm ảnh đại diện cho chuyến đi.
     * Trả về chuỗi query ngắn (5-8 từ) phù hợp dùng với Unsplash API.
     */
    public function generateCoverImageQuery(\App\Models\Trip $trip): string;
}
