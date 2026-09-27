<?php
/** @var App\Core\Template $this @var int $status @var string $title @var string|null $message */
echo $this->partial('errors/error', [
    'icon' => 'clock',
    'message' => null,
    'default' => "Your session expired for security reasons. Please go back, refresh the page and try again.",
]);
