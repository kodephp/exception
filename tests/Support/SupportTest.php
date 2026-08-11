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

    public function testRuntimeNameIsValidConstant(): void
    {
        $this->assertContains(
            Runtime::name(),
            [Runtime::CLI, Runtime::FPM, Runtime::SWOOLE, Runtime::WORKERMAN, Runtime::ROADRUNNER, Runtime::FRANKENPHP, Runtime::FIBER]
        );
    }

    public function testRuntimeCoroutineStateConsistent(): void
    {
        // 协程态内部须自洽，且不依赖全局进程是否被 Swoole 自动包裹（Environment-agnostic）。
        $inCoroutine = Runtime::inCoroutine();
        $this->assertSame($inCoroutine, Runtime::coroutineId() !== null);
        $this->assertSame($inCoroutine, Runtime::contextKey() !== 'main');

        if (!$inCoroutine) {
            $this->assertNull(Runtime::coroutineId());
            $this->assertSame('main', Runtime::contextKey());
            // 非协程且 SAPI 为 cli 时，绝不应误报为 Swoole 运行时
            if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
                $this->assertNotSame(Runtime::SWOOLE, Runtime::name());
            }
        }
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

    public function testContextSanitizerTokenBasedNoOverMasking(): void
    {
        $san = new ContextSanitizer();

        // 整键或词元命中才脱敏
        $this->assertTrue($san->isSensitiveKey('password'));
        $this->assertTrue($san->isSensitiveKey('user_password'));
        $this->assertTrue($san->isSensitiveKey('apiKey'));        // 驼峰
        $this->assertTrue($san->isSensitiveKey('authorization'));
        $this->assertTrue($san->isSensitiveKey('signature'));

        // 普通字段不应被 auth / sign 子串误伤
        $this->assertFalse($san->isSensitiveKey('author'));
        $this->assertFalse($san->isSensitiveKey('design'));
        $this->assertFalse($san->isSensitiveKey('assign'));
        $this->assertFalse($san->isSensitiveKey('signal'));
    }

    public function testContextSanitizerMasksButKeepsBenign(): void
    {
        $san = new ContextSanitizer();
        $out = $san->sanitize([
            'author' => 'Jane Doe',
            'authorization' => 'Bearer secret',
            'design' => 'blueprint',
            'signature' => 'abc123',
            'user_password' => 'hunter2',
        ]);

        $this->assertSame('Jane Doe', $out['author']);
        $this->assertSame('blueprint', $out['design']);
        $this->assertSame(ContextSanitizer::DEFAULT_MASK, $out['authorization']);
        $this->assertSame(ContextSanitizer::DEFAULT_MASK, $out['signature']);
        $this->assertSame(ContextSanitizer::DEFAULT_MASK, $out['user_password']);
    }
}
