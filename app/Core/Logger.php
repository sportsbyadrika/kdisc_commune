<?php

declare(strict_types=1);

namespace App\Core;

use App\Support\Redactor;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;

/**
 * The application PSR-3 logger: daily files storage/logs/app-YYYY-MM-DD.log (30 kept), one line per entry
 * ("[Y-m-d H:i:s] app.LEVEL: message {context}"), {placeholders} interpolated from the context.
 * Use: logger()->info('Booking {no} approved', ['no' => $no]);
 *
 * Deliberately self-contained (no Monolog): shared cPanel/CloudLinux PHP often loads the "psr" extension, which
 * defines the psr/log 1.x interfaces itself (PsrExt\Log\LoggerInterface, untyped). Monolog 3 cannot implement those,
 * and the extension wins over vendor/psr/log. The method signatures below (untyped $message, `: void`) are valid
 * implementations of psr/log 1.x, 2.x and 3.x alike.
 *
 * Sensitive data: context is redacted before interpolation and the final message is scrubbed again (full Aadhaar
 * numbers, passwords, tokens — see App\Support\Redactor).
 */
final class Logger implements LoggerInterface
{
    /** RFC 5424 severities, Monolog-compatible numbers. */
    public const LEVELS = [
        LogLevel::DEBUG => 100,
        LogLevel::INFO => 200,
        LogLevel::NOTICE => 250,
        LogLevel::WARNING => 300,
        LogLevel::ERROR => 400,
        LogLevel::CRITICAL => 500,
        LogLevel::ALERT => 550,
        LogLevel::EMERGENCY => 600,
    ];

    private const KEEP_FILES = 30;

    private readonly int $threshold;

    /** @var list<callable(LogRecord): void> */
    private array $listeners = [];

    private ?string $prunedFor = null;

    public function __construct(
        private readonly string $path,
        string $level = LogLevel::DEBUG,
        private readonly string $channel = 'app',
    ) {
        $this->threshold = self::LEVELS[strtolower($level)] ?? self::LEVELS[LogLevel::DEBUG];
    }

    public static function create(string $path, string $level = 'debug', string $channel = 'app'): self
    {
        return new self($path, $level, $channel);
    }

    /** Receive every record (after redaction), whatever the file threshold — used by tests. */
    public function listen(callable $listener): void
    {
        $this->listeners[] = $listener;
    }

    /** @param string|Stringable $message @param array<mixed> $context */
    public function emergency($message, array $context = []): void { $this->log(LogLevel::EMERGENCY, $message, $context); }

    /** @param string|Stringable $message @param array<mixed> $context */
    public function alert($message, array $context = []): void { $this->log(LogLevel::ALERT, $message, $context); }

    /** @param string|Stringable $message @param array<mixed> $context */
    public function critical($message, array $context = []): void { $this->log(LogLevel::CRITICAL, $message, $context); }

    /** @param string|Stringable $message @param array<mixed> $context */
    public function error($message, array $context = []): void { $this->log(LogLevel::ERROR, $message, $context); }

    /** @param string|Stringable $message @param array<mixed> $context */
    public function warning($message, array $context = []): void { $this->log(LogLevel::WARNING, $message, $context); }

    /** @param string|Stringable $message @param array<mixed> $context */
    public function notice($message, array $context = []): void { $this->log(LogLevel::NOTICE, $message, $context); }

    /** @param string|Stringable $message @param array<mixed> $context */
    public function info($message, array $context = []): void { $this->log(LogLevel::INFO, $message, $context); }

    /** @param string|Stringable $message @param array<mixed> $context */
    public function debug($message, array $context = []): void { $this->log(LogLevel::DEBUG, $message, $context); }

    /**
     * @param mixed $level
     * @param string|Stringable $message
     * @param array<mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $name = strtolower(is_string($level) ? $level : (string) (is_scalar($level) ? $level : ''));
        if (!isset(self::LEVELS[$name])) {
            throw new InvalidArgumentException('Unknown log level: ' . $name);
        }
        $severity = self::LEVELS[$name];
        if ($severity < $this->threshold && $this->listeners === []) {
            return;
        }

        $context = Redactor::array($context);
        $text = Redactor::string(self::interpolate((string) $message, $context));
        $record = new LogRecord($severity, strtoupper($name), $text, $context, $this->channel, new \DateTimeImmutable());

        foreach ($this->listeners as $listener) {
            $listener($record);
        }
        if ($severity >= $this->threshold) {
            $this->write($record);
        }
    }

    /** @param array<mixed> $context */
    private static function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }
        $replace = [];
        foreach ($context as $key => $value) {
            $replace['{' . $key . '}'] = match (true) {
                $value === null, is_scalar($value) => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
                $value instanceof Stringable => (string) $value,
                $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::RFC3339),
                is_object($value) => '[object ' . $value::class . ']',
                default => '[' . get_debug_type($value) . ']',
            };
        }
        return strtr($message, $replace);
    }

    private function write(LogRecord $record): void
    {
        $context = $record->context === [] ? '' : ' ' . json_encode(
            self::normalise($record->context),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        $line = sprintf('[%s] %s.%s: %s%s', $record->datetime->format('Y-m-d H:i:s'), $record->channel, $record->levelName,
            str_replace(["\r", "\n"], ' ', $record->message), $context) . "\n";

        $file = $this->datedPath($record->datetime);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $new = !is_file($file);
        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log(rtrim($line)); // never let logging break a request
            return;
        }
        if ($new) {
            @chmod($file, 0664);
        }
        $this->prune($record->datetime);
    }

    /** storage/logs/app.log → storage/logs/app-2026-10-09.log */
    private function datedPath(\DateTimeImmutable $at): string
    {
        $info = pathinfo($this->path);
        $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
        return ($info['dirname'] ?? '.') . '/' . $info['filename'] . '-' . $at->format('Y-m-d') . $ext;
    }

    private function prune(\DateTimeImmutable $at): void
    {
        $day = $at->format('Y-m-d');
        if ($this->prunedFor === $day) {
            return;
        }
        $this->prunedFor = $day;
        $info = pathinfo($this->path);
        $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
        $files = glob(($info['dirname'] ?? '.') . '/' . $info['filename'] . '-[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]' . $ext) ?: [];
        rsort($files);
        foreach (array_slice($files, self::KEEP_FILES) as $old) {
            @unlink($old);
        }
    }

    /**
     * JSON-safe context: exceptions become "[object] (Class(code: c): message at file:line)" plus the trace.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function normalise(array $data, int $depth = 0): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $out[$key] = match (true) {
                $value instanceof Throwable => self::exception($value),
                is_array($value) => $depth < 5 ? self::normalise($value, $depth + 1) : '[array]',
                $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::RFC3339),
                $value instanceof \JsonSerializable => $value->jsonSerialize(),
                $value instanceof Stringable => (string) $value,
                is_object($value) => '[object ' . $value::class . ']',
                is_resource($value) => '[resource]',
                default => $value,
            };
        }
        return $out;
    }

    private static function exception(Throwable $e): string
    {
        $text = sprintf('[object] (%s(code: %s): %s at %s:%d)', $e::class, (string) $e->getCode(), Redactor::string($e->getMessage()),
            $e->getFile(), $e->getLine());
        $text .= "\n[stacktrace]\n" . Redactor::string($e->getTraceAsString());
        if ($e->getPrevious() !== null) {
            $text .= "\n[previous exception] " . self::exception($e->getPrevious());
        }
        return $text;
    }
}
