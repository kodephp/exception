<?php

declare(strict_types=1);

namespace Kode\Exception\Handler;

use Kode\Exception\ExceptionInterface;
use Kode\Exception\RuntimeException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * 运行时异常处理器
 * 专门处理 RuntimeException 类型异常
 */
class RuntimeExceptionHandler implements ExceptionHandlerInterface
{
    /** @var LoggerInterface 日志记录器 */
    protected LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function handle(Throwable $exception): bool
    {
        if ($exception instanceof RuntimeException) {
            $level = $exception->isRecoverable() ? 'error' : 'critical';

            $this->logger->log($level, '运行时异常', [
                'message' => $exception->getMessage(),
                'recoverable' => $exception->isRecoverable(),
                'suggestion' => $exception->getSuggestion(),
                'trace_id' => $exception instanceof ExceptionInterface ? $exception->getTraceId() : null,
                'context' => $exception instanceof ExceptionInterface ? $exception->getContext() : [],
            ]);

            return true;
        }

        return false;
    }

    public function getPriority(): int
    {
        return 80;
    }
}