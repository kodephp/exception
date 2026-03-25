<?php

declare(strict_types=1);

namespace Kode\Exception;

use Kode\Exception\Logger\LoggerFactory;

require_once __DIR__ . '/../vendor/autoload.php';

$environment = $_ENV['APP_ENV'] ?? $_ENV['ENVIRONMENT'] ?? 'production';
$logPath = $_ENV['LOG_PATH'] ?? __DIR__ . '/../logs';

$loggerFactory = new LoggerFactory($logPath, $environment);
$logger = $loggerFactory->createExceptionLogger('exception');

ExceptionManager::setInstance(new ExceptionManager($logger, null, $environment === 'production'));

return ExceptionManager::getInstance();