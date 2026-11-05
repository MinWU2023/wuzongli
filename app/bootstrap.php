<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('CONFIG_PATH', BASE_PATH . '/config');
define('APP_VERSION', '1.0.0');

$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
// Some SAPI implementations (notably PHP's built-in server with a router)
// place the request path in SCRIPT_NAME. Only a real PHP entry script can
// tell us the application's base directory.
if (str_ends_with(strtolower($scriptName), '.php')) {
    $basePath = str_replace('\\', '/', dirname($scriptName));
    $basePath = rtrim($basePath, '/');
} else {
    $basePath = '';
}
if ($basePath === '.' || $basePath === '/' || $basePath === '') {
    $basePath = '';
}
define('APP_BASE_PATH', $basePath);

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $path = APP_PATH . '/' . $relative . '.php';
    if (is_file($path)) {
        require $path;
    }
});

require APP_PATH . '/helpers.php';

$configFile = CONFIG_PATH . '/config.php';
$config = [];
if (is_file($configFile)) {
    $loaded = require $configFile;
    if (is_array($loaded)) {
        $config = $loaded;
    }
}

return new App\Core\App($config);
