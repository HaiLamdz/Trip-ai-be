<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

class TelegramService
{
    private string $botToken;
    private string $chatId;
    private string $apiUrl;

    public function __construct()
    {
        $this->botToken = config('services.telegram.bot_token', '');
        $this->chatId   = config('services.telegram.chat_id', '');
        $this->apiUrl   = "https://api.telegram.org/bot{$this->botToken}";
    }

    /**
     * Gửi message thuần text tới Telegram.
     */
    public function sendMessage(string $message): bool
    {
        if (empty($this->botToken) || empty($this->chatId)) {
            return false;
        }

        try {
            $response = Http::timeout(5)->post("{$this->apiUrl}/sendMessage", [
                'chat_id'    => $this->chatId,
                'text'       => $message,
                'parse_mode' => 'HTML',
            ]);

            return $response->successful();
        } catch (Throwable) {
            // Không throw lại để tránh vòng lặp lỗi
            return false;
        }
    }

    /**
     * Gửi thông báo lỗi / exception có định dạng đẹp.
     */
    public function sendException(Throwable $e, ?string $context = null): bool
    {
        $env     = app()->environment();
        $appName = config('app.name', 'Laravel');
        $now     = now()->format('Y-m-d H:i:s');

        // Rút gọn file path cho gọn
        $file = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $e->getFile());

        $message  = "🚨 <b>[{$appName}] Exception – {$env}</b>\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "📌 <b>Type:</b> " . class_basename($e) . "\n";
        $message .= "💬 <b>Message:</b> " . htmlspecialchars(mb_substr($e->getMessage(), 0, 300)) . "\n";
        $message .= "📂 <b>File:</b> <code>{$file}</code>\n";
        $message .= "🔢 <b>Line:</b> {$e->getLine()}\n";
        $message .= "🕐 <b>Time:</b> {$now}\n";

        if ($context) {
            $message .= "📋 <b>Context:</b> " . htmlspecialchars(mb_substr($context, 0, 200)) . "\n";
        }

        // Stack trace – chỉ lấy 5 frame đầu
        $trace = collect($e->getTrace())
            ->take(5)
            ->map(function ($frame, $i) {
                $file     = isset($frame['file'])
                    ? str_replace(base_path() . DIRECTORY_SEPARATOR, '', $frame['file'])
                    : '[internal]';
                $line     = $frame['line'] ?? '?';
                $function = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
                return "  #{$i} {$file}:{$line} → {$function}()";
            })
            ->implode("\n");

        if ($trace) {
            $message .= "\n🔍 <b>Trace:</b>\n<pre>" . htmlspecialchars($trace) . "</pre>";
        }

        return $this->sendMessage($message);
    }

    /**
     * Gửi log level tùy chỉnh (info, warning, error…).
     */
    public function sendLog(string $level, string $message, array $context = []): bool
    {
        $icons = [
            'emergency' => '🆘',
            'alert'     => '🚨',
            'critical'  => '🔴',
            'error'     => '❌',
            'warning'   => '⚠️',
            'notice'    => '📢',
            'info'      => 'ℹ️',
            'debug'     => '🐛',
        ];

        $icon    = $icons[strtolower($level)] ?? '📝';
        $env     = app()->environment();
        $appName = config('app.name', 'Laravel');
        $now     = now()->format('Y-m-d H:i:s');

        $text  = "{$icon} <b>[{$appName}] " . strtoupper($level) . " – {$env}</b>\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━\n";
        $text .= "💬 " . htmlspecialchars(mb_substr($message, 0, 500)) . "\n";
        $text .= "🕐 {$now}";

        if (!empty($context)) {
            $contextStr = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $text .= "\n📋 <pre>" . htmlspecialchars(mb_substr($contextStr, 0, 400)) . "</pre>";
        }

        return $this->sendMessage($text);
    }
}
