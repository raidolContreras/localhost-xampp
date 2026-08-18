<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=UTF-8');

define('APP_ROOT', __DIR__);

require_once APP_ROOT . '/inc/common.php';
require_once APP_ROOT . '/inc/cache.php';
require_once APP_ROOT . '/inc/smtp.php';
require_once APP_ROOT . '/inc/auth.php';
require_once APP_ROOT . '/inc/passwords.php';
require_once APP_ROOT . '/inc/projects.php';

$baseDir = APP_ROOT;
$trashDirName = '_PAPELERIA';
$trashPath = $baseDir . DIRECTORY_SEPARATOR . $trashDirName;
$noMostrar = ['.', '..', 'dashboard', 'xampp', 'webalizer', 'img', '_PAPELERIA', 'pass', 'inc'];

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
    handle_auth_status($authStatus);
}

if ($method === 'POST' && $action === 'auth_setup') {
    handle_auth_setup($body, $authStatus);
}

if ($method === 'POST' && $action === 'auth_login') {
    handle_auth_login($body, $auth, $authStatus);
}

if ($method === 'POST' && $action === 'auth_logout') {
    handle_auth_logout();
}

if ($method === 'GET' && $action === 'auth_get_security') {
    handle_auth_get_security($auth, $authStatus);
}

if ($method === 'POST' && $action === 'auth_update_smtp') {
    handle_auth_update_smtp($body, $auth, $authStatus);
}

if ($method === 'POST' && $action === 'auth_request_reset') {
    handle_auth_request_reset($body, $auth);
}

if ($method === 'POST' && $action === 'auth_reset_password') {
    handle_auth_reset_password($body, $auth);
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
    handle_init($baseDir, $trashPath, $noMostrar, $cache, $force);
}

if ($method === 'GET' && $action === 'get_php_config') {
    handle_get_php_config();
}

if ($method === 'GET' && $action === 'get_php_ini') {
    handle_get_php_ini();
}

if ($method === 'POST' && $action === 'save_php_ini') {
    handle_save_php_ini($body);
}

if ($method === 'POST' && $action === 'create_project') {
    handle_create_project($body, $baseDir, $cache);
}

if ($method === 'POST' && $action === 'move') {
    handle_move($body, $baseDir, $trashPath, $cache);
}

if ($method === 'POST' && $action === 'bulk_move') {
    handle_bulk_move($body, $baseDir, $trashPath, $cache);
}

if ($method === 'POST' && $action === 'list_trash') {
    handle_list_trash($trashPath, $cache);
}

if ($method === 'POST' && $action === 'restore_project') {
    handle_restore_project($body, $baseDir, $trashPath, $cache);
}

if ($method === 'POST' && $action === 'bulk_restore') {
    handle_bulk_restore($body, $baseDir, $trashPath, $cache);
}

if ($method === 'POST' && $action === 'delete_permanently') {
    handle_delete_permanently($body, $trashPath, $cache);
}

if ($method === 'POST' && $action === 'bulk_delete_permanently') {
    handle_bulk_delete_permanently($body, $trashPath, $cache);
}

if ($method === 'POST' && $action === 'refresh_metrics') {
    handle_refresh_metrics($baseDir, $trashPath, $noMostrar, $cache);
}

if ($method === 'POST' && $action === 'list_files') {
    handle_list_files($body, $baseDir, $trashPath);
}

if ($method === 'POST' && $action === 'list_passwords') {
    handle_list_passwords($body, $baseDir);
}

if ($method === 'POST' && $action === 'save_passwords') {
    handle_save_passwords($body, $baseDir);
}

if ($method === 'POST' && $action === 'delete_password') {
    handle_delete_password($body, $baseDir);
}

if ($method === 'POST' && $action === 'update_password') {
    handle_update_password($body, $baseDir);
}

jsonOut(['success' => false, 'message' => 'Accion no soportada'], 404);
