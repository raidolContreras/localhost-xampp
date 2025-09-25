<?php
// api.php — Endpoint JSON para tu dashboard de proyectos (htdocs root)
// Reemplaza a functions.php como backend con respuestas JSON.

declare(strict_types=1);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$baseDir   = __DIR__;
$noMostrar = ['.', '..', 'dashboard', 'xampp', 'webalizer', 'img', '_PAPELERIA', 'pass'];

// -------- Helpers --------
function jsonOut(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function humanFileSize(int $bytes, int $decimals = 2): string {
    $units = ['B','KB','MB','GB','TB'];
    if ($bytes <= 0) return '0 B';
    $factor = (int)floor(log($bytes, 1024));
    return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . ' ' . $units[$factor];
}

function dirSizeRecursive(string $dir): int {
    $size = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if ($f->isFile()) $size += $f->getSize();
    }
    return $size;
}

function listProjects(string $baseDir, array $noMostrar): array {
    $items = scandir($baseDir);
    $folders = array_filter($items, function ($e) use ($baseDir, $noMostrar) {
        return !in_array($e, $noMostrar, true) && is_dir($baseDir . DIRECTORY_SEPARATOR . $e);
    });

    $projects = [];
    foreach ($folders as $folder) {
        $path = $baseDir . DIRECTORY_SEPARATOR . $folder;
        $size = dirSizeRecursive($path);
        $created = @filectime($path) ?: 0;
        $filesCount = count(glob($path . DIRECTORY_SEPARATOR . '*'));
        $projects[] = [
            'name'        => $folder,
            'path'        => $path,               // para vscode://file/
            'size_bytes'  => $size,
            'size_human'  => humanFileSize($size),
            'created'     => $created,
            'files_count' => $filesCount
        ];
    }
    return $projects;
}

function listFilesAndReadme(string $folderPath): array {
    if (!is_dir($folderPath)) {
        return ['success' => false, 'message' => 'La carpeta no existe'];
    }

    $items = [];
    foreach (scandir($folderPath) as $it) {
        if ($it === '.' || $it === '..') continue;
        $full = $folderPath . DIRECTORY_SEPARATOR . $it;
        if (is_dir($full)) {
            $items[] = [
                'name'    => $it,
                'type'    => 'folder',
                'size'    => humanFileSize(dirSizeRecursive($full)),
                'created' => @filectime($full) ?: 0,
            ];
        } elseif (is_file($full)) {
            $items[] = [
                'name'    => $it,
                'type'    => 'file',
                'size'    => humanFileSize(filesize($full) ?: 0),
                'created' => @filectime($full) ?: 0,
            ];
        }
    }

    // README.md → HTML
    $readmeHtml = null;
    $readmePath = $folderPath . DIRECTORY_SEPARATOR . 'README.md';
    if (is_file($readmePath)) {
        $raw = file_get_contents($readmePath) ?: '';
        $parsed = null;
        // Soporta Parsedown si existe, si no, simple nl2br
        $parsedownFile = __DIR__ . DIRECTORY_SEPARATOR . 'Parsedown.php';
        if (is_file($parsedownFile)) {
            require_once $parsedownFile;
            if (class_exists('Parsedown')) {
                $pd = new Parsedown();
                $parsed = $pd->text($raw);
            }
        }
        $readmeHtml = $parsed ?? '<pre style="white-space:pre-wrap">'.htmlspecialchars($raw).'</pre>';
    }

    return ['success' => true, 'items' => $items, 'readme' => $readmeHtml];
}

// -------- Router --------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? null;

$body = null;
if ($method === 'POST') {
    $raw = file_get_contents('php://input') ?: '';
    $body = json_decode($raw, true);
    if (!is_array($body)) $body = [];
    if (!$action && isset($body['action'])) $action = $body['action'];
}

// -------- Actions --------

// GET /api.php?action=init  → meta + proyectos
if ($method === 'GET' && $action === 'init') {
    $projects = listProjects($baseDir, $noMostrar);
    $totalSize = array_sum(array_column($projects, 'size_bytes'));

    // php.ini y extensiones
    $ini = @ini_get_all(null, false) ?: [];
    $extensions = @get_loaded_extensions() ?: [];
    sort($extensions);

    jsonOut([
        'success'           => true,
        'php_version'       => PHP_VERSION,
        'ini'               => $ini,
        'extensions'        => $extensions,
        'total_size_bytes'  => $totalSize,
        'total_size_human'  => humanFileSize($totalSize),
        'projects'          => $projects
    ]);
}

// GET /api.php?action=get_php_ini
if ($method === 'GET' && $action === 'get_php_ini') {
    $iniFile = php_ini_loaded_file();
    if ($iniFile && is_readable($iniFile)) {
        jsonOut(['success' => true, 'content' => file_get_contents($iniFile)]);
    }
    jsonOut(['success' => false, 'message' => 'No se pudo leer php.ini'], 400);
}

// POST /api.php {action: "save_php_ini", content: "..."}
if ($method === 'POST' && $action === 'save_php_ini') {
    $content = (string)($body['content'] ?? '');
    $iniFile = php_ini_loaded_file();
    if ($iniFile && is_writable($iniFile)) {
        $ok = @file_put_contents($iniFile, $content);
        if ($ok !== false) jsonOut(['success' => true]);
        jsonOut(['success' => false, 'message' => 'No se pudo escribir en php.ini'], 400);
    }
    jsonOut(['success' => false, 'message' => 'php.ini no es escribible'], 400);
}

// POST /api.php {action: "create_project", name: "carpeta"}
if ($method === 'POST' && $action === 'create_project') {
    $name = basename((string)($body['name'] ?? ''));
    if (!$name) jsonOut(['success' => false, 'message' => 'Nombre inválido'], 400);

    $dest = $baseDir . DIRECTORY_SEPARATOR . $name;
    if (is_dir($dest)) jsonOut(['success' => false, 'message' => 'El proyecto ya existe'], 409);

    $ok = @mkdir($dest, 0755);
    if ($ok) jsonOut(['success' => true, 'message' => 'Proyecto creado']);
    jsonOut(['success' => false, 'message' => 'No se pudo crear el proyecto'], 500);
}

// POST /api.php {action:"move", folder:"nombre"}
if ($method === 'POST' && $action === 'move') {
    $folder = basename((string)($body['folder'] ?? ''));
    if (!$folder) jsonOut(['success'=>false,'message'=>'Carpeta inválida'],400);

    $src = $baseDir . DIRECTORY_SEPARATOR . $folder;
    if (!is_dir($src)) jsonOut(['success'=>false,'message'=>'No existe la carpeta'],404);

    $trash = $baseDir . DIRECTORY_SEPARATOR . '_PAPELERIA';
    if (!is_dir($trash)) {
        if (!@mkdir($trash, 0755)) {
            jsonOut(['success'=>false,'message'=>'No se pudo crear _PAPELERIA'],500);
        }
    }

    $dst = $trash . DIRECTORY_SEPARATOR . $folder;
    if (@rename($src, $dst)) jsonOut(['success'=>true]);
    jsonOut(['success'=>false,'message'=>'No se pudo mover la carpeta'],500);
}

// POST /api.php {action:"list_files", folder:"nombre"}
if ($method === 'POST' && $action === 'list_files') {
    $folder = basename((string)($body['folder'] ?? ''));
    $path = $baseDir . DIRECTORY_SEPARATOR . $folder;
    jsonOut(listFilesAndReadme($path));
}

// --- Contraseñas (por proyecto) ---
// Files en: /pass/<carpeta>.json

function passFileFor(string $folder, string $baseDir): string {
    $passDir = $baseDir . DIRECTORY_SEPARATOR . 'pass';
    if (!is_dir($passDir)) @mkdir($passDir, 0755, true);
    return $passDir . DIRECTORY_SEPARATOR . $folder . '.json';
}

// POST {action:"list_passwords", folder}
if ($method === 'POST' && $action === 'list_passwords') {
    $folder = basename((string)($body['folder'] ?? ''));
    if (!$folder) jsonOut(['success'=>false,'message'=>'Falta folder'],400);

    $jsonFile = passFileFor($folder, $baseDir);
    if (!is_file($jsonFile)) @file_put_contents($jsonFile, json_encode([]));

    $arr = json_decode(@file_get_contents($jsonFile) ?: '[]', true);
    if (!is_array($arr)) $arr = [];
    jsonOut(['success'=>true,'passwords'=>$arr]);
}

// POST {action:"save_passwords", folder, name, password}
if ($method === 'POST' && $action === 'save_passwords') {
    $folder   = basename((string)($body['folder'] ?? ''));
    $name     = (string)($body['name'] ?? '');
    $password = (string)($body['password'] ?? '');
    if (!$folder || !$name || !$password) jsonOut(['success'=>false,'message'=>'Faltan datos'],400);

    $jsonFile = passFileFor($folder, $baseDir);
    $arr = json_decode(@file_get_contents($jsonFile) ?: '[]', true);
    if (!is_array($arr)) $arr = [];

    $updated = false;
    foreach ($arr as &$it) {
        if (($it['name'] ?? '') === $name) {
            $it['password'] = $password;
            $updated = true;
            break;
        }
    }
    unset($it);
    if (!$updated) $arr[] = ['name'=>$name, 'password'=>$password];

    $ok = @file_put_contents($jsonFile, json_encode($arr, JSON_PRETTY_PRINT));
    if ($ok !== false) jsonOut(['success'=>true, 'message'=>$updated?'Contraseña actualizada':'Contraseña guardada']);
    jsonOut(['success'=>false,'message'=>'No se pudo guardar'],500);
}

// POST {action:"delete_password", folder, name}
if ($method === 'POST' && $action === 'delete_password') {
    $folder = basename((string)($body['folder'] ?? ''));
    $name   = (string)($body['name'] ?? '');
    if (!$folder || !$name) jsonOut(['success'=>false,'message'=>'Faltan datos'],400);

    $jsonFile = passFileFor($folder, $baseDir);
    if (!is_file($jsonFile)) jsonOut(['success'=>false,'message'=>'Archivo no encontrado'],404);

    $arr = json_decode(@file_get_contents($jsonFile) ?: '[]', true);
    if (!is_array($arr)) $arr = [];
    $arr = array_values(array_filter($arr, fn($x) => ($x['name'] ?? null) !== $name));

    $ok = @file_put_contents($jsonFile, json_encode($arr, JSON_PRETTY_PRINT));
    if ($ok !== false) jsonOut(['success'=>true]);
    jsonOut(['success'=>false,'message'=>'No se pudo eliminar'],500);
}

// POST {action:"update_password", folder, name, password}
if ($method === 'POST' && $action === 'update_password') {
    $folder   = basename((string)($body['folder'] ?? ''));
    $name     = (string)($body['name'] ?? '');
    $password = (string)($body['password'] ?? '');
    if (!$folder || !$name || !$password) jsonOut(['success'=>false,'message'=>'Faltan datos'],400);

    $jsonFile = passFileFor($folder, $baseDir);
    if (!is_file($jsonFile)) jsonOut(['success'=>false,'message'=>'Archivo no encontrado'],404);

    $arr = json_decode(@file_get_contents($jsonFile) ?: '[]', true);
    if (!is_array($arr)) $arr = [];
    $found = false;
    foreach ($arr as &$it) {
        if (($it['name'] ?? '') === $name) {
            $it['password'] = $password;
            $found = true;
            break;
        }
    }
    unset($it);
    if (!$found) jsonOut(['success'=>false,'message'=>'Usuario no encontrado'],404);

    $ok = @file_put_contents($jsonFile, json_encode($arr, JSON_PRETTY_PRINT));
    if ($ok !== false) jsonOut(['success'=>true]);
    jsonOut(['success'=>false,'message'=>'No se pudo actualizar'],500);
}

// Default: acción no soportada
jsonOut(['success'=>false,'message'=>'Acción no soportada'], 404);
