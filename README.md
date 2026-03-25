# kode/exception

kode 统一异常处理包，支持多进程、多线程、协程环境。

## 功能特性

- **统一异常体系** - HTTP 异常、业务异常、运行时异常
- **全局异常处理** - 自动捕获未处理异常并返回标准 JSON 响应
- **协程安全** - 支持 Swoole/Fiber 协程环境
- **Worker 保护** - 多进程环境下异常不崩溃整个 Worker
- **日志集成** - 完整 Monolog 日志支持
- **追踪溯源** - 唯一 TraceId 便于问题定位
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

// 初始化（推荐在应用入口）
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

## 异常类型

### HttpException

HTTP 异常，用于处理 HTTP 错误状态码：

```php
HttpException::badRequest($message)           // 400
HttpException::unauthorized($message)         // 401
HttpException::forbidden($message)             // 403
HttpException::notFound($message)              // 404
HttpException::unprocessableEntity($message)   // 422
HttpException::internalServerError($message)   // 500
HttpException::serviceUnavailable($message)   // 503
```

### BusinessException

业务异常，用于处理业务逻辑错误：

```php
BusinessException::invalidArgument($message, $context)  // 无效参数
BusinessException::validationFailed($message, $context)  // 验证失败
BusinessException::notFound($entity, $id)                // 资源不存在
BusinessException::conflict($message, $context)          // 数据冲突
```

### RuntimeException

运行时异常，用于多进程/协程环境：

```php
RuntimeException::coroutinePanic($message)   // 协程崩溃
RuntimeException::workerCrash($message)     // Worker 崩溃
RuntimeException::poolExhausted($message)   // 连接池耗尽
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
    "file": "/app/UserController.php",
    "line": 42
  }
}
```

## 配置选项

```php
use Kode\Exception\ExceptionManager;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// 创建日志记录器
$logger = new Logger('exception');
$logger->pushHandler(new StreamHandler('path/to/exception.log'));

// 创建异常管理器
$manager = new ExceptionManager(
    $logger,              // 日志记录器
    null,                 // 格式化器（默认 JSON）
    true                  // 是否生产模式
);

// 添加自定义处理器
$manager->addHandler(new MyCustomHandler());

// 注册全局处理器
$manager->register();
```

## 协程环境使用

```php
use Kode\Exception\Coroutine\CoroutineExceptionHandler;
use Kode\Exception\Coroutine\WorkerExceptionGuard;

// 安全运行协程代码
$result = CoroutineExceptionHandler::runSafely(function() {
    // 协程代码
    return doSomething();
}, function($exception, $context) {
    // 异常回调
    return ['error' => $exception->getMessage()];
});

// Worker 保护
$guard = new WorkerExceptionGuard($logger);
$result = $guard->guard(function() {
    return processRequest();
});
```

## 工具类

### TraceIdGenerator

生成唯一追踪 ID：

```php
use Kode\Exception\Util\TraceIdGenerator;

$traceId = TraceIdGenerator::generate();
```

### ExceptionTraceAnalyzer

分析异常堆栈溯源：

```php
use Kode\Exception\Util\ExceptionTraceAnalyzer;

$source = ExceptionTraceAnalyzer::findOriginalSource($exception);
echo $source['file'] . ':' . $source['line'];
```

## 响应输出

```php
$manager = new ExceptionManager();

$exception = HttpException::badRequest('参数错误');

// 格式化为数组
$array = $manager->format($exception);

// 渲染为 JSON 字符串
$json = $manager->render($exception);
```

## 项目规范

- PHP 版本: >=8.1
- 严格类型: `declare(strict_types=1);`
- 命名空间: `Kode\Exception`
- 测试框架: PHPUnit

## License

MIT
