<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Open-redirect guard for user-supplied "return to" values (?next=, back=, the remembered _intended URL).
 * Only site-relative paths under an allowed prefix pass: no scheme, no host, no protocol-relative "//", no
 * backslashes (browsers treat "/\evil" as "//evil"), no control characters, no encoded slashes that could be
 * decoded into one of those.
 *
 *   SafeRedirect::path($request->string('next'), ['/my', '/spaces'], $request->basePath())  // ?string
 */
final class SafeRedirect
{
    /** @param list<string> $prefixes allowed path prefixes (without the base path), e.g. ['/staff/'] */
    public static function path(mixed $candidate, array $prefixes, string $base = ''): ?string
    {
        if (!is_string($candidate) || $candidate === '' || strlen($candidate) > 2000) {
            return null;
        }
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $candidate) === 1) {
            return null;
        }
        if (!str_starts_with($candidate, '/') || str_starts_with($candidate, '//')) {
            return null;
        }
        $path = (string) parse_url('http://localhost' . $candidate, PHP_URL_PATH);
        // encoded slashes / backslashes / NUL in the path could be decoded into "//host" further down the line
        if ($path === '' || preg_match('#%(2f|5c|00)#i', $path) === 1 || preg_match('#(^|/)\.\.?(/|$)#', $path) === 1) {
            return null;
        }
        foreach ($prefixes as $prefix) {
            $bare = rtrim($base . $prefix, '/');
            if ($path === $bare || str_starts_with($path, $bare . '/')) {
                return $candidate;
            }
        }
        return null;
    }
}
