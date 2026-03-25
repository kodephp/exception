<?php

declare(strict_types=1);

namespace Kode\Exception;

use DateTimeImmutable;
use Throwable;

/**
 * 基础异常抽象类
 * 提供异常追踪、上下文和统一格式化能力
 */
abstract class BaseException extends \Exception implements ExceptionInterface
{
    /** 错误代码 */
    protected string $errorCode;
    /** 错误上下文数据 */
    protected array $context;
    /** 追踪ID，用于日志关联 */
    protected string $traceId;
    /** 异常发生时间戳 */
    protected DateTimeImmutable $timestamp;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        string $errorCode = 'UNKNOWN_ERROR',
        array $context = []
    ) {
        parent::__construct($message, $code, $previous);
        $this->errorCode = $errorCode;
        $this->context = $context;
        $this->traceId = $this->generateTraceId();
        $this->timestamp = new DateTimeImmutable();
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getTraceId(): string
    {
        return $this->traceId;
    }

    public function getTimestamp(): DateTimeImmutable
    {
        return $this->timestamp;
    }

    public function toArray(): array
    {
        return [
            'error_code' => $this->errorCode,
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'trace_id' => $this->traceId,
            'timestamp' => $this->timestamp->format('Y-m-d\TH:i:s.uP'),
            'context' => $this->context,
            'file' => $this->getFile(),
            'line' => $this->getLine(),
        ];
    }

    protected function generateTraceId(): string
    {
        return sprintf(
            '%08x-%04x-%04x-%04x-%012x',
            time(),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffffffffffff)
        );
    }

    public function withContext(array $context): self
    {
        $new = clone $this;
        $new->context = array_merge($this->context, $context);
        return $new;
    }
}