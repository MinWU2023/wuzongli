<?php
declare(strict_types=1);

/**
 * Single front controller. Keep this file in the web root.
 */
$app = require __DIR__ . '/app/bootstrap.php';
$app->run();
