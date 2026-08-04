<?php

declare(strict_types=1);

namespace Kode\Exception\Tests\Enum;

use Kode\Exception\Enum\ErrorType;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

class ErrorTypeTest extends TestCase
{
    public function testLabel(): void
    {
        foreach (ErrorType::cases() as $t) {
            $this->assertNotEmpty($t->label());
        }
    }

    public function testLogLevel(): void
    {
        $this->assertSame(LogLevel::WARNING, ErrorType::Http->logLevel());
        $this->assertSame(LogLevel::NOTICE, ErrorType::Business->logLevel());
        $this->assertSame(LogLevel::ERROR, ErrorType::Runtime->logLevel());
        $this->assertSame(LogLevel::CRITICAL, ErrorType::System->logLevel());
    }

    public function testIsRecoverableByDefault(): void
    {
        $this->assertTrue(ErrorType::Http->isRecoverableByDefault());
        $this->assertTrue(ErrorType::Business->isRecoverableByDefault());
        $this->assertFalse(ErrorType::Runtime->isRecoverableByDefault());
        $this->assertFalse(ErrorType::System->isRecoverableByDefault());
    }

    public function testDefaultHttpStatus(): void
    {
        $this->assertSame(500, ErrorType::Http->defaultHttpStatus());
        $this->assertSame(200, ErrorType::Business->defaultHttpStatus());
        $this->assertSame(503, ErrorType::Runtime->defaultHttpStatus());
        $this->assertSame(500, ErrorType::System->defaultHttpStatus());
    }

    public function testCoerce(): void
    {
        $this->assertSame(ErrorType::Http, ErrorType::coerce('http'));
        $this->assertSame(ErrorType::Http, ErrorType::coerce(ErrorType::Http));
        $this->assertSame(ErrorType::System, ErrorType::coerce(null));
        $this->assertSame(ErrorType::System, ErrorType::coerce(''));
        $this->assertSame(ErrorType::Business, ErrorType::coerce('nope', ErrorType::Business));
    }
}
