<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Unit tests do not boot the full App (no DB, no session). Tests that need the
// database should set DB_DATABASE=commune_test and call App\Core\App::boot().
