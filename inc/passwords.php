<?php

declare(strict_types=1);

const PASS_KEY_FILE = APP_ROOT . '/.pass_key.bin';

function passFileFor(string $folder, string $baseDir): string {
    $passDir = $baseDir . DIRECTORY_SEPARATOR . 'pass';
    if (!is_dir($passDir)) @mkdir($passDir, 0755, true);
    return $passDir . DIRECTORY_SEPARATOR . $folder . '.json';
}

function getPasswordCryptoKey(): ?string {
    if (!function_exists('sodium_crypto_secretbox')) {
        return null;
    }

    $need = SODIUM_CRYPTO_SECRETBOX_KEYBYTES;
    if (is_file(PASS_KEY_FILE)) {
        $k = @file_get_contents(PASS_KEY_FILE);
        if (is_string($k) && strlen($k) === $need) {
            return $k;
        }
    }

    try {
        $key = random_bytes($need);
    } catch (Throwable $e) {
        return null;
    }

    if (@file_put_contents(PASS_KEY_FILE, $key, LOCK_EX) === false) {
        return null;
    }

    @chmod(PASS_KEY_FILE, 0600);
    return $key;
}

function encryptPasswordValue(string $plain, string $key): string {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plain, $nonce, $key);
    return base64_encode($nonce . $cipher);
}

function decryptPasswordValue(string $encoded, string $key): ?string {
    $bin = base64_decode($encoded, true);
    if (!is_string($bin) || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return null;
    }

    $nonce = substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
    return $plain === false ? null : $plain;
}

function readPasswordsDecrypted(string $folder, string $baseDir, string $key): array {
    $jsonFile = passFileFor($folder, $baseDir);
    if (!is_file($jsonFile)) {
        @file_put_contents($jsonFile, json_encode([]));
    }

    $arr = json_decode(@file_get_contents($jsonFile) ?: '[]', true);
    if (!is_array($arr)) $arr = [];

    $out = [];
    $needsRewrite = false;

    foreach ($arr as $it) {
        if (!is_array($it)) continue;

        $name = trim((string) ($it['name'] ?? ''));
        if ($name === '') continue;

        $password = '';
        if (isset($it['password_enc'])) {
            $password = decryptPasswordValue((string) $it['password_enc'], $key) ?? '';
        } elseif (isset($it['password'])) {
            // Compatibilidad con esquema previo en texto plano.
            $password = (string) $it['password'];
            $needsRewrite = true;
        }

        $out[] = ['name' => $name, 'password' => $password];
    }

    if ($needsRewrite) {
        writePasswordsEncrypted($folder, $baseDir, $out, $key);
    }

    return $out;
}

function writePasswordsEncrypted(string $folder, string $baseDir, array $entries, string $key): bool {
    $jsonFile = passFileFor($folder, $baseDir);

    $store = [];
    foreach ($entries as $it) {
        $name = trim((string) ($it['name'] ?? ''));
        $password = (string) ($it['password'] ?? '');
        if ($name === '') continue;

        $store[] = [
            'name' => $name,
            'password_enc' => encryptPasswordValue($password, $key),
            'v' => 1,
            'updated_at' => time(),
        ];
    }

    return @file_put_contents($jsonFile, json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

function handle_list_passwords(array $body, string $baseDir): never {
    $folder = sanitizeFolderName((string) ($body['folder'] ?? ''));
    if ($folder === '') jsonOut(['success' => false, 'message' => 'Falta folder'], 400);
    if (isProtectedFolder($folder)) jsonOut(['success' => false, 'message' => 'Carpeta protegida.'], 403);

    $projectPath = $baseDir . DIRECTORY_SEPARATOR . $folder;
    if (!is_dir($projectPath)) jsonOut(['success' => false, 'message' => 'Proyecto no encontrado.'], 404);

    $key = getPasswordCryptoKey();
    if ($key === null) {
        jsonOut(['success' => false, 'message' => 'No se pudo inicializar cifrado seguro de contrasenas.'], 500);
    }

    $arr = readPasswordsDecrypted($folder, $baseDir, $key);
    jsonOut(['success' => true, 'passwords' => $arr]);
}

function handle_save_passwords(array $body, string $baseDir): never {
    $folder = sanitizeFolderName((string) ($body['folder'] ?? ''));
    $name = trim((string) ($body['name'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    if ($folder === '' || $name === '' || $password === '') {
        jsonOut(['success' => false, 'message' => 'Faltan datos'], 400);
    }
    if (isProtectedFolder($folder)) jsonOut(['success' => false, 'message' => 'Carpeta protegida.'], 403);

    $projectPath = $baseDir . DIRECTORY_SEPARATOR . $folder;
    if (!is_dir($projectPath)) jsonOut(['success' => false, 'message' => 'Proyecto no encontrado.'], 404);

    $key = getPasswordCryptoKey();
    if ($key === null) {
        jsonOut(['success' => false, 'message' => 'No se pudo inicializar cifrado seguro de contrasenas.'], 500);
    }

    $arr = readPasswordsDecrypted($folder, $baseDir, $key);

    $updated = false;
    foreach ($arr as &$item) {
        if (($item['name'] ?? '') === $name) {
            $item['password'] = $password;
            $updated = true;
            break;
        }
    }
    unset($item);

    if (!$updated) $arr[] = ['name' => $name, 'password' => $password];

    $ok = writePasswordsEncrypted($folder, $baseDir, $arr, $key);
    if ($ok !== false) {
        jsonOut(['success' => true, 'message' => $updated ? 'Contrasena actualizada' : 'Contrasena guardada']);
    }

    jsonOut(['success' => false, 'message' => 'No se pudo guardar'], 500);
}

function handle_delete_password(array $body, string $baseDir): never {
    $folder = sanitizeFolderName((string) ($body['folder'] ?? ''));
    $name = trim((string) ($body['name'] ?? ''));

    if ($folder === '' || $name === '') {
        jsonOut(['success' => false, 'message' => 'Faltan datos'], 400);
    }
    if (isProtectedFolder($folder)) jsonOut(['success' => false, 'message' => 'Carpeta protegida.'], 403);

    $projectPath = $baseDir . DIRECTORY_SEPARATOR . $folder;
    if (!is_dir($projectPath)) jsonOut(['success' => false, 'message' => 'Proyecto no encontrado.'], 404);

    $key = getPasswordCryptoKey();
    if ($key === null) {
        jsonOut(['success' => false, 'message' => 'No se pudo inicializar cifrado seguro de contrasenas.'], 500);
    }

    $arr = readPasswordsDecrypted($folder, $baseDir, $key);

    $arr = array_values(array_filter($arr, static fn(array $x): bool => ($x['name'] ?? '') !== $name));

    $ok = writePasswordsEncrypted($folder, $baseDir, $arr, $key);
    if ($ok !== false) jsonOut(['success' => true]);

    jsonOut(['success' => false, 'message' => 'No se pudo eliminar'], 500);
}

function handle_update_password(array $body, string $baseDir): never {
    $folder = sanitizeFolderName((string) ($body['folder'] ?? ''));
    $name = trim((string) ($body['name'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    if ($folder === '' || $name === '' || $password === '') {
        jsonOut(['success' => false, 'message' => 'Faltan datos'], 400);
    }
    if (isProtectedFolder($folder)) jsonOut(['success' => false, 'message' => 'Carpeta protegida.'], 403);

    $projectPath = $baseDir . DIRECTORY_SEPARATOR . $folder;
    if (!is_dir($projectPath)) jsonOut(['success' => false, 'message' => 'Proyecto no encontrado.'], 404);

    $key = getPasswordCryptoKey();
    if ($key === null) {
        jsonOut(['success' => false, 'message' => 'No se pudo inicializar cifrado seguro de contrasenas.'], 500);
    }

    $arr = readPasswordsDecrypted($folder, $baseDir, $key);

    $found = false;
    foreach ($arr as &$item) {
        if (($item['name'] ?? '') === $name) {
            $item['password'] = $password;
            $found = true;
            break;
        }
    }
    unset($item);

    if (!$found) jsonOut(['success' => false, 'message' => 'Usuario no encontrado'], 404);

    $ok = writePasswordsEncrypted($folder, $baseDir, $arr, $key);
    if ($ok !== false) jsonOut(['success' => true]);

    jsonOut(['success' => false, 'message' => 'No se pudo actualizar'], 500);
}
