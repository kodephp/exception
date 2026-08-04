<?php

declare(strict_types=1);

namespace Kode\Exception\Tests\Enum;

use Kode\Exception\Enum\ErrorCode;
use Kode\Exception\Enum\ErrorType;
use PHPUnit\Framework\TestCase;

class ErrorCodeTest extends TestCase
{
    public function testNumericHexdecBugFixed(): void
    {
        // v2 用 hexdec(substr($code,1)) 会得到 4097，这里必须得到 1001
        $this->assertSame(1001, ErrorCode::toNumeric('E1001'));
        $this->assertSame(1503, ErrorCode::toNumeric('E1503'));
        $this->assertSame(9999, ErrorCode::toNumeric('E9999'));
        $this->assertSame(1001, ErrorCode::BadRequest->numeric());
        $this->assertSame(2001, ErrorCode::InvalidParam->numeric());
    }

    public function testTypeMapping(): void
    {
        $this->assertSame(ErrorType::Http, ErrorCode::BadRequest->type());
        $this->assertSame(ErrorType::Http, ErrorCode::ServiceUnavailable->type());
        $this->assertSame(ErrorType::Business, ErrorCode::InvalidParam->type());
        $this->assertSame(ErrorType::Runtime, ErrorCode::Timeout->type());
        $this->assertSame(ErrorType::System, ErrorCode::FatalError->type());
    }

    public function testHttpStatusMapping(): void
    {
        $cases = [
            [ErrorCode::BadRequest, 400],
            [ErrorCode::Unauthorized, 401],
            [ErrorCode::Forbidden, 403],
            [ErrorCode::NotFound, 404],
            [ErrorCode::MethodNotAllowed, 405],
            [ErrorCode::RequestTimeout, 408],
            [ErrorCode::Conflict, 409],
            [ErrorCode::ValidationFailed, 422],
            [ErrorCode::TooManyRequests, 429],
            [ErrorCode::InternalError, 500],
            [ErrorCode::BadGateway, 502],
            [ErrorCode::ServiceUnavailable, 503],
            [ErrorCode::GatewayTimeout, 504],
        ];
        foreach ($cases as [$code, $status]) {
            $this->assertSame($status, $code->httpStatus(), (string) $code->value);
        }
    }

    public function testMessageNotEmpty(): void
    {
        foreach (ErrorCode::cases() as $code) {
            $this->assertNotEmpty($code->message(), (string) $code->value);
        }
    }

    public function testSuggestion(): void
    {
        $this->assertNotNull(ErrorCode::Timeout->suggestion());
        $this->assertNotNull(ErrorCode::MemoryExhausted->suggestion());
        $this->assertNull(ErrorCode::BadRequest->suggestion());
    }

    public function testIsRecoverable(): void
    {
        $this->assertTrue(ErrorCode::Timeout->isRecoverable());
        $this->assertTrue(ErrorCode::DependencyFailure->isRecoverable());
        $this->assertFalse(ErrorCode::FatalError->isRecoverable());
        $this->assertFalse(ErrorCode::Deadlock->isRecoverable());
        $this->assertTrue(ErrorCode::BadRequest->isRecoverable()); // 默认 Http 可恢复
    }

    public function testGroup(): void
    {
        $this->assertSame(1, ErrorCode::BadRequest->group());
        $this->assertSame(2, ErrorCode::InvalidParam->group());
        $this->assertSame(3, ErrorCode::Timeout->group());
        $this->assertSame(5, ErrorCode::FatalError->group());
        $this->assertSame(9, ErrorCode::Unknown->group());
    }

    public function testParse(): void
    {
        $this->assertSame(ErrorCode::BadRequest, ErrorCode::parse('E1001'));
        $this->assertSame(ErrorCode::BadRequest, ErrorCode::parse(ErrorCode::BadRequest));
        $this->assertNull(ErrorCode::parse('E0000'));
        $this->assertNull(ErrorCode::parse('garbage'));
        $this->assertNull(ErrorCode::parse(null));
    }

    public function testIsWellFormed(): void
    {
        $this->assertTrue(ErrorCode::isWellFormed('E1001'));
        $this->assertFalse(ErrorCode::isWellFormed('E99'));
        $this->assertFalse(ErrorCode::isWellFormed('X1001'));
        $this->assertFalse(ErrorCode::isWellFormed('E10001'));
    }
}
