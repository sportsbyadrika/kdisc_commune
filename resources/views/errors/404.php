<?php
/** @var App\Core\Template $this @var int $status @var string $title @var string|null $message */
echo $this->partial('errors/error', [
    'icon' => 'map',
    'message' => null,
    'default' => "We couldn't find the page you were looking for. It may have moved, or the link may be mistyped.",
]);
