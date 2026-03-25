<?php

declare(strict_types=1);

namespace Kode\Exception;

use Kode\Exception\Formatter\ResponseFormatterInterface;
use Kode\Exception\Formatter\UnifiedResponseFormatter;
use Kode\Exception\Handler\ExceptionHandlerInterface;
use Kode\Exception\Tracer\DistributedTracer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * 异常管理器
 * 全局异常处理、响应格式化、日志记录
 */
class ExceptionManager
{
    /** 日志记录器 */
    protected LoggerInterface $logger;
    /** 响应格式化器 */
    protected ResponseFormatterInterface $formatter;
    /** 异常处理器列表 */
    protected array $handlers = [];
    /** 是否生产模式 */
    protected bool $isProduction;
    /** 是否已注册全局处理器 */
    protected bool $isRegistered = false;
    /** 单例实例 */
    protected static ?ExceptionManager $instance = null;
    /** 链路追踪器 */
    protected ?DistributedTracer $tracer = null;

    public function __construct(
        ?LoggerInterface $logger = null,
        ?ResponseFormatterInterface $formatter = null,
        bool $isProduction = false
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->formatter = $formatter ?? new UnifiedResponseFormatter($isProduction);
        $this->isProduction = $isProduction;
    }

    /** 获取单例实例 */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** 设置单例实例 */
    public static function setInstance(self $instance): void
    {
        self::$instance = $instance;
    }

    /** 注册全局异常处理器 */
    public function register(): self
    {
        if ($this->isRegistered) {
            return $this;
        }

        set_exception_handler([$this, 'handleException']);
        set_error_handler([$this, 'handleError']);

        $this->isRegistered = true;
        return $this;
    }

    /** 取消注册全局异常处理器 */
    public function unregister(): void
    {
        restore_error_handler();
        restore_exception_handler();
        $this->isRegistered = false;
    }

    /** 处理异常 */
    public function handleException(Throwable $exception): void
    {
        $this->logger->error('Exception: ' . $exception->getMessage(), [
            'exception' => $exception,
            'trace_id' => method_exists($exception, 'getTraceId') ? $exception->getTraceId() : null,
        ]);

        $response = $this->formatter->format($exception);
        $statusCode = $this->determineStatusCode($exception);

        $this->sendResponse($response, $statusCode);
    }

    /** 处理错误（将错误转换为异常） */
    public function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    /** 格式化异常为数组 */
    public function format(Throwable $exception): array
    {
        return $this->formatter->format($exception);
    }

    /** 渲染异常为 JSON 字符串 */
    public function render(Throwable $exception): string
    {
        return json_encode($this->format($exception), JSON_THROW_ON_ERROR);
    }

    /** 根据异常类型确定 HTTP 状态码 */
    protected function determineStatusCode(Throwable $exception): int
    {
        if ($exception instanceof KodeException) {
            $code = $exception->getErrorCode();

            if (in_array($code, [KodeException::CODE_UNAUTHORIZED])) {
                return 401;
            }
            if (in_array($code, [KodeException::CODE_FORBIDDEN])) {
                return 403;
            }
            if (in_array($code, [KodeException::CODE_NOT_FOUND, KodeException::CODE_NOT_FOUND_ENTITY])) {
                return 404;
            }
            if (in_array($code, [KodeException::CODE_VALIDATION_FAILED, KodeException::CODE_INVALID_PARAM])) {
                return 422;
            }
            if (in_array($code, [KodeException::CODE_TOO_MANY_REQUESTS])) {
                return 429;
            }
            if (in_array($code, [KodeException::CODE_INTERNAL_ERROR, KodeException::CODE_SERVER_ERROR])) {
                return 500;
            }
            if (in_array($code, [KodeException::CODE_SERVICE_UNAVAILABLE])) {
                return 503;
            }
        }

        return 500;
    }

    /** 发送响应 */
    protected function sendResponse(array $response, int $statusCode): void
    {
        if (headers_sent($file, $line)) {
            echo json_encode($response, JSON_THROW_ON_ERROR);
            return;
        }

        http_response_code($statusCode);
        header('Content-Type: ' . $this->formatter->getContentType());
        header('X-Trace-Id: ' . ($response['trace_id'] ?? 'unknown'));

        if (isset($response['span_id'])) {
            header('X-Span-Id: ' . $response['span_id']);
        }

        echo json_encode($response, JSON_THROW_ON_ERROR);
    }

    /** 是否生产模式 */
    public function isProduction(): bool
    {
        return $this->isProduction;
    }

    /** 设置生产模式 */
    public function setProduction(bool $isProduction): self
    {
        $this->isProduction = $isProduction;
        return $this;
    }

    /** 获取日志记录器 */
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /** 设置日志记录器 */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    /** 获取链路追踪器 */
    public function getTracer(): ?DistributedTracer
    {
        return $this->tracer;
    }

    /** 设置链路追踪器 */
    public function setTracer(DistributedTracer $tracer): self
    {
        $this->tracer = $tracer;
        return $this;
    }

    /** 创建链路追踪器 */
    public function createTracer(string $serviceName = 'kode-app'): DistributedTracer
    {
        $this->tracer = new DistributedTracer($serviceName);
        return $this->tracer;
    }
}