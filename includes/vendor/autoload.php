<?php
/**
 * Minimal PSR-4 autoloader for the vendored libraries used by SFTP deploy.
 *
 * We vendor phpseclib3 (pure-PHP SFTP) instead of using Composer because the
 * factory has no build step and this host's curl (8.5 + libssh) can't accept
 * SSH host keys. phpseclib needs only paragonie/constant_time_encoding at
 * runtime, so both are cloned into includes/vendor/ and mapped by hand here.
 *
 * Require this file before using phpseclib3\Net\SFTP.
 */

spl_autoload_register(function (string $class): void {
    static $prefixes = [
        'phpseclib3\\'            => __DIR__ . '/phpseclib/phpseclib/',
        'ParagonIE\\ConstantTime\\' => __DIR__ . '/constant_time/src/',
    ];
    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($class, $prefix, $len) !== 0) continue;
        $relative = str_replace('\\', '/', substr($class, $len));
        $file = $baseDir . $relative . '.php';
        if (is_file($file)) require $file;
        return;
    }
});
