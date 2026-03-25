# kode/exception

kode 统一异常处理包，支持 FPM / 多进程 / 多线程 / 协程环境。

## 功能特性

- **多环境支持** - 完美支持 FPM、Swoole、Workerman、Fiber 协程
- **链路追踪** - 完整异常传播链路记录，精确定位问题根源
- **位置定位** - 自动识别项目代码与第三方库堆栈
- **统一异常体系** - HTTP 异常、业务异常、运行时异常
- **全局异常处理** - 自动捕获未处理异常并返回标准 JSON 响应
- **Monolog 集成** - 完整日志支持，自动分级记录
- **生产/开发模式** - 自动切换响应详细程度

## 安装

```bash
composer require kode/exception
```

## 快速开始

```php
use Kode\Exception\ExceptionManager;
use Kode\Exception\HttpException;
use Kode\Exception\BusinessException;

// 初始化（建议在应用入口）
$manager = new ExceptionManager();
$manager->register();

// 抛出 HTTP 异常
throw HttpException::badRequest('参数错误');
throw HttpException::unauthorized('未登录');
throw HttpException::notFound('资源不存在');

// 抛出业务异常
throw BusinessException::validationFailed('验证失败', ['field' => 'email']);
throw BusinessException::notFound('User', '12345');
```

## 环境支持

### FPM 模式

```php
// Web 应用
$manager = new ExceptionManager($logger, null, true);
$manager->register();
```

### Swoole 协程

```php
use Kode\Exception\Coroutine\CoroutineExceptionHandler;
use Kode\Exception\Coroutine\WorkerExceptionGuard;

// 安全运行协程代码
$result = CoroutineExceptionHandler::runSafely(function() {
    return co::getCid();
}, function($exception, $context) {
    return ['error' => $exception->getMessage()];
});

// Worker 保护
$guard = new WorkerExceptionGuard($logger);
$guard->guard(function() {
    return processRequest();
});
```

### Workerman 多进程

```php
$guard = new WorkerExceptionGuard($logger);
$guard->guardCoroutine(function() {
    return someAsyncWork();
}, $coroutineId);
```

## 异常类型

### HttpException

HTTP 异常，用于处理 HTTP 错误状态码：

| 方法 | 状态码 | 描述 |
|------|--------|------|
| `badRequest()` | 400 | 请求参数错误 |
| `unauthorized()` | 401 | 未授权 |
| `forbidden()` | 403 | 禁止访问 |
| `notFound()` | 404 | 资源不存在 |
| `unprocessableEntity()` | 422 | 验证失败 |
| `internalServerError()` | 500 | 服务器错误 |
| `serviceUnavailable()` | 503 | 服务不可用 |

### BusinessException

业务异常，用于处理业务逻辑错误：

```php
BusinessException::invalidArgument($message, $context)  // 无效参数
BusinessException::validationFailed($message, $context)  // 验证失败
BusinessException::notFound($entity, $id)                // 资源不存在
BusinessException::conflict($message, $context)         // 数据冲突
```

### RuntimeException

运行时异常，用于多进程/协程环境：

```php
RuntimeException::coroutinePanic($message)   // 协程崩溃（不可恢复）
RuntimeException::workerCrash($message)     // Worker 崩溃（可恢复）
RuntimeException::poolExhausted($message)    // 连接池耗尽
```

## 链路追踪

### ChainTracer - 链路追踪器

```php
use Kode\Exception\Tracer\ChainTracer;

$tracer = new ChainTracer();
$report = $tracer->trace($exception);

// 输出链路报告
echo $tracer->exportAsString($report);
```

### ExceptionLocator - 位置定位器

```php
use Kode\Exception\Tracer\ExceptionLocator;

$locator = new ExceptionLocator();
$location = $locator->locate($exception);

echo $location->getSourceFile() . ':' . $location->getSourceLine();
echo $location->severity; // critical / error / warning / info
```

## 响应格式

```json
{
  "success": false,
  "error": {
    "code": "BAD_REQUEST",
    "message": "参数错误",
    "trace_id": "kode-67f3a1b2-c8d4-e925-f6a1-3b7c9e2d4f5a",
    "context": {"field": "email"},
    "http_status": 400,
    "source": {
      "file": "/app/Controller/UserController.php",
      "line": 42,
      "function": "updateUser"
    }
  }
}
```

## 配置选项

```php
use Kode\Exception\ExceptionManager;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Kode\Exception\Formatter\JsonResponseFormatter;

// 创建日志记录器
$logger = new Logger('exception');
$logger->pushHandler(new StreamHandler('path/to/exception.log'));

// 创建异常管理器
$manager = new ExceptionManager(
    $logger,              // 日志记录器
    null,                 // 格式化器（默认 JSON）
    true                  // 生产模式
);

// 添加自定义处理器
$manager->addHandler(new MyCustomHandler());

// 注册全局处理器
$manager->register();
```

## 工具类

### TraceIdGenerator

生成唯一追踪 ID：

```php
use Kode\Exception\Util\TraceIdGenerator;

$traceId = TraceIdGenerator::generate();
$traceId = TraceIdGenerator::generateFromException($exception);
```

### ExceptionTraceAnalyzer

分析异常堆栈溯源：

```php
use Kode\Exception\Util\ExceptionTraceAnalyzer;

$source = ExceptionTraceAnalyzer::findOriginalSource($exception);
echo $source['file'] . ':' . $source['line'];
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
