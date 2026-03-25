<?php

declare(strict_types=1);

namespace Kode\Exception\Coroutine;

use Error;
use Kode\ExceptionManager;
use Kode\Exception\ExceptionInterface;
use Kode\Exception\HttpException;
use Kode\Exception\RuntimeException;
use Psr\Log\LoggerInterface;
use Throwable;
use TypeError;

/**
 * Worker 进程异常保护器
 * 防止异常崩溃整个 Worker 进程，支持多进程环境下的异常恢复
 */
class WorkerExceptionGuard
{
    /** @var LoggerInterface 日志记录器 */
    protected LoggerInterface $logger;
    /** 异常管理器 */
    protected ExceptionManager $exceptionManager;
    /** 是否 Worker 环境 */
    protected bool $isWorker;
    /** 最大重试次数 */
    protected int $maxRetries;
    /** 当前重试计数 */
    protected int $retryCount = 0;
    /** 失败的协程列表 */
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

    /** 检测 Worker 环境 */
    protected function detectWorkerEnvironment(): bool
    {
        return (
            function_exists('swoole_server_getswmaster') ||
            function_exists('workerman_getpid') ||
            getmypid() !== (int)getenv('PPID') ||
            isset($_SERVER['WORKER_MODE'])
        );
    }

    /** 保护执行回调 */
    public function guard(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            return $this->handleWorkerException($e);
        }
    }

    /** 保护协程执行 */
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

    /** 处理 Worker 异常 */
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

    /** 处理协程异常 */
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

        if ($exception instanceof RuntimeException && !$exception->isRecoverable()) {
            $this->logger->critical('不可恢复的协程异常', [
                'coroutine_id' => $coroutineId,
                'exception' => $exception->getMessage(),
                'suggestion' => $exception->getSuggestion(),
            ]);

            $this->notifyWorkerSupervisor($coroutineId, $exception);
            return null;
        }

        $this->logException($exception, ['coroutine_id' => $coroutineId]);
        return $this->returnErrorResponse($exception);
    }

    /** 是否为关键异常 */
    protected function isCriticalException(Throwable $exception): bool
    {
        if ($exception instanceof ExceptionInterface) {
            return $exception instanceof RuntimeException && !$exception->isRecoverable();
        }

        return $exception instanceof \Error;
    }

    /** 处理关键异常 */
    protected function handleCriticalException(Throwable $exception): void
    {
        $this->logger->emergency('Worker 中的关键异常', [
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ]);

        $this->notifyWorkerSupervisor('main', $exception);

        if ($this->shouldExitWorker($exception)) {
            $this->exitWorker(1);
        }
    }

    /** 是否应该重试 */
    protected function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof ExceptionInterface) {
            if ($exception instanceof HttpException) {
                return in_array($exception->getHttpStatusCode(), [502, 503, 504]);
            }
        }

        return false;
    }

    /** 重试或失败 */
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

    /** 返回错误响应 */
    protected function returnErrorResponse(Throwable $exception): mixed
    {
        return $this->exceptionManager->format($exception);
    }

    /** 记录异常日志 */
    protected function logException(Throwable $exception, array $extraContext = []): void
    {
        $context = array_merge([
            'exception_class' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ], $extraContext);

        if ($exception instanceof ExceptionInterface) {
            $context['error_code'] = $exception->getErrorCode();
            $context['trace_id'] = $exception->getTraceId();
            $context['context'] = $exception->getContext();

            $this->logger->error('异常已记录', $context);
        } else {
            $this->logger->critical('未知异常', $context);
        }
    }

    /** 通知 Worker 监督者 */
    protected function notifyWorkerSupervisor(string $coroutineId, Throwable $exception): void
    {
        if (function_exists('swoole_server_getswmaster')) {
            $server = swoole_server_getswmaster();
            if ($server) {
                $server->notifyWorkerError($coroutineId, $exception);
            }
        }
    }

    /** 是否应该退出 Worker */
    protected function shouldExitWorker(Throwable $exception): bool
    {
        return $exception instanceof \Error || $exception instanceof \TypeError;
    }

    /** 退出 Worker */
    protected function exitWorker(int $code): void
    {
        $this->logger->info('Worker 退出', ['code' => $code]);
        exit($code);
    }

    /** 获取失败的协程列表 */
    public function getFailedCoroutines(): array
    {
        return $this->failedCoroutines;
    }

    /** 清除失败的协程列表 */
    public function clearFailedCoroutines(): void
    {
        $this->failedCoroutines = [];
    }

    /** 是否为 Worker 环境 */
    public function isWorkerEnvironment(): bool
    {
        return $this->isWorker;
    }
}