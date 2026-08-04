<?php

declare(strict_types=1);

namespace Kode\Exception\Handler;

use Kode\Exception\Enum\ErrorType;
use Kode\Exception\KodeException;
use Kode\Exception\Support\Runtime;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

/**
 * 运行时异常处理器
 *
 * 仅处理 type=runtime 的 KodeException；可恢复记 error，不可恢复记 critical。
 */
final class RuntimeExceptionHandler implements ExceptionHandlerInterface
{
    public const int PRIORITY = 80;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Override]
    public function handle(Throwable $exception): bool
    {
        if (!$exception instanceof KodeException || $exception->getType() !== ErrorType::Runtime) {
            return false;
        }

        $this->logger->log(
            $exception->isRecoverable() ? LogLevel::ERROR : LogLevel::CRITICAL,
            '运行时异常',
            [
                'code' => $exception->getErrorCode(),
                'msg' => $exception->getErrorMsg(),
                'trace_id' => $exception->getTraceId(),
                'recoverable' => $exception->isRecoverable(),
                'suggestion' => $exception->getSuggestion(),
                'runtime' => Runtime::name(),
                'coroutine_id' => Runtime::coroutineId(),
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
