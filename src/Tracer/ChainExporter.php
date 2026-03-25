<?php

declare(strict_types=1);

namespace Kode\Exception\Tracer;

use Kode\Exception\KodeException;
use Throwable;

/**
 * 链路报告导出器
 * 支持 JSON、HTML、纯文本格式输出
 */
class ChainExporter
{
    public const FORMAT_JSON = 'json';
    public const FORMAT_HTML = 'html';
    public const FORMAT_TEXT = 'text';

    protected string $format = self::FORMAT_TEXT;

    public function __construct(string $format = self::FORMAT_TEXT)
    {
        $this->format = $format;
    }

    public function setFormat(string $format): self
    {
        $this->format = $format;
        return $this;
    }

    public function export(array $report): string
    {
        return match ($this->format) {
            self::FORMAT_JSON => $this->exportJson($report),
            self::FORMAT_HTML => $this->exportHtml($report),
            default => $this->exportText($report),
        };
    }

    public function exportJson(array $report): string
    {
        return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function exportHtml(array $report): string
    {
        $traceId = $this->escapeHtml($report['trace_id'] ?? 'N/A');
        $service = $this->escapeHtml($report['environment']['service'] ?? 'N/A');
        $runtime = $this->escapeHtml($report['environment']['runtime'] ?? 'N/A');
        $duration = $this->formatDuration($report['duration_ms'] ?? 0);
        $errorCode = $this->escapeHtml($report['error_code'] ?? 'E9999');
        $errorType = $report['error_type'] ?? 'system';
        $tagClass = $this->getTagClass($errorType);
        $sourceClass = $this->escapeHtml($report['source']['class'] ?? '');
        $sourceMsg = $this->escapeHtml($report['source']['message'] ?? 'N/A');
        $sourceFile = $this->escapeHtml($report['source']['file'] ?? '');
        $sourceLine = $report['source']['line'] ?? 0;
        $pid = $report['environment']['pid'] ?? 'N/A';
        $phpVersion = $report['environment']['php_version'] ?? 'N/A';
        $sapi = $report['environment']['sapi'] ?? 'N/A';
        $exportTime = $this->formatTime($report['export_time'] ?? time());

        $chainHtml = '';
        if (!empty($report['chain'])) {
            foreach ($report['chain'] as $index => $item) {
                $num = $index + 1;
                $method = $this->escapeHtml($item['method'] ?? 'unknown()');
                $file = $this->escapeHtml($item['file'] ?? 'unknown');
                $line = $item['line'] ?? 0;
                $isPrev = ($item['is_previous'] ?? false) ? ' (上一个异常)' : '';
                $chainHtml .= '<li class="chain-item">';
                $chainHtml .= '<div class="chain-method">#' . $num . $isPrev . ' ' . $method . '</div>';
                $chainHtml .= '<div class="chain-location">📁 ' . $file . ' : ' . $line . '</div>';
                $chainHtml .= '</li>';
            }
        }

        $spanHtml = '';
        if (!empty($report['spans'])) {
            foreach ($report['spans'] as $span) {
                $hasError = $span['tags']['error'] ?? false;
                $errorMark = $hasError ? '<span class="span-error">⚠️</span>' : '';
                $spanName = $this->escapeHtml($span['name'] ?? 'unknown');
                $spanDuration = $this->formatDuration($span['duration'] ?? 0);
                $spanHtml .= '<div class="span-item">' . $errorMark . '<span class="span-name">' . $spanName . '</span><span class="span-duration">' . $spanDuration . '</span></div>';
            }
        }

        $requestInfo = '';
        if (isset($report['environment']['request_uri'])) {
            $method = $this->escapeHtml($report['environment']['request_method'] ?? 'GET');
            $uri = $this->escapeHtml($report['environment']['request_uri']);
            $requestInfo = '<div class="env-item"><span>请求:</span> ' . $method . ' ' . $uri . '</div>';
        }

        return '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>链路追踪报告 - ' . $traceId . '</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "SF Mono", Monaco, "Cascadia Code", monospace; background: #1a1a2e; color: #eee; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { color: #e94560; margin-bottom: 20px; font-size: 1.5rem; border-bottom: 2px solid #e94560; padding-bottom: 10px; }
        .card { background: #16213e; border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.3); }
        .card-title { color: #0f3460; font-size: 1.1rem; margin-bottom: 15px; padding-bottom: 8px; border-bottom: 1px solid #0f3460; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
        .info-item { background: #0f3460; padding: 12px; border-radius: 6px; }
        .info-label { color: #e94560; font-size: 0.75rem; margin-bottom: 5px; }
        .info-value { color: #fff; font-size: 0.9rem; word-break: break-all; }
        .error-box { background: rgba(233, 69, 96, 0.1); border: 1px solid #e94560; border-radius: 6px; padding: 15px; margin-top: 15px; }
        .error-msg { color: #e94560; font-size: 1rem; margin-bottom: 10px; }
        .error-source { color: #aaa; font-size: 0.85rem; }
        .chain { list-style: none; }
        .chain-item { background: #0f3460; padding: 12px; margin-bottom: 8px; border-radius: 6px; border-left: 3px solid #e94560; }
        .chain-method { color: #00d9ff; font-size: 0.9rem; }
        .chain-location { color: #888; font-size: 0.8rem; margin-top: 5px; }
        .span-item { display: flex; align-items: center; padding: 10px; background: #0f3460; margin-bottom: 8px; border-radius: 6px; }
        .span-name { flex: 1; color: #fff; }
        .span-duration { color: #00d9ff; margin-right: 15px; font-size: 0.85rem; }
        .span-error { color: #e94560; margin-right: 10px; }
        .tag { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; margin-right: 5px; }
        .tag-http { background: #3498db; }
        .tag-business { background: #2ecc71; }
        .tag-runtime { background: #f39c12; }
        .tag-system { background: #e74c3c; }
        .env-row { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 15px; }
        .env-item { background: #0f3460; padding: 8px 12px; border-radius: 4px; font-size: 0.85rem; }
        .env-item span { color: #e94560; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 链路追踪报告</h1>

        <div class="card">
            <div class="card-title">📊 追踪信息</div>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">追踪 ID</div>
                    <div class="info-value">' . $traceId . '</div>
                </div>
                <div class="info-item">
                    <div class="info-label">服务</div>
                    <div class="info-value">' . $service . '</div>
                </div>
                <div class="info-item">
                    <div class="info-label">运行时</div>
                    <div class="info-value">' . $runtime . '</div>
                </div>
                <div class="info-item">
                    <div class="info-label">执行时间</div>
                    <div class="info-value">' . $duration . '</div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-title">⚠️ 异常信息</div>
            <div class="error-box">
                <div class="error-msg">' . $sourceMsg . '</div>
                <div class="error-source">
                    <span class="tag ' . $tagClass . '">' . $errorCode . '</span>
                    ' . $sourceClass . '
                </div>
                <div class="error-source" style="margin-top:8px;">
                    📁 ' . $sourceFile . ' : ' . $sourceLine . '
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-title">🔗 调用链路</div>
            <ul class="chain">' . $chainHtml . '</ul>
        </div>

        ' . ($spanHtml ? '<div class="card"><div class="card-title">📍 Span 追踪</div>' . $spanHtml . '</div>' : '') . '

        <div class="card">
            <div class="card-title">🖥️ 环境信息</div>
            <div class="env-row">
                <div class="env-item"><span>PID:</span> ' . $pid . '</div>
                <div class="env-item"><span>PHP:</span> ' . $phpVersion . '</div>
                <div class="env-item"><span>SAPI:</span> ' . $sapi . '</div>
                ' . $requestInfo . '
            </div>
        </div>

        <div style="text-align:center; color:#666; margin-top:30px; font-size:0.8rem;">
            kode/exception | 生成时间: ' . $exportTime . '
        </div>
    </div>
</body>
</html>';
    }

    public function exportText(array $report): string
    {
        $lines = [];
        $lines[] = "╔══════════════════════════════════════════════════════════════╗";
        $lines[] = "║                    🔍 链路追踪报告                           ║";
        $lines[] = "╚══════════════════════════════════════════════════════════════╝";
        $lines[] = "";

        $lines[] = "【追踪ID】" . ($report['trace_id'] ?? 'N/A');
        $lines[] = "【服务】" . ($report['environment']['service'] ?? 'N/A');
        $lines[] = "【运行时】" . ($report['environment']['runtime'] ?? 'N/A');
        $lines[] = "【执行时间】" . $this->formatDuration($report['duration_ms'] ?? 0);
        $lines[] = "";

        if (isset($report['source'])) {
            $source = $report['source'];
            $lines[] = "【⚠️ 异常】";
            $lines[] = "  代码: " . ($report['error_code'] ?? 'E9999');
            $lines[] = "  类型: " . ($report['error_type'] ?? 'system');
            $lines[] = "  信息: " . ($source['message'] ?? 'N/A');
            $lines[] = "  位置: " . ($source['file'] ?? 'unknown') . " : " . ($source['line'] ?? 0);
            $lines[] = "";
        }

        if (!empty($report['chain'])) {
            $lines[] = "【🔗 调用链路】";
            foreach ($report['chain'] as $index => $item) {
                $num = str_pad((string)($index + 1), 2, ' ', STR_PAD_LEFT);
                $isPrev = ($item['is_previous'] ?? false) ? ' ↳' : ' →';
                $lines[] = sprintf(
                    "  %s %s %s (%s:%d)",
                    $isPrev,
                    $num,
                    $item['method'] ?? 'unknown()',
                    basename($item['file'] ?? 'unknown'),
                    $item['line'] ?? 0
                );
            }
            $lines[] = "";
        }

        if (!empty($report['spans'])) {
            $lines[] = "【📍 Span 追踪】";
            foreach ($report['spans'] as $span) {
                $hasError = $span['tags']['error'] ?? false;
                $errorMark = $hasError ? ' ⚠️' : '';
                $lines[] = sprintf(
                    "  [%s] %s (%s)%s",
                    substr($span['span_id'] ?? 'unknown', 0, 8),
                    $span['name'] ?? 'unknown',
                    $this->formatDuration($span['duration'] ?? 0),
                    $errorMark
                );
            }
            $lines[] = "";
        }

        $lines[] = "【🖥️ 环境】";
        if (isset($report['environment'])) {
            $env = $report['environment'];
            $lines[] = "  PID: " . ($env['pid'] ?? 'N/A');
            $lines[] = "  PHP: " . ($env['php_version'] ?? 'N/A');
            $lines[] = "  SAPI: " . ($env['sapi'] ?? 'N/A');
        }
        $lines[] = "";
        $lines[] = "───────────────────────────────────────────────────────────────";
        $lines[] = "kode/exception | " . date('Y-m-d H:i:s');

        return implode("\n", $lines);
    }

    protected function escapeHtml(string $str): string
    {
        return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    }

    protected function formatDuration(float $ms): string
    {
        if ($ms < 1) {
            return sprintf('%.2fμs', $ms * 1000);
        }
        if ($ms < 1000) {
            return sprintf('%.2fms', $ms);
        }
        return sprintf('%.2fs', $ms / 1000);
    }

    protected function formatTime(float $timestamp): string
    {
        return date('Y-m-d H:i:s', (int)$timestamp);
    }

    protected function getTagClass(string $type): string
    {
        return match ($type) {
            'http' => 'tag-http',
            'business' => 'tag-business',
            'runtime' => 'tag-runtime',
            default => 'tag-system',
        };
    }
}