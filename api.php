<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=UTF-8');

$baseDir = __DIR__;
$trashDirName = '_PAPELERIA';
$trashPath = $baseDir . DIRECTORY_SEPARATOR . $trashDirName;
$noMostrar = ['.', '..', 'dashboard', 'xampp', 'webalizer', 'img', '_PAPELERIA', 'pass'];

const CACHE_FILE = __DIR__ . '/.folder_cache.json';
const CACHE_SOFT_TTL = 120;
const CACHE_HARD_TTL = 1800;
const AUTH_FILE = __DIR__ . '/.dashboard_auth.json';
const PASS_KEY_FILE = __DIR__ . '/.pass_key.bin';
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCK_SECONDS = 900;

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

function readAuthConfig(): array {
    return readJsonFile(AUTH_FILE, []);
}

function writeAuthConfig(array $auth): bool {
    return writeJsonFile(AUTH_FILE, $auth);
}

function loginKey(): string {
    return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? 'ua'));
}

function getLoginGuard(array $auth, string $key): array {
    $guards = is_array($auth['login_guards'] ?? null) ? $auth['login_guards'] : [];
    $g = is_array($guards[$key] ?? null) ? $guards[$key] : [];
    return [
        'attempts' => (int) ($g['attempts'] ?? 0),
        'locked_until' => (int) ($g['locked_until'] ?? 0),
    ];
}

function registerLoginFailure(array &$auth, string $key): array {
    if (!is_array($auth['login_guards'] ?? null)) $auth['login_guards'] = [];

    $guard = getLoginGuard($auth, $key);
    $guard['attempts']++;

    if ($guard['attempts'] >= LOGIN_MAX_ATTEMPTS) {
        $guard['attempts'] = 0;
        $guard['locked_until'] = time() + LOGIN_LOCK_SECONDS;
    }

    $auth['login_guards'][$key] = $guard;
    return $guard;
}

function clearLoginGuard(array &$auth, string $key): void {
    if (!is_array($auth['login_guards'] ?? null)) return;
    unset($auth['login_guards'][$key]);
}

function sanitizeSmtp(array $smtp): array {
    $enc = (string) ($smtp['encryption'] ?? 'tls');
    if (!in_array($enc, ['tls', 'ssl', 'none'], true)) {
        $enc = 'tls';
    }

    $hostRaw = trim((string) ($smtp['host'] ?? ''));
    $hostRaw = preg_replace('#^(ssl|tls|tcp)://#i', '', $hostRaw) ?? $hostRaw;
    $host = $hostRaw;
    $port = (int) ($smtp['port'] ?? 0);

    $hasSingleColon = substr_count($hostRaw, ':') === 1;
    if ($hasSingleColon && preg_match('/^(.+):(\d+)$/', $hostRaw, $m)) {
        $host = (string) ($m[1] ?? $hostRaw);
        $maybePort = (int) ($m[2] ?? 0);
        if ($maybePort > 0 && $port <= 0) {
            $port = $maybePort;
        }
    }

    $host = trim($host);

    return [
        'host' => $host,
        'port' => $port,
        'encryption' => $enc,
        'user' => trim((string) ($smtp['user'] ?? '')),
        'pass' => (string) ($smtp['pass'] ?? ''),
        'from_email' => trim((string) ($smtp['from_email'] ?? '')),
        'from_name' => trim((string) ($smtp['from_name'] ?? 'Dashboard XAMPP')),
    ];
}

function smtpConfigured(array $smtp): bool {
    return $smtp['host'] !== '' && $smtp['port'] > 0 && $smtp['user'] !== '' && $smtp['from_email'] !== '';
}

function smtpRead($fp): string {
    $data = '';
    while (($line = fgets($fp, 515)) !== false) {
        $data .= $line;
        if (strlen($line) < 4) break;
        if ($line[3] === ' ') break;
    }
    return $data;
}

function smtpExpect(string $resp, array $codes): bool {
    $code = (int) substr($resp, 0, 3);
    return in_array($code, $codes, true);
}

function smtpCmd($fp, string $cmd, array $expectCodes): bool {
    fwrite($fp, $cmd . "\r\n");
    $resp = smtpRead($fp);
    return smtpExpect($resp, $expectCodes);
}

function sendMailViaSmtp(array $smtp, string $to, string $subject, string $body, string &$error = ''): bool {
    $host = $smtp['host'];
    $port = (int) $smtp['port'];
    $enc = $smtp['encryption'];
    $user = $smtp['user'];
    $pass = $smtp['pass'];
    $fromEmail = $smtp['from_email'];
    $fromName = $smtp['from_name'] !== '' ? $smtp['from_name'] : 'Dashboard XAMPP';

    if ($host === '' || $port <= 0) {
        $error = 'Host o puerto SMTP invalidos.';
        return false;
    }

    // Corrige configuraciones SMTP comunes mal seleccionadas en UI.
    if ($enc === 'ssl' && $port === 587) {
        $enc = 'tls';
    } elseif ($enc === 'tls' && $port === 465) {
        $enc = 'ssl';
    }

    $transport = $enc === 'ssl' ? 'ssl://' . $host : 'tcp://' . $host;
    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ],
    ]);

    $fp = @stream_socket_client($transport . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        $last = error_get_last();
        $details = trim((string) $errstr);
        if ($details === '' && is_array($last) && isset($last['message'])) {
            $details = trim((string) $last['message']);
        }
        if ($details === '') {
            $details = 'Fallo de socket sin detalle del sistema.';
        }

        $hint = ($port === 587 || $port === 465)
            ? ' Verifica cifrado/puerto: 587->TLS (STARTTLS), 465->SSL.'
            : '';

        $error = "No se pudo conectar a {$host}:{$port} ({$transport}). errno={$errno}. {$details}.{$hint}";
        return false;
    }

    stream_set_timeout($fp, 20);

    $banner = smtpRead($fp);
    if (!smtpExpect($banner, [220])) {
        fclose($fp);
        $error = 'El servidor SMTP rechazo la conexion.';
        return false;
    }

    if (!smtpCmd($fp, 'EHLO localhost', [250])) {
        fclose($fp);
        $error = 'EHLO fallo.';
        return false;
    }

    if ($enc === 'tls') {
        if (!smtpCmd($fp, 'STARTTLS', [220])) {
            fclose($fp);
            $error = 'STARTTLS no disponible.';
            return false;
        }

        $crypto = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($crypto !== true) {
            fclose($fp);
            $error = 'No se pudo negociar TLS.';
            return false;
        }

        if (!smtpCmd($fp, 'EHLO localhost', [250])) {
            fclose($fp);
            $error = 'EHLO post-TLS fallo.';
            return false;
        }
    }

    if ($user !== '') {
        if (!smtpCmd($fp, 'AUTH LOGIN', [334])) {
            fclose($fp);
            $error = 'AUTH LOGIN no permitido por el servidor.';
            return false;
        }
        if (!smtpCmd($fp, base64_encode($user), [334])) {
            fclose($fp);
            $error = 'Usuario SMTP rechazado.';
            return false;
        }
        if (!smtpCmd($fp, base64_encode($pass), [235])) {
            fclose($fp);
            $error = 'Password SMTP rechazado.';
            return false;
        }
    }

    if (!smtpCmd($fp, 'MAIL FROM:<' . $fromEmail . '>', [250])) {
        fclose($fp);
        $error = 'MAIL FROM rechazado.';
        return false;
    }
    if (!smtpCmd($fp, 'RCPT TO:<' . $to . '>', [250, 251])) {
        fclose($fp);
        $error = 'RCPT TO rechazado.';
        return false;
    }
    if (!smtpCmd($fp, 'DATA', [354])) {
        fclose($fp);
        $error = 'DATA rechazado.';
        return false;
    }

    $safeBody = str_replace("\n.", "\n..", $body);
    $headers =
        'From: ' . $fromName . ' <' . $fromEmail . ">\r\n" .
        'To: <' . $to . ">\r\n" .
        'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n" .
        "MIME-Version: 1.0\r\n" .
        "Content-Type: text/plain; charset=UTF-8\r\n" .
        "Content-Transfer-Encoding: 8bit\r\n\r\n";

    fwrite($fp, $headers . $safeBody . "\r\n.\r\n");
    $dataResp = smtpRead($fp);
    if (!smtpExpect($dataResp, [250])) {
        fclose($fp);
        $error = 'El servidor no acepto el mensaje.';
        return false;
    }

    smtpCmd($fp, 'QUIT', [221]);
    fclose($fp);
    return true;
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
    $protected = ['img', 'pass', '_papeleria', 'dashboard', 'xampp', 'webalizer'];
    if ($n === '' || $n === '.' || $n === '..') return true;
    if (in_array($n, $protected, true)) return true;
    if (preg_match('/\.(php|js|css|md|json)$/i', $name)) return true;
    return false;
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

        $parsedownFile = __DIR__ . DIRECTORY_SEPARATOR . 'Parsedown.php';
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

function ensureCsrfToken(): string {
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf_token'];
}

function csrfValid(): bool {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $session = $_SESSION['csrf_token'] ?? '';
    return is_string($token) && is_string($session) && $token !== '' && hash_equals($session, $token);
}

function authStatusPayload(array $auth): array {
    $needsSetup = empty($auth) || !isset($auth['mode']);
    $mode = $needsSetup ? null : (string) $auth['mode'];
    $authenticated = $needsSetup ? false : ($mode === 'local' ? true : (bool) ($_SESSION['auth_ok'] ?? false));
    $requireAuth = !$needsSetup && $mode === 'network' && !$authenticated;

    return [
        'success' => true,
        'needs_setup' => $needsSetup,
        'mode' => $mode,
        'authenticated' => $authenticated,
        'require_auth' => $requireAuth,
        'is_local_request' => isLocalRequest(),
        'csrf_token' => $authenticated ? ensureCsrfToken() : '',
    ];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? null;

$body = [];
if ($method === 'POST') {
    $decoded = json_decode(file_get_contents('php://input') ?: '', true);
    $body = is_array($decoded) ? $decoded : [];
    if (!$action && isset($body['action'])) $action = (string) $body['action'];
}

$auth = readAuthConfig();
$authStatus = authStatusPayload($auth);

if ($method === 'GET' && $action === 'auth_status') {
    jsonOut($authStatus);
}

if ($method === 'POST' && $action === 'auth_setup') {
    if (!$authStatus['needs_setup']) {
        jsonOut(['success' => false, 'message' => 'La seguridad ya fue configurada.'], 409);
    }

    $mode = (string) ($body['mode'] ?? 'local');
    if (!in_array($mode, ['local', 'network'], true)) {
        jsonOut(['success' => false, 'message' => 'Modo invalido.'], 400);
    }

    $newAuth = [
        'mode' => $mode,
        'created_at' => time(),
    ];

    if ($mode === 'network') {
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $recoveryEmail = trim((string) ($body['recovery_email'] ?? ''));
        $smtp = sanitizeSmtp(is_array($body['smtp'] ?? null) ? $body['smtp'] : []);

        if ($username === '' || $password === '' || $recoveryEmail === '') {
            jsonOut(['success' => false, 'message' => 'Faltan datos de seguridad.'], 400);
        }

        if (!filter_var($recoveryEmail, FILTER_VALIDATE_EMAIL)) {
            jsonOut(['success' => false, 'message' => 'Correo de recuperacion invalido.'], 400);
        }

        if (!smtpConfigured($smtp)) {
            jsonOut(['success' => false, 'message' => 'Configuracion SMTP incompleta.'], 400);
        }

        if (!filter_var($smtp['from_email'], FILTER_VALIDATE_EMAIL)) {
            jsonOut(['success' => false, 'message' => 'Correo remitente SMTP invalido.'], 400);
        }

        $newAuth['username'] = $username;
        $newAuth['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        $newAuth['recovery_email'] = $recoveryEmail;
        $newAuth['reset_code_hash'] = '';
        $newAuth['reset_code_expires'] = 0;
        $newAuth['smtp'] = $smtp;
        $newAuth['login_guards'] = [];
    }

    if (!writeAuthConfig($newAuth)) {
        jsonOut(['success' => false, 'message' => 'No se pudo guardar la configuracion.'], 500);
    }

    if ($mode === 'network') {
        $_SESSION['auth_ok'] = true;
        $_SESSION['auth_user'] = $newAuth['username'];
    }

    jsonOut(['success' => true, 'csrf_token' => ensureCsrfToken()]);
}

if ($method === 'POST' && $action === 'auth_login') {
    if ($authStatus['needs_setup']) jsonOut(['success' => false, 'message' => 'Primero configura seguridad.'], 409);
    if (($auth['mode'] ?? '') !== 'network') jsonOut(['success' => false, 'message' => 'Login no requerido en modo local.'], 400);

    $username = trim((string) ($body['username'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    $guardKey = loginKey();
    $guard = getLoginGuard($auth, $guardKey);

    if ($guard['locked_until'] > time()) {
        $seconds = $guard['locked_until'] - time();
        jsonOut([
            'success' => false,
            'message' => "Demasiados intentos fallidos. Intenta de nuevo en {$seconds} segundos.",
            'lock_remaining' => $seconds,
        ], 429);
    }

    if ($username === '' || $password === '') jsonOut(['success' => false, 'message' => 'Credenciales incompletas.'], 400);

    $okUser = hash_equals((string) ($auth['username'] ?? ''), $username);
    $okPass = password_verify($password, (string) ($auth['password_hash'] ?? ''));

    if (!$okUser || !$okPass) {
        $guard = registerLoginFailure($auth, $guardKey);
        writeAuthConfig($auth);

        $remaining = max(0, LOGIN_MAX_ATTEMPTS - (int) $guard['attempts']);
        if ($guard['locked_until'] > time()) {
            $seconds = $guard['locked_until'] - time();
            jsonOut([
                'success' => false,
                'message' => "Demasiados intentos fallidos. Intenta de nuevo en {$seconds} segundos.",
                'lock_remaining' => $seconds,
            ], 429);
        }

        jsonOut([
            'success' => false,
            'message' => "Usuario o contrasena incorrecta. Intentos restantes: {$remaining}.",
            'attempts_remaining' => $remaining,
        ], 401);
    }

    clearLoginGuard($auth, $guardKey);
    writeAuthConfig($auth);

    $_SESSION['auth_ok'] = true;
    $_SESSION['auth_user'] = $username;

    jsonOut(['success' => true, 'csrf_token' => ensureCsrfToken()]);
}

if ($method === 'POST' && $action === 'auth_logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    jsonOut(['success' => true]);
}

if ($method === 'GET' && $action === 'auth_get_security') {
    if ($authStatus['require_auth']) {
        jsonOut(['success' => false, 'auth_required' => true, 'message' => 'Inicia sesion para ver seguridad.'], 401);
    }

    if (($auth['mode'] ?? '') !== 'network') {
        jsonOut(['success' => false, 'message' => 'Solo disponible en modo red.'], 400);
    }

    $smtp = sanitizeSmtp(is_array($auth['smtp'] ?? null) ? $auth['smtp'] : []);
    $smtp['pass'] = '';

    jsonOut([
        'success' => true,
        'mode' => $auth['mode'],
        'smtp' => $smtp,
        'recovery_email' => (string) ($auth['recovery_email'] ?? ''),
    ]);
}

if ($method === 'POST' && $action === 'auth_update_smtp') {
    if ($authStatus['require_auth']) {
        jsonOut(['success' => false, 'auth_required' => true, 'message' => 'Inicia sesion para actualizar SMTP.'], 401);
    }

    if (($auth['mode'] ?? '') !== 'network') {
        jsonOut(['success' => false, 'message' => 'Solo disponible en modo red.'], 400);
    }

    $incoming = sanitizeSmtp(is_array($body['smtp'] ?? null) ? $body['smtp'] : []);
    if (!smtpConfigured($incoming)) {
        jsonOut(['success' => false, 'message' => 'Configuracion SMTP incompleta.'], 400);
    }
    if (!filter_var($incoming['from_email'], FILTER_VALIDATE_EMAIL)) {
        jsonOut(['success' => false, 'message' => 'Correo remitente invalido.'], 400);
    }

    $current = sanitizeSmtp(is_array($auth['smtp'] ?? null) ? $auth['smtp'] : []);
    if ($incoming['pass'] === '' && $current['pass'] !== '') {
        $incoming['pass'] = $current['pass'];
    }

    $auth['smtp'] = $incoming;
    if (!writeAuthConfig($auth)) {
        jsonOut(['success' => false, 'message' => 'No se pudo guardar SMTP.'], 500);
    }

    jsonOut(['success' => true]);
}

if ($method === 'POST' && $action === 'auth_request_reset') {
    if (($auth['mode'] ?? '') !== 'network') jsonOut(['success' => false, 'message' => 'Recuperacion solo disponible en modo red.'], 400);

    $username = trim((string) ($body['username'] ?? ''));
    $email = trim((string) ($body['email'] ?? ''));

    if ($username === '' || $email === '') jsonOut(['success' => false, 'message' => 'Faltan datos.'], 400);
    if (!hash_equals((string) ($auth['username'] ?? ''), $username) || !hash_equals((string) ($auth['recovery_email'] ?? ''), $email)) {
        jsonOut(['success' => false, 'message' => 'Los datos no coinciden con la cuenta configurada.'], 403);
    }

    $code = (string) random_int(100000, 999999);
    $auth['reset_code_hash'] = password_hash($code, PASSWORD_DEFAULT);
    $auth['reset_code_expires'] = time() + 900;

    $smtp = sanitizeSmtp(is_array($auth['smtp'] ?? null) ? $auth['smtp'] : []);
    if (!smtpConfigured($smtp)) {
        jsonOut(['success' => false, 'message' => 'SMTP no configurado. Pide al administrador configurarlo.'], 400);
    }

    if (!writeAuthConfig($auth)) {
        jsonOut(['success' => false, 'message' => 'No se pudo preparar la recuperacion.'], 500);
    }

    $subject = 'Codigo de recuperacion - Dashboard XAMPP';
    $message = "Tu codigo de recuperacion es: {$code}\n\nExpira en 15 minutos.";
    $smtpErr = '';
    $sent = sendMailViaSmtp($smtp, $email, $subject, $message, $smtpErr);
    if (!$sent) {
        jsonOut(['success' => false, 'message' => 'No se pudo enviar el correo por SMTP: ' . $smtpErr], 500);
    }

    jsonOut(['success' => true]);
}

if ($method === 'POST' && $action === 'auth_reset_password') {
    if (($auth['mode'] ?? '') !== 'network') jsonOut(['success' => false, 'message' => 'Recuperacion solo disponible en modo red.'], 400);

    $username = trim((string) ($body['username'] ?? ''));
    $code = trim((string) ($body['code'] ?? ''));
    $newPassword = (string) ($body['new_password'] ?? '');

    if ($username === '' || $code === '' || $newPassword === '') {
        jsonOut(['success' => false, 'message' => 'Faltan datos.'], 400);
    }

    if (!hash_equals((string) ($auth['username'] ?? ''), $username)) {
        jsonOut(['success' => false, 'message' => 'Usuario invalido.'], 403);
    }

    $expires = (int) ($auth['reset_code_expires'] ?? 0);
    $hash = (string) ($auth['reset_code_hash'] ?? '');
    if ($hash === '' || $expires < time()) {
        jsonOut(['success' => false, 'message' => 'Codigo expirado o inexistente.'], 400);
    }

    if (!password_verify($code, $hash)) {
        jsonOut(['success' => false, 'message' => 'Codigo incorrecto.'], 400);
    }

    $auth['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
    $auth['reset_code_hash'] = '';
    $auth['reset_code_expires'] = 0;

    if (!writeAuthConfig($auth)) {
        jsonOut(['success' => false, 'message' => 'No se pudo actualizar la contrasena.'], 500);
    }

    jsonOut(['success' => true]);
}

if ($authStatus['require_auth']) {
    jsonOut(['success' => false, 'auth_required' => true, 'message' => 'Acceso restringido. Inicia sesion.'], 401);
}

$skipCsrfActions = ['auth_setup', 'auth_login', 'auth_logout', 'auth_request_reset', 'auth_reset_password'];
if ($method === 'POST' && !in_array((string) $action, $skipCsrfActions, true)) {
    ensureCsrfToken();
    if (!csrfValid()) {
        jsonOut(['success' => false, 'message' => 'Token CSRF invalido.'], 403);
    }
}

$cache = readCache();

if ($method === 'GET' && $action === 'init') {
    $force = isset($_GET['force']) && $_GET['force'] === '1';

    $projects = buildProjectPayload(listVisibleProjects($baseDir, $noMostrar), $cache, $force);
    $trash = buildProjectPayload(listTrashProjects($trashPath), $cache, $force);

    usort($projects, fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    usort($trash, fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

    $totalSize = array_sum(array_column($projects, 'size_bytes'));
    $ini = @ini_get_all(null, false) ?: [];
    $extensions = @get_loaded_extensions() ?: [];
    sort($extensions);

    writeCache($cache);

    jsonOut([
        'success' => true,
        'php_version' => PHP_VERSION,
        'ini' => $ini,
        'extensions' => $extensions,
        'total_size_bytes' => $totalSize,
        'total_size_human' => humanFileSize((int) $totalSize),
        'projects' => $projects,
        'trash' => $trash,
        'cache' => [
            'soft_ttl' => CACHE_SOFT_TTL,
            'hard_ttl' => CACHE_HARD_TTL,
            'updated_at' => (int) ($cache['meta']['updated_at'] ?? 0),
        ],
    ]);
}

if ($method === 'GET' && $action === 'get_php_ini') {
    $iniFile = php_ini_loaded_file();
    if ($iniFile && is_readable($iniFile)) {
        jsonOut(['success' => true, 'path' => $iniFile, 'content' => @file_get_contents($iniFile) ?: '']);
    }
    jsonOut(['success' => false, 'message' => 'No se pudo leer php.ini'], 400);
}

if ($method === 'POST' && $action === 'save_php_ini') {
    $content = (string) ($body['content'] ?? '');
    $iniFile = php_ini_loaded_file();

    if ($iniFile && is_writable($iniFile)) {
        $ok = @file_put_contents($iniFile, $content);
        if ($ok !== false) jsonOut(['success' => true]);
        jsonOut(['success' => false, 'message' => 'No se pudo escribir en php.ini'], 500);
    }

    jsonOut(['success' => false, 'message' => 'php.ini no es escribible'], 400);
}

if ($method === 'POST' && $action === 'create_project') {
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

if ($method === 'POST' && $action === 'move') {
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

if ($method === 'POST' && $action === 'bulk_move') {
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

if ($method === 'POST' && $action === 'list_trash') {
    $trash = buildProjectPayload(listTrashProjects($trashPath), $cache, false);
    usort($trash, fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    writeCache($cache);
    jsonOut(['success' => true, 'trash' => $trash]);
}

if ($method === 'POST' && $action === 'restore_project') {
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

if ($method === 'POST' && $action === 'bulk_restore') {
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

if ($method === 'POST' && $action === 'delete_permanently') {
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

if ($method === 'POST' && $action === 'bulk_delete_permanently') {
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

if ($method === 'POST' && $action === 'refresh_metrics') {
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

if ($method === 'POST' && $action === 'list_files') {
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

if ($method === 'POST' && $action === 'list_passwords') {
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

if ($method === 'POST' && $action === 'save_passwords') {
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

if ($method === 'POST' && $action === 'delete_password') {
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

if ($method === 'POST' && $action === 'update_password') {
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

jsonOut(['success' => false, 'message' => 'Accion no soportada'], 404);
