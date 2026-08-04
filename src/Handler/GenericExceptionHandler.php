<?php

declare(strict_types=1);

namespace Kode\Exception\Handler;

use Kode\Exception\KodeException;
use Kode\Exception\Support\Runtime;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

/**
 * 兜底异常处理器
 *
 * 优先级最低，处理所有未被前序处理器消费的异常。
 */
final class GenericExceptionHandler implements ExceptionHandlerInterface
{
    public const int PRIORITY = -100;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly bool $isProduction = false,
    ) {
    }

    #[Override]
    public function handle(Throwable $exception): bool
    {
        if ($exception instanceof KodeException) {
            $this->logger->log($exception->getLogLevel(), $exception->getErrorMsg(), [
                'code' => $exception->getErrorCode(),
                'type' => $exception->getErrorType(),
                'trace_id' => $exception->getTraceId(),
                'span_id' => $exception->getSpanId(),
                'context' => $exception->getErrorContext(),
                'location' => $exception->getLocation(),
                'runtime' => Runtime::name(),
            ]);

            return true;
        }

        $context = [
            'class' => $exception::class,
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'runtime' => Runtime::name(),
        ];

        if (!$this->isProduction) {
            $context['trace'] = $exception->getTraceAsString();
        }

        $this->logger->log(LogLevel::CRITICAL, $exception->getMessage(), $context);

        return true;
    }

    #[Override]
    public function getPriority(): int
    {
        return self::PRIORITY;
    }
}
