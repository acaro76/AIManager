<?php

declare(strict_types=1);

namespace App\Core;

final class Directory
{
    public static function ensure(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException('Unable to create directory: ' . $path);
        }
    }
}
