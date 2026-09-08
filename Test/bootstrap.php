<?php
/**
 * Unit-test bootstrap.
 *
 * Deliberately does not use Composer: installing this module's dependencies
 * needs authenticated access to repo.magento.com, and the suite covers only the
 * pure parts of the telemetry code — the deny assertion, the environment
 * pairing, the batch splitter and the flusher's decision table — none of which
 * need a Magento application.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Paypercut\\Payment\\';

    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = dirname(__DIR__) . '/' . $relative . '.php';

    if (is_file($file)) {
        require $file;
    }
});
