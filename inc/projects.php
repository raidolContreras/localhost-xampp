<?php

declare(strict_types=1);

function recursiveDeleteDir(string $dir): bool {
    if (!is_dir($dir)) return false;

    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $item) {
            $ok = $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            if (!$ok) return false;
        }

        return @rmdir($dir);
    } catch (Throwable $e) {
        return false;
    }
}

function ensureTrash(string $trashPath): bool {
    if (is_dir($trashPath)) return true;
    return @mkdir($trashPath, 0755, true);
}

function listVisibleProjects(string $baseDir, array $excluded): array {
    $items = @scandir($baseDir) ?: [];
    $projects = [];

    foreach ($items as $name) {
        if (in_array($name, $excluded, true)) continue;
        if ($name === '' || $name[0] === '.') continue;
        if (isProtectedFolder($name)) continue;

        $full = $baseDir . DIRECTORY_SEPARATOR . $name;
        if (!is_dir($full)) continue;

        $projects[] = ['name' => $name, 'path' => $full, 'scope' => 'root'];
    }

    return $projects;
}

function listTrashProjects(string $trashPath): array {
    if (!is_dir($trashPath)) return [];

    $items = @scandir($trashPath) ?: [];
    $projects = [];

    foreach ($items as $name) {
        if ($name === '.' || $name === '..') continue;
        if ($name === '' || $name[0] === '.') continue;

        $full = $trashPath . DIRECTORY_SEPARATOR . $name;
        if (!is_dir($full)) continue;

        $projects[] = ['name' => $name, 'path' => $full, 'scope' => 'trash'];
    }

    return $projects;
}

function listFilesAndReadme(string $folderPath): array {
    if (!is_dir($folderPath)) return ['success' => false, 'message' => 'La carpeta no existe'];

    $items = [];
    foreach (@scandir($folderPath) ?: [] as $it) {
        if ($it === '.' || $it === '..') continue;
        $full = $folderPath . DIRECTORY_SEPARATOR . $it;

        if (is_dir($full)) {
            [$size] = dirSizeAndCount($full);
            $items[] = [
                'name' => $it,
                'type' => 'folder',
                'size' => humanFileSize($size),
                'created' => @filectime($full) ?: 0,
            ];
            continue;
        }

        if (is_file($full)) {
            $items[] = [
                'name' => $it,
                'type' => 'file',
                'size' => humanFileSize((int) (@filesize($full) ?: 0)),
                'created' => @filectime($full) ?: 0,
            ];
        }
    }

    $readmeHtml = null;
    $readmePath = $folderPath . DIRECTORY_SEPARATOR . 'README.md';
    if (is_file($readmePath)) {
        $raw = @file_get_contents($readmePath) ?: '';
        $parsed = null;

        $parsedownFile = APP_ROOT . DIRECTORY_SEPARATOR . 'Parsedown.php';
        if (is_file($parsedownFile)) {
            require_once $parsedownFile;
            if (class_exists('Parsedown')) {
                $pd = new Parsedown();
                if (method_exists($pd, 'setSafeMode')) {
                    $pd->setSafeMode(true);
                }
                $parsed = $pd->text($raw);
            }
        }

        $readmeHtml = $parsed ?? '<pre style="white-space:pre-wrap">' . htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
        $readmeHtml = sanitizeRenderedHtml($readmeHtml);
    }

    return ['success' => true, 'items' => $items, 'readme' => $readmeHtml];
}

function sanitizeRenderedHtml(string $html): string {
    if ($html === '') return '';

    // Fallback rapido para entornos sin DOM o HTML roto.
    $fallback = static function (string $input): string {
        $clean = preg_replace('/<\s*(script|style|iframe|object|embed|form|input|button|textarea|select)[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $input) ?? '';
        $clean = preg_replace('/<\s*(script|style|iframe|object|embed|form|input|button|textarea|select)[^>]*\/?>/is', '', $clean) ?? '';
        $clean = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean) ?? '';
        $clean = preg_replace('/\s+(href|src)\s*=\s*("|\')\s*javascript:[^\2]*\2/i', '', $clean) ?? '';
        return $clean;
    };

    if (!class_exists('DOMDocument')) {
        return $fallback($html);
    }

    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $dom->loadHTML('<?xml encoding="utf-8" ?><div id="__root__">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    if (!$ok) {
        return $fallback($html);
    }

    $dangerTags = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'meta', 'link', 'base'];
    foreach ($dangerTags as $tag) {
        while (true) {
            $nodes = $dom->getElementsByTagName($tag);
            if ($nodes->length === 0) break;
            $node = $nodes->item(0);
            if ($node && $node->parentNode) {
                $node->parentNode->removeChild($node);
            } else {
                break;
            }
        }
    }

    $all = $dom->getElementsByTagName('*');
    for ($i = $all->length - 1; $i >= 0; $i--) {
        $el = $all->item($i);
        if (!$el || !$el->hasAttributes()) continue;

        for ($j = $el->attributes->length - 1; $j >= 0; $j--) {
            $attr = $el->attributes->item($j);
            if (!$attr) continue;

            $name = strtolower((string) $attr->nodeName);
            $value = trim((string) $attr->nodeValue);

            if (str_starts_with($name, 'on')) {
                $el->removeAttributeNode($attr);
                continue;
            }

            if (($name === 'href' || $name === 'src') && preg_match('/^\s*javascript\s*:/i', $value)) {
                $el->removeAttributeNode($attr);
                continue;
            }

            if ($name === 'style') {
                $el->removeAttributeNode($attr);
                continue;
            }
        }
    }

    $root = $dom->getElementById('__root__');
    if (!$root) {
        return $fallback($html);
    }

    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $dom->saveHTML($child);
    }

    return $out;
}

function handle_init(string $baseDir, string $trashPath, array $noMostrar, array &$cache, bool $force): never {
    $projects = buildProjectPayload(listVisibleProjects($baseDir, $noMostrar), $cache, $force);
    $trash = buildProjectPayload(listTrashProjects($trashPath), $cache, $force);

    usort($projects, fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    usort($trash, fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

    $totalSize = array_sum(array_column($projects, 'size_bytes'));

    $dirty = in_array(false, array_column($projects, 'cached'), true)
        || in_array(false, array_column($trash, 'cached'), true);
    if ($dirty) {
        writeCache($cache);
    }

    jsonOut([
        'success' => true,
        'php_version' => PHP_VERSION,
        'total_size_bytes' => $totalSize,
        'total_size_human' => humanFileSize((int) $totalSize),
        'projects' => $projects,
        'trash' => $trash,
        'cache' => [
            'hard_ttl' => CACHE_HARD_TTL,
            'updated_at' => (int) ($cache['meta']['updated_at'] ?? 0),
        ],
    ]);
}

function handle_get_php_config(): never {
    $ini = @ini_get_all(null, false) ?: [];
    $extensions = @get_loaded_extensions() ?: [];
    sort($extensions);

    jsonOut([
        'success' => true,
        'php_version' => PHP_VERSION,
        'ini' => $ini,
        'extensions' => $extensions,
    ]);
}

function handle_get_php_ini(): never {
    $iniFile = php_ini_loaded_file();
    if ($iniFile && is_readable($iniFile)) {
        jsonOut(['success' => true, 'path' => $iniFile, 'content' => @file_get_contents($iniFile) ?: '']);
    }
    jsonOut(['success' => false, 'message' => 'No se pudo leer php.ini'], 400);
}

function handle_save_php_ini(array $body): never {
    $content = (string) ($body['content'] ?? '');
    $iniFile = php_ini_loaded_file();

    if ($iniFile && is_writable($iniFile)) {
        $ok = @file_put_contents($iniFile, $content);
        if ($ok !== false) jsonOut(['success' => true]);
        jsonOut(['success' => false, 'message' => 'No se pudo escribir en php.ini'], 500);
    }

    jsonOut(['success' => false, 'message' => 'php.ini no es escribible'], 400);
}

function handle_create_project(array $body, string $baseDir, array &$cache): never {
    $name = sanitizeFolderName((string) ($body['name'] ?? ''));
    if ($name === '' || isProtectedFolder($name)) jsonOut(['success' => false, 'message' => 'Nombre invalido.'], 400);

    $dest = $baseDir . DIRECTORY_SEPARATOR . $name;
    if (is_dir($dest)) jsonOut(['success' => false, 'message' => 'El proyecto ya existe'], 409);

    if (@mkdir($dest, 0755)) {
        invalidateMetricsForFolder($cache, $name);
        writeCache($cache);
        jsonOut(['success' => true, 'message' => 'Proyecto creado']);
    }

    jsonOut(['success' => false, 'message' => 'No se pudo crear el proyecto'], 500);
}

function handle_move(array $body, string $baseDir, string $trashPath, array &$cache): never {
    $folder = sanitizeFolderName((string) ($body['folder'] ?? ''));
    if ($folder === '' || isProtectedFolder($folder)) jsonOut(['success' => false, 'message' => 'Carpeta invalida'], 400);

    $src = $baseDir . DIRECTORY_SEPARATOR . $folder;
    if (!is_dir($src)) jsonOut(['success' => false, 'message' => 'No existe la carpeta'], 404);

    if (!ensureTrash($trashPath)) {
        jsonOut(['success' => false, 'message' => 'No se pudo crear _PAPELERIA'], 500);
    }

    $dst = $trashPath . DIRECTORY_SEPARATOR . $folder;
    if (is_dir($dst)) $dst = $trashPath . DIRECTORY_SEPARATOR . $folder . '__' . date('Ymd_His');

    if (!@rename($src, $dst)) {
        jsonOut(['success' => false, 'message' => 'No se pudo mover la carpeta'], 500);
    }

    invalidateMetricsForFolder($cache, $folder);
    invalidateMetricsForFolder($cache, basename($dst));
    writeCache($cache);

    jsonOut(['success' => true, 'message' => 'Proyecto enviado a papeleria', 'trash_name' => basename($dst)]);
}

function handle_bulk_move(array $body, string $baseDir, string $trashPath, array &$cache): never {
    $folders = $body['folders'] ?? [];
    if (!is_array($folders) || count($folders) === 0) {
        jsonOut(['success' => false, 'message' => 'No se recibieron carpetas.'], 400);
    }

    if (!ensureTrash($trashPath)) {
        jsonOut(['success' => false, 'message' => 'No se pudo crear _PAPELERIA'], 500);
    }

    $moved = [];
    $failed = [];

    foreach ($folders as $entry) {
        $folder = sanitizeFolderName((string) $entry);
        if ($folder === '' || isProtectedFolder($folder)) {
            $failed[] = ['folder' => (string) $entry, 'reason' => 'Carpeta invalida o protegida'];
            continue;
        }

        $src = $baseDir . DIRECTORY_SEPARATOR . $folder;
        if (!is_dir($src)) {
            $failed[] = ['folder' => $folder, 'reason' => 'No existe'];
            continue;
        }

        $dst = $trashPath . DIRECTORY_SEPARATOR . $folder;
        if (is_dir($dst)) {
            $dst = $trashPath . DIRECTORY_SEPARATOR . $folder . '__' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(2)), 0, 4);
        }

        if (!@rename($src, $dst)) {
            $failed[] = ['folder' => $folder, 'reason' => 'No se pudo mover'];
            continue;
        }

        $moved[] = basename($dst);
        invalidateMetricsForFolder($cache, $folder);
        invalidateMetricsForFolder($cache, basename($dst));
    }

    writeCache($cache);
    jsonOut(['success' => true, 'moved' => $moved, 'failed' => $failed]);
}

function handle_list_trash(string $trashPath, array &$cache): never {
    $trash = buildProjectPayload(listTrashProjects($trashPath), $cache, false);
    usort($trash, fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    if (in_array(false, array_column($trash, 'cached'), true)) {
        writeCache($cache);
    }
    jsonOut(['success' => true, 'trash' => $trash]);
}

function handle_restore_project(array $body, string $baseDir, string $trashPath, array &$cache): never {
    $folder = sanitizeFolderName((string) ($body['folder'] ?? ''));
    $newName = sanitizeFolderName((string) ($body['new_name'] ?? ''));
    if ($folder === '') jsonOut(['success' => false, 'message' => 'Carpeta invalida'], 400);

    $src = $trashPath . DIRECTORY_SEPARATOR . $folder;
    if (!is_dir($src)) jsonOut(['success' => false, 'message' => 'No existe en papeleria'], 404);

    $targetName = $newName !== '' ? $newName : $folder;
    if (isProtectedFolder($targetName)) jsonOut(['success' => false, 'message' => 'Nombre invalido para restaurar'], 400);

    $dst = $baseDir . DIRECTORY_SEPARATOR . $targetName;
    if (is_dir($dst)) {
        jsonOut(['success' => false, 'message' => 'Ya existe un proyecto con ese nombre'], 409);
    }

    if (!@rename($src, $dst)) {
        jsonOut(['success' => false, 'message' => 'No se pudo restaurar el proyecto'], 500);
    }

    invalidateMetricsForFolder($cache, $folder);
    invalidateMetricsForFolder($cache, $targetName);
    writeCache($cache);

    jsonOut(['success' => true, 'message' => 'Proyecto restaurado', 'name' => $targetName]);
}

function handle_bulk_restore(array $body, string $baseDir, string $trashPath, array &$cache): never {
    $folders = $body['folders'] ?? [];
    if (!is_array($folders) || count($folders) === 0) {
        jsonOut(['success' => false, 'message' => 'No se recibieron carpetas.'], 400);
    }

    $restored = [];
    $failed = [];

    foreach ($folders as $entry) {
        $folder = sanitizeFolderName((string) $entry);
        if ($folder === '') {
            $failed[] = ['folder' => (string) $entry, 'reason' => 'Nombre invalido'];
            continue;
        }

        $src = $trashPath . DIRECTORY_SEPARATOR . $folder;
        if (!is_dir($src)) {
            $failed[] = ['folder' => $folder, 'reason' => 'No existe en papeleria'];
            continue;
        }

        if (isProtectedFolder($folder)) {
            $failed[] = ['folder' => $folder, 'reason' => 'Nombre protegido'];
            continue;
        }

        $dst = $baseDir . DIRECTORY_SEPARATOR . $folder;
        if (is_dir($dst)) {
            $failed[] = ['folder' => $folder, 'reason' => 'Ya existe en raiz'];
            continue;
        }

        if (!@rename($src, $dst)) {
            $failed[] = ['folder' => $folder, 'reason' => 'No se pudo restaurar'];
            continue;
        }

        $restored[] = $folder;
        invalidateMetricsForFolder($cache, $folder);
    }

    writeCache($cache);
    jsonOut(['success' => true, 'restored' => $restored, 'failed' => $failed]);
}

function handle_delete_permanently(array $body, string $trashPath, array &$cache): never {
    $folder = sanitizeFolderName((string) ($body['folder'] ?? ''));
    if ($folder === '') jsonOut(['success' => false, 'message' => 'Carpeta invalida'], 400);

    $src = $trashPath . DIRECTORY_SEPARATOR . $folder;
    if (!is_dir($src)) jsonOut(['success' => false, 'message' => 'No existe en papeleria'], 404);

    if (!recursiveDeleteDir($src)) {
        jsonOut(['success' => false, 'message' => 'No se pudo borrar definitivamente'], 500);
    }

    invalidateMetricsForFolder($cache, $folder);
    writeCache($cache);

    jsonOut(['success' => true, 'message' => 'Proyecto eliminado definitivamente']);
}

function handle_bulk_delete_permanently(array $body, string $trashPath, array &$cache): never {
    $folders = $body['folders'] ?? [];
    if (!is_array($folders) || count($folders) === 0) {
        jsonOut(['success' => false, 'message' => 'No se recibieron carpetas.'], 400);
    }

    $deleted = [];
    $failed = [];

    foreach ($folders as $entry) {
        $folder = sanitizeFolderName((string) $entry);
        if ($folder === '') {
            $failed[] = ['folder' => (string) $entry, 'reason' => 'Nombre invalido'];
            continue;
        }

        $src = $trashPath . DIRECTORY_SEPARATOR . $folder;
        if (!is_dir($src)) {
            $failed[] = ['folder' => $folder, 'reason' => 'No existe en papeleria'];
            continue;
        }

        if (!recursiveDeleteDir($src)) {
            $failed[] = ['folder' => $folder, 'reason' => 'No se pudo borrar'];
            continue;
        }

        $deleted[] = $folder;
        invalidateMetricsForFolder($cache, $folder);
    }

    writeCache($cache);
    jsonOut(['success' => true, 'deleted' => $deleted, 'failed' => $failed]);
}

function handle_refresh_metrics(string $baseDir, string $trashPath, array $noMostrar, array &$cache): never {
    $projects = buildProjectPayload(listVisibleProjects($baseDir, $noMostrar), $cache, true);
    $trash = buildProjectPayload(listTrashProjects($trashPath), $cache, true);
    writeCache($cache);

    jsonOut([
        'success' => true,
        'projects' => $projects,
        'trash' => $trash,
        'message' => 'Metricas actualizadas',
    ]);
}

function handle_list_files(array $body, string $baseDir, string $trashPath): never {
    $folder = sanitizeFolderName((string) ($body['folder'] ?? ''));
    $fromTrash = (bool) ($body['fromTrash'] ?? false);

    if ($folder === '') jsonOut(['success' => false, 'message' => 'Carpeta invalida'], 400);
    if (!$fromTrash && isProtectedFolder($folder)) {
        jsonOut(['success' => false, 'message' => 'Acceso restringido a carpeta protegida.'], 403);
    }

    $root = $fromTrash ? $trashPath : $baseDir;
    $path = $root . DIRECTORY_SEPARATOR . $folder;

    jsonOut(listFilesAndReadme($path));
}
