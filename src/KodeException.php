<?php

declare(strict_types=1);

namespace Kode\Exception;

use Exception;
use JsonSerializable;
use Kode\Exception\Enum\ErrorCode;
use Kode\Exception\Enum\ErrorType;
use Kode\Exception\Formatter\ResponseFormatterInterface;
use Kode\Exception\Support\ContextSanitizer;
use Kode\Exception\Support\IdGenerator;
use Kode\Exception\Support\TraceContext;
use Kode\Exception\Tracer\DistributedTracer;
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;

/**
 * 统一异常类
 *
 * 单一入口覆盖 HTTP / 业务 / 运行时 / 系统四类异常，统一错误码体系与链路追踪。
 * v3 起内部以 {@see ErrorCode} / {@see ErrorType} 枚举驱动，字符串常量保留向后兼容。
 */
class KodeException extends Exception implements JsonSerializable, Stringable
{
    // ==================== 错误类型（字符串常量，向后兼容） ====================

    public const string TYPE_HTTP = 'http';
    public const string TYPE_BUSINESS = 'business';
    public const string TYPE_RUNTIME = 'runtime';
    public const string TYPE_SYSTEM = 'system';

    // ==================== HTTP 错误码 E1xxx ====================

    public const string CODE_BAD_REQUEST = 'E1001';
    public const string CODE_UNAUTHORIZED = 'E1002';
    public const string CODE_FORBIDDEN = 'E1003';
    public const string CODE_NOT_FOUND = 'E1004';
    public const string CODE_METHOD_NOT_ALLOWED = 'E1005';
    public const string CODE_VALIDATION_FAILED = 'E1006';
    public const string CODE_TOO_MANY_REQUESTS = 'E1007';
    public const string CODE_UNSUPPORTED_MEDIA_TYPE = 'E1008';
    public const string CODE_REQUEST_TIMEOUT = 'E1009';
    public const string CODE_INTERNAL_ERROR = 'E1500';
    public const string CODE_SERVER_ERROR = 'E1501';
    public const string CODE_BAD_GATEWAY = 'E1502';
    public const string CODE_SERVICE_UNAVAILABLE = 'E1503';
    public const string CODE_GATEWAY_TIMEOUT = 'E1504';

    // ==================== 业务错误码 E2xxx ====================

    public const string CODE_INVALID_PARAM = 'E2001';
    public const string CODE_NOT_FOUND_ENTITY = 'E2004';
    public const string CODE_CONFLICT = 'E2009';
    public const string CODE_DEPRECATED = 'E2010';
    public const string CODE_QUOTA_EXCEEDED = 'E2011';
    public const string CODE_STATE_INVALID = 'E2012';

    // ==================== 运行时错误码 E3xxx ====================

    public const string CODE_COROUTINE_PANIC = 'E3001';
    public const string CODE_WORKER_CRASH = 'E3002';
    public const string CODE_POOL_EXHAUSTED = 'E3003';
    public const string CODE_TIMEOUT = 'E3004';
    public const string CODE_DEADLOCK = 'E3005';
    public const string CODE_CIRCUIT_OPEN = 'E3006';
    public const string CODE_DEPENDENCY_FAILURE = 'E3007';

    // ==================== 系统错误码 E5xxx ====================

    public const string CODE_MEMORY_EXHAUSTED = 'E5001';
    public const string CODE_DISK_FULL = 'E5002';
    public const string CODE_FATAL_ERROR = 'E5003';
    public const string CODE_CONFIG_INVALID = 'E5004';

    /** 未知错误码 */
    public const string CODE_UNKNOWN = 'E9999';

    /** 调用链最大深度 */
    public const int MAX_CHAIN_DEPTH = 15;

    /** 错误码字面量 */
    protected string $errorCode;
    /** 错误码枚举，自定义码为 null */
    protected ?ErrorCode $errorCodeEnum;
    /** 错误消息 */
    protected string $errorMsg;
    /** 错误类型 */
    protected ErrorType $type;
    /** 错误上下文（已脱敏） */
    protected array $errorContext;
    /** 链路 ID */
    protected string $traceId;
    /** Span ID */
    protected string $spanId;
    /** 调用链路 */
    protected array $callChain = [];
    /** 发生时间（微秒时间戳） */
    protected float $occurredAt;
    /** HTTP 状态码覆盖 */
    protected ?int $httpStatusOverride = null;
    /** 可恢复标记覆盖 */
    protected ?bool $recoverableOverride = null;

    /** 全局链路上下文 */
    private static ?TraceContext $traceContext = null;
    /** 链路上下文是否由宿主显式注入（true 时不再按请求头自动换绑） */
    private static bool $traceContextBound = false;
    /** 全局脱敏器 */
    private static ?ContextSanitizer $sanitizer = null;
    /** 是否采集堆栈实参 */
    private static bool $captureArgs = true;

    /**
     * @param ErrorCode|string      $errorCode 错误码，支持枚举或 E+4 位数字字面量
     * @param string|null           $errorMsg  错误消息，传 null 时取枚举默认文案
     * @param Throwable|null        $previous  上游异常
     * @param ErrorType|string|null $errorType 错误类型，传 null 时由错误码推导
     * @param array                 $context   错误上下文，输出前自动脱敏
     */
    public function __construct(
        ErrorCode|string $errorCode,
        ?string $errorMsg = null,
        ?Throwable $previous = null,
        ErrorType|string|null $errorType = null,
        #[\SensitiveParameter] array $context = []
    ) {
        $enum = ErrorCode::parse($errorCode);

        $this->errorCodeEnum = $enum;
        $this->errorCode = $enum?->value ?? (is_string($errorCode) ? strtoupper($errorCode) : self::CODE_UNKNOWN);
        $this->errorMsg = $errorMsg ?? $enum?->message() ?? '未知错误';
        $this->type = $errorType !== null
            ? ErrorType::coerce($errorType)
            : ($enum?->type() ?? ErrorType::System);
        $this->errorContext = self::sanitizer()->sanitize($context);
        $this->occurredAt = microtime(true);

        $trace = self::traceContext();
        $this->traceId = $trace->traceId;
        $this->spanId = IdGenerator::spanId();

        parent::__construct($this->errorMsg, ErrorCode::toNumeric($this->errorCode), $previous);

        $this->callChain = $this->buildCallChain($this->getTrace(), $previous);
    }

    // ==================== 全局链路上下文 ====================

    /**
     * 获取当前链路上下文，首次访问时从请求头恢复或新建
     *
     * 常驻多进程模型下 $_SERVER 的入向链路头随每个请求更新（kode/framework 的
     * TraceMiddleware 每请求回写 X-Trace-Id），故缓存必须随之换绑：否则同一 worker
     * 生命周期内全部请求共用首个 trace_id，日志与审计无法按链路串联。
     * 宿主显式 setTraceContext() 注入的上下文优先，不被自动换绑覆盖。
     */
    public static function traceContext(): TraceContext
    {
        $current = self::$traceContext;

        if ($current === null) {
            return self::$traceContext = (TraceContext::fromServer() ?? TraceContext::start());
        }

        if (!self::$traceContextBound) {
            // 先做一次廉价比对，避免每次构造异常都全量扫描 $_SERVER
            $incoming = self::incomingTraceId();

            if ($incoming !== null && $incoming !== $current->traceId) {
                return self::$traceContext = (TraceContext::fromServer() ?? TraceContext::start());
            }
        }

        return $current;
    }

    /**
     * 从入向请求头取 trace id（小写），无则 null。
     * traceparent 定长：version(2) + '-' + trace-id(32)，故 trace id 位于偏移 3、长 32。
     */
    private static function incomingTraceId(): ?string
    {
        $legacy = $_SERVER['HTTP_X_TRACE_ID'] ?? null;
        if (is_string($legacy) && $legacy !== '') {
            return strtolower($legacy);
        }

        $traceparent = $_SERVER['HTTP_TRACEPARENT'] ?? null;
        if (is_string($traceparent) && strlen($traceparent) >= 35) {
            return strtolower(substr($traceparent, 3, 32));
        }

        return null;
    }

    /** 指定链路上下文（常用于协程 / Worker 每请求初始化）；传 null 交回自动派生 */
    public static function setTraceContext(?TraceContext $context): void
    {
        self::$traceContext = $context;
        self::$traceContextBound = $context !== null;
    }

    /** 重置链路上下文，常驻进程每请求开始时调用 */
    public static function resetTraceContext(): TraceContext
    {
        self::$traceContext = null;
        self::$traceContextBound = false;

        return self::traceContext();
    }

    /** 获取全局脱敏器 */
    public static function sanitizer(): ContextSanitizer
    {
        return self::$sanitizer ??= new ContextSanitizer();
    }

    /** 替换全局脱敏器 */
    public static function setSanitizer(ContextSanitizer $sanitizer): void
    {
        self::$sanitizer = $sanitizer;
    }

    /** 是否采集堆栈实参（生产环境建议关闭） */
    public static function captureArgs(bool $enabled): void
    {
        self::$captureArgs = $enabled;
    }

    public static function isCapturingArgs(): bool
    {
        return self::$captureArgs;
    }

    // ==================== 链路构建 ====================

    protected function buildCallChain(array $trace, ?Throwable $previous): array
    {
        $chain = [];
        $skipPrefixes = ['Kode\\Exception\\', 'Monolog\\', 'Psr\\Log\\'];

        foreach ($trace as $index => $frame) {
            if ($index >= self::MAX_CHAIN_DEPTH) {
                break;
            }

            if (isset($frame['class'])) {
                foreach ($skipPrefixes as $prefix) {
                    if (str_starts_with((string) $frame['class'], $prefix)) {
                        continue 2; // 跳过框架自身帧，继续外层 trace 循环
                    }
                }
            }

            $node = [
                'file' => $frame['file'] ?? 'unknown',
                'line' => $frame['line'] ?? 0,
                'method' => $this->formatMethod($frame),
            ];

            if (self::$captureArgs) {
                $node['args'] = $this->formatArgs($frame['args'] ?? []);
            }

            $chain[] = $node;
        }

        if ($previous !== null) {
            $chain[] = [
                'file' => $previous->getFile(),
                'line' => $previous->getLine(),
                'method' => $previous::class . '::__construct',
                'msg' => self::sanitizer()->sanitizeValue($previous->getMessage()),
                'is_previous' => true,
            ];
        }

        return $chain;
    }

    protected function formatMethod(array $frame): string
    {
        $method = '';
        if (isset($frame['class'])) {
            $method .= $frame['class'] . ($frame['type'] ?? '::');
        }

        return $method . ($frame['function'] ?? 'unknown') . '()';
    }

    protected function formatArgs(array $args): array
    {
        $sanitizer = self::sanitizer();

        return array_map(
            static fn (mixed $arg): mixed => match (true) {
                is_object($arg) => $arg::class . '#' . spl_object_id($arg),
                is_array($arg) => 'array[' . count($arg) . ']',
                is_string($arg) => strlen($arg) > 30 ? substr($arg, 0, 27) . '...' : $arg,
                default => $sanitizer->sanitizeValue($arg),
            },
            array_slice($args, 0, 3)
        );
    }

    // ==================== 访问器 ====================

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** 错误码枚举，自定义错误码返回 null */
    public function getErrorCodeEnum(): ?ErrorCode
    {
        return $this->errorCodeEnum;
    }

    public function getErrorMsg(): string
    {
        return $this->errorMsg;
    }

    public function getErrorType(): string
    {
        return $this->type->value;
    }

    public function getType(): ErrorType
    {
        return $this->type;
    }

    public function getErrorContext(): array
    {
        return $this->errorContext;
    }

    public function getContext(): array
    {
        return $this->errorContext;
    }

    public function getTraceId(): string
    {
        return $this->traceId;
    }

    public function getSpanId(): string
    {
        return $this->spanId;
    }

    public function getCallChain(): array
    {
        return $this->callChain;
    }

    public function getOccurredAt(): float
    {
        return $this->occurredAt;
    }

    /** 建议的 PSR-3 日志级别 */
    public function getLogLevel(): string
    {
        return $this->type->logLevel();
    }

    public function isHttp(): bool
    {
        return $this->type === ErrorType::Http;
    }

    public function isBusiness(): bool
    {
        return $this->type === ErrorType::Business;
    }

    public function isRuntime(): bool
    {
        return $this->type === ErrorType::Runtime;
    }

    public function isSystem(): bool
    {
        return $this->type === ErrorType::System;
    }

    public function isCode(ErrorCode|string $code): bool
    {
        return $this->errorCode === ($code instanceof ErrorCode ? $code->value : strtoupper($code));
    }

    /** @param array<int, ErrorCode|string> $codes */
    public function isCodeIn(array $codes): bool
    {
        foreach ($codes as $code) {
            if ($this->isCode($code)) {
                return true;
            }
        }

        return false;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusOverride
            ?? $this->errorCodeEnum?->httpStatus()
            ?? $this->type->defaultHttpStatus();
    }

    public function isRecoverable(): bool
    {
        return $this->recoverableOverride
            ?? $this->errorCodeEnum?->isRecoverable()
            ?? $this->type->isRecoverableByDefault();
    }

    /** 修复建议：优先取上下文，其次取错误码内置建议 */
    public function getSuggestion(): ?string
    {
        $fromContext = $this->errorContext['suggestion'] ?? null;

        return is_string($fromContext) && $fromContext !== ''
            ? $fromContext
            : $this->errorCodeEnum?->suggestion();
    }

    public function getLocation(): array
    {
        return [
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'method' => $this->getCallerMethod(),
        ];
    }

    protected function getCallerMethod(): string
    {
        $trace = $this->getTrace();

        foreach ($trace as $frame) {
            if (!isset($frame['class'])) {
                continue;
            }
            if (!str_starts_with((string) $frame['class'], 'Kode\\Exception\\')) {
                return $this->formatMethod($frame);
            }
        }

        foreach ($trace as $frame) {
            if (isset($frame['function'])) {
                return $this->formatMethod($frame);
            }
        }

        return 'unknown';
    }

    /** 上游异常链（不含自身） @return list<Throwable> */
    public function getPreviousChain(): array
    {
        $chain = [];
        $previous = $this->getPrevious();

        while ($previous !== null && count($chain) < self::MAX_CHAIN_DEPTH) {
            $chain[] = $previous;
            $previous = $previous->getPrevious();
        }

        return $chain;
    }

    /** 最底层根因异常 */
    public function getRootCause(): Throwable
    {
        $chain = $this->getPreviousChain();

        return $chain === [] ? $this : $chain[array_key_last($chain)];
    }

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

    // ==================== 不可变派生 ====================

    /**
     * 以当前实例为蓝本构造一个新实例，并拷贝不可变标识与链路。
     *
     * 注意：PHP 不允许 clone 任何 Throwable 子类（含本类），
     * 因此不可变派生必须走 new 而非 clone。
     */
    protected function replicate(): static
    {
        $clone = new static(
            $this->errorCodeEnum ?? $this->errorCode,
            $this->errorMsg,
            $this->getPrevious(),
            $this->type,
            $this->errorContext
        );

        $clone->errorCode = $this->errorCode;
        $clone->errorContext = $this->errorContext;
        $clone->traceId = $this->traceId;
        $clone->spanId = $this->spanId;
        $clone->occurredAt = $this->occurredAt;
        $clone->callChain = $this->callChain;
        $clone->httpStatusOverride = $this->httpStatusOverride;
        $clone->recoverableOverride = $this->recoverableOverride;

        return $clone;
    }

    /** 追加上下文，返回新实例 */
    public function withContext(#[\SensitiveParameter] array $context): static
    {
        $clone = $this->replicate();
        $clone->errorContext = array_merge(
            $this->errorContext,
            self::sanitizer()->sanitize($context)
        );

        return $clone;
    }

    /** 覆盖 HTTP 状态码，返回新实例 */
    public function withHttpStatus(int $status): static
    {
        $clone = $this->replicate();
        $clone->httpStatusOverride = $status;

        return $clone;
    }

    /** 覆盖可恢复标记，返回新实例 */
    public function withRecoverable(bool $recoverable): static
    {
        $clone = $this->replicate();
        $clone->recoverableOverride = $recoverable;

        return $clone;
    }

    /** 挂载到指定链路，返回新实例 */
    public function withTraceId(string $traceId): static
    {
        if (!IdGenerator::isValidTraceId($traceId)) {
            throw new \InvalidArgumentException('非法 trace id，需为 32 位小写十六进制字符串');
        }

        $clone = $this->replicate();
        $clone->traceId = $traceId;

        return $clone;
    }

    // ==================== 序列化 ====================

    public function toArray(): array
    {
        $data = [
            'code' => $this->errorCode,
            'msg' => $this->errorMsg,
            'type' => $this->type->value,
            'trace_id' => $this->traceId,
            'span_id' => $this->spanId,
            'location' => $this->getLocation(),
            'context' => $this->errorContext,
            'chain' => $this->callChain,
        ];

        $suggestion = $this->getSuggestion();
        if ($suggestion !== null) {
            $data['suggestion'] = $suggestion;
        }

        return $data;
    }

    public function toResponse(): array
    {
        return $this->toArray();
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson(int $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES): string
    {
        return json_encode($this, $flags | JSON_THROW_ON_ERROR);
    }

    public function __toString(): string
    {
        return $this->getSummary();
    }

    // ==================== HTTP 异常快捷方法 ====================

    /** 400 请求参数错误 */
    public static function bad(string $msg = '请求参数错误', array $context = [], ?Throwable $previous = null): static
    {
        return new static(ErrorCode::BadRequest, $msg, $previous, ErrorType::Http, $context);
    }

    /** 401 未授权 */
    public static function auth(string $msg = '未授权', array $context = [], ?Throwable $previous = null): static
    {
        return new static(ErrorCode::Unauthorized, $msg, $previous, ErrorType::Http, $context);
    }

    /** 403 禁止访问 */
    public static function deny(string $msg = '禁止访问', array $context = [], ?Throwable $previous = null): static
    {
        return new static(ErrorCode::Forbidden, $msg, $previous, ErrorType::Http, $context);
    }

    /** 404 资源不存在 */
    public static function notFound(string $msg = '资源不存在', array $context = [], ?Throwable $previous = null): static
    {
        return new static(ErrorCode::NotFound, $msg, $previous, ErrorType::Http, $context);
    }

    /** 405 请求方法不允许 */
    public static function methodNotAllowed(string $msg = '请求方法不允许', array $context = [], ?Throwable $previous = null): static
    {
        return new static(ErrorCode::MethodNotAllowed, $msg, $previous, ErrorType::Http, $context);
    }

    /** 422 验证失败 */
    public static function invalid(string $msg = '验证失败', array $context = [], ?Throwable $previous = null): static
    {
        return new static(ErrorCode::ValidationFailed, $msg, $previous, ErrorType::Http, $context);
    }

    /** 429 请求过频 */
    public static function frequent(string $msg = '请求过于频繁', array $context = [], ?Throwable $previous = null): static
    {
        return new static(ErrorCode::TooManyRequests, $msg, $previous, ErrorType::Http, $context);
    }

    /** 500 服务器错误 */
    public static function error(string $msg = '服务器错误', array $context = [], ?Throwable $previous = null): static
    {
        return new static(ErrorCode::InternalError, $msg, $previous, ErrorType::Http, $context);
    }

    /** 502 网关错误 */
    public static function badGateway(string $msg = '网关错误', array $context = [], ?Throwable $previous = null): static
    {
        return new static(ErrorCode::BadGateway, $msg, $previous, ErrorType::Http, $context);
    }

    /** 503 服务不可用 */
    public static function unavailable(string $msg = '服务不可用', array $context = [], ?Throwable $previous = null): static
    {
        return new static(ErrorCode::ServiceUnavailable, $msg, $previous, ErrorType::Http, $context);
    }

    // ==================== 业务异常快捷方法 ====================

    /** 参数错误 */
    public static function param(string $msg, array $context = [], ?Throwable $previous = null): static
    {
        return new static(ErrorCode::InvalidParam, $msg, $previous, ErrorType::Business, $context);
    }

    /** 实体不存在 */
    public static function missing(string $entity, string $id, ?Throwable $previous = null): static
    {
        return new static(
            ErrorCode::NotFoundEntity,
            "{$entity}[{$id}] 不存在",
            $previous,
            ErrorType::Business,
            ['entity' => $entity, 'id' => $id]
        );
    }

    /** 数据冲突 */
    public static function conflict(string $msg, array $context = [], ?Throwable $previous = null): static
    {
        return new static(ErrorCode::Conflict, $msg, $previous, ErrorType::Business, $context);
    }

    /** 配额超限 */
    public static function quota(string $msg = '配额超限', array $context = [], ?Throwable $previous = null): static
    {
        return new static(ErrorCode::QuotaExceeded, $msg, $previous, ErrorType::Business, $context);
    }

    /** 状态不允许 */
    public static function stateInvalid(string $msg = '状态不允许该操作', array $context = [], ?Throwable $previous = null): static
    {
        return new static(ErrorCode::StateInvalid, $msg, $previous, ErrorType::Business, $context);
    }

    // ==================== 运行时异常快捷方法 ====================

    /** 协程崩溃 */
    public static function coroutinePanic(string $msg = '协程崩溃', array $context = [], ?Throwable $previous = null): static
    {
        return new static(ErrorCode::CoroutinePanic, $msg, $previous, ErrorType::Runtime, $context);
    }

    /** Worker 崩溃 */
    public static function workerCrash(string $msg = 'Worker 崩溃', array $context = [], ?Throwable $previous = null): static
    {
        return new static(ErrorCode::WorkerCrash, $msg, $previous, ErrorType::Runtime, $context);
    }

    /** 连接池耗尽 */
    public static function poolExhausted(string $msg = '连接池耗尽', array $context = [], ?Throwable $previous = null): static
    {
        return new static(ErrorCode::PoolExhausted, $msg, $previous, ErrorType::Runtime, $context);
    }

    /** 超时 */
    public static function timeout(string $msg = '执行超时', array $context = [], ?Throwable $previous = null): static
    {
        return new static(ErrorCode::Timeout, $msg, $previous, ErrorType::Runtime, $context);
    }

    /** 死锁 */
    public static function deadlock(string $msg = '检测到死锁', array $context = [], ?Throwable $previous = null): static
    {
        return new static(ErrorCode::Deadlock, $msg, $previous, ErrorType::Runtime, $context);
    }

    /** 熔断打开 */
    public static function circuitOpen(string $msg = '熔断器已打开', array $context = [], ?Throwable $previous = null): static
    {
        return new static(ErrorCode::CircuitOpen, $msg, $previous, ErrorType::Runtime, $context);
    }

    /** 依赖服务异常 */
    public static function dependency(string $msg = '依赖服务异常', array $context = [], ?Throwable $previous = null): static
    {
        return new static(ErrorCode::DependencyFailure, $msg, $previous, ErrorType::Runtime, $context);
    }

    // ==================== 系统异常快捷方法 ====================

    /** 内存耗尽 */
    public static function memory(string $msg = '内存耗尽', array $context = [], ?Throwable $previous = null): static
    {
        return new static(ErrorCode::MemoryExhausted, $msg, $previous, ErrorType::System, $context);
    }

    /** 磁盘空间不足 */
    public static function disk(string $msg = '磁盘空间不足', array $context = [], ?Throwable $previous = null): static
    {
        return new static(ErrorCode::DiskFull, $msg, $previous, ErrorType::System, $context);
    }

    /** 致命错误 */
    public static function fatal(string $msg = '致命错误', array $context = [], ?Throwable $previous = null): static
    {
        return new static(ErrorCode::FatalError, $msg, $previous, ErrorType::System, $context);
    }

    /** 配置不合法 */
    public static function config(string $msg = '配置不合法', array $context = [], ?Throwable $previous = null): static
    {
        return new static(ErrorCode::ConfigInvalid, $msg, $previous, ErrorType::System, $context);
    }

    // ==================== 通用构造 ====================

    /** 按错误码枚举构造，消息与类型自动推导 */
    public static function make(
        ErrorCode $code,
        ?string $msg = null,
        array $context = [],
        ?Throwable $previous = null
    ): static {
        return new static($code, $msg, $previous, $code->type(), $context);
    }

    /** 从已有异常创建；已是 KodeException 时原样返回 */
    public static function from(Throwable $exception, ErrorCode|string|null $errorCode = null, ?string $errorMsg = null): static
    {
        if ($exception instanceof static) {
            return $exception;
        }

        return new static(
            $errorCode ?? ErrorCode::Unknown,
            $errorMsg ?? $exception->getMessage(),
            $exception,
            null,
            ['origin_class' => $exception::class]
        );
    }

    /** 包装任意异常并指定错误码与类型 */
    public static function wrap(
        Throwable $exception,
        ErrorCode|string $errorCode,
        ?string $errorMsg = null,
        ErrorType|string|null $type = null,
        array $context = []
    ): static {
        return new static(
            $errorCode,
            $errorMsg ?? $exception->getMessage(),
            $exception,
            $type,
            $context + ['origin_class' => $exception::class]
        );
    }

    /** 重新抛出为 KodeException */
    public static function rethrow(
        Throwable $exception,
        ErrorCode|string $errorCode,
        string $errorMsg,
        ErrorType|string $type = ErrorType::System
    ): static {
        return new static($errorCode, $errorMsg, $exception, $type);
    }

    /** 条件成立时抛出 */
    public static function throwIf(bool $condition, self $exception): void
    {
        if ($condition) {
            throw $exception;
        }
    }

    /** 条件不成立时抛出 */
    public static function throwUnless(bool $condition, self $exception): void
    {
        if (!$condition) {
            throw $exception;
        }
    }

    // ==================== 静态便捷入口 ====================

    /** 获取异常管理器实例 */
    public static function manager(): ExceptionManager
    {
        return ExceptionManager::getInstance();
    }

    /** 初始化异常处理系统 */
    public static function init(
        bool $isProduction = false,
        ?LoggerInterface $logger = null,
        ?ResponseFormatterInterface $formatter = null,
        string $serviceName = 'kode-app'
    ): ExceptionManager {
        $manager = ExceptionManager::getInstance();

        if ($logger !== null) {
            $manager->setLogger($logger);
        }

        if ($formatter !== null) {
            $manager->setFormatter($formatter);
        }

        $manager->setProduction($isProduction);

        if ($manager->getTracer() === null) {
            $manager->createTracer($serviceName);
        }

        return $manager;
    }

    /** 获取链路追踪器 */
    public static function tracer(): ?DistributedTracer
    {
        return ExceptionManager::getInstance()->getTracer();
    }

    /** 获取日志记录器 */
    public static function logger(): LoggerInterface
    {
        return ExceptionManager::getInstance()->getLogger();
    }

    /** 格式化异常 */
    public static function format(Throwable $exception): array
    {
        return ExceptionManager::getInstance()->format($exception);
    }

    /** 渲染异常为 JSON */
    public static function render(Throwable $exception): string
    {
        return ExceptionManager::getInstance()->render($exception);
    }

    public static function log(string $level, string|Stringable $message, array $context = []): void
    {
        self::logger()->log($level, $message, $context);
    }

    public static function debug(string|Stringable $message, array $context = []): void
    {
        self::logger()->debug($message, $context);
    }

    public static function info(string|Stringable $message, array $context = []): void
    {
        self::logger()->info($message, $context);
    }

    public static function warning(string|Stringable $message, array $context = []): void
    {
        self::logger()->warning($message, $context);
    }

    public static function errorLog(string|Stringable $message, array $context = []): void
    {
        self::logger()->error($message, $context);
    }

    public static function critical(string|Stringable $message, array $context = []): void
    {
        self::logger()->critical($message, $context);
    }
}
