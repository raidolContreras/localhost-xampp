<?php

declare(strict_types=1);

const CACHE_FILE = APP_ROOT . '/.folder_cache.json';
const CACHE_SOFT_TTL = 120;
const CACHE_HARD_TTL = 1800;

function readCache(): array {
    $fallback = ['meta' => ['updated_at' => 0], 'metrics' => []];
    $cache = readJsonFile(CACHE_FILE, $fallback);
    $cache['meta'] = is_array($cache['meta'] ?? null) ? $cache['meta'] : ['updated_at' => 0];
    $cache['metrics'] = is_array($cache['metrics'] ?? null) ? $cache['metrics'] : [];
    return $cache;
}

function writeCache(array $cache): void {
    $cache['meta'] = is_array($cache['meta'] ?? null) ? $cache['meta'] : [];
    $cache['meta']['updated_at'] = time();
    writeJsonFile(CACHE_FILE, $cache);
}

function topLevelFingerprint(string $dir): string {
    if (!is_dir($dir)) return '';
    $entries = @scandir($dir) ?: [];
    $parts = [];

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $full = $dir . DIRECTORY_SEPARATOR . $entry;
        $isDir = is_dir($full) ? 'd' : 'f';
        $mtime = @filemtime($full) ?: 0;
        $size = is_file($full) ? (@filesize($full) ?: 0) : 0;
        $parts[] = $entry . '|' . $isDir . '|' . $mtime . '|' . $size;
    }

    sort($parts);
    return hash('sha256', (@filemtime($dir) ?: 0) . '|' . count($parts) . '|' . implode(';', $parts));
}

function dirSizeAndCount(string $dir): array {
    $size = 0;
    $files = 0;

    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($it as $item) {
            if ($item->isFile()) {
                $files++;
                $size += $item->getSize();
            }
        }
    } catch (Throwable $e) {
        // Best effort.
    }

    return [$size, $files];
}

function metricKey(string $scope, string $name): string {
    return $scope . ':' . $name;
}

function getMetrics(string $scope, string $name, string $path, array &$cache, bool $force = false): array {
    $now = time();
    $key = metricKey($scope, $name);

    $fingerprint = topLevelFingerprint($path);
    $cached = $cache['metrics'][$key] ?? null;

    $isValidCached = is_array($cached)
        && isset($cached['fingerprint'], $cached['updated_at'], $cached['size_bytes'], $cached['files_count'])
        && $cached['fingerprint'] === $fingerprint
        && ($now - (int) $cached['updated_at']) <= CACHE_HARD_TTL;

    if (!$force && $isValidCached) {
        $age = $now - (int) $cached['updated_at'];
        if ($age <= CACHE_SOFT_TTL) {
            return [
                'size_bytes' => (int) $cached['size_bytes'],
                'files_count' => (int) $cached['files_count'],
                'cached' => true,
                'cache_age' => $age,
            ];
        }
    }

    [$size, $files] = dirSizeAndCount($path);

    $cache['metrics'][$key] = [
        'fingerprint' => $fingerprint,
        'size_bytes' => $size,
        'files_count' => $files,
        'updated_at' => $now,
    ];

    return [
        'size_bytes' => $size,
        'files_count' => $files,
        'cached' => false,
        'cache_age' => 0,
    ];
}

function buildProjectPayload(array $baseProjects, array &$cache, bool $force = false): array {
    $out = [];

    foreach ($baseProjects as $project) {
        $name = $project['name'];
        $path = $project['path'];
        $scope = $project['scope'];
        $metrics = getMetrics($scope, $name, $path, $cache, $force);

        $out[] = [
            'name' => $name,
            'path' => $path,
            'scope' => $scope,
            'size_bytes' => $metrics['size_bytes'],
            'size_human' => humanFileSize($metrics['size_bytes']),
            'files_count' => $metrics['files_count'],
            'created' => @filectime($path) ?: 0,
            'cache_age' => $metrics['cache_age'],
            'cached' => $metrics['cached'],
        ];
    }

    return $out;
}

function invalidateMetricsForFolder(array &$cache, string $folder): void {
    foreach (['root', 'trash'] as $scope) {
        unset($cache['metrics'][metricKey($scope, $folder)]);
    }
}
