<?php

spl_autoload_register(function (string $class): void {
    $prefix = 'LeconfePlugins\\UserImportExport\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $path = __DIR__.'/src/'.str_replace('\\', '/', $relativeClass).'.php';

    if (is_file($path)) {
        require_once $path;
    }
});

return new LeconfePlugins\UserImportExport\UserImportExportPlugin;
