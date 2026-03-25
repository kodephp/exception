<?php

declare(strict_types=1);

namespace Kode\Exception\Logger;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\IntrospectionProcessor;
use Monolog\Processor\ProcessIdProcessor;
use Monolog\Processor\PsrLogMessageProcessor;
use Monolog\Processor\WebProcessor;
use Psr\Log\LoggerInterface;

/**
 * 日志工厂
 * 创建符合项目规范的 Monolog 日志记录器
 */
class LoggerFactory
{
    /** 日志文件路径 */
    protected string $logPath;
    /** 最大保留文件数 */
    protected int $maxFiles = 14;
    /** 环境名称 */
    protected string $environment;

    public function __construct(string $logPath = '/var/log/kode', string $environment = 'production')
    {
        $this->logPath = $logPath;
        $this->environment = $environment;
    }

    /** 创建日志记录器 */
    public function createLogger(string $name = 'kode'): LoggerInterface
    {
        $logger = new Logger($name);

        $this->addHandlers($logger, $name);
        $this->addProcessors($logger);

        return $logger;
    }

    /** 创建异常专用日志记录器 */
    public function createExceptionLogger(string $name = 'exception'): LoggerInterface
    {
        $logger = new Logger($name);

        $this->addExceptionHandlers($logger, $name);
        $this->addProcessors($logger);

        return $logger;
    }

    /** 添加处理器 */
    protected function addHandlers(Logger $logger, string $name): void
    {
        if ($this->environment === 'production') {
            $fileHandler = new RotatingFileHandler(
                $this->logPath . '/' . $name . '.log',
                $this->maxFiles,
                Level::Debug
            );
            $logger->pushHandler($fileHandler);
        }

        if ($this->environment === 'development') {
            $streamHandler = new StreamHandler('php://stdout', Level::Debug);
            $logger->pushHandler($streamHandler);
        }
    }

    /** 添加异常日志处理器 */
    protected function addExceptionHandlers(Logger $logger, string $name): void
    {
        if ($this->environment === 'production') {
            $fileHandler = new RotatingFileHandler(
                $this->logPath . '/' . $name . '.log',
                $this->maxFiles,
                Level::Info
            );
            $logger->pushHandler($fileHandler);

            $errorHandler = new RotatingFileHandler(
                $this->logPath . '/error.log',
                $this->maxFiles,
                Level::Error
            );
            $logger->pushHandler($errorHandler);
        }

        if ($this->environment === 'development') {
            $streamHandler = new StreamHandler('php://stderr', Level::Debug);
            $logger->pushHandler($streamHandler);
        }
    }

    /** 添加处理器 */
    protected function addProcessors(Logger $logger): void
    {
        $logger->pushProcessor(new PsrLogMessageProcessor());
        $logger->pushProcessor(new WebProcessor());
        $logger->pushProcessor(new ProcessIdProcessor());

        if ($this->environment === 'development') {
            $logger->pushProcessor(new IntrospectionProcessor(Level::Debug, ['Monolog\\']));
        }
    }

    /** 添加上下文 */
    public function addContext(string $key, mixed $value): self
    {
        return $this;
    }

    /** 设置日志路径 */
    public function setLogPath(string $logPath): self
    {
        $this->logPath = $logPath;
        return $this;
    }

    /** 设置最大文件数 */
    public function setMaxFiles(int $maxFiles): self
    {
        $this->maxFiles = $maxFiles;
        return $this;
    }

    /** 设置环境 */
    public function setEnvironment(string $environment): self
    {
        $this->environment = $environment;
        return $this;
    }
}