<?php

declare(strict_types=1);

namespace App\Core;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger as Monolog;
use Monolog\LogRecord;
use App\Support\Redactor;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LoggerInterface;

/**
 * Builds the application PSR-3 logger (Monolog, daily files in storage/logs).
 * Use: logger()->info('Booking {no} approved', ['no' => $no]);
 */
final class Logger
{
    public static function create(string $path, string $level = 'debug', string $channel = 'app'): LoggerInterface
    {
        $logger = new Monolog($channel);
        $handler = new RotatingFileHandler($path, 30, Level::fromName(ucfirst(strtolower($level))), true, 0664);
        $handler->setFormatter(new LineFormatter(null, 'Y-m-d H:i:s', true, true));
        $logger->pushHandler($handler);
        // Processors run last-pushed first: context is redacted before {placeholders} are interpolated, and the
        // final message is scrubbed again afterwards (full Aadhaar numbers, passwords, tokens — see Redactor).
        $logger->pushProcessor(static fn (LogRecord $r): LogRecord => $r->with(
            message: Redactor::string($r->message),
            context: Redactor::array($r->context),
        ));
        $logger->pushProcessor(new PsrLogMessageProcessor());
        $logger->pushProcessor(static fn (LogRecord $r): LogRecord => $r->with(context: Redactor::array($r->context)));
        return $logger;
    }
}
