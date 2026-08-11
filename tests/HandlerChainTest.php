<?php

declare(strict_types=1);

namespace Kode\Exception\Tests;

use Kode\Exception\ExceptionManager;
use Kode\Exception\Formatter\ArrayResponseFormatter;
use Kode\Exception\Formatter\JsonResponseFormatter;
use Kode\Exception\Formatter\ProblemDetailsFormatter;
use Kode\Exception\Handler\CallableExceptionHandler;
use Kode\Exception\KodeException;
use PHPUnit\Framework\TestCase;
use Throwable;

class HandlerChainTest extends TestCase
{
    public function testDispatchHonorsPriority(): void
    {
        $log = [];
        $m = new ExceptionManager();
        $m->clearHandlers();
        $m->addHandler(new CallableExceptionHandler(static function () use (&$log) { $log[] = 'generic'; return false; }, priority: -100));
        $m->addHandler(new CallableExceptionHandler(static function () use (&$log) { $log[] = 'http'; return false; }, priority: 100));
        $m->addHandler(new CallableExceptionHandler(static function () use (&$log) { $log[] = 'mid'; return false; }, priority: 50));

        $m->dispatch(KodeException::bad('x'));

        $this->assertSame(['http', 'mid', 'generic'], $log);
    }

    public function testReportableCanStopLogging(): void
    {
        $m = new ExceptionManager();
        $m->reportable(static fn () => false);
        $m->report(KodeException::bad('x')); // 不抛异常即证明提前返回
        $this->assertTrue(true);
    }

    public function testDontReport(): void
    {
        $m = new ExceptionManager();
        $m->dontReport(KodeException::class);
        $this->assertFalse($m->shouldReport(KodeException::bad('x')));

        $m->dontReport(\RuntimeException::class);
        $this->assertTrue($m->shouldReport(new \InvalidArgumentException('y')));
    }

    public function testCallableHandlerOnlyFilter(): void
    {
        $called = [];
        $h = new CallableExceptionHandler(
            static function (Throwable $e) use (&$called) { $called[] = $e; return true; },
            priority: 200,
            only: KodeException::class,
        );

        $this->assertTrue($h->handle(KodeException::bad('x')));
        $this->assertFalse($h->handle(new \RuntimeException('y')));
        $this->assertCount(1, $called);
    }

    public function testProblemDetailsFormatter(): void
    {
        $f = new ProblemDetailsFormatter('https://api.example.com/problem');
        $out = $f->format(KodeException::notFound('nope', ['id' => 5]));

        $this->assertSame('application/problem+json; charset=utf-8', $f->getContentType());
        $this->assertArrayHasKey('type', $out);
        $this->assertArrayHasKey('title', $out);
        $this->assertSame(404, $out['status']);
        $this->assertStringContainsString('E1004', $out['type']);
        $this->assertSame('E1004', $out['code']);
        $this->assertArrayHasKey('trace_id', $out);
        $this->assertArrayHasKey('errors', $out);
    }

    public function testJsonAndArrayFormatters(): void
    {
        $e = KodeException::bad('x');

        $json = (new JsonResponseFormatter())->format($e);
        $this->assertArrayHasKey('code', $json);
        $this->assertSame('E1001', $json['code']);
        $this->assertSame('application/json; charset=utf-8', (new JsonResponseFormatter())->getContentType());

        $arr = (new ArrayResponseFormatter())->format($e);
        $this->assertIsArray($arr);
        $this->assertSame('E1001', $arr['code']);
        $this->assertSame('application/json; charset=utf-8', (new ArrayResponseFormatter())->getContentType());
    }

    public function testHeaderInjectionIsStripped(): void
    {
        $m = new ExceptionManager();
        $ref = new \ReflectionMethod($m, 'safeHeaderValue');
        $ref->setAccessible(true);

        $dirty = "abc\r\nX-Injected: evil";
        $clean = $ref->invoke($m, $dirty);

        $this->assertStringNotContainsString("\r", $clean);
        $this->assertStringNotContainsString("\n", $clean);
        $this->assertSame('abcX-Injected: evil', $clean);
    }

    public function testRespondReturnsStatusAndBodyWithoutEcho(): void
    {
        $logger = new class implements \Psr\Log\LoggerInterface {
            /** @var array<int, array{level: mixed, message: string}> */
            public array $records = [];
            public function emergency(string|\Stringable $message, array $context = []): void { $this->records[] = ['level' => 'emergency', 'message' => (string) $message]; }
            public function alert(string|\Stringable $message, array $context = []): void { $this->records[] = ['level' => 'alert', 'message' => (string) $message]; }
            public function critical(string|\Stringable $message, array $context = []): void { $this->records[] = ['level' => 'critical', 'message' => (string) $message]; }
            public function error(string|\Stringable $message, array $context = []): void { $this->records[] = ['level' => 'error', 'message' => (string) $message]; }
            public function warning(string|\Stringable $message, array $context = []): void { $this->records[] = ['level' => 'warning', 'message' => (string) $message]; }
            public function notice(string|\Stringable $message, array $context = []): void { $this->records[] = ['level' => 'notice', 'message' => (string) $message]; }
            public function info(string|\Stringable $message, array $context = []): void { $this->records[] = ['level' => 'info', 'message' => (string) $message]; }
            public function debug(string|\Stringable $message, array $context = []): void { $this->records[] = ['level' => 'debug', 'message' => (string) $message]; }
            public function log($level, string|\Stringable $message, array $context = []): void { $this->records[] = ['level' => $level, 'message' => (string) $message]; }
        };

        $m = (new ExceptionManager($logger))->setSendHeaders(false);

        $result = $m->respond(KodeException::notFound('missing'));

        $this->assertSame(404, $result['status']);
        $this->assertArrayHasKey('body', $result);
        $this->assertSame('E1004', $result['body']['code']);
        // 默认兜底处理器已记录日志
        $this->assertNotEmpty($logger->records);
    }

    public function testHandleExceptionNeverRethrowsWhenLoggerFails(): void
    {
        $throwingLogger = new class implements \Psr\Log\LoggerInterface {
            private function fail(): void { throw new \RuntimeException('logger broken'); }
            public function emergency(string|\Stringable $message, array $context = []): void { $this->fail(); }
            public function alert(string|\Stringable $message, array $context = []): void { $this->fail(); }
            public function critical(string|\Stringable $message, array $context = []): void { $this->fail(); }
            public function error(string|\Stringable $message, array $context = []): void { $this->fail(); }
            public function warning(string|\Stringable $message, array $context = []): void { $this->fail(); }
            public function notice(string|\Stringable $message, array $context = []): void { $this->fail(); }
            public function info(string|\Stringable $message, array $context = []): void { $this->fail(); }
            public function debug(string|\Stringable $message, array $context = []): void { $this->fail(); }
            public function log($level, string|\Stringable $message, array $context = []): void { $this->fail(); }
        };

        $m = (new ExceptionManager($throwingLogger))->setSendHeaders(false);

        ob_start();
        try {
            $m->handleException(KodeException::bad('x'));
            $this->assertTrue(true); // 未向上抛出异常
        } finally {
            ob_end_clean();
        }
    }
}
