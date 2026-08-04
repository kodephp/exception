<?php

declare(strict_types=1);

namespace Kode\Exception\Tests;

use Kode\Exception\Enum\ErrorCode;
use Kode\Exception\Enum\ErrorType;
use Kode\Exception\KodeException;
use Kode\Exception\Support\IdGenerator;
use PHPUnit\Framework\TestCase;

class KodeExceptionV3Test extends TestCase
{
    public function testGetErrorCodeEnum(): void
    {
        $e = KodeException::bad('x');
        $this->assertSame(ErrorCode::BadRequest, $e->getErrorCodeEnum());
    }

    public function testGetTypeReturnsEnum(): void
    {
        $e = KodeException::param('x');
        $this->assertSame(ErrorType::Business, $e->getType());
        $this->assertSame('business', $e->getErrorType());
    }

    public function testGetSpanId(): void
    {
        $e = KodeException::bad('x');
        $this->assertTrue(IdGenerator::isValidSpanId($e->getSpanId()));
    }

    public function testToArrayHasSpanId(): void
    {
        $e = KodeException::bad('x');
        $arr = $e->toArray();
        $this->assertArrayHasKey('span_id', $arr);
        $this->assertArrayHasKey('code', $arr);
    }

    public function testToArrayIncludesSuggestionWhenPresent(): void
    {
        $e = KodeException::timeout('x');
        $arr = $e->toArray();
        $this->assertArrayHasKey('suggestion', $arr);
        $this->assertSame('排查慢查询或调高超时阈值', $arr['suggestion']);
    }

    public function testSuggestionContextOverride(): void
    {
        $e = KodeException::timeout('x', ['suggestion' => 'custom tip']);
        $this->assertSame('custom tip', $e->getSuggestion());
    }

    public function testWithContextImmutableAndPreservesIdentity(): void
    {
        $e = KodeException::bad('x', ['a' => 1]);
        $derived = $e->withContext(['b' => 2]);

        $this->assertNotSame($e, $derived);
        $this->assertSame($e->getTraceId(), $derived->getTraceId());
        $this->assertSame($e->getSpanId(), $derived->getSpanId());
        $this->assertArrayNotHasKey('b', $e->getErrorContext());
        $this->assertSame(2, $derived->getErrorContext()['b']);
        $this->assertSame(1, $derived->getErrorContext()['a']);
    }

    public function testWithHttpStatusImmutable(): void
    {
        $e = KodeException::bad('x');
        $derived = $e->withHttpStatus(418);
        $this->assertSame(418, $derived->getHttpStatusCode());
        $this->assertSame(400, $e->getHttpStatusCode());
    }

    public function testWithRecoverableImmutable(): void
    {
        $e = KodeException::memory('x');
        $derived = $e->withRecoverable(true);
        $this->assertTrue($derived->isRecoverable());
        $this->assertFalse($e->isRecoverable());
    }

    public function testWithTraceIdImmutable(): void
    {
        $e = KodeException::bad('x');
        $newTid = IdGenerator::traceId();
        $derived = $e->withTraceId($newTid);
        $this->assertSame($newTid, $derived->getTraceId());
        $this->assertNotSame($e->getTraceId(), $derived->getTraceId());
    }

    public function testWithTraceIdRejectsInvalid(): void
    {
        $e = KodeException::bad('x');
        $this->expectException(\InvalidArgumentException::class);
        $e->withTraceId('not-hex');
    }

    public function testPreviousChainAndRootCause(): void
    {
        $root = new \RuntimeException('deep');
        $e = KodeException::bad('x', [], $root);
        $this->assertCount(1, $e->getPreviousChain());
        $this->assertSame($root, $e->getRootCause());
    }

    public function testThrowIfAndThrowUnless(): void
    {
        $e = KodeException::bad('x');
        KodeException::throwUnless(true, $e);

        $this->expectException(KodeException::class);
        KodeException::throwIf(true, $e);
    }

    public function testMakeAndWrap(): void
    {
        $e = KodeException::make(ErrorCode::Conflict, 'dup');
        $this->assertSame(ErrorCode::Conflict, $e->getErrorCodeEnum());
        $this->assertSame('dup', $e->getErrorMsg());

        $orig = new \PDOException('db down');
        $w = KodeException::wrap($orig, ErrorCode::DependencyFailure, 'dep fail');
        $this->assertSame($orig, $w->getPrevious());
        $this->assertSame(ErrorCode::DependencyFailure, $w->getErrorCodeEnum());
    }

    public function testFromIdempotent(): void
    {
        $e = KodeException::bad('x');
        $this->assertSame($e, KodeException::from($e));
    }

    public function testStaticShortcutsReturnKodeException(): void
    {
        $this->assertInstanceOf(KodeException::class, KodeException::deny());
        $this->assertInstanceOf(KodeException::class, KodeException::notFound());
        $this->assertInstanceOf(KodeException::class, KodeException::methodNotAllowed());
        $this->assertInstanceOf(KodeException::class, KodeException::invalid());
        $this->assertInstanceOf(KodeException::class, KodeException::frequent());
        $this->assertInstanceOf(KodeException::class, KodeException::badGateway());
        $this->assertInstanceOf(KodeException::class, KodeException::unavailable());
        $this->assertInstanceOf(KodeException::class, KodeException::missing('User', '1'));
        $this->assertInstanceOf(KodeException::class, KodeException::conflict('c'));
        $this->assertInstanceOf(KodeException::class, KodeException::quota());
        $this->assertInstanceOf(KodeException::class, KodeException::stateInvalid());
        $this->assertInstanceOf(KodeException::class, KodeException::coroutinePanic('p'));
        $this->assertInstanceOf(KodeException::class, KodeException::workerCrash('w'));
        $this->assertInstanceOf(KodeException::class, KodeException::poolExhausted('p'));
        $this->assertInstanceOf(KodeException::class, KodeException::deadlock('d'));
        $this->assertInstanceOf(KodeException::class, KodeException::circuitOpen('c'));
        $this->assertInstanceOf(KodeException::class, KodeException::dependency('d'));
        $this->assertInstanceOf(KodeException::class, KodeException::memory());
        $this->assertInstanceOf(KodeException::class, KodeException::disk());
        $this->assertInstanceOf(KodeException::class, KodeException::fatal('f'));
        $this->assertInstanceOf(KodeException::class, KodeException::config('c'));
    }
}
