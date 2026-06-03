<?php

namespace App\Logging;

use App\Services\TelegramService;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

class TelegramHandler extends AbstractProcessingHandler
{
    private TelegramService $telegram;

    public function __construct(int|string|Level $level = Level::Error)
    {
        parent::__construct($level);
        $this->telegram = new TelegramService();
    }

    protected function write(LogRecord $record): void
    {
        // Kiểm tra môi trường có được phép gửi không
        $enabledEnvs = config('services.telegram.enabled_envs', ['production', 'staging']);
        if (!in_array(app()->environment(), $enabledEnvs, true)) {
            return;
        }

        $context = $record->context;

        // Nếu context có exception thì dùng sendException
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $this->telegram->sendException($context['exception'], $record->message);
            return;
        }

        $this->telegram->sendLog(
            $record->level->getName(),
            $record->message,
            $context
        );
    }
}

/**
 * Factory được gọi bởi Laravel logging system.
 * Khai báo trong config/logging.php với driver => 'custom'.
 */
class TelegramLogger
{
    public function __invoke(array $config): \Monolog\Logger
    {
        $level   = $config['level'] ?? 'error';
        $logger  = new \Monolog\Logger('telegram');
        $logger->pushHandler(new TelegramHandler(\Monolog\Level::fromName($level)));
        return $logger;
    }
}
