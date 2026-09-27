<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\View;

/**
 * Base controller. Controllers are resolved through the container, so
 * services can be type-hinted in the constructor or in action methods;
 * route parameters are injected by name ({id} -> int $id).
 */
abstract class Controller
{
    /** @param array<string, mixed> $data */
    protected function view(string $name, array $data = [], int $status = 200): Response
    {
        /** @var View $view */
        $view = App::container()->get(View::class);
        return Response::html($view->render($name, $data), $status);
    }

    /** @param array<string, scalar> $params */
    protected function redirectTo(string $routeName, array $params = []): RedirectResponse
    {
        return redirect(url($routeName, $params));
    }

    /**
     * Validate request input or redirect back with errors (throws ValidationException).
     *
     * @param array<string, string|list<string|\Closure>> $rules
     * @param array<string, string> $messages
     * @param array<string, string> $attributes
     * @return array<string, mixed>
     */
    protected function validate(Request $request, array $rules, array $messages = [], array $attributes = []): array
    {
        return Validator::make($request->all(), $rules, $messages, $attributes)->validate();
    }
}
