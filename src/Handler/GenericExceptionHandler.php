<?php

declare(strict_types=1);

namespace Kode\Exception\Handler;

use Kode\Exception\KodeException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * 通用异常处理器
 * 处理所有未被特殊处理器捕获的异常
 */
class GenericExceptionHandler implements ExceptionHandlerInterface
{
    protected LoggerInterface $logger;
    protected bool $isProduction;

    public function __construct(LoggerInterface $logger, bool $isProduction = false)
    {
        $this->logger = $logger;
        $this->isProduction = $isProduction;
    }

    public function handle(Throwable $exception): bool
    {
        if ($exception instanceof KodeException) {
            $this->logger->error('异常', [
                'code' => $exception->getErrorCode(),
                'msg' => $exception->getErrorMsg(),
                'type' => $exception->getErrorType(),
                'trace_id' => $exception->getTraceId(),
                'context' => $exception->getErrorContext(),
                'location' => $exception->getLocation(),
            ]);

            return true;
        }

        $this->logger->critical('未知异常', [
            'msg' => $exception->getMessage(),
            'class' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ]);

        return true;
    }

    public function getPriority(): int
    {
        return -100;
    }
}