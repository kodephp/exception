<?php

declare(strict_types=1);

namespace Kode\Exception\Handler;

use Kode\Exception\KodeException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * 运行时异常处理器
 * 专门处理 KodeException 中 type=runtime 的异常
 */
class RuntimeExceptionHandler implements ExceptionHandlerInterface
{
    protected LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function handle(Throwable $exception): bool
    {
        if ($exception instanceof KodeException && $exception->getErrorType() === KodeException::TYPE_RUNTIME) {
            $context = $exception->getErrorContext();
            $level = isset($context['suggestion']) ? 'error' : 'critical';

            $this->logger->log($level, '运行时异常', [
                'code' => $exception->getErrorCode(),
                'msg' => $exception->getErrorMsg(),
                'trace_id' => $exception->getTraceId(),
                'context' => $context,
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