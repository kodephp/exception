<?php

declare(strict_types=1);

namespace Kode\Exception\Tests;

use Kode\Exception\ExceptionManager;
use Kode\Exception\KodeException;
use Kode\Exception\Tracer\DistributedTracer;
use PHPUnit\Framework\TestCase;

class ExceptionTest extends TestCase
{
    public function testKodeExceptionHttp(): void
    {
        $e = KodeException::bad('参数错误', ['field' => 'email']);

        $this->assertEquals('E1001', $e->getErrorCode());
        $this->assertEquals('参数错误', $e->getErrorMsg());
        $this->assertEquals('http', $e->getErrorType());
        $this->assertEquals('http', $e->getErrorType());
    }

    public function testKodeExceptionBusiness(): void
    {
        $e = KodeException::param('参数不正确');

        $this->assertEquals('E2001', $e->getErrorCode());
        $this->assertEquals('business', $e->getErrorType());
    }

    public function testKodeExceptionRuntime(): void
    {
        $e = KodeException::coroutinePanic('协程崩溃');

        $this->assertEquals('E3001', $e->getErrorCode());
        $this->assertEquals('runtime', $e->getErrorType());
    }

    public function testKodeExceptionNotFound(): void
    {
        $e = KodeException::notFound('用户不存在', ['id' => 123]);

        $this->assertEquals('E1004', $e->getErrorCode());
        $this->assertEquals('用户不存在', $e->getErrorMsg());
        $this->assertEquals(['id' => 123], $e->getErrorContext());
    }

    public function testKodeExceptionToResponse(): void
    {
        $e = KodeException::bad('参数错误');
        $response = $e->toResponse();

        $this->assertFalse($response['success']);
        $this->assertEquals('E1001', $response['error']['code']);
        $this->assertEquals('参数错误', $response['error']['msg']);
        $this->assertEquals('http', $response['error']['type']);
        $this->assertArrayHasKey('trace_id', $response['error']);
    }

    public function testExceptionManagerFormat(): void
    {
        $manager = new ExceptionManager();
        $e = KodeException::bad('参数错误');
        $formatted = $manager->format($e);

        $this->assertFalse($formatted['success']);
        $this->assertEquals('E1001', $formatted['error']['code']);
        $this->assertEquals('参数错误', $formatted['error']['msg']);
    }

    public function testDistributedTracer(): void
    {
        $tracer = new DistributedTracer('test-service');
        $this->assertNotEmpty($tracer->getTraceId());

        $spanId = $tracer->startSpan('test-span');
        $this->assertNotEmpty($spanId);

        $tracer->endSpan($spanId);
        $spans = $tracer->getSpans();
        $this->assertCount(1, $spans);
    }

    public function testDistributedTracerTrace(): void
    {
        $tracer = new DistributedTracer('test-service');
        $e = KodeException::bad('测试异常');
        $report = $tracer->trace($e);

        $this->assertArrayHasKey('trace_id', $report);
        $this->assertArrayHasKey('spans', $report);
        $this->assertArrayHasKey('source', $report);
    }

    public function testDistributedTracerExport(): void
    {
        $tracer = new DistributedTracer('test-service');
        $e = KodeException::error('服务器错误');
        $report = $tracer->trace($e);
        $str = $tracer->exportAsString($report);

        $this->assertStringContainsString('链路追踪报告', $str);
        $this->assertStringContainsString('test-service', $str);
    }

    public function testTraceHeaders(): void
    {
        $tracer = new DistributedTracer('test-service');
        $headers = $tracer->getTraceHeaders();

        $this->assertArrayHasKey('X-Trace-Id', $headers);
        $this->assertArrayHasKey('X-Span-Id', $headers);
    }
}