<?php
declare(strict_types=1);

// Pure classes only: Domain, Security, Api, Service\FinancingError. No WordPress needed.
spl_autoload_register(static function (string $class): void {
    $prefix = 'FinancingBridge\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/../../plugin/financing-bridge/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
