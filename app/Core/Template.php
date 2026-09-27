<?php

declare(strict_types=1);

namespace App\Core;

use LogicException;

/**
 * A single render of a view file. See {@see View} for usage.
 */
final class Template
{
    private ?string $layoutName = null;

    /** @var array<string, mixed> */
    private array $layoutData = [];

    /** @var array<string, string> */
    private array $sections;

    /** @var list<string> */
    private array $sectionStack = [];

    /** @var list<array{name: string, data: array<string, mixed>}> */
    private array $componentStack = [];

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $sections inherited from a child template
     */
    public function __construct(
        private readonly View $view,
        private readonly string $name,
        private array $data = [],
        array $sections = [],
    ) {
        $this->sections = $sections;
    }

    public function render(): string
    {
        $content = $this->capture($this->view->resolve($this->name), $this->data);

        if ($this->sectionStack !== []) {
            throw new LogicException("Unclosed section [{$this->sectionStack[0]}] in view [{$this->name}].");
        }

        if ($this->layoutName === null) {
            return $content;
        }
        // Markup outside of sections becomes the 'content' section. A layout that
        // itself extends a layout (site -> base) already embeds its child's content,
        // so overwriting here is intended.
        if (trim($content) !== '') {
            $this->sections['content'] = $content;
        }
        $layout = new Template($this->view, $this->layoutName, array_merge($this->data, $this->layoutData), $this->sections);
        return $layout->render();
    }

    /** @param array<string, mixed> $data */
    public function layout(string $name, array $data = []): void
    {
        $this->layoutName = $name;
        $this->layoutData = $data;
    }

    public function start(string $name): void
    {
        $this->sectionStack[] = $name;
        ob_start();
    }

    /** Close the current section. Child sections override layout defaults; use append() to add. */
    public function stop(): void
    {
        $name = array_pop($this->sectionStack) ?? throw new LogicException('stop() called without start().');
        $this->sections[$name] = (string) ob_get_clean();
    }

    /** Close the current section, appending to any existing content (for "stacks" like scripts). */
    public function append(): void
    {
        $name = array_pop($this->sectionStack) ?? throw new LogicException('append() called without start().');
        $this->sections[$name] = ($this->sections[$name] ?? '') . (string) ob_get_clean();
    }

    public function section(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    public function hasSection(string $name): bool
    {
        return isset($this->sections[$name]) && trim($this->sections[$name]) !== '';
    }

    /**
     * Render another view with the current data plus extras.
     * @param array<string, mixed> $data
     */
    public function partial(string $name, array $data = []): string
    {
        return $this->view->render($name, array_merge($this->data, $data));
    }

    /**
     * Render resources/views/components/{name}.php with ONLY the given data
     * (components are isolated from page data).
     *
     * @param array<string, mixed> $props
     */
    public function component(string $name, array $props = []): string
    {
        return $this->view->render('components/' . $name, $props + ['slot' => '', 'attrs' => []]);
    }

    /**
     * Begin a component whose body is captured as $slot.
     * @param array<string, mixed> $props
     */
    public function begin(string $name, array $props = []): void
    {
        $this->componentStack[] = ['name' => $name, 'data' => $props];
        ob_start();
    }

    public function end(): string
    {
        $c = array_pop($this->componentStack) ?? throw new LogicException('end() called without begin().');
        $slot = (string) ob_get_clean();
        return $this->component($c['name'], ['slot' => $slot] + $c['data']);
    }

    /** @param array<string, mixed> $data */
    private function capture(string $file, array $data): string
    {
        $level = ob_get_level();
        ob_start();
        try {
            (function (string $__file, array $__data): void {
                extract($__data, EXTR_SKIP);
                require $__file;
            })->call($this, $file, $data);
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
