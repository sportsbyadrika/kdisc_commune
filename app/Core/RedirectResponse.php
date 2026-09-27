<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Redirect with fluent flash helpers:
 *   return redirect(url('staff.dashboard'))->with('success', 'Saved.');
 *   return back()->withErrors(['email' => 'Unknown email'])->withInput();
 */
final class RedirectResponse extends Response
{
    public function __construct(private string $targetUrl, int $status = 302)
    {
        parent::__construct('', $status, ['Location' => $targetUrl]);
    }

    public function targetUrl(): string
    {
        return $this->targetUrl;
    }

    /** Flash a message (keys used by the flash partial: success, error, warning, info). */
    public function with(string $key, mixed $value): self
    {
        Session::current()?->flash($key, $value);
        return $this;
    }

    /** @param array<string, string|list<string>> $errors */
    public function withErrors(array $errors): self
    {
        $normalized = [];
        foreach ($errors as $field => $messages) {
            $normalized[$field] = is_array($messages) ? array_values($messages) : [$messages];
        }
        Session::current()?->flash('_errors', $normalized);
        return $this;
    }

    /** @param array<string, mixed>|null $input defaults to the current request body (passwords stripped) */
    public function withInput(?array $input = null): self
    {
        $session = Session::current();
        if ($session !== null) {
            $input ??= App::request()?->post() ?? [];
            $session->flashInput($input);
        }
        return $this;
    }
}
