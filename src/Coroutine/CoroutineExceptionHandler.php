<?php

declare(strict_types=1);

namespace Kode\Exception\Coroutine;

use Error;
use Kode\Exception\KodeException;
use Kode\Exception\Support\Runtime;
use Throwable;

/**
 * 协程异常处理器
 * 支持 Swoole/Fiber 原生协程环境的异常处理
 */
class CoroutineExceptionHandler
{
    protected CoroutineExceptionContext $context;
    protected static array $coroutineContexts = [];
    protected static ?self $currentHandler = null;
    protected bool $isRecoverableFailure = false;

    public function __construct(?CoroutineExceptionContext $context = null)
    {
        $this->context = $context ?? new CoroutineExceptionContext();
    }

    public static function getCurrentContext(): ?CoroutineExceptionContext
    {
        $coroutineId = self::getCurrentCoroutineId();
        return self::$coroutineContexts[$coroutineId] ?? null;
    }

    public static function setCurrentContext(CoroutineExceptionContext $context): void
    {
        // 键必须与 getCurrentContext()/clearContext() 同源（当前执行单元），
        // 早先用 context 自带的随机 spanId 作键：查不到、清不掉，每次调用还泄漏一条
        self::$coroutineContexts[self::getCurrentCoroutineId()] = $context;
    }

    public static function getCurrentCoroutineId(): string
    {
        return Runtime::contextKey();
    }

    public static function isInCoroutine(): bool
    {
        return Runtime::inCoroutine();
    }

    public function __invoke(Throwable $exception): void
    {
        $this->handle($exception);
    }

    public function handle(Throwable $exception): void
    {
        $previousHandler = self::$currentHandler;
        self::$currentHandler = $this;

        try {
            $this->context->setException($exception);

            if ($exception instanceof KodeException) {
                $this->logException($exception);
            }

            $this->context->markAsHandled();
        } finally {
            self::$currentHandler = $previousHandler;
        }
    }

    protected function logException(KodeException $exception): void
    {
    }

    public function getContext(): CoroutineExceptionContext
    {
        return $this->context;
    }

    public function isRecoverableFailure(): bool
    {
        return $this->isRecoverableFailure;
    }

    public static function runSafely(callable $callback, ?callable $exceptionCallback = null): mixed
    {
        $handler = new self();
        self::setCurrentContext($handler->getContext());

        try {
            return $callback();
        } catch (Throwable $e) {
            $handler->handle($e);

            if ($exceptionCallback !== null) {
                return $exceptionCallback($e, $handler->getContext());
            }

            return null;
        } finally {
            $handler->getContext()->markAsHandled();
        }
    }

    public static function clearContext(): void
    {
        $coroutineId = self::getCurrentCoroutineId();
        unset(self::$coroutineContexts[$coroutineId]);
    }

    public static function getAllContexts(): array
    {
        return self::$coroutineContexts;
    }
}