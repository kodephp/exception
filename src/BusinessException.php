<?php

declare(strict_types=1);

namespace Kode\Exception;

use Throwable;

/**
 * 业务异常类
 * 用于处理业务逻辑错误，如参数验证、数据不存在、冲突等
 */
class BusinessException extends BaseException
{
    /** 业务错误代码 */
    protected string $businessCode;

    public function __construct(
        string $businessCode,
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        array $context = []
    ) {
        $this->businessCode = $businessCode;
        parent::__construct($message, $code, $previous, $businessCode, $context);
    }

    public function getBusinessCode(): string
    {
        return $this->businessCode;
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'business_code' => $this->businessCode,
            'type' => 'business',
        ]);
    }

    public static function invalidArgument(string $message, array $context = [], ?Throwable $previous = null): self
    {
        return new self('INVALID_ARGUMENT', $message, 0, $previous, $context);
    }

    public static function validationFailed(string $message, array $context = [], ?Throwable $previous = null): self
    {
        return new self('VALIDATION_FAILED', $message, 0, $previous, $context);
    }

    public static function notFound(string $entity, string $id, ?Throwable $previous = null): self
    {
        return new self(
            'NOT_FOUND',
            "{$entity} with id '{$id}' not found",
            0,
            $previous,
            ['entity' => $entity, 'id' => $id]
        );
    }

    public static function conflict(string $message, array $context = [], ?Throwable $previous = null): self
    {
        return new self('CONFLICT', $message, 0, $previous, $context);
    }
}