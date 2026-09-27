<?php
/** @var App\Core\Template $this @var int $status @var string $title @var string|null $message */
echo $this->partial('errors/error', [
    'icon' => 'triangle-alert',
    'message' => null,
    'default' => "We're sorry — something went wrong on our side. The team has been notified.",
]);
