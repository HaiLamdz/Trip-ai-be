<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UnsplashService
{
    private string $accessKey;
    private string $endpoint;

    public function __construct()
    {
        $this->accessKey = (string) config('services.unsplash.access_key', '');
        $this->endpoint  = (string) config('services.unsplash.endpoint', 'https://api.unsplash.com');
    }

    /**
     * Tìm ảnh đại diện dựa trên search query (thường do AI sinh ra).
     * Trả về URL ảnh regular (~1080px wide) hoặc null nếu thất bại.
     *
     * @param string $query  Search query tiếng Anh, ví dụ "Ha Long Bay emerald waters karst"
     */
    public function getPhotoByQuery(string $query): ?string
    {
        if (empty($this->accessKey)) {
            Log::warning('UnsplashService: UNSPLASH_ACCESS_KEY is not set');
            return null;
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders(['Authorization' => "Client-ID {$this->accessKey}"])
                ->get("{$this->endpoint}/photos/random", [
                    'query'          => $query,
                    'orientation'    => 'landscape',
                    'content_filter' => 'high',
                ]);

            if (! $response->successful()) {
                Log::warning('UnsplashService: API error', [
                    'status' => $response->status(),
                    'query'  => $query,
                ]);
                return null;
            }

            $url = $response->json('urls.regular');

            return $url ?: null;

        } catch (\Throwable $e) {
            Log::warning('UnsplashService: request failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Fallback: tìm ảnh dựa trên tên điểm đến.
     */
    public function getTravelPhoto(string $destination): ?string
    {
        return $this->getPhotoByQuery("travel {$destination} landscape scenery");
    }
}
