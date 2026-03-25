<?php

declare(strict_types=1);

namespace Kode\Exception\Tests;

use Kode\Exception\ExceptionManager;
use Kode\Exception\KodeException;
use Kode\Exception\Formatter\UnifiedResponseFormatter;
use Kode\Exception\Tracer\ChainExporter;
use Kode\Exception\Tracer\DistributedTracer;
use PHPUnit\Framework\TestCase;

class ExceptionTest extends TestCase
{
    public function testKodeExceptionBad(): void
    {
        $e = KodeException::bad('参数错误', ['field' => 'email']);

        $this->assertEquals('E1001', $e->getErrorCode());
        $this->assertEquals('参数错误', $e->getErrorMsg());
        $this->assertEquals('http', $e->getErrorType());
    }

    public function testKodeExceptionAuth(): void
    {
        $e = KodeException::auth('未授权');

        $this->assertEquals('E1002', $e->getErrorCode());
        $this->assertEquals('http', $e->getErrorType());
    }

    public function testKodeExceptionParam(): void
    {
        $e = KodeException::param('参数不正确');

        $this->assertEquals('E2001', $e->getErrorCode());
        $this->assertEquals('business', $e->getErrorType());
    }

    public function testKodeExceptionRuntime(): void
    {
        $e = KodeException::timeout('请求超时');

        $this->assertEquals('E3004', $e->getErrorCode());
        $this->assertEquals('runtime', $e->getErrorType());
    }

    public function testKodeExceptionMissing(): void
    {
        $e = KodeException::missing('User', '123');

        $this->assertEquals('E2004', $e->getErrorCode());
        $this->assertEquals('User[123] 不存在', $e->getErrorMsg());
    }

    public function testKodeExceptionToResponse(): void
    {
        $e = KodeException::bad('参数错误');
        $response = $e->toResponse();

        $this->assertEquals('E1001', $response['code']);
        $this->assertEquals('参数错误', $response['msg']);
        $this->assertEquals('http', $response['type']);
        $this->assertArrayHasKey('trace_id', $response);
        $this->assertArrayHasKey('location', $response);
        $this->assertArrayHasKey('chain', $response);
    }

    public function testKodeExceptionLocation(): void
    {
        $e = KodeException::bad('测试');
        $location = $e->getLocation();

        $this->assertArrayHasKey('file', $location);
        $this->assertArrayHasKey('line', $location);
        $this->assertArrayHasKey('method', $location);
    }

    public function testKodeExceptionCallChain(): void
    {
        $e = KodeException::bad('测试');
        $chain = $e->getCallChain();

        $this->assertIsArray($chain);
    }

    public function testUnifiedResponseFormatter(): void
    {
        $formatter = new UnifiedResponseFormatter();
        $e = KodeException::bad('参数错误');
        $formatted = $formatter->format($e);

        $this->assertEquals('E1001', $formatted['code']);
        $this->assertEquals('参数错误', $formatted['msg']);
        $this->assertEquals('http', $formatted['type']);
        $this->assertArrayHasKey('trace_id', $formatted);
        $this->assertArrayHasKey('location', $formatted);
        $this->assertArrayHasKey('chain', $formatted);
    }

    public function testChainExporterText(): void
    {
        $exporter = new ChainExporter(ChainExporter::FORMAT_TEXT);
        $tracer = new DistributedTracer('test-service');
        $e = KodeException::bad('测试异常');
        $report = $tracer->trace($e);

        $text = $exporter->export($report);

        $this->assertStringContainsString('链路追踪报告', $text);
        $this->assertStringContainsString('test-service', $text);
    }

    public function testChainExporterJson(): void
    {
        $exporter = new ChainExporter(ChainExporter::FORMAT_JSON);
        $tracer = new DistributedTracer('test-service');
        $e = KodeException::bad('测试异常');
        $report = $tracer->trace($e);

        $json = $exporter->export($report);
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('trace_id', $decoded);
    }

    public function testChainExporterHtml(): void
    {
        $exporter = new ChainExporter(ChainExporter::FORMAT_HTML);
        $tracer = new DistributedTracer('test-service');
        $e = KodeException::bad('测试异常');
        $report = $tracer->trace($e);

        $html = $exporter->export($report);

        $this->assertStringContainsString('<html', $html);
        $this->assertStringContainsString('链路追踪报告', $html);
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

    public function testExceptionManagerFormat(): void
    {
        $manager = new ExceptionManager();
        $e = KodeException::bad('参数错误');
        $formatted = $manager->format($e);

        $this->assertEquals('E1001', $formatted['code']);
        $this->assertEquals('参数错误', $formatted['msg']);
        $this->assertEquals('http', $formatted['type']);
    }

    public function testKodeExceptionIsType(): void
    {
        $e = KodeException::bad('测试');
        $this->assertTrue($e->isHttp());
        $this->assertFalse($e->isBusiness());

        $e2 = KodeException::param('测试');
        $this->assertTrue($e2->isBusiness());
        $this->assertFalse($e2->isHttp());

        $e3 = KodeException::timeout('测试');
        $this->assertTrue($e3->isRuntime());

        $e4 = KodeException::memory('测试');
        $this->assertTrue($e4->isSystem());
    }

    public function testKodeExceptionGetHttpStatusCode(): void
    {
        $this->assertEquals(400, KodeException::bad('测试')->getHttpStatusCode());
        $this->assertEquals(401, KodeException::auth('测试')->getHttpStatusCode());
        $this->assertEquals(403, KodeException::deny('测试')->getHttpStatusCode());
        $this->assertEquals(404, KodeException::notFound('测试')->getHttpStatusCode());
        $this->assertEquals(422, KodeException::invalid('测试')->getHttpStatusCode());
        $this->assertEquals(500, KodeException::error('测试')->getHttpStatusCode());
        $this->assertEquals(503, KodeException::unavailable('测试')->getHttpStatusCode());
    }

    public function testKodeExceptionIsCode(): void
    {
        $e = KodeException::bad('测试');
        $this->assertTrue($e->isCode('E1001'));
        $this->assertFalse($e->isCode('E1002'));
        $this->assertTrue($e->isCodeIn(['E1001', 'E1002', 'E1003']));
        $this->assertFalse($e->isCodeIn(['E2001', 'E3001']));
    }

    public function testKodeExceptionFrom(): void
    {
        $original = new \RuntimeException('原始错误');
        $kode = KodeException::from($original, 'E9999', '包装后的错误');

        $this->assertEquals('E9999', $kode->getErrorCode());
        $this->assertEquals('包装后的错误', $kode->getErrorMsg());
        $this->assertSame($original, $kode->getPrevious());
    }

    public function testKodeExceptionRethrow(): void
    {
        $original = new \RuntimeException('原始错误');
        $kode = KodeException::rethrow($original, 'E3001', '运行时错误', KodeException::TYPE_RUNTIME);

        $this->assertEquals('E3001', $kode->getErrorCode());
        $this->assertEquals('运行时错误', $kode->getErrorMsg());
        $this->assertEquals('runtime', $kode->getErrorType());
    }

    public function testKodeExceptionGetSummary(): void
    {
        $e = KodeException::bad('测试错误');
        $summary = $e->getSummary();

        $this->assertStringContainsString('E1001', $summary);
        $this->assertStringContainsString('测试错误', $summary);
    }
}