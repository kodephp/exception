# kode/exception

kode 统一异常处理包，支持 FPM / 多进程 / 多线程 / 协程 / 分布式环境。

## 功能特性

- **统一异常类** - 单一 `KodeException` 类支持所有异常类型
- **统一错误码体系** - `E1xxx` HTTP / `E2xxx` 业务 / `E3xxx` 运行时 / `E5xxx` 系统
- **统一响应格式** - 直接返回 `{code, msg, type, trace_id, location, chain, ...}`
- **链路追踪** - 完整调用链路记录，精确定位问题文件和行号
- **多格式报告** - 支持 JSON、HTML、纯文本链路报告导出
- **多环境支持** - FPM、Swoole、Workerman、Fiber 协程
- **Monolog 集成** - 完整日志支持，自动分级记录

## 安装

```bash
composer require kode/exception
```

## 快速开始

```php
use Kode\Exception\KodeException;
use Kode\Exception\ExceptionManager;

// 初始化
$manager = new ExceptionManager();
$manager->register();

// 抛出异常 - HTTP 错误
throw KodeException::bad('参数错误', ['field' => 'email']);      // E1001 400
throw KodeException::auth('未授权');                               // E1002 401
throw KodeException::deny('禁止访问');                            // E1003 403
throw KodeException::notFound('资源不存在');                      // E1004 404
throw KodeException::invalid('验证失败', ['errors' => []]);      // E1006 422

// 抛出异常 - 业务错误
throw KodeException::param('参数不正确');                          // E2001
throw KodeException::missing('User', '123');                      // E2004
throw KodeException::conflict('数据冲突');                        // E2009

// 抛出异常 - 运行时错误
throw KodeException::timeout('请求超时');                         // E3004
throw KodeException::coroutinePanic('协程崩溃');                 // E3001
throw KodeException::poolExhausted('连接池耗尽');                // E3003

// 抛出异常 - 系统错误
throw KodeException::memory('内存耗尽');                           // E5001
throw KodeException::disk('磁盘空间不足');                        // E5002
```

## 统一入口（推荐）

使用 `KodeException` 统一入口进行一站式异常处理、日志记录和链路追踪：

```php
use Kode\Exception\KodeException;

// 一键初始化异常处理系统
KodeException::init(
    isProduction: false,
    serviceName: 'my-service'
);

// 快速抛出异常
throw KodeException::bad('参数错误', ['field' => 'email']);
throw KodeException::auth('未授权');
throw KodeException::notFound('资源不存在');

// 快速日志记录
KodeException::info('服务启动');
KodeException::warning('请求频繁');
KodeException::errorLog('系统错误');

// 访问管理器、追踪器、日志器
$manager = KodeException::manager();
$tracer = KodeException::tracer();
$logger = KodeException::logger();

// 格式化和渲染异常
$response = KodeException::format($exception);
$json = KodeException::render($exception);
```

## 统一响应格式

响应直接以 code 层开始，无需外层包装：

```json
{
  "code": "E1001",
  "msg": "参数错误",
  "type": "http",
  "trace_id": "67f3a1b2-c8d4-e925-f6a1-3b7c9e2d4f5a",
  "location": {
    "file": "/app/Service/UserService.php",
    "line": 42,
    "method": "App\\Service\\UserService::updateUser()"
  },
  "context": {"field": "email"},
  "chain": [
    {"file": "/app/Controller/UserController.php", "line": 20, "method": "handleRequest()"},
    {"file": "/app/Service/UserService.php", "line": 42, "method": "updateUser()"}
  ]
}
```

## 错误码体系

| 范围 | 类型 | 说明 |
|------|------|------|
| E1xxx | HTTP | HTTP 协议相关错误（400-503）|
| E2xxx | 业务 | 业务逻辑错误（参数、验证、不存在、冲突）|
| E3xxx | 运行时 | 多进程/协程/连接池等运行时错误 |
| E5xxx | 系统 | 系统级错误（内存、磁盘等）|

## 调用链路追踪

### 基本使用

```php
use Kode\Exception\KodeException;
use Kode\Exception\Tracer\DistributedTracer;
use Kode\Exception\Tracer\ChainExporter;

$tracer = new DistributedTracer('user-service');

// 开始追踪
$spanId = $tracer->startSpan('getUserById');

// ... 执行逻辑 ...

// 结束追踪
$tracer->endSpan($spanId);

// 记录异常
$tracer->recordException($exception);

// 追踪异常
$report = $tracer->trace($exception);
```

### 导出链路报告

```php
// 导出为纯文本
$exporter = new ChainExporter(ChainExporter::FORMAT_TEXT);
echo $exporter->export($report);

// 导出为 HTML（可浏览器查看）
$exporter = new ChainExporter(ChainExporter::FORMAT_HTML);
echo $exporter->export($report);

// 导出为 JSON
$exporter = new ChainExporter(ChainExporter::FORMAT_JSON);
echo $exporter->export($report);
```

### 纯文本报告示例

```
╔══════════════════════════════════════════════════════════════╗
║                    🔍 链路追踪报告                           ║
╚══════════════════════════════════════════════════════════════╝

【追踪ID】67f3a1b2-c8d4-e925-f6a1-3b7c9e2d4f5a
【服务】user-service
【运行时】fpm
【执行时间】12.34ms

【⚠️ 异常】
  代码: E1001
  类型: http
  信息: 参数错误
  位置: /app/Service/UserService.php : 42

【🔗 调用链路】
   → #1 App\Controller\UserController::handleRequest() (user.php:20)
   → #2 App\Service\UserService::updateUser() (user.php:42)

【🖥️ 环境】
  PID: 12345
  PHP: 8.2.0
  SAPI: fpm

───────────────────────────────────────────────────────────────
kode/exception | 2024-01-15 10:30:45
```

## HTTP 头

响应自动包含以下 HTTP 头：

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

go(function() use ($tracer) {
    $spanId = $tracer->startSpan('async-task');
    // ... 异步逻辑 ...
    $tracer->endSpan($spanId);
});
```

### 跨服务调用

```php
// 调用前获取追踪头
$headers = $tracer->getTraceHeaders();
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'X-Trace-Id: ' . $headers['X-Trace-Id'],
    'X-Span-Id: ' . $headers['X-Span-Id'],
]);

// 被调用方自动接收父追踪
```

## 配置

```php
use Kode\Exception\ExceptionManager;
use Kode\Exception\Tracer\DistributedTracer;
use Kode\Exception\Tracer\ChainExporter;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('exception');
$logger->pushHandler(new StreamHandler('exception.log'));

$manager = new ExceptionManager($logger, null, false);
$manager->register();

// 创建链路追踪
$tracer = $manager->createTracer('my-service');
```

## 异常位置信息

每个异常都包含精确定位信息：

```php
$e = KodeException::bad('参数错误');

// 获取位置信息
$location = $e->getLocation();
// ['file' => '/app/UserService.php', 'line' => 42, 'method' => 'update()']

// 获取调用链路
$chain = $e->getCallChain();
// [['file' => ..., 'line' => ..., 'method' => '...()'], ...]
```

## 类型判断方法

```php
$e = KodeException::bad('参数错误');

// 判断异常类型
$e->isHttp();      // true
$e->isBusiness();  // false
$e->isRuntime();   // false
$e->isSystem();    // false

// 获取 HTTP 状态码
$e->getHttpStatusCode(); // 400

// 判断错误码
$e->isCode('E1001');           // true
$e->isCodeIn(['E1001','E1002']); // true
```

## 异常转换

```php
// 将任意异常转换为 KodeException
$original = new RuntimeException('原始错误');
$kode = KodeException::from($original, 'E9999', '包装后的错误');

// 重新抛出为 KodeException
$kode = KodeException::rethrow($original, 'E3001', '运行时错误', KodeException::TYPE_RUNTIME);

// 获取简化描述
$e->getSummary(); // [E1001] 参数错误 (UserService.php:42)
```

## 项目规范

- PHP 版本: >=8.1
- 严格类型: `declare(strict_types=1);`
- 命名空间: `Kode\Exception`
- 测试框架: PHPUnit

## 依赖

- `psr/log`: ^3.0
- `monolog/monolog`: ^3.0

## License

MIT
