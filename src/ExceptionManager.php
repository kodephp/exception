<?php

declare(strict_types=1);

namespace Kode\Exception;

use ErrorException;
use JsonException;
use Kode\Exception\Enum\ErrorCode;
use Kode\Exception\Formatter\ResponseFormatterInterface;
use Kode\Exception\Formatter\UnifiedResponseFormatter;
use Kode\Exception\Handler\ExceptionHandlerInterface;
use Kode\Exception\Support\Runtime;
use Kode\Exception\Support\TraceContext;
use Kode\Exception\Tracer\DistributedTracer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * 异常管理器
 *
 * 负责全局异常接管、处理器责任链分发、响应格式化与日志记录。
 *
 * v3 主要变化：
 *  - handlers 责任链真正生效（v2 该属性从未被使用）
 *  - 新增 reportable / renderable 回调扩展点
 *  - 新增 register_shutdown_function 致命错误兜底
 *  - 响应头值统一过滤，杜绝 CRLF 注入
 */
class ExceptionManager
{
    /** 致命错误级别掩码 */
    public const int FATAL_ERROR_MASK = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;

    protected LoggerInterface $logger;
    protected ResponseFormatterInterface $formatter;

    /** @var list<ExceptionHandlerInterface> 按优先级降序 */
    protected array $handlers = [];
    /** @var list<callable(Throwable):mixed> */
    protected array $reportCallbacks = [];
    /** @var list<callable(Throwable):(array|null)> */
    protected array $renderCallbacks = [];
    /** @var list<class-string<Throwable>> 不记录日志的异常类型 */
    protected array $dontReport = [];

    protected bool $isProduction;
    protected bool $isRegistered = false;
    protected bool $shutdownRegistered = false;
    protected bool $sendHeaders = true;
    protected ?DistributedTracer $tracer = null;

    protected static ?self $instance = null;

    public function __construct(
        ?LoggerInterface $logger = null,
        ?ResponseFormatterInterface $formatter = null,
        bool $isProduction = false
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->formatter = $formatter ?? new UnifiedResponseFormatter($isProduction);
        $this->isProduction = $isProduction;

        KodeException::captureArgs(!$isProduction);
    }

    public static function getInstance(): static
    {
        return self::$instance ??= new static();
    }

    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    /** 重置单例（测试或 Worker 重启时使用） */
    public static function reset(): void
    {
        self::$instance = null;
    }

    // ==================== 全局接管 ====================

    /** 注册全局异常 / 错误 / 致命错误处理器 */
    public function register(bool $withShutdown = true): static
    {
        if ($this->isRegistered) {
            return $this;
        }

        set_exception_handler($this->handleException(...));
        set_error_handler($this->handleError(...));

        if ($withShutdown && !$this->shutdownRegistered) {
            register_shutdown_function($this->handleShutdown(...));
            $this->shutdownRegistered = true;
        }

        $this->isRegistered = true;

        return $this;
    }

    public function unregister(): static
    {
        if (!$this->isRegistered) {
            return $this;
        }

        restore_error_handler();
        restore_exception_handler();
        $this->isRegistered = false;

        return $this;
    }

    public function isRegistered(): bool
    {
        return $this->isRegistered;
    }

    /**
     * 常驻进程每请求开始时调用：重置链路上下文并按需继承上游 traceparent。
     */
    public function startRequest(?array $server = null): TraceContext
    {
        $context = TraceContext::fromServer($server) ?? TraceContext::start();
        KodeException::setTraceContext($context);

        return $context;
    }

    // ==================== 处理器责任链 ====================

    /** 注册异常处理器，按优先级降序执行 */
    public function addHandler(ExceptionHandlerInterface $handler): static
    {
        $this->handlers[] = $handler;
        usort(
            $this->handlers,
            static fn (ExceptionHandlerInterface $a, ExceptionHandlerInterface $b): int
                => $b->getPriority() <=> $a->getPriority()
        );

        return $this;
    }

    /** @param iterable<ExceptionHandlerInterface> $handlers */
    public function addHandlers(iterable $handlers): static
    {
        foreach ($handlers as $handler) {
            $this->addHandler($handler);
        }

        return $this;
    }

    /** @return list<ExceptionHandlerInterface> */
    public function getHandlers(): array
    {
        return $this->handlers;
    }

    public function clearHandlers(): static
    {
        $this->handlers = [];

        return $this;
    }

    /** 沿责任链分发异常，返回是否已被处理 */
    public function dispatch(Throwable $exception): bool
    {
        foreach ($this->handlers as $handler) {
            try {
                if ($handler->handle($exception)) {
                    return true;
                }
            } catch (Throwable $handlerError) {
                $this->logger->error('异常处理器自身抛出异常', [
                    'handler' => $handler::class,
                    'error' => $handlerError->getMessage(),
                ]);
            }
        }

        return false;
    }

    // ==================== 扩展回调 ====================

    /** 注册上报回调；回调返回 false 可阻止默认日志记录 */
    public function reportable(callable $callback): static
    {
        $this->reportCallbacks[] = $callback;

        return $this;
    }

    /** 注册渲染回调；回调返回数组即作为最终响应体 */
    public function renderable(callable $callback): static
    {
        $this->renderCallbacks[] = $callback;

        return $this;
    }

    /** 忽略指定异常类型的日志上报 @param class-string<Throwable> ...$classes */
    public function dontReport(string ...$classes): static
    {
        foreach ($classes as $class) {
            if (!in_array($class, $this->dontReport, true)) {
                $this->dontReport[] = $class;
            }
        }

        return $this;
    }

    public function shouldReport(Throwable $exception): bool
    {
        foreach ($this->dontReport as $class) {
            if ($exception instanceof $class) {
                return false;
            }
        }

        return true;
    }

    // ==================== 处理入口 ====================

    public function handleException(Throwable $exception): void
    {
        try {
            $this->report($exception);

            $response = $this->format($exception);
            $this->sendResponse($response, $this->determineStatusCode($exception));
        } catch (Throwable $handlerError) {
            // 全局异常处理器是最后一道防线，自身绝不能再抛出，否则会暴露裸 fatal。
            if ($this->sendHeaders && !headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }

            echo json_encode([
                'code' => ErrorCode::Unknown->value,
                'msg' => '内部错误处理失败',
                'type' => 'system',
            ], JSON_UNESCAPED_UNICODE);

            try {
                $this->logger->critical('全局异常处理器自身失败', [
                    'error' => $handlerError->getMessage(),
                    'original' => $exception->getMessage(),
                ]);
            } catch (Throwable) {
                // 日志组件也坏了，放弃记录
            }
        }
    }

    /** 仅上报不输出，适用于捕获后继续执行的场景 */
    public function report(Throwable $exception): void
    {
        foreach ($this->reportCallbacks as $callback) {
            if ($callback($exception) === false) {
                return;
            }
        }

        if (!$this->shouldReport($exception)) {
            return;
        }

        if ($this->dispatch($exception)) {
            return;
        }

        $this->logger->log(
            $exception instanceof KodeException ? $exception->getLogLevel() : 'error',
            $exception->getMessage(),
            $this->buildLogContext($exception)
        );
    }

    /** PHP 错误转异常；返回 false 交回 PHP 默认处理 */
    public function handleError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if ((error_reporting() & $severity) === 0) {
            return false;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    /** 脚本结束时兜底捕获致命错误（v2 缺失） */
    public function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error === null || ($error['type'] & self::FATAL_ERROR_MASK) === 0) {
            return;
        }

        $exception = new KodeException(
            ErrorCode::FatalError,
            $error['message'],
            null,
            null,
            [
                'file' => $error['file'],
                'line' => $error['line'],
                'severity' => $error['type'],
                'runtime' => Runtime::name(),
            ]
        );

        $this->report($exception);

        if (!$this->isProduction) {
            $this->sendResponse($this->format($exception), 500);
        }
    }

    // ==================== 格式化与输出 ====================

    public function format(Throwable $exception): array
    {
        foreach ($this->renderCallbacks as $callback) {
            $rendered = $callback($exception);
            if (is_array($rendered)) {
                return $rendered;
            }
        }

        return $this->formatter->format($exception);
    }

    public function render(Throwable $exception): string
    {
        return $this->encode($this->format($exception));
    }

    /**
     * 程序化处理异常：上报日志并返回 [状态码, 响应体]。
     *
     * 适用于 Swoole / Workerman / Fiber 等不走 PHP 原生 http 输出的运行时，
     * 调用方自行把状态码与响应体写入对应响应对象，无需本类 echo。
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function respond(Throwable $exception): array
    {
        $this->report($exception);

        return [
            'status' => $this->determineStatusCode($exception),
            'body' => $this->format($exception),
        ];
    }

    protected function determineStatusCode(Throwable $exception): int
    {
        return $exception instanceof KodeException ? $exception->getHttpStatusCode() : 500;
    }

    protected function buildLogContext(Throwable $exception): array
    {
        $context = [
            'exception_class' => $exception::class,
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'runtime' => Runtime::name(),
        ];

        if ($exception instanceof KodeException) {
            $context['code'] = $exception->getErrorCode();
            $context['type'] = $exception->getErrorType();
            $context['trace_id'] = $exception->getTraceId();
            $context['span_id'] = $exception->getSpanId();
            $context['context'] = $exception->getErrorContext();
        }

        return $context;
    }

    protected function sendResponse(array $response, int $statusCode): void
    {
        if ($this->sendHeaders && !headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: ' . $this->formatter->getContentType());

            foreach ($this->buildTraceHeaders($response) as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        echo $this->encode($response);
    }

    /**
     * 构建链路响应头。
     * 所有值均经过白名单过滤，避免把请求侧可控内容原样回写造成响应头注入。
     *
     * @return array<string, string>
     */
    protected function buildTraceHeaders(array $response): array
    {
        $headers = [];

        $traceId = $this->safeHeaderValue((string) ($response['trace_id'] ?? ''));
        if ($traceId !== '') {
            $headers['X-Trace-Id'] = $traceId;
        }

        $spanId = $this->safeHeaderValue((string) ($response['span_id'] ?? ''));
        if ($spanId !== '') {
            $headers['X-Span-Id'] = $spanId;
        }

        return $headers;
    }

    /** 仅保留可见 ASCII，剔除控制字符（含 CR/LF）与 DEL，长度上限 200 */
    protected function safeHeaderValue(string $value): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';

        return substr($clean, 0, 200);
    }

    /** 安全 JSON 编码，失败时降级为最小可用响应 */
    protected function encode(array $data): string
    {
        try {
            return json_encode(
                $data,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );
        } catch (JsonException $e) {
            $fallback = json_encode([
                'code' => ErrorCode::Unknown->value,
                'msg' => 'JSON 编码失败: ' . $e->getMessage(),
                'type' => 'system',
                'trace_id' => $this->safeHeaderValue((string) ($data['trace_id'] ?? 'unknown')),
            ], JSON_UNESCAPED_UNICODE);

            return $fallback !== false ? $fallback : '{"code":"E9999","msg":"encode failed","type":"system"}';
        }
    }

    // ==================== 配置访问器 ====================

    public function isProduction(): bool
    {
        return $this->isProduction;
    }

    public function setProduction(bool $isProduction): static
    {
        $this->isProduction = $isProduction;
        KodeException::captureArgs(!$isProduction);

        if ($this->formatter instanceof UnifiedResponseFormatter) {
            $this->formatter = new UnifiedResponseFormatter($isProduction);
        }

        return $this;
    }

    /** 关闭 header() 输出，便于测试或在 Swoole/Workerman 中自行接管响应 */
    public function setSendHeaders(bool $sendHeaders): static
    {
        $this->sendHeaders = $sendHeaders;

        return $this;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    public function setLogger(LoggerInterface $logger): static
    {
        $this->logger = $logger;

        return $this;
    }

    public function getFormatter(): ResponseFormatterInterface
    {
        return $this->formatter;
    }

    public function setFormatter(ResponseFormatterInterface $formatter): static
    {
        $this->formatter = $formatter;

        return $this;
    }

    public function getTracer(): ?DistributedTracer
    {
        return $this->tracer;
    }

    public function setTracer(DistributedTracer $tracer): static
    {
        $this->tracer = $tracer;

        return $this;
    }

    public function createTracer(string $serviceName = 'kode-app'): DistributedTracer
    {
        return $this->tracer = new DistributedTracer($serviceName);
    }
}
