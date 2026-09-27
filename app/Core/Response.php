<?php

declare(strict_types=1);

namespace App\Core;

/**
 * HTTP response. Controllers return one of:
 *   Response::html($markup) | Response::json($data) | redirect(url('x'))
 *   Response::download($path, 'name.pdf') | Response::file($path) (inline)
 */
class Response
{
    /** @var array<string, string> */
    protected array $headers = [];

    /** @var null|callable(): void streamed body */
    protected $stream = null;

    /** @param array<string, string> $headers */
    public function __construct(protected string $content = '', protected int $status = 200, array $headers = [])
    {
        foreach ($headers as $k => $v) {
            $this->header($k, $v);
        }
    }

    /** @param array<string, string> $headers */
    public static function html(string $content, int $status = 200, array $headers = []): self
    {
        return new self($content, $status, ['Content-Type' => 'text/html; charset=UTF-8'] + $headers);
    }

    /** @param array<string, string> $headers */
    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return new self($json, $status, ['Content-Type' => 'application/json; charset=UTF-8'] + $headers);
    }

    public static function text(string $content, int $status = 200): self
    {
        return new self($content, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public static function redirect(string $url, int $status = 302): RedirectResponse
    {
        return new RedirectResponse($url, $status);
    }

    /** Force a download of a file on disk. */
    public static function download(string $path, ?string $filename = null, ?string $mime = null): self
    {
        return static::file($path, $filename, $mime, 'attachment');
    }

    /** Stream a file (inline by default). Use for PDFs and authorised uploads outside public/. */
    public static function file(string $path, ?string $filename = null, ?string $mime = null, string $disposition = 'inline'): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new Exceptions\NotFoundException('File not found');
        }
        $filename ??= basename($path);
        $mime ??= (mime_content_type($path) ?: 'application/octet-stream');
        $safe = str_replace(['"', "\r", "\n"], '', $filename);
        $response = new self('', 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) filesize($path),
            'Content-Disposition' => sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $disposition, $safe, rawurlencode($filename)),
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->stream = static function () use ($path): void {
            readfile($path);
        };
        return $response;
    }

    /** Download generated content (e.g. a PDF string from dompdf). */
    public static function downloadContent(string $content, string $filename, string $mime = 'application/octet-stream'): self
    {
        $safe = str_replace(['"', "\r", "\n"], '', $filename);
        return new self($content, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => sprintf('attachment; filename="%s"', $safe),
        ]);
    }

    public function header(string $name, string $value): static
    {
        $this->headers[$this->normalizeHeader($name)] = $value;
        return $this;
    }

    public function withoutHeader(string $name): static
    {
        unset($this->headers[$this->normalizeHeader($name)]);
        return $this;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[$this->normalizeHeader($name)] ?? null;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;
        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }
        if ($this->stream !== null) {
            ($this->stream)();
            return;
        }
        echo $this->content;
    }

    private function normalizeHeader(string $name): string
    {
        return str_replace(' ', '-', ucwords(str_replace('-', ' ', strtolower($name))));
    }
}
