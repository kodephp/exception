<?php

declare(strict_types=1);

namespace Kode\Exception\Handler;

use Kode\Exception\ExceptionInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * 通用异常处理器
 * 处理所有未被特殊处理器捕获的异常
 */
class GenericExceptionHandler implements ExceptionHandlerInterface
{
    /** @var LoggerInterface 日志记录器 */
    protected LoggerInterface $logger;
    /** 是否生产模式 */
    protected bool $isProduction;

    public function __construct(LoggerInterface $logger, bool $isProduction = false)
    {
        $this->logger = $logger;
        $this->isProduction = $isProduction;
    }

    public function handle(Throwable $exception): bool
    {
        if ($exception instanceof ExceptionInterface) {
            $this->logger->error('通用异常', [
                'error_code' => $exception->getErrorCode(),
                'message' => $exception->getMessage(),
                'trace_id' => $exception->getTraceId(),
                'context' => $exception->getContext(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $this->isProduction ? null : $exception->getTraceAsString(),
            ]);

            return true;
        }

        $this->logger->critical('未知异常', [
            'message' => $exception->getMessage(),
            'class' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $this->isProduction ? null : $exception->getTraceAsString(),
        ]);

        return true;
    }

    public function getPriority(): int
    {
        return -100;
    }
}