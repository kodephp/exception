# kode/exception

kode 统一异常处理包，支持 FPM / 多进程 / 多线程 / 协程 / 分布式环境。

## 功能特性

- **统一异常类** - 单一 `KodeException` 类支持所有异常类型
- **统一错误码体系** - `E1001` HTTP / `E2001` 业务 / `E3001` 运行时 / `E5001` 系统
- **统一响应格式** - `{success, error: {code, msg, type, trace_id, ...}}`
- **分布式链路追踪** - 支持跨机器、跨进程完整链路追踪
- **多环境支持** - FPM、Swoole、Workerman、Fiber 协程
- **Monolog 集成** - 完整日志支持，自动分级记录

## 安装

```bash
composer require kode/exception
```

## 快速开始

```php
use Kode\Exception\KodeException;

// 初始化
$manager = new ExceptionManager();
$manager->register();

// HTTP 错误 - 4xx
throw KodeException::bad('参数错误', ['field' => 'email']);      // E1001 400
throw KodeException::auth('未授权');                            // E1002 401
throw KodeException::deny('禁止访问');                          // E1003 403
throw KodeException::notFound('资源不存在');                   // E1004 404
throw KodeException::invalid('验证失败', ['errors' => []]);    // E1006 422

// 业务错误
throw KodeException::param('参数不正确');                       // E2001
throw KodeException::missing('User', '123');                    // E2004
throw KodeException::conflict('数据冲突');                      // E2009

// 运行时错误
throw KodeException::coroutinePanic('协程崩溃');                // E3001
throw KodeException::workerCrash('Worker 崩溃');               // E3002
throw KodeException::poolExhausted('连接池耗尽');              // E3003
throw KodeException::timeout('请求超时');                       // E3004

// 系统错误
throw KodeException::memory('内存耗尽');                        // E5001
throw KodeException::disk('磁盘空间不足');                     // E5002
```

## 错误码体系

| 范围 | 类型 | 说明 |
|------|------|------|
| E1xxx | HTTP | HTTP 协议相关错误 |
| E2xxx | 业务 | 业务逻辑错误 |
| E3xxx | 运行时 | 多进程/协程/连接池等运行时错误 |
| E5xxx | 系统 | 系统级错误（内存、磁盘等）|

## 统一响应格式

```json
{
  "success": false,
  "error": {
    "code": "E1001",
    "msg": "参数错误",
    "type": "http",
    "trace_id": "67f3a1b2-c8d4-e925-f6a1-3b7c9e2d4f5a",
    "context": {"field": "email"},
    "trace_chain": [...],
    "distributed": {
      "trace_id": "...",
      "span_id": "...",
      "parent_trace_id": "..."
    }
  }
}
```

## 分布式链路追踪

```php
use Kode\Exception\Tracer\DistributedTracer;

// 创建链路追踪器
$tracer = new DistributedTracer('user-service');

// 开始一个调用 span
$spanId = $tracer->startSpan('getUser');

// ... 执行逻辑 ...

// 结束 span
$tracer->endSpan($spanId);

// 记录异常
$tracer->recordException($exception);

// 追踪异常链路
$report = $tracer->trace($exception);

// 导出可读报告
echo $tracer->exportAsString($report);
```

### 链路报告示例

```
═══════════════════════════════════════════════════════
                    链路追踪报告
═══════════════════════════════════════════════════════

【追踪ID】67f3a1b2-c8d4-e925-f6a1-3b7c9e2d4f5a
【服务】user-service
【运行时】fpm
【执行时间】12.34ms

【异常源头】
  /app/Service/UserService.php:42
  Kode\Exception\KodeException: 参数错误

【调用链路】
  [a1b2c3d4e5f67890] getUser (5.21ms) ⚠
  [b2c3d4e5f6789012] database.query (3.12ms)
  [c3d4e5f678901234] cache.get (1.05ms)

═══════════════════════════════════════════════════════
```

## HTTP 头

响应会自动包含以下 HTTP 头：

- `X-Trace-Id`: 追踪 ID
- `X-Span-Id`: 当前 Span ID

## 环境支持

### FPM

```php
$manager = new ExceptionManager(null, null, true);
$manager->register();
```

### Swoole 协程

```php
$tracer = $manager->createTracer('swoole-service');
go(function() {
    $spanId = $tracer->startSpan('async-task');
    // ...
    $tracer->endSpan($spanId);
});
```

### 跨服务调用

```php
// 调用前设置追踪头
$headers = $tracer->getTraceHeaders();
$response = curl_request($url, $headers);

// 被调用方自动接收父追踪
```

## 配置

```php
use Kode\Exception\ExceptionManager;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('exception');
$logger->pushHandler(new StreamHandler('exception.log'));

$manager = new ExceptionManager($logger, null, false);
$manager->register();
```

## 项目规范

- PHP 版本: >=8.1
- 严格类型: `declare(strict_types=1);`
- 命名空间: `Kode\Exception`
- 测试框架: PHPUnit

## License

MIT
