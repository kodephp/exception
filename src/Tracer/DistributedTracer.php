<?php

declare(strict_types=1);

namespace Kode\Exception\Tracer;

use Kode\Exception\KodeException;
use Throwable;

/**
 * 分布式链路追踪器
 * 支持跨机器、跨进程的完整链路追踪
 */
class DistributedTracer
{
    /** 链路节点 */
    protected array $spans = [];
    /** 当前 span ID */
    protected string $currentSpanId;
    /** trace ID */
    protected string $traceId;
    /** 开始时间 */
    protected float $startTime;
    /** 服务名称 */
    protected string $serviceName;
    /** 服务实例 ID */
    protected string $instanceId;
    /** 父追踪头 */
    protected array $parentTrace = [];

    public function __construct(string $serviceName = 'kode-app')
    {
        $this->serviceName = $serviceName;
        $this->instanceId = $this->generateInstanceId();
        $this->startTime = microtime(true);
        $this->currentSpanId = $this->generateSpanId();
        $this->traceId = $this->loadOrCreateTraceId();
        $this->loadParentTrace();
    }

    /** 加载或创建 TraceId */
    protected function loadOrCreateTraceId(): string
    {
        if (isset($_SERVER['HTTP_X_TRACE_ID'])) {
            return $_SERVER['HTTP_X_TRACE_ID'];
        }

        if (function_exists('swooleCoroutine_getuid')) {
            $cid = swooleCoroutine_getuid();
            if ($cid > 0) {
                return sprintf('%s-c%d', $this->generateTraceId(), $cid);
            }
        }

        return $this->generateTraceId();
    }

    /** 加载父追踪头 */
    protected function loadParentTrace(): void
    {
        $this->parentTrace = [
            'parent_trace_id' => $_SERVER['HTTP_X_TRACE_ID'] ?? null,
            'parent_span_id' => $_SERVER['HTTP_X_SPAN_ID'] ?? null,
        ];
    }

    /** 生成 TraceId */
    public function generateTraceId(): string
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

    /** 生成 SpanId */
    public function generateSpanId(): string
    {
        return sprintf('%016x', mt_rand(0, PHP_INT_MAX));
    }

    /** 生成实例 ID */
    protected function generateInstanceId(): string
    {
        return sprintf('i-%s-%d', gethostname() ?: 'host', getmypid());
    }

    /** 开始一个 span */
    public function startSpan(string $name, array $tags = []): string
    {
        $spanId = $this->generateSpanId();
        $parentSpanId = $this->currentSpanId;

        $this->spans[] = [
            'span_id' => $spanId,
            'parent_span_id' => $parentSpanId,
            'name' => $name,
            'trace_id' => $this->traceId,
            'service' => $this->serviceName,
            'instance' => $this->instanceId,
            'tags' => $tags,
            'start_time' => microtime(true),
            'end_time' => null,
            'duration' => null,
        ];

        $this->currentSpanId = $spanId;
        return $spanId;
    }

    /** 结束一个 span */
    public function endSpan(string $spanId, array $logs = []): void
    {
        foreach ($this->spans as &$span) {
            if ($span['span_id'] === $spanId && $span['end_time'] === null) {
                $span['end_time'] = microtime(true);
                $span['duration'] = ($span['end_time'] - $span['start_time']) * 1000;
                if (!empty($logs)) {
                    $span['logs'] = $logs;
                }
                break;
            }
        }
    }

    /** 记录异常到当前 span */
    public function recordException(Throwable $exception, ?string $spanId = null): void
    {
        $spanId = $spanId ?? $this->currentSpanId;

        $log = [
            'event' => 'error',
            'timestamp' => microtime(true),
            'error' => [
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
            ],
        ];

        if ($exception instanceof KodeException) {
            $log['error']['code'] = $exception->getErrorCode();
            $log['error']['trace_id'] = $exception->getTraceId();
            $log['error']['type'] = $exception->getErrorType();
        } else {
            $log['error']['code'] = 'E9999';
        }

        foreach ($this->spans as &$span) {
            if ($span['span_id'] === $spanId) {
                if (!isset($span['logs'])) {
                    $span['logs'] = [];
                }
                $span['logs'][] = $log;
                $span['tags']['error'] = true;
                break;
            }
        }
    }

    /** 追踪异常 */
    public function trace(Throwable $exception): array
    {
        $this->startSpan('exception', [
            'error.class' => get_class($exception),
            'error.msg' => substr($exception->getMessage(), 0, 200),
        ]);

        $this->recordException($exception);

        if ($exception instanceof KodeException) {
            $this->addTag('error.code', $exception->getErrorCode());
            $this->addTag('error.type', $exception->getErrorType());
        }

        foreach ($exception->getTrace() as $index => $frame) {
            if ($index >= 10) break;

            $funcName = '';
            if (isset($frame['class'])) {
                $funcName .= $frame['class'] . ($frame['type'] ?? '::');
            }
            $funcName .= ($frame['function'] ?? 'unknown');

            $this->addLog('call', [
                'function' => $funcName,
                'file' => isset($frame['file']) ? basename($frame['file']) : 'unknown',
                'line' => $frame['line'] ?? 0,
            ]);
        }

        return $this->buildReport($exception);
    }

    /** 添加标签 */
    public function addTag(string $key, string $value): void
    {
        if (!empty($this->spans)) {
            $lastIndex = count($this->spans) - 1;
            if (!isset($this->spans[$lastIndex]['tags'])) {
                $this->spans[$lastIndex]['tags'] = [];
            }
            $this->spans[$lastIndex]['tags'][$key] = $value;
        }
    }

    /** 添加日志 */
    public function addLog(string $event, array $fields = []): void
    {
        if (!empty($this->spans)) {
            $lastIndex = count($this->spans) - 1;
            if (!isset($this->spans[$lastIndex]['logs'])) {
                $this->spans[$lastIndex]['logs'] = [];
            }
            $this->spans[$lastIndex]['logs'][] = [
                'event' => $event,
                'timestamp' => microtime(true),
                'fields' => $fields,
            ];
        }
    }

    /** 构建追踪报告 */
    public function buildReport(Throwable $exception): array
    {
        $endTime = microtime(true);

        return [
            'trace_id' => $this->traceId,
            'spans' => $this->spans,
            'source' => [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'message' => $exception->getMessage(),
                'class' => get_class($exception),
            ],
            'environment' => $this->getEnvironment(),
            'parent_trace' => $this->parentTrace,
            'duration_ms' => ($endTime - $this->startTime) * 1000,
            'export_time' => $endTime,
        ];
    }

    /** 获取环境信息 */
    protected function getEnvironment(): array
    {
        $env = [
            'service' => $this->serviceName,
            'instance' => $this->instanceId,
            'php_version' => PHP_VERSION,
            'sapi' => php_sapi_name(),
            'pid' => getmypid(),
        ];

        if (function_exists('swoole_version')) {
            $env['runtime'] = 'swoole';
            $env['coroutine_id'] = swooleCoroutine_getuid();
        } elseif (function_exists('Fiber::getCurrent')) {
            $fiber = Fiber::getCurrent();
            $env['runtime'] = 'fiber';
            $env['fiber_id'] = $fiber ? spl_object_id($fiber) : null;
        } else {
            $env['runtime'] = 'fpm';
        }

        if (isset($_SERVER['REQUEST_URI'])) {
            $env['request_uri'] = $_SERVER['REQUEST_URI'];
            $env['request_method'] = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
        }

        return $env;
    }

    /** 获取 HTTP 响应头 */
    public function getTraceHeaders(): array
    {
        return [
            'X-Trace-Id' => $this->traceId,
            'X-Span-Id' => $this->currentSpanId,
        ];
    }

    /** 导出链路为可读字符串 */
    public function exportAsString(array $report): string
    {
        $lines = [];
        $lines[] = "═══════════════════════════════════════════════════════";
        $lines[] = "                    链路追踪报告";
        $lines[] = "═══════════════════════════════════════════════════════";
        $lines[] = "";
        $lines[] = "【追踪ID】" . ($report['trace_id'] ?? 'N/A');
        $lines[] = "【服务】" . ($report['environment']['service'] ?? 'N/A');
        $lines[] = "【运行时】" . ($report['environment']['runtime'] ?? 'N/A');
        $lines[] = "【执行时间】" . sprintf("%.2fms", $report['duration_ms'] ?? 0);
        $lines[] = "";

        if (isset($report['source'])) {
            $lines[] = "【异常源头】";
            $lines[] = sprintf(
                "  %s:%d",
                $report['source']['file'],
                $report['source']['line']
            );
            $lines[] = sprintf(
                "  %s: %s",
                $report['source']['class'],
                $report['source']['message']
            );
            $lines[] = "";
        }

        if (!empty($report['spans'])) {
            $lines[] = "【调用链路】";
            foreach ($report['spans'] as $span) {
                $duration = isset($span['duration']) ? sprintf("%.2fms", $span['duration']) : '?';
                $hasError = $span['tags']['error'] ?? false;
                $errorMark = $hasError ? ' ⚠' : '';
                $lines[] = sprintf(
                    "  [%s] %s (%s)%s",
                    $span['span_id'],
                    $span['name'],
                    $duration,
                    $errorMark
                );
            }
            $lines[] = "";
        }

        if (!empty($report['parent_trace']['parent_trace_id'])) {
            $lines[] = "【父追踪】";
            $lines[] = "  Trace: " . $report['parent_trace']['parent_trace_id'];
            $lines[] = "  Span: " . ($report['parent_trace']['parent_span_id'] ?? 'N/A');
            $lines[] = "";
        }

        $lines[] = "═══════════════════════════════════════════════════════";

        return implode("\n", $lines);
    }

    /** 获取当前 trace ID */
    public function getTraceId(): string
    {
        return $this->traceId;
    }

    /** 获取所有 spans */
    public function getSpans(): array
    {
        return $this->spans;
    }
}