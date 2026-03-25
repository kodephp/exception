<?php

declare(strict_types=1);

namespace Kode\Exception\Coroutine;

use Kode\Exception\ExceptionManager;
use Kode\Exception\KodeException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Worker 进程异常保护器
 * 防止异常崩溃整个 Worker 进程，支持多进程环境下的异常恢复
 */
class WorkerExceptionGuard
{
    protected LoggerInterface $logger;
    protected ExceptionManager $exceptionManager;
    protected bool $isWorker;
    protected int $maxRetries;
    protected int $retryCount = 0;
    protected array $failedCoroutines = [];

    public function __construct(
        LoggerInterface $logger,
        ?ExceptionManager $exceptionManager = null,
        int $maxRetries = 3
    ) {
        $this->logger = $logger;
        $this->exceptionManager = $exceptionManager ?? ExceptionManager::getInstance();
        $this->isWorker = $this->detectWorkerEnvironment();
        $this->maxRetries = $maxRetries;
    }

    protected function detectWorkerEnvironment(): bool
    {
        return (
            function_exists('swoole_server_getswmaster') ||
            function_exists('workerman_getpid') ||
            getmypid() !== (int)getenv('PPID') ||
            isset($_SERVER['WORKER_MODE'])
        );
    }

    public function guard(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            return $this->handleWorkerException($e);
        }
    }

    public function guardCoroutine(callable $callback, string $coroutineId): mixed
    {
        $context = new CoroutineExceptionContext();

        try {
            CoroutineExceptionHandler::setCurrentContext($context);
            return $callback();
        } catch (Throwable $e) {
            return $this->handleCoroutineException($e, $coroutineId, $context);
        } finally {
            CoroutineExceptionHandler::clearContext();
        }
    }

    protected function handleWorkerException(Throwable $exception): mixed
    {
        $this->logException($exception);

        if ($this->isCriticalException($exception)) {
            $this->handleCriticalException($exception);
            return null;
        }

        if ($this->shouldRetry($exception)) {
            return $this->retryOrFail($exception);
        }

        return $this->returnErrorResponse($exception);
    }

    protected function handleCoroutineException(
        Throwable $exception,
        string $coroutineId,
        CoroutineExceptionContext $context
    ): mixed {
        $this->failedCoroutines[$coroutineId] = [
            'exception' => $exception,
            'context' => $context,
            'timestamp' => time(),
        ];

        if ($exception instanceof KodeException && !$exception->isRecoverable()) {
            $ctx = $exception->getErrorContext();
            $this->logger->critical('不可恢复的协程异常', [
                'coroutine_id' => $coroutineId,
                'exception' => $exception->getMessage(),
                'suggestion' => $ctx['suggestion'] ?? '',
            ]);

            $this->notifyWorkerSupervisor($coroutineId, $exception);
            return null;
        }

        $this->logException($exception, ['coroutine_id' => $coroutineId]);
        return $this->returnErrorResponse($exception);
    }

    protected function isCriticalException(Throwable $exception): bool
    {
        if ($exception instanceof \Error) {
            return true;
        }

        if ($exception instanceof \TypeError) {
            return true;
        }

        return false;
    }

    protected function handleCriticalException(Throwable $exception): void
    {
        $this->logger->emergency('Worker 中的关键异常', [
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ]);

        $this->notifyWorkerSupervisor('main', $exception);

        if ($this->shouldExitWorker($exception)) {
            $this->exitWorker(1);
        }
    }

    protected function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof KodeException) {
            $code = $exception->getErrorCode();
            return in_array($code, [KodeException::CODE_SERVICE_UNAVAILABLE]);
        }

        return false;
    }

    protected function retryOrFail(Throwable $exception): mixed
    {
        $this->retryCount++;

        if ($this->retryCount < $this->maxRetries) {
            $this->logger->warning('异常后重试', [
                'attempt' => $this->retryCount,
                'max_retries' => $this->maxRetries,
                'exception' => $exception->getMessage(),
            ]);

            usleep(100000 * $this->retryCount);
            return null;
        }

        $this->logger->error('超过最大重试次数', [
            'attempts' => $this->retryCount,
            'exception' => $exception->getMessage(),
        ]);

        $this->retryCount = 0;
        return $this->returnErrorResponse($exception);
    }

    protected function returnErrorResponse(Throwable $exception): mixed
    {
        return $this->exceptionManager->format($exception);
    }

    protected function logException(Throwable $exception, array $extraContext = []): void
    {
        $context = array_merge([
            'exception_class' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ], $extraContext);

        if ($exception instanceof KodeException) {
            $context['code'] = $exception->getErrorCode();
            $context['trace_id'] = $exception->getTraceId();
            $context['context'] = $exception->getErrorContext();

            $this->logger->error('异常已记录', $context);
        } else {
            $this->logger->critical('未知异常', $context);
        }
    }

    protected function notifyWorkerSupervisor(string $coroutineId, Throwable $exception): void
    {
        if (function_exists('swoole_server_getswmaster')) {
            $server = swoole_server_getswmaster();
            if ($server) {
                $server->notifyWorkerError($coroutineId, $exception);
            }
        }
    }

    protected function shouldExitWorker(Throwable $exception): bool
    {
        return $exception instanceof \Error || $exception instanceof \TypeError;
    }

    protected function exitWorker(int $code): void
    {
        $this->logger->info('Worker 退出', ['code' => $code]);
        exit($code);
    }

    public function getFailedCoroutines(): array
    {
        return $this->failedCoroutines;
    }

    public function clearFailedCoroutines(): void
    {
        $this->failedCoroutines = [];
    }

    public function isWorkerEnvironment(): bool
    {
        return $this->isWorker;
    }
}