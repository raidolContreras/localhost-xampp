<?php

$directorioActual = __DIR__;
$noMostrar = ['.', '..', 'dashboard', 'xampp', 'webalizer', 'img', '_PAPELERIA', 'pass'];

// --- Manejo de AJAX para mover carpeta a "papeleria" ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_response_code(400);
        exit(json_encode(['success' => false, 'message' => 'JSON inválido']));
    }

    if (isset($data['action'], $data['folder']) && $data['action'] === 'move') {
        $folder = basename($data['folder'], DIRECTORY_SEPARATOR);
        $src = $directorioActual . DIRECTORY_SEPARATOR . $folder;
        $destDir = $directorioActual . DIRECTORY_SEPARATOR . '_PAPELERIA';

        // Crear "papeleria" si no existe
        if (!is_dir($destDir)) {
            if (!mkdir($destDir, 0755)) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'No se pudo crear la carpeta papeleria']);
                exit;
            }
        }

        $dest = $destDir . DIRECTORY_SEPARATOR . $folder;
        if (rename($src, $dest)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'No se pudo mover la carpeta']);
            exit;
        }
    }
    // guardar php.ini
    elseif ($data['action'] === 'save_php_ini' && isset($data['content'])) {
        $iniFile = php_ini_loaded_file();
        header('Content-Type: application/json');
        if ($iniFile && is_writable($iniFile) && file_put_contents($iniFile, $data['content']) !== false) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'No se pudo escribir en php.ini']);
        }
        exit;
    } elseif ($data['action'] === 'list_files' && isset($data['folder'])) {
        $folder = basename($data['folder'], DIRECTORY_SEPARATOR);
        $folderPath = $directorioActual . DIRECTORY_SEPARATOR . $folder;
        header('Content-Type: application/json');
        if (!is_dir($folderPath)) {
            echo json_encode(['success' => false, 'message' => 'La carpeta no existe']);
            exit;
        }
        $items = [];
        foreach (scandir($folderPath) as $item) {
            if ($item === '.' || $item === '..')
                continue;
            $itemPath = $folderPath . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath)) {
                $items[] = [
                    'name' => $item,
                    'type' => 'folder',
                    'size' => humanFileSize(getDirectorySize($itemPath)),
                    'created' => filectime($itemPath)
                ];
            } elseif (is_file($itemPath)) {
                $items[] = [
                    'name' => $item,
                    'type' => 'file',
                    'size' => humanFileSize(filesize($itemPath)),
                    'created' => filectime($itemPath)
                ];
            }
        }
        echo json_encode(['success' => true, 'items' => $items]);
        exit;
    } elseif ($data['action'] === 'create_project' && isset($data['name'])) {
        $projectName = basename($data['name'], DIRECTORY_SEPARATOR);
        $projectPath = $directorioActual . DIRECTORY_SEPARATOR . $projectName;
        header('Content-Type: application/json');
        if (is_dir($projectPath)) {
            echo json_encode(['success' => false, 'message' => 'El proyecto ya existe']);
            exit;
        }
        if (mkdir($projectPath, 0755)) {
            echo json_encode(['success' => true, 'message' => 'Proyecto creado exitosamente']);
        } else {
            echo json_encode(['success' => false, 'message' => 'No se pudo crear el proyecto']);
        }
        exit;
    } elseif ($data['action'] === 'list_passwords') {
        header('Content-Type: application/json');
        if (!isset($data['folder'])) {
            echo json_encode(['success' => false, 'message' => 'Falta el nombre de la carpeta']);
            exit;
        }
        $folder = basename($data['folder'], DIRECTORY_SEPARATOR);
        $passDir = $directorioActual . DIRECTORY_SEPARATOR . 'pass';
        if (!is_dir($passDir)) {
            if (!mkdir($passDir, 0755, true)) {
                echo json_encode(['success' => false, 'message' => 'No se pudo crear la carpeta pass']);
                exit;
            }
        }
        $jsonFile = $passDir . DIRECTORY_SEPARATOR . $folder . '.json';
        if (!file_exists($jsonFile)) {
            file_put_contents($jsonFile, json_encode([]));
        }
        $passwords = [];
        if (is_readable($jsonFile)) {
            $content = file_get_contents($jsonFile);
            $passwords = json_decode($content, true);
            if (!is_array($passwords)) {
                $passwords = [];
            }
        }
        echo json_encode(['success' => true, 'passwords' => $passwords]);
        exit;
    }
    // ─────────────────────────────────────────────────────────────────────────────
// Guardar contraseñas editadas / nuevas (acción: save_passwords)
// ─────────────────────────────────────────────────────────────────────────────
elseif ($data['action'] === 'save_passwords') {
    header('Content-Type: application/json');
    if (!isset($data['folder'], $data['name'], $data['password'])) {
        echo json_encode(['success' => false, 'message' => 'Faltan datos requeridos']);
        exit;
    }
    $folder = basename($data['folder']);
    $passDir = $directorioActual . DIRECTORY_SEPARATOR . 'pass';
    if (!is_dir($passDir) && !mkdir($passDir, 0755, true)) {
        echo json_encode(['success' => false, 'message' => 'No se pudo crear la carpeta pass']);
        exit;
    }
    $jsonFile = "$passDir/$folder.json";

    // 1) Leer existente
    $passwords = [];
    if (file_exists($jsonFile)) {
        $content = file_get_contents($jsonFile);
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            $passwords = $decoded;
        }
    }
    // 2) Evitar duplicados (opcional: actualizar si ya existe)
    foreach ($passwords as &$item) {
        if ($item['name'] === $data['name']) {
            // si ya existe, lo actualizamos y volvemos a guardar
            $item['password'] = $data['password'];
            file_put_contents($jsonFile, json_encode($passwords, JSON_PRETTY_PRINT));
            echo json_encode(['success' => true, 'message' => 'Contraseña actualizada']);
            exit;
        }
    }
    unset($item);

    // 3) Agregar nuevo
    $passwords[] = [
        'name'     => $data['name'],
        'password' => $data['password'],
    ];

    // 4) Guardar todo
    $ok = file_put_contents($jsonFile, json_encode($passwords, JSON_PRETTY_PRINT));
    if ($ok !== false) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No se pudo guardar las contraseñas']);
    }
    exit;
}

    elseif ($data['action'] === 'delete_password') {
        header('Content-Type: application/json');
        if (!isset($data['folder']) || !isset($data['name'])) {
            echo json_encode(['success' => false, 'message' => 'Faltan datos requeridos']);
            exit;
        }
        $folder = basename($data['folder'], DIRECTORY_SEPARATOR);
        $passDir = $directorioActual . DIRECTORY_SEPARATOR . 'pass';
        $jsonFile = $passDir . DIRECTORY_SEPARATOR . $folder . '.json';
        if (!file_exists($jsonFile)) {
            echo json_encode(['success' => false, 'message' => 'Archivo de contraseñas no encontrado']);
            exit;
        }
        $content = file_get_contents($jsonFile);
        $passwords = json_decode($content, true);
        if (!is_array($passwords)) {
            $passwords = [];
        }
        $passwords = array_filter($passwords, function ($item) use ($data) {
            return $item['name'] !== $data['name'];
        });
        $ok = file_put_contents($jsonFile, json_encode($passwords, JSON_PRETTY_PRINT));
        if ($ok !== false) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'No se pudo eliminar la contraseña']);
        }
        exit;
    }
    elseif ($data['action'] === 'update_password') {
        header('Content-Type: application/json');
        if (!isset($data['folder']) || !isset($data['name']) || !isset($data['password'])) {
            echo json_encode(['success' => false, 'message' => 'Faltan datos requeridos']);
            exit;
        }
        $folder = basename($data['folder'], DIRECTORY_SEPARATOR);
        $passDir = $directorioActual . DIRECTORY_SEPARATOR . 'pass';
        $jsonFile = $passDir . DIRECTORY_SEPARATOR . $folder . '.json';
        if (!file_exists($jsonFile)) {
            echo json_encode(['success' => false, 'message' => 'Archivo de contraseñas no encontrado']);
            exit;
        }
        $content = file_get_contents($jsonFile);
        $passwords = json_decode($content, true);
        if (!is_array($passwords)) {
            $passwords = [];
        }
        $updated = false;
        foreach ($passwords as &$item) {
            if ($item['name'] === $data['name']) {
                $item['password'] = $data['password'];
                $updated = true;
                break;
            }
        }
        unset($item);
        if (!$updated) {
            echo json_encode(['success' => false, 'message' => 'Usuario no encontrado']);
            exit;
        }
        $ok = file_put_contents($jsonFile, json_encode($passwords, JSON_PRETTY_PRINT));
        if ($ok !== false) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'No se pudo actualizar la contraseña']);
        }
        exit;
    }
}

// obtener contenido php.ini
if (isset($_GET['action']) && $_GET['action'] === 'get_php_ini') {
    $iniFile = php_ini_loaded_file();
    header('Content-Type: application/json');
    if ($iniFile && is_readable($iniFile)) {
        echo json_encode(['success' => true, 'content' => file_get_contents($iniFile)]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No se pudo leer php.ini']);
    }
    exit;
}

// --- Obtener carpetas del directorio actual ---
$elementos = scandir($directorioActual);

$carpetas = array_filter($elementos, function ($elemento) use ($directorioActual, $noMostrar) {
    return !in_array($elemento, $noMostrar)
        && is_dir($directorioActual . DIRECTORY_SEPARATOR . $elemento);
});

// --- Función para calcular tamaño de un directorio recursivamente ---
function getDirectorySize(string $dir): int
{
    $size = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->isFile()) {
            $size += $file->getSize();
        }
    }
    return $size;
}

// --- Función para formatear bytes a KB/MB/GB ---
function humanFileSize(int $bytes, int $decimals = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    if ($bytes === 0)
        return '0 B';
    $factor = floor(log($bytes, 1024));
    return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . ' ' . $units[$factor];
}

// --- Recolectar datos de cada carpeta ---
$totalSize = 0;
$folderData = [];
foreach ($carpetas as $carpeta) {
    $ruta = $directorioActual . DIRECTORY_SEPARATOR . $carpeta;
    $size = getDirectorySize($ruta);
    $created = filectime($ruta);
    $totalSize += $size;
    $folderData[$carpeta] = [
        'size' => $size,
        'created' => $created
    ];
}