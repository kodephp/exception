<?php

declare(strict_types=1);

namespace Kode\Exception\Handler;

use Kode\Exception\KodeException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * HTTP 异常处理器
 * 专门处理 KodeException 中 type=http 的异常
 */
class HttpExceptionHandler implements ExceptionHandlerInterface
{
    protected LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function handle(Throwable $exception): bool
    {
        if ($exception instanceof KodeException && $exception->getErrorType() === KodeException::TYPE_HTTP) {
            $this->logger->warning('HTTP 异常', [
                'code' => $exception->getErrorCode(),
                'msg' => $exception->getErrorMsg(),
                'trace_id' => $exception->getTraceId(),
            ]);
            return true;
        }

        return false;
    }

    public function getPriority(): int
    {
        return 100;
    }
}