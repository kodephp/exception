<?php

declare(strict_types=1);

namespace Kode\Exception\Tests;

use Kode\Exception\Coroutine\CoroutineExceptionContext;
use Kode\Exception\Coroutine\CoroutineExceptionHandler;
use Kode\Exception\Coroutine\WorkerExceptionGuard;
use Kode\Exception\Enum\ErrorCode;
use Kode\Exception\ExceptionManager;
use Kode\Exception\Formatter\ArrayResponseFormatter;
use Kode\Exception\KodeException;
use Kode\Exception\Support\ContextSanitizer;
use Kode\Exception\Support\TraceContext;
use Kode\Exception\Tracer\ChainTracer;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Throwable;

/**
 * 常驻进程（worker / 协程）下的状态泄漏与保护器行为回归
 *
 * 覆盖 v3.1.0 修复：协程上下文键错配导致的静态表泄漏、guard() 重试不重跑调用体、
 * 主管进程通知走不存在的 swoole 函数、失败协程快照无界增长、
 * trace 上下文跨请求串链、脱敏漏掉带前缀的键名、生产态数组格式化器外泄源码路径。
 */
final class ResidentStateHardeningTest extends TestCase
{
    /** @var list<array{level: mixed, message: string, context: array}> */
    private array $logs = [];

    protected function setUp(): void
    {
        unset($_SERVER['HTTP_X_TRACE_ID'], $_SERVER['HTTP_TRACEPARENT']);
        KodeException::setTraceContext(null);
        CoroutineExceptionHandler::clearContext();
        $this->logs = [];
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_X_TRACE_ID'], $_SERVER['HTTP_TRACEPARENT']);
        KodeException::setTraceContext(null);
        CoroutineExceptionHandler::clearContext();
    }

    /** 供匿名 logger 回调写入，便于断言「是否记录、记录级别」 */
    public function recordLog(mixed $level, string $message, array $context): void
    {
        $this->logs[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }

    private function logger(): AbstractLogger
    {
        return new class ($this) extends AbstractLogger {
            public function __construct(private ResidentStateHardeningTest $owner)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->owner->recordLog($level, (string) $message, $context);
            }
        };
    }

    /** 不真正 sleep 的 guard，用于重试语义测试 */
    private function guard(int $maxRetries = 3): WorkerExceptionGuard
    {
        $logger = $this->logger();

        return new class ($logger, new ExceptionManager($logger), $maxRetries) extends WorkerExceptionGuard {
            protected function backoff(int $attempt): void
            {
            }
        };
    }

    /** @return list<string> */
    private function loggedMessages(): array
    {
        return array_column($this->logs, 'message');
    }

    // ==================== 协程上下文表 ====================

    public function testCurrentContextIsReadableAndCleared(): void
    {
        $context = new CoroutineExceptionContext();
        CoroutineExceptionHandler::setCurrentContext($context);

        // 旧实现以 context 自带的随机 id 作键，这里恒取不到
        $this->assertSame($context, CoroutineExceptionHandler::getCurrentContext());
        $this->assertCount(1, CoroutineExceptionHandler::getAllContexts());

        CoroutineExceptionHandler::clearContext();
        $this->assertNull(CoroutineExceptionHandler::getCurrentContext());
        $this->assertSame([], CoroutineExceptionHandler::getAllContexts());
    }

    public function testGuardCoroutineDoesNotLeakContextEntries(): void
    {
        $guard = $this->guard();

        for ($i = 0; $i < 50; $i++) {
            $result = $guard->guardCoroutine(static function (): void {
                throw new KodeException(ErrorCode::NotFound, 'missing');
            }, 'coroutine-' . $i);
            $this->assertIsArray($result);
        }

        // 常驻 worker 内每请求都调用，表必须回到空
        $this->assertSame([], CoroutineExceptionHandler::getAllContexts());
    }

    // ==================== guard() 重试 ====================

    public function testGuardActuallyReinvokesCallbackUntilItSucceeds(): void
    {
        $attempts = 0;
        $guard = $this->guard(3);

        $result = $guard->guard(static function () use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new KodeException(ErrorCode::ServiceUnavailable);
            }

            return 'recovered';
        });

        $this->assertSame('recovered', $result);
        $this->assertSame(3, $attempts);
    }

    public function testGuardGivesUpAfterMaxRetriesAndReturnsErrorResponse(): void
    {
        $attempts = 0;
        $guard = $this->guard(2);

        $result = $guard->guard(static function () use (&$attempts) {
            $attempts++;

            throw new KodeException(ErrorCode::ServiceUnavailable);
        });

        // 首次调用 + 2 次重试
        $this->assertSame(3, $attempts);
        $this->assertIsArray($result);
        $this->assertSame('E1503', $result['code']);

        // 计数复位：下一个请求还能拿到完整重试预算
        $attempts = 0;
        $this->assertSame('ok', $guard->guard(static function () use (&$attempts) {
            $attempts++;

            return 'ok';
        }));
        $this->assertSame(1, $attempts);
    }

    public function testNonRetryableExceptionIsNotRetried(): void
    {
        $attempts = 0;
        $guard = $this->guard(3);

        $result = $guard->guard(static function () use (&$attempts) {
            $attempts++;

            throw new KodeException(ErrorCode::NotFound, 'nope');
        });

        $this->assertSame(1, $attempts);
        $this->assertSame('E1004', $result['code']);
    }

    public function testCriticalErrorIsNotRetriedAndNotifiesSupervisor(): void
    {
        $attempts = 0;
        $notifications = [];
        $guard = $this->guard(3);
        $guard->setSupervisorNotifier(static function (string $id, Throwable $e) use (&$notifications): void {
            $notifications[] = [$id, $e::class];
        });

        $result = $guard->guard(static function () use (&$attempts) {
            $attempts++;

            throw new \Error('call to undefined function');
        });

        $this->assertNull($result);
        $this->assertSame(1, $attempts);
        $this->assertSame([['main', \Error::class]], $notifications);
        $this->assertContains('Worker 中的关键异常', $this->loggedMessages());
    }

    // ==================== 主管进程通知 ====================

    public function testUnrecoverableCoroutineExceptionNotifiesSupervisor(): void
    {
        $notifications = [];
        $guard = $this->guard();
        $guard->setSupervisorNotifier(static function (string $id, Throwable $e) use (&$notifications): void {
            $notifications[] = $id;
        });

        $result = $guard->guardCoroutine(static function (): void {
            throw new KodeException(ErrorCode::ConfigInvalid);
        }, 'coroutine-x');

        $this->assertNull($result);
        $this->assertSame(['coroutine-x'], $notifications);
    }

    public function testFailingSupervisorNotifierDoesNotBreakGuard(): void
    {
        $guard = $this->guard();
        $guard->setSupervisorNotifier(static function (): void {
            throw new \RuntimeException('channel closed');
        });

        $result = $guard->guardCoroutine(static function (): void {
            throw new KodeException(ErrorCode::ConfigInvalid);
        }, 'coroutine-y');

        $this->assertNull($result);
        $this->assertContains('主管进程通知失败', $this->loggedMessages());
    }

    public function testFailedCoroutineSnapshotsAreBounded(): void
    {
        $guard = $this->guard();

        for ($i = 0; $i < WorkerExceptionGuard::MAX_FAILED_COROUTINES + 25; $i++) {
            $guard->guardCoroutine(static function (): void {
                throw new KodeException(ErrorCode::NotFound, 'boom');
            }, 'c' . $i);
        }

        $failed = $guard->getFailedCoroutines();
        $this->assertCount(WorkerExceptionGuard::MAX_FAILED_COROUTINES, $failed);
        // 保留的是最新的快照
        $this->assertArrayHasKey('c' . (WorkerExceptionGuard::MAX_FAILED_COROUTINES + 24), $failed);
        $this->assertArrayNotHasKey('c0', $failed);

        $guard->clearFailedCoroutines();
        $this->assertSame([], $guard->getFailedCoroutines());
    }

    // ==================== trace 上下文换绑 ====================

    public function testTraceContextRebindsWhenRequestHeaderChanges(): void
    {
        $_SERVER['HTTP_X_TRACE_ID'] = str_repeat('a', 32);
        $first = KodeException::traceContext()->traceId;
        $this->assertSame(str_repeat('a', 32), $first);

        // 同一 worker 处理下一个请求
        $_SERVER['HTTP_X_TRACE_ID'] = str_repeat('b', 32);
        $this->assertSame(str_repeat('b', 32), KodeException::traceContext()->traceId);
        $this->assertSame(str_repeat('b', 32), (new KodeException(ErrorCode::NotFound))->getTraceId());
    }

    public function testTraceContextRebindsFromTraceparentOnly(): void
    {
        $_SERVER['HTTP_TRACEPARENT'] = '00-' . str_repeat('c', 32) . '-' . str_repeat('d', 16) . '-01';
        $first = KodeException::traceContext()->traceId;
        $this->assertSame(str_repeat('c', 32), $first);

        $_SERVER['HTTP_TRACEPARENT'] = '00-' . str_repeat('e', 32) . '-' . str_repeat('d', 16) . '-01';
        $this->assertSame(str_repeat('e', 32), KodeException::traceContext()->traceId);
    }

    public function testExplicitlyBoundTraceContextIsNotAutoRebound(): void
    {
        $injected = new TraceContext('injected-trace', str_repeat('f', 16));
        KodeException::setTraceContext($injected);

        $_SERVER['HTTP_X_TRACE_ID'] = str_repeat('a', 32);

        $this->assertSame('injected-trace', KodeException::traceContext()->traceId);
    }

    public function testResetTraceContextReadsCurrentRequestHeaders(): void
    {
        KodeException::traceContext();

        $_SERVER['HTTP_X_TRACE_ID'] = str_repeat('9', 32);
        $this->assertSame(str_repeat('9', 32), KodeException::resetTraceContext()->traceId);
    }

    // ==================== 脱敏 ====================

    public function testPrefixedAndSuffixedSensitiveKeysAreMasked(): void
    {
        $sanitizer = new ContextSanitizer();

        $masked = $sanitizer->sanitize([
            'x-api-key' => 'AKIA123456',
            'X-Api-Key' => 'AKIA123456',
            'request_api_key' => 'AKIA123456',
            'access_key' => 'AKIA123456',
            'csrf_token' => 'abcdef',
            'user_password_hash' => 'hunter2',
        ]);

        foreach ($masked as $value) {
            $this->assertSame(ContextSanitizer::DEFAULT_MASK, $value);
        }
    }

    public function testOrdinaryKeysAreNotMaskedByTokenSubstring(): void
    {
        $sanitizer = new ContextSanitizer();

        $clean = $sanitizer->sanitize([
            'author' => 'Ann',
            'design' => 'ok',
            'assignment' => 'task-1',
            'authority_level' => 'high',
            'caption' => 'hello',
        ]);

        $this->assertSame([
            'author' => 'Ann',
            'design' => 'ok',
            'assignment' => 'task-1',
            'authority_level' => 'high',
            'caption' => 'hello',
        ], $clean);
    }

    // ==================== 生产态输出 ====================

    public function testArrayResponseFormatterHidesPathsInProduction(): void
    {
        $exception = KodeException::notFound('订单不存在');

        $debug = (new ArrayResponseFormatter(false))->format($exception);
        $this->assertArrayHasKey('chain', $debug);
        $this->assertStringContainsString('/', $debug['location']['file']);

        $prod = (new ArrayResponseFormatter(true))->format($exception);
        $this->assertArrayNotHasKey('chain', $prod);
        $this->assertStringNotContainsString('/', $prod['location']['file']);
        $this->assertSame(basename($debug['location']['file']), $prod['location']['file']);
        $this->assertSame('E1004', $prod['code']);
        $this->assertNotEmpty($prod['trace_id']);
    }

    public function testChainTracerCapsPreviousDepth(): void
    {
        $exception = new KodeException(ErrorCode::NotFound, 'root');
        for ($i = 0; $i < WorkerExceptionGuard::MAX_FAILED_COROUTINES + 40; $i++) {
            $exception = new KodeException(ErrorCode::NotFound, 'wrap', $exception);
        }

        $report = (new ChainTracer())->trace($exception);

        $this->assertCount(ChainTracer::MAX_CHAIN_NODES, $report['full_chain']);
        $this->assertTrue($report['truncated']);
    }

    public function testChainTracerReportsNoTruncationForShortChains(): void
    {
        $exception = new KodeException(ErrorCode::NotFound, 'root');
        $exception = new KodeException(ErrorCode::InternalError, 'wrap', $exception);

        $report = (new ChainTracer())->trace($exception);

        $this->assertCount(2, $report['full_chain']);
        $this->assertFalse($report['truncated']);
    }
}
