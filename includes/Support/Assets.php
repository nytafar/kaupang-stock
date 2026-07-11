<?php
declare(strict_types=1);

namespace Kaupang\Stock\Support;

/**
 * Asset cache-busting helper (spis-fiken convention). The enqueue "version" is the
 * file's mtime, NOT the plugin release version: bumping KAUPANG_STOCK_VERSION is a
 * release concern, whereas a stylesheet edit must bust the browser cache the moment
 * it lands — mtime does exactly that, per file, with no version bookkeeping. Falls
 * back to the plugin version when the file is missing (defensive; should not happen).
 */
final class Assets {

    /**
     * Cache-bust token for an asset, addressed by its path relative to the plugin
     * root (e.g. 'assets/purchasing.css').
     */
    public static function ver(string $relPath): string {
        $path = KAUPANG_STOCK_DIR . ltrim($relPath, '/');
        return (string) (is_file($path) ? filemtime($path) : KAUPANG_STOCK_VERSION);
    }
}
