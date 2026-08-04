<?php

declare(strict_types=1);

namespace Kode\Exception\Enum;

/**
 * 统一错误码枚举
 *
 * 编码规则：E + 4 位数字
 *   E1xxx  HTTP 协议错误
 *   E2xxx  业务逻辑错误
 *   E3xxx  运行时错误
 *   E5xxx  系统级错误
 *   E9xxx  未分类 / 未知错误
 */
enum ErrorCode: string
{
    // ---------- E1xxx HTTP ----------
    case BadRequest = 'E1001';
    case Unauthorized = 'E1002';
    case Forbidden = 'E1003';
    case NotFound = 'E1004';
    case MethodNotAllowed = 'E1005';
    case ValidationFailed = 'E1006';
    case TooManyRequests = 'E1007';
    case UnsupportedMediaType = 'E1008';
    case RequestTimeout = 'E1009';
    case InternalError = 'E1500';
    case ServerError = 'E1501';
    case BadGateway = 'E1502';
    case ServiceUnavailable = 'E1503';
    case GatewayTimeout = 'E1504';

    // ---------- E2xxx 业务 ----------
    case InvalidParam = 'E2001';
    case NotFoundEntity = 'E2004';
    case Conflict = 'E2009';
    case Deprecated = 'E2010';
    case QuotaExceeded = 'E2011';
    case StateInvalid = 'E2012';

    // ---------- E3xxx 运行时 ----------
    case CoroutinePanic = 'E3001';
    case WorkerCrash = 'E3002';
    case PoolExhausted = 'E3003';
    case Timeout = 'E3004';
    case Deadlock = 'E3005';
    case CircuitOpen = 'E3006';
    case DependencyFailure = 'E3007';

    // ---------- E5xxx 系统 ----------
    case MemoryExhausted = 'E5001';
    case DiskFull = 'E5002';
    case FatalError = 'E5003';
    case ConfigInvalid = 'E5004';

    // ---------- E9xxx 未知 ----------
    case Unknown = 'E9999';

    /** 所属错误类型 */
    public function type(): ErrorType
    {
        return match ($this->group()) {
            1 => ErrorType::Http,
            2 => ErrorType::Business,
            3 => ErrorType::Runtime,
            5 => ErrorType::System,
            default => ErrorType::System,
        };
    }

    /** 对应 HTTP 状态码 */
    public function httpStatus(): int
    {
        return match ($this) {
            self::BadRequest, self::InvalidParam => 400,
            self::Unauthorized => 401,
            self::Forbidden => 403,
            self::NotFound, self::NotFoundEntity => 404,
            self::MethodNotAllowed => 405,
            self::RequestTimeout => 408,
            self::Conflict, self::StateInvalid => 409,
            self::Deprecated => 410,
            self::UnsupportedMediaType => 415,
            self::ValidationFailed => 422,
            self::TooManyRequests, self::QuotaExceeded => 429,
            self::BadGateway, self::DependencyFailure => 502,
            self::ServiceUnavailable, self::PoolExhausted, self::CircuitOpen => 503,
            self::GatewayTimeout, self::Timeout => 504,
            default => 500,
        };
    }

    /** 默认中文提示语 */
    public function message(): string
    {
        return match ($this) {
            self::BadRequest => '请求参数错误',
            self::Unauthorized => '未授权',
            self::Forbidden => '禁止访问',
            self::NotFound => '资源不存在',
            self::MethodNotAllowed => '请求方法不允许',
            self::ValidationFailed => '验证失败',
            self::TooManyRequests => '请求过于频繁',
            self::UnsupportedMediaType => '不支持的媒体类型',
            self::RequestTimeout => '请求超时',
            self::InternalError => '服务器错误',
            self::ServerError => '服务端异常',
            self::BadGateway => '网关错误',
            self::ServiceUnavailable => '服务不可用',
            self::GatewayTimeout => '网关超时',
            self::InvalidParam => '参数不合法',
            self::NotFoundEntity => '实体不存在',
            self::Conflict => '数据冲突',
            self::Deprecated => '接口已废弃',
            self::QuotaExceeded => '配额超限',
            self::StateInvalid => '状态不允许该操作',
            self::CoroutinePanic => '协程崩溃',
            self::WorkerCrash => 'Worker 崩溃',
            self::PoolExhausted => '连接池耗尽',
            self::Timeout => '执行超时',
            self::Deadlock => '检测到死锁',
            self::CircuitOpen => '熔断器已打开',
            self::DependencyFailure => '依赖服务异常',
            self::MemoryExhausted => '内存耗尽',
            self::DiskFull => '磁盘空间不足',
            self::FatalError => '致命错误',
            self::ConfigInvalid => '配置不合法',
            self::Unknown => '未知错误',
        };
    }

    /** 修复建议（无建议返回 null） */
    public function suggestion(): ?string
    {
        return match ($this) {
            self::CoroutinePanic => '检查协程状态，确保正确等待',
            self::WorkerCrash => 'Worker 将被自动重启',
            self::PoolExhausted => '增加池大小或降低并发',
            self::Timeout => '排查慢查询或调高超时阈值',
            self::Deadlock => '检查资源加锁顺序',
            self::CircuitOpen => '等待熔断恢复或降级处理',
            self::MemoryExhausted => '调高 memory_limit 或优化内存占用',
            self::DiskFull => '清理磁盘或扩容',
            default => null,
        };
    }

    /** 是否可恢复 */
    public function isRecoverable(): bool
    {
        return match ($this) {
            self::Timeout, self::PoolExhausted, self::CircuitOpen, self::DependencyFailure => true,
            self::MemoryExhausted, self::DiskFull, self::FatalError, self::Deadlock,
            self::CoroutinePanic, self::WorkerCrash, self::ConfigInvalid => false,
            default => $this->type()->isRecoverableByDefault(),
        };
    }

    /** 纯数字形式，E1001 => 1001 */
    public function numeric(): int
    {
        return (int) substr($this->value, 1);
    }

    /** 错误码分组（首位数字） */
    public function group(): int
    {
        return (int) $this->value[1];
    }

    /** 宽松解析，未登记的错误码返回 null */
    public static function parse(self|string|null $code): ?self
    {
        if ($code instanceof self) {
            return $code;
        }

        return $code === null ? null : self::tryFrom(strtoupper($code));
    }

    /** 校验错误码字面量是否符合 E + 4 位数字 */
    public static function isWellFormed(string $code): bool
    {
        return preg_match('/^E\d{4}$/', $code) === 1;
    }

    /** 把任意错误码字符串换算为数字 code（供 \Exception::getCode 使用） */
    public static function toNumeric(string $code): int
    {
        $digits = preg_replace('/\D+/', '', $code) ?? '';

        return $digits === '' ? 0 : (int) $digits;
    }
}
