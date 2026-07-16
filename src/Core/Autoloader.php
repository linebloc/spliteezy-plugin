<?php

namespace Spliteezy\Core;

defined('ABSPATH') || exit;

class Autoloader
{
    public static function register(): void
    {
        spl_autoload_register([static::class, 'load']);
    }

    public static function load(string $class): void
    {
        if (strpos($class, 'Spliteezy\\') !== 0) {
            return;
        }

        $relative = substr($class, strlen('Spliteezy\\'));
        $path = SPLITEEZY_DIR.'src/'.str_replace('\\', '/', $relative).'.php';

        if (file_exists($path)) {
            require_once $path;
        }
    }
}
