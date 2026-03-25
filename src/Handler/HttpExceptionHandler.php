<?php

declare(strict_types=1);

namespace Kode\Exception\Handler;

use Kode\Exception\ExceptionInterface;
use Kode\Exception\HttpException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * HTTP 异常处理器
 * 专门处理 HttpException 类型异常
 */
class HttpExceptionHandler implements ExceptionHandlerInterface
{
    /** @var LoggerInterface 日志记录器 */
    protected LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function handle(Throwable $exception): bool
    {
        if ($exception instanceof HttpException) {
            $this->logger->warning('HTTP 异常', [
                'status_code' => $exception->getHttpStatusCode(),
                'message' => $exception->getMessage(),
                'trace_id' => $exception instanceof ExceptionInterface ? $exception->getTraceId() : null,
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