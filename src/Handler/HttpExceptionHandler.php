<?php

declare(strict_types=1);

namespace Kode\Exception\Handler;

use Kode\Exception\Enum\ErrorType;
use Kode\Exception\KodeException;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

/**
 * HTTP 异常处理器
 *
 * 仅处理 type=http 的 KodeException；5xx 记 error，4xx 记 warning。
 */
final class HttpExceptionHandler implements ExceptionHandlerInterface
{
    public const int PRIORITY = 100;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Override]
    public function handle(Throwable $exception): bool
    {
        if (!$exception instanceof KodeException || $exception->getType() !== ErrorType::Http) {
            return false;
        }

        $status = $exception->getHttpStatusCode();

        $this->logger->log(
            $status >= 500 ? LogLevel::ERROR : LogLevel::WARNING,
            'HTTP 异常',
            [
                'code' => $exception->getErrorCode(),
                'msg' => $exception->getErrorMsg(),
                'status' => $status,
                'trace_id' => $exception->getTraceId(),
                'span_id' => $exception->getSpanId(),
                'context' => $exception->getErrorContext(),
            ]
        );

        return true;
    }

    #[Override]
    public function getPriority(): int
    {
        return self::PRIORITY;
    }
}
