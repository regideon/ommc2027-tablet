<?php

namespace App\Support;

class NativeMediaPath
{
    /**
     * Normalize a native camera/gallery payload entry into a local filesystem path.
     *
     * NativePHP gallery events send file objects with a `path` key; camera events
     * send a plain path string. Both may arrive as `file://` URLs.
     */
    public static function resolve(mixed $file): ?string
    {
        $path = null;

        if (is_string($file) && $file !== '') {
            $path = $file;
        } elseif (is_array($file)) {
            $candidate = $file['path'] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                $path = $candidate;
            }
        }

        if ($path === null) {
            return null;
        }

        return self::normalizeFilesystemPath($path);
    }

    private static function normalizeFilesystemPath(string $path): string
    {
        if (! str_starts_with($path, 'file:')) {
            return $path;
        }

        $parsedPath = parse_url($path, PHP_URL_PATH);

        if (! is_string($parsedPath) || $parsedPath === '') {
            return $path;
        }

        return rawurldecode($parsedPath);
    }
}
