<?php

declare(strict_types=1);

namespace Kode\Exception\Tests;

use Kode\Exception\BusinessException;
use Kode\Exception\ExceptionManager;
use Kode\Exception\HttpException;
use Kode\Exception\RuntimeException;
use PHPUnit\Framework\TestCase;

class ExceptionTest extends TestCase
{
    public function testHttpExceptionCreation(): void
    {
        $exception = HttpException::badRequest('Invalid input');

        $this->assertEquals(400, $exception->getHttpStatusCode());
        $this->assertEquals('BAD_REQUEST', $exception->getErrorCode());
        $this->assertEquals('Invalid input', $exception->getMessage());
    }

    public function testHttpExceptionToArray(): void
    {
        $exception = HttpException::notFound('Resource not found');
        $array = $exception->toArray();

        $this->assertEquals(404, $array['http_status_code']);
        $this->assertEquals('NOT_FOUND', $array['error_code']);
        $this->assertEquals('Resource not found', $array['message']);
        $this->assertArrayHasKey('trace_id', $array);
    }

    public function testBusinessExceptionWithContext(): void
    {
        $exception = BusinessException::notFound('User', '12345');
        $context = $exception->getContext();

        $this->assertEquals('NOT_FOUND', $exception->getErrorCode());
        $this->assertEquals('User', $context['entity']);
        $this->assertEquals('12345', $context['id']);
    }

    public function testRuntimeExceptionRecoverable(): void
    {
        $exception = RuntimeException::coroutinePanic('Coroutine failed');

        $this->assertFalse($exception->isRecoverable());
        $this->assertNotEmpty($exception->getSuggestion());
    }

    public function testExceptionManagerFormat(): void
    {
        $manager = new ExceptionManager();
        $exception = new \RuntimeException('Test error');
        $formatted = $manager->format($exception);

        $this->assertFalse($formatted['success']);
        $this->assertEquals('Test error', $formatted['error']['message']);
    }

    public function testExceptionManagerRender(): void
    {
        $manager = new ExceptionManager();
        $exception = HttpException::unauthorized();
        $rendered = $manager->render($exception);

        $this->assertJson($rendered);

        $decoded = json_decode($rendered, true);
        $this->assertFalse($decoded['success']);
        $this->assertEquals('UNAUTHORIZED', $decoded['error']['code']);
    }
}