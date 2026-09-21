<?php

declare(strict_types=1);

namespace Kode\Exception\Coroutine;

use Kode\Exception\ExceptionManager;
use Kode\Exception\KodeException;
use Kode\Exception\Support\Runtime;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Worker 进程异常保护器
 * 防止异常崩溃整个 Worker 进程，支持多进程环境下的异常恢复
 */
class WorkerExceptionGuard
{
    /** 失败协程快照上限：常驻 worker 内无界累积会随请求量吃满内存 */
    public const int MAX_FAILED_COROUTINES = 100;

    protected LoggerInterface $logger;
    protected ExceptionManager $exceptionManager;
    protected bool $isWorker;
    protected int $maxRetries;
    protected int $retryCount = 0;
    protected array $failedCoroutines = [];
    /** @var (callable(string, Throwable): void)|null 主管进程通知钩子 */
    protected $supervisorNotifier = null;

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
        return Runtime::isWorker();
    }

    public function guard(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            return $this->handleWorkerException($e, $callback);
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

    protected function handleWorkerException(Throwable $exception, ?callable $callback = null): mixed
    {
        $this->logException($exception);

        if ($this->isCriticalException($exception)) {
            $this->handleCriticalException($exception);
            return null;
        }

        if ($this->shouldRetry($exception)) {
            return $this->retryOrFail($exception, $callback);
        }

        return $this->returnErrorResponse($exception);
    }

    protected function handleCoroutineException(
        Throwable $exception,
        string $coroutineId,
        CoroutineExceptionContext $context
    ): mixed {
        if (count($this->failedCoroutines) >= self::MAX_FAILED_COROUTINES) {
            unset($this->failedCoroutines[array_key_first($this->failedCoroutines)]);
        }

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

    protected function retryOrFail(Throwable $exception, ?callable $callback = null): mixed
    {
        if ($callback === null) {
            $this->logger->error('无可重试的调用体，直接返回错误响应', [
                'exception' => $exception->getMessage(),
            ]);
            $this->retryCount = 0;

            return $this->returnErrorResponse($exception);
        }

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            $this->retryCount = $attempt;
            $this->logger->warning('异常后重试', [
                'attempt' => $attempt,
                'max_retries' => $this->maxRetries,
                'exception' => $exception->getMessage(),
            ]);

            $this->backoff($attempt);

            try {
                $result = $callback();
                $this->retryCount = 0;

                return $result;
            } catch (Throwable $retryFailure) {
                if ($this->isCriticalException($retryFailure) || !$this->shouldRetry($retryFailure)) {
                    $this->retryCount = 0;

                    return $this->handleWorkerException($retryFailure);
                }
                $exception = $retryFailure;
            }
        }

        $this->logger->error('超过最大重试次数', [
            'attempts' => $this->maxRetries,
            'exception' => $exception->getMessage(),
        ]);
        $this->retryCount = 0;

        return $this->returnErrorResponse($exception);
    }

    /**
     * 重试退避。注意这是同步阻塞等待，只可用于 worker 级后台执行，
     * 请求路径（热路径）禁用 —— 见 README 的性能约束。
     */
    protected function backoff(int $attempt): void
    {
        usleep(100000 * $attempt);
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
        $notifier = $this->supervisorNotifier;
        if ($notifier === null) {
            return;
        }

        try {
            $notifier($coroutineId, $exception);
        } catch (Throwable $e) {
            // 通知主管进程失败绝不能影响主流程
            $this->logger->warning('主管进程通知失败', ['error' => $e->getMessage()]);
        }
    }

    /** 注册主管进程通知钩子（由常驻进程运行时在 worker 启动时注入） */
    public function setSupervisorNotifier(?callable $notifier): self
    {
        $this->supervisorNotifier = $notifier;

        return $this;
    }

    protected function shouldExitWorker(Throwable $exception): bool
    {
        // 只在常驻 worker 内自我退出（交给主管进程重启）；FPM / CLI 下退出会带走整个进程组
        return $this->isWorker && $exception instanceof \Error;
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