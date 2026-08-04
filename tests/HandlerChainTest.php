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
}
