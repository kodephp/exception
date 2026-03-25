<?php

declare(strict_types=1);

namespace Kode\Exception;

use Throwable;

/**
 * 统一异常类
 * 支持 HTTP、业务、运行时等所有异常类型，统一 error_code 错误码体系
 */
class KodeException extends \Exception
{
    /** 错误类型枚举 */
    public const TYPE_HTTP = 'http';
    public const TYPE_BUSINESS = 'business';
    public const TYPE_RUNTIME = 'runtime';
    public const TYPE_SYSTEM = 'system';

    /** HTTP 错误码 (E1xxx) */
    public const CODE_BAD_REQUEST       = 'E1001';
    public const CODE_UNAUTHORIZED      = 'E1002';
    public const CODE_FORBIDDEN         = 'E1003';
    public const CODE_NOT_FOUND         = 'E1004';
    public const CODE_METHOD_NOT_ALLOWED = 'E1005';
    public const CODE_VALIDATION_FAILED  = 'E1006';
    public const CODE_TOO_MANY_REQUESTS  = 'E1007';
    public const CODE_INTERNAL_ERROR     = 'E1500';
    public const CODE_SERVER_ERROR       = 'E1501';
    public const CODE_SERVICE_UNAVAILABLE = 'E1503';

    /** 业务错误码 (E2xxx) */
    public const CODE_INVALID_PARAM     = 'E2001';
    public const CODE_NOT_FOUND_ENTITY  = 'E2004';
    public const CODE_CONFLICT         = 'E2009';
    public const CODE_DEPRECATED       = 'E2010';

    /** 运行时错误码 (E3xxx) */
    public const CODE_COROUTINE_PANIC   = 'E3001';
    public const CODE_WORKER_CRASH       = 'E3002';
    public const CODE_POOL_EXHAUSTED    = 'E3003';
    public const CODE_TIMEOUT            = 'E3004';
    public const CODE_DEADLOCK           = 'E3005';

    /** 系统错误码 (E5xxx) */
    public const CODE_MEMORY_EXHAUSTED  = 'E5001';
    public const CODE_DISK_FULL          = 'E5002';

    /** @var string 错误类型 */
    protected string $errorType = self::TYPE_BUSINESS;
    /** @var string 错误码 */
    protected string $errorCode;
    /** @var string 中文错误消息 */
    protected string $errorMsg;
    /** @var array 错误上下文 */
    protected array $errorContext = [];
    /** @var string 追踪ID */
    protected string $traceId = '';
    /** @var array 调用链路 */
    protected array $callChain = [];

    public function __construct(
        string $errorCode,
        string $errorMsg,
        ?Throwable $previous = null,
        string $errorType = self::TYPE_BUSINESS,
        array $context = []
    ) {
        $this->errorCode = $errorCode;
        $this->errorMsg = $errorMsg;
        $this->errorType = $errorType;
        $this->errorContext = $context;
        $this->traceId = $this->generateTraceId();

        $code = hexdec(substr($errorCode, 1)) ?: 0;
        parent::__construct($errorMsg, $code, $previous);

        $this->loadCallChain($previous);
    }

    /** 加载调用链路 */
    protected function loadCallChain(?Throwable $previous): void
    {
        $this->callChain = $this->buildCallChain($this->getTrace(), $previous);
    }

    /** 构建调用链路 */
    protected function buildCallChain(array $trace, ?Throwable $previous): array
    {
        $chain = [];
        $skipPrefixes = ['Kode\\Exception\\', 'Monolog\\', 'Psr\\Log\\'];

        foreach ($trace as $index => $frame) {
            if ($index >= 15) break;

            if (isset($frame['class'])) {
                $skip = false;
                foreach ($skipPrefixes as $prefix) {
                    if (str_starts_with($frame['class'], $prefix)) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) continue;
            }

            $chain[] = [
                'file' => $frame['file'] ?? 'unknown',
                'line' => $frame['line'] ?? 0,
                'method' => $this->formatMethod($frame),
                'args' => $this->formatArgs($frame['args'] ?? []),
            ];
        }

        if ($previous !== null) {
            $chain[] = [
                'file' => $previous->getFile(),
                'line' => $previous->getLine(),
                'method' => get_class($previous) . '::__construct',
                'msg' => $previous->getMessage(),
                'is_previous' => true,
            ];
        }

        return $chain;
    }

    /** 格式化方法名 */
    protected function formatMethod(array $frame): string
    {
        $method = '';
        if (isset($frame['class'])) {
            $method .= $frame['class'] . ($frame['type'] ?? '::');
        }
        $method .= ($frame['function'] ?? 'unknown') . '()';
        return $method;
    }

    /** 格式化参数 */
    protected function formatArgs(array $args): array
    {
        return array_map(function ($arg) {
            if (is_object($arg)) {
                return get_class($arg) . '#' . spl_object_id($arg);
            }
            if (is_array($arg)) {
                return 'array[' . count($arg) . ']';
            }
            if (is_string($arg) && strlen($arg) > 30) {
                return substr($arg, 0, 27) . '...';
            }
            return var_export($arg, true);
        }, array_slice($args, 0, 3));
    }

    /** 生成 TraceId */
    protected function generateTraceId(): string
    {
        return sprintf(
            '%08x-%04x-%04x-%04x-%012x',
            time(),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffffffffffff)
        );
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getErrorMsg(): string
    {
        return $this->errorMsg;
    }

    public function getErrorType(): string
    {
        return $this->errorType;
    }

    public function getErrorContext(): array
    {
        return $this->errorContext;
    }

    public function getTraceId(): string
    {
        return $this->traceId;
    }

    public function getCallChain(): array
    {
        return $this->callChain;
    }

    public function getContext(): array
    {
        return $this->errorContext;
    }

    /** 判断是否为 HTTP 类型异常 */
    public function isHttp(): bool
    {
        return $this->errorType === self::TYPE_HTTP;
    }

    /** 判断是否为业务类型异常 */
    public function isBusiness(): bool
    {
        return $this->errorType === self::TYPE_BUSINESS;
    }

    /** 判断是否为运行时异常 */
    public function isRuntime(): bool
    {
        return $this->errorType === self::TYPE_RUNTIME;
    }

    /** 判断是否为系统异常 */
    public function isSystem(): bool
    {
        return $this->errorType === self::TYPE_SYSTEM;
    }

    /** 获取 HTTP 状态码 */
    public function getHttpStatusCode(): int
    {
        return match ($this->errorCode) {
            self::CODE_BAD_REQUEST => 400,
            self::CODE_UNAUTHORIZED => 401,
            self::CODE_FORBIDDEN => 403,
            self::CODE_NOT_FOUND => 404,
            self::CODE_METHOD_NOT_ALLOWED => 405,
            self::CODE_VALIDATION_FAILED => 422,
            self::CODE_TOO_MANY_REQUESTS => 429,
            self::CODE_INTERNAL_ERROR, self::CODE_SERVER_ERROR => 500,
            self::CODE_SERVICE_UNAVAILABLE => 503,
            default => 500,
        };
    }

    /** 获取异常位置信息 */
    public function getLocation(): array
    {
        return [
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'method' => $this->getCallerMethod(),
        ];
    }

    /** 获取调用者方法 */
    protected function getCallerMethod(): string
    {
        $basePath = defined('APP_ROOT') ? constant('APP_ROOT') : dirname(__DIR__);
        $trace = $this->getTrace();
        foreach ($trace as $frame) {
            if (isset($frame['file']) && str_starts_with($frame['file'], $basePath)) {
                return $this->formatMethod($frame);
            }
        }
        return isset($trace[0]) ? $this->formatMethod($trace[0]) : 'unknown';
    }

    public function toArray(): array
    {
        return [
            'code' => $this->errorCode,
            'msg' => $this->errorMsg,
            'type' => $this->errorType,
            'trace_id' => $this->traceId,
            'location' => [
                'file' => $this->getFile(),
                'line' => $this->getLine(),
                'method' => $this->getCallerMethod(),
            ],
            'context' => $this->errorContext,
            'chain' => $this->callChain,
        ];
    }

    /** 转换为统一响应格式 */
    public function toResponse(): array
    {
        return $this->toArray();
    }

    /** 设置上下文 */
    public function withContext(array $context): self
    {
        $new = clone $this;
        $new->errorContext = array_merge($this->errorContext, $context);
        return $new;
    }

    // ==================== HTTP 异常快捷方法 ====================

    /** 400 请求参数错误 */
    public static function bad(string $msg = '请求参数错误', array $context = [], ?Throwable $previous = null): self
    {
        return new self(self::CODE_BAD_REQUEST, $msg, $previous, self::TYPE_HTTP, $context);
    }

    /** 401 未授权 */
    public static function auth(string $msg = '未授权', array $context = [], ?Throwable $previous = null): self
    {
        return new self(self::CODE_UNAUTHORIZED, $msg, $previous, self::TYPE_HTTP, $context);
    }

    /** 403 禁止访问 */
    public static function deny(string $msg = '禁止访问', array $context = [], ?Throwable $previous = null): self
    {
        return new self(self::CODE_FORBIDDEN, $msg, $previous, self::TYPE_HTTP, $context);
    }

    /** 404 资源不存在 */
    public static function notFound(string $msg = '资源不存在', array $context = [], ?Throwable $previous = null): self
    {
        return new self(self::CODE_NOT_FOUND, $msg, $previous, self::TYPE_HTTP, $context);
    }

    /** 422 验证失败 */
    public static function invalid(string $msg = '验证失败', array $context = [], ?Throwable $previous = null): self
    {
        return new self(self::CODE_VALIDATION_FAILED, $msg, $previous, self::TYPE_HTTP, $context);
    }

    /** 429 请求过频 */
    public static function frequent(string $msg = '请求过于频繁', array $context = [], ?Throwable $previous = null): self
    {
        return new self(self::CODE_TOO_MANY_REQUESTS, $msg, $previous, self::TYPE_HTTP, $context);
    }

    /** 500 服务器错误 */
    public static function error(string $msg = '服务器错误', array $context = [], ?Throwable $previous = null): self
    {
        return new self(self::CODE_INTERNAL_ERROR, $msg, $previous, self::TYPE_HTTP, $context);
    }

    /** 503 服务不可用 */
    public static function unavailable(string $msg = '服务不可用', array $context = [], ?Throwable $previous = null): self
    {
        return new self(self::CODE_SERVICE_UNAVAILABLE, $msg, $previous, self::TYPE_HTTP, $context);
    }

    // ==================== 业务异常快捷方法 ====================

    /** 参数错误 */
    public static function param(string $msg, array $context = [], ?Throwable $previous = null): self
    {
        return new self(self::CODE_INVALID_PARAM, $msg, $previous, self::TYPE_BUSINESS, $context);
    }

    /** 实体不存在 */
    public static function missing(string $entity, string $id, ?Throwable $previous = null): self
    {
        return new self(
            self::CODE_NOT_FOUND_ENTITY,
            "{$entity}[{$id}] 不存在",
            $previous,
            self::TYPE_BUSINESS,
            ['entity' => $entity, 'id' => $id]
        );
    }

    /** 数据冲突 */
    public static function conflict(string $msg, array $context = [], ?Throwable $previous = null): self
    {
        return new self(self::CODE_CONFLICT, $msg, $previous, self::TYPE_BUSINESS, $context);
    }

    // ==================== 运行时异常快捷方法 ====================

    /** 协程崩溃 */
    public static function coroutinePanic(string $msg, ?Throwable $previous = null): self
    {
        return new self(
            self::CODE_COROUTINE_PANIC,
            $msg,
            $previous,
            self::TYPE_RUNTIME,
            ['suggestion' => '检查协程状态，确保正确等待']
        );
    }

    /** Worker 崩溃 */
    public static function workerCrash(string $msg, ?Throwable $previous = null): self
    {
        return new self(
            self::CODE_WORKER_CRASH,
            $msg,
            $previous,
            self::TYPE_RUNTIME,
            ['suggestion' => 'Worker 将被自动重启']
        );
    }

    /** 连接池耗尽 */
    public static function poolExhausted(string $msg, ?Throwable $previous = null): self
    {
        return new self(
            self::CODE_POOL_EXHAUSTED,
            $msg,
            $previous,
            self::TYPE_RUNTIME,
            ['suggestion' => '增加池大小或降低并发']
        );
    }

    /** 超时 */
    public static function timeout(string $msg, ?Throwable $previous = null): self
    {
        return new self(self::CODE_TIMEOUT, $msg, $previous, self::TYPE_RUNTIME);
    }

    /** 死锁 */
    public static function deadlock(string $msg, ?Throwable $previous = null): self
    {
        return new self(self::CODE_DEADLOCK, $msg, $previous, self::TYPE_RUNTIME);
    }

    // ==================== 系统异常快捷方法 ====================

    /** 内存耗尽 */
    public static function memory(string $msg = '内存耗尽', ?Throwable $previous = null): self
    {
        return new self(self::CODE_MEMORY_EXHAUSTED, $msg, $previous, self::TYPE_SYSTEM);
    }

    /** 磁盘空间不足 */
    public static function disk(string $msg = '磁盘空间不足', ?Throwable $previous = null): self
    {
        return new self(self::CODE_DISK_FULL, $msg, $previous, self::TYPE_SYSTEM);
    }

    // ==================== 增强方法 ====================

    /** 从已有异常创建（保留原始信息） */
    public static function from(Throwable $exception, ?string $errorCode = null, ?string $errorMsg = null): self
    {
        $code = $errorCode ?? 'E9999';
        $msg = $errorMsg ?? $exception->getMessage();

        if ($exception instanceof self) {
            return $exception;
        }

        return new self($code, $msg, $exception, self::TYPE_SYSTEM);
    }

    /** 重新抛出为 KodeException */
    public static function rethrow(Throwable $exception, string $errorCode, string $errorMsg, string $type = self::TYPE_SYSTEM): self
    {
        return new self($errorCode, $errorMsg, $exception, $type);
    }

    /** 验证是否为指定错误码 */
    public function isCode(string $code): bool
    {
        return $this->errorCode === $code;
    }

    /** 验证是否匹配多个错误码之一 */
    public function isCodeIn(array $codes): bool
    {
        return in_array($this->errorCode, $codes, true);
    }

    /** 获取简化的错误描述 */
    public function getSummary(): string
    {
        return sprintf(
            '[%s] %s (%s:%d)',
            $this->errorCode,
            $this->errorMsg,
            basename($this->getFile()),
            $this->getLine()
        );
    }
}