<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Plain-PHP template engine with layouts, sections, partials and components.
 * Views live in resources/views and are referenced without extension using
 * slashes: 'site/home', 'layouts/site', 'components/card'.
 *
 * Inside a template `$this` is a {@see Template}:
 *
 *   <?php $this->layout('layouts/site', ['title' => 'Pricing']) ?>
 *   <?php $this->start('head') ?> <meta ...> <?php $this->stop() ?>
 *   <h1><?= e($heading) ?></h1>                      // markup outside sections = 'content'
 *   <?= $this->partial('partials/flash') ?>
 *   <?= $this->component('badge', ['label' => 'New', 'tone' => 'success']) ?>
 *   <?php $this->begin('modal', ['id' => 'x', 'title' => 'Hi']) ?> body… <?= $this->end() ?>
 *
 * In a layout: <?= $this->section('content') ?>, <?= $this->section('scripts', '') ?>
 */
final class View
{
    /** @var array<string, mixed> */
    private array $shared = [];

    public function __construct(private readonly string $path)
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /** @return array<string, mixed> */
    public function shared(): array
    {
        return $this->shared;
    }

    /** @param array<string, mixed> $data */
    public function render(string $name, array $data = []): string
    {
        $template = new Template($this, $name, array_merge($this->shared, $data));
        return $template->render();
    }

    public function resolve(string $name): string
    {
        $name = str_replace('.', '/', $name);
        if (str_contains($name, '..')) {
            throw new RuntimeException("Invalid view name [{$name}].");
        }
        $file = $this->path . '/' . ltrim($name, '/') . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("View [{$name}] not found at {$file}.");
        }
        return $file;
    }

    public function exists(string $name): bool
    {
        return is_file($this->path . '/' . str_replace('.', '/', $name) . '.php');
    }
}
