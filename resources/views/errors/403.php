<?php
/** @var App\Core\Template $this @var int $status @var string $title @var string|null $message */
echo $this->partial('errors/error', [
    'icon' => 'lock',
    'message' => $message,
    'default' => "You don't have permission to view this page. If you think this is a mistake, contact your Centre Manager.",
]);
