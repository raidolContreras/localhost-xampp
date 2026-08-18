<?php

declare(strict_types=1);

function jsonOut(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function humanFileSize(int $bytes, int $decimals = 2): string {
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $factor = (int) floor(log($bytes, 1024));
    $factor = max(0, min($factor, count($units) - 1));
    return sprintf("%.{$decimals}f", $bytes / (1024 ** $factor)) . ' ' . $units[$factor];
}

function readJsonFile(string $path, array $fallback): array {
    if (!is_file($path)) return $fallback;
    $raw = @file_get_contents($path);
    if ($raw === false) return $fallback;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $fallback;
}

function writeJsonFile(string $path, array $data): bool {
    $fp = @fopen($path, 'c+');
    if (!$fp) return false;
    if (!@flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }

    ftruncate($fp, 0);
    rewind($fp);
    $ok = fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false;
    fflush($fp);
    @flock($fp, LOCK_UN);
    fclose($fp);

    return $ok;
}

function isLocalRequest(): bool {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    return $remote === '127.0.0.1' || $remote === '::1';
}

function sanitizeFolderName(string $name): string {
    $name = trim($name);
    $name = str_replace(['\\', '/'], '', $name);
    $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
    return trim($name);
}

function isProtectedFolder(string $name): bool {
    $n = strtolower($name);
    $protected = ['img', 'pass', '_papeleria', 'dashboard', 'xampp', 'webalizer', 'inc'];
    if ($n === '' || $n === '.' || $n === '..') return true;
    if (in_array($n, $protected, true)) return true;
    if (preg_match('/\.(php|js|css|md|json)$/i', $name)) return true;
    return false;
}
