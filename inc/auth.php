<?php

declare(strict_types=1);

const AUTH_FILE = APP_ROOT . '/.dashboard_auth.json';
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCK_SECONDS = 900;

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

function handle_auth_status(array $authStatus): never {
    jsonOut($authStatus);
}

function handle_auth_setup(array $body, array $authStatus): never {
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

function handle_auth_login(array $body, array $auth, array $authStatus): never {
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

function handle_auth_logout(): never {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    jsonOut(['success' => true]);
}

function handle_auth_get_security(array $auth, array $authStatus): never {
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

function handle_auth_update_smtp(array $body, array $auth, array $authStatus): never {
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

function handle_auth_request_reset(array $body, array $auth): never {
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

function handle_auth_reset_password(array $body, array $auth): never {
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
