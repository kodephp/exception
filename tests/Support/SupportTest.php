<?php

declare(strict_types=1);

namespace Kode\Exception\Tests\Support;

use Kode\Exception\Support\ContextSanitizer;
use Kode\Exception\Support\IdGenerator;
use Kode\Exception\Support\Runtime;
use Kode\Exception\Support\TraceContext;
use PHPUnit\Framework\TestCase;

class SupportTest extends TestCase
{
    public function testIdGeneratorTraceId(): void
    {
        $id = IdGenerator::traceId();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        $this->assertNotSame(str_repeat('0', 32), $id);
    }

    public function testIdGeneratorSpanId(): void
    {
        $id = IdGenerator::spanId();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $id);
    }

    public function testIdGeneratorUuid4(): void
    {
        $uuid = IdGenerator::uuid4();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid
        );
    }

    public function testIdGeneratorInstanceId(): void
    {
        $id = IdGenerator::instanceId();
        $this->assertStringContainsString((string) getmypid(), $id);
    }

    public function testIdGeneratorValidators(): void
    {
        $tid = IdGenerator::traceId();
        $this->assertTrue(IdGenerator::isValidTraceId($tid));
        $this->assertFalse(IdGenerator::isValidTraceId(str_repeat('0', 32)));
        $this->assertFalse(IdGenerator::isValidTraceId('zzz'));
        $this->assertFalse(IdGenerator::isValidTraceId(str_repeat('a', 31)));

        $sid = IdGenerator::spanId();
        $this->assertTrue(IdGenerator::isValidSpanId($sid));
        $this->assertFalse(IdGenerator::isValidSpanId(str_repeat('0', 16)));
    }

    public function testRuntimeInCli(): void
    {
        $this->assertContains(
            Runtime::name(),
            [Runtime::CLI, Runtime::FPM, Runtime::SWOOLE, Runtime::WORKERMAN, Runtime::ROADRUNNER, Runtime::FRANKENPHP, Runtime::FIBER]
        );
        $this->assertFalse(Runtime::inCoroutine());
        $this->assertNull(Runtime::coroutineId());
        $this->assertSame('main', Runtime::contextKey());
        $this->assertFalse(Runtime::isWorker());
    }

    public function testRuntimeSnapshot(): void
    {
        $snap = Runtime::snapshot();
        $this->assertArrayHasKey('runtime', $snap);
        $this->assertArrayHasKey('php_version', $snap);
        $this->assertArrayHasKey('sapi', $snap);
        $this->assertArrayHasKey('pid', $snap);
    }

    public function testTraceContextStartAndFormat(): void
    {
        $tc = TraceContext::start();
        $this->assertMatchesRegularExpression('/^00-[0-9a-f]{32}-[0-9a-f]{16}-[0-9a-f]{2}$/', $tc->toTraceparent());
        $this->assertTrue($tc->isRoot());
        $this->assertTrue($tc->isSampled());
    }

    public function testTraceContextChildSharesTrace(): void
    {
        $parent = TraceContext::start();
        $child = $parent->child();
        $this->assertSame($parent->traceId, $child->traceId);
        $this->assertNotSame($parent->spanId, $child->spanId);
        $this->assertSame($parent->spanId, $child->parentSpanId);
    }

    public function testTraceContextFromHeaders(): void
    {
        $traceId = IdGenerator::traceId();
        $spanId = IdGenerator::spanId();
        $ctx = TraceContext::fromHeaders(['traceparent' => "00-{$traceId}-{$spanId}-01"]);
        $this->assertNotNull($ctx);
        $this->assertSame($traceId, $ctx->traceId);
        $this->assertSame($spanId, $ctx->parentSpanId);
        $this->assertTrue($ctx->isSampled());
    }

    public function testTraceContextMalformedReturnsNull(): void
    {
        $this->assertNull(TraceContext::parseTraceparent('not-a-valid-traceparent'));
        $this->assertNull(TraceContext::fromHeaders(['traceparent' => 'xx']));
    }

    public function testContextSanitizerMasksSensitive(): void
    {
        $san = new ContextSanitizer();
        $out = $san->sanitize([
            'password' => 'hunter2',
            'api_key' => 'sk-123',
            'user' => 'alice',
        ]);
        $this->assertSame(ContextSanitizer::DEFAULT_MASK, $out['password']);
        $this->assertSame(ContextSanitizer::DEFAULT_MASK, $out['api_key']);
        $this->assertSame('alice', $out['user']);
    }

    public function testContextSanitizerTruncatesLongStrings(): void
    {
        $san = new ContextSanitizer();
        $out = $san->sanitizeValue(str_repeat('x', 600));
        $this->assertLessThanOrEqual(ContextSanitizer::DEFAULT_MAX_STRING + 20, mb_strlen($out));
        $this->assertStringContainsString('(+', $out);
    }

    public function testContextSanitizerAddKeys(): void
    {
        $san = new ContextSanitizer();
        $san->addSensitiveKeys('ssn');
        $this->assertTrue($san->isSensitiveKey('user_ssn'));
    }
}
