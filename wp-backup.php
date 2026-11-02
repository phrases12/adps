<?php
declare(strict_types=1);

/*
 * Single-file Site Manager
 * Requirements: PHP 7.0+ with standard extensions.
 *
 * Optional root restriction (leave empty for full filesystem access):
 *   $fileManagerRoot = '/var/www/html';
 *
 * Optional environment overrides:
 *   FILE_MANAGER_ROOT=/var/www/html
 *   FILE_MANAGER_PASSWORD_HASH=<password_hash output from PHP password_hash()>
 *
 * The built-in password hash is documented in the deployment notes. Replace it
 * with your own password_hash('your-password', PASSWORD_DEFAULT) output.
 */

$fileManagerRoot = '';

const FM_APP_NAME = 'Single-file Site Manager';
const FM_MAX_EDIT_BYTES = 5 * 1024 * 1024;
const FM_DEFAULT_PASSWORD_HASH = '$2y$10$x/6.qHheudnhHcJ3YQeVte8v3PZexf.p4e3Xjwvx3DhnwAOqLByTm';

if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => fm_session_path(),
        'secure' => fm_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
} else {
    // PHP 7.2 and older only accept the positional form.
    session_set_cookie_params(0, fm_session_path(), '', fm_is_https(), true);
}
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('site_file_manager');
session_start();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

function fm_session_path(): string
{
    $path = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $path = '/' . trim($path, '/');

    return $path === '/' ? '/' : $path . '/';
}

function fm_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function fm_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Polyfills for the PHP 8 string helpers, so the file also runs on PHP 7.0.
 */
function fm_starts_with(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return true;
    }

    return strncmp($haystack, $needle, strlen($needle)) === 0;
}

function fm_ends_with(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return true;
    }

    $length = strlen($needle);
    if (strlen($haystack) < $length) {
        return false;
    }

    return substr($haystack, -$length) === $needle;
}

function fm_contains(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return true;
    }

    return strpos($haystack, $needle) !== false;
}

/**
 * True on Windows. PHP_OS_FAMILY only exists from PHP 7.2 onwards.
 */
function fm_is_windows(): bool
{
    return stripos(PHP_OS, 'WIN') === 0;
}

function fm_url(array $parameters): string
{
    return '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
}

function fm_redirect(array $parameters)
{
    header('Location: ' . fm_url($parameters), true, 303);
    exit;
}

function fm_flash(string $type, string $message)
{
    $_SESSION['fm_flash'][] = ['type' => $type, 'message' => $message];
}

function fm_take_flashes(): array
{
    $flashes = $_SESSION['fm_flash'] ?? [];
    unset($_SESSION['fm_flash']);

    return is_array($flashes) ? $flashes : [];
}

function fm_csrf_token(): string
{
    if (empty($_SESSION['fm_csrf'])) {
        $_SESSION['fm_csrf'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['fm_csrf'];
}

function fm_verify_csrf()
{
    $submitted = (string) ($_POST['csrf'] ?? '');
    if ($submitted === '' || !hash_equals(fm_csrf_token(), $submitted)) {
        http_response_code(419);
        exit('The form expired. Go back, reload the page, and try again.');
    }
}

function fm_is_authenticated(): bool
{
    return ($_SESSION['fm_authenticated'] ?? false) === true;
}

function fm_configured_root()
{
    global $fileManagerRoot;

    $configured = getenv('FILE_MANAGER_ROOT');
    if ($configured !== false && trim($configured) !== '') {
        $root = trim($configured);
    } elseif (is_string($fileManagerRoot) && trim($fileManagerRoot) !== '') {
        $root = trim($fileManagerRoot);
    } else {
        return null;
    }

    if (!fm_is_absolute_path($root)) {
        $root = __DIR__ . DIRECTORY_SEPARATOR . $root;
    }

    $realRoot = realpath($root);
    if ($realRoot === false || !is_dir($realRoot)) {
        throw new RuntimeException('The configured FILE_MANAGER_ROOT does not exist or is not a directory.');
    }

    return fm_normalize_filesystem_path($realRoot);
}

function fm_is_absolute_path(string $path): bool
{
    $path = trim(str_replace('\\', '/', $path));

    return fm_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1;
}

function fm_normalize_filesystem_path(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    if ($path === '/') {
        return '/';
    }
    if (preg_match('/^[A-Za-z]:\/$/', $path) === 1) {
        return $path;
    }

    return rtrim($path, '/');
}

function fm_lexically_normalize_path(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    $prefix = '';

    if (preg_match('/^([A-Za-z]:)\//', $path, $matches) === 1) {
        $prefix = $matches[1] . '/';
        $path = substr($path, strlen($prefix));
    } elseif (fm_starts_with($path, '/')) {
        $prefix = '/';
        $path = ltrim($path, '/');
    }

    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            if ($segments !== [] && end($segments) !== '..') {
                array_pop($segments);
            } elseif ($prefix === '') {
                $segments[] = '..';
            }
            continue;
        }
        $segments[] = $segment;
    }

    $normalized = $prefix . implode('/', $segments);
    if ($normalized === '') {
        return $prefix === '/' ? '/' : '.';
    }

    return fm_normalize_filesystem_path($normalized);
}

function fm_path_is_within_root(string $path, $root): bool
{
    if ($root === null) {
        return true;
    }

    $path = fm_normalize_filesystem_path($path);
    $root = fm_normalize_filesystem_path($root);
    if ($path === '' || $root === '') {
        return false;
    }

    if (fm_is_windows()) {
        $rootPrefix = fm_ends_with($root, '/') ? $root : $root . '/';

        return strcasecmp($path, $root) === 0 || fm_starts_with(strtolower($path), strtolower($rootPrefix));
    }

    if ($root === '/') {
        return fm_starts_with($path, '/');
    }

    return $path === $root || fm_starts_with($path, $root . '/');
}

function fm_resolve_existing(string $input, string $currentDirectory, $root): string
{
    $input = trim($input);
    if ($input === '') {
        $candidate = $currentDirectory;
    } elseif (fm_is_absolute_path($input)) {
        $candidate = str_replace('\\', '/', $input);
    } else {
        $candidate = rtrim($currentDirectory, '/\\') . DIRECTORY_SEPARATOR . $input;
    }

    $candidate = fm_lexically_normalize_path($candidate);
    if ($root !== null && !fm_path_is_within_root($candidate, $root)) {
        throw new RuntimeException('That path is outside the configured manager root.');
    }

    $real = realpath($candidate);
    if ($real === false) {
        throw new RuntimeException('The requested path does not exist or cannot be accessed.');
    }

    $real = fm_normalize_filesystem_path($real);
    if (!fm_path_is_within_root($real, $root)) {
        throw new RuntimeException('That path is outside the configured manager root.');
    }

    if (is_link($candidate)) {
        return $candidate;
    }

    return $real;
}

function fm_current_directory($root): string
{
    if ($root !== null) {
        return $root;
    }

    $cwd = getcwd();
    if ($cwd === false) {
        throw new RuntimeException('The current working directory could not be determined.');
    }

    return fm_normalize_filesystem_path(realpath($cwd) ?: $cwd);
}

function fm_manager_path(): string
{
    $path = realpath(__FILE__);

    return $path === false ? str_replace('\\', '/', __FILE__) : str_replace('\\', '/', $path);
}

function fm_assert_not_manager(string $path)
{
    $real = realpath($path);
    $resolved = $real === false ? fm_normalize_filesystem_path($path) : fm_normalize_filesystem_path($real);
    if (fm_path_is_same($resolved, fm_manager_path())) {
        throw new RuntimeException('The manager file is protected from direct editing, renaming, or deletion.');
    }
}

function fm_assert_manager_not_inside(string $path)
{
    $manager = fm_normalize_filesystem_path(fm_manager_path());
    $candidate = fm_normalize_filesystem_path($path);
    if ($candidate === '' || $candidate === '.' || $candidate === '/') {
        return;
    }

    $prefix = $candidate . '/';
    $containsManager = fm_is_windows()
        ? fm_starts_with(strtolower($manager), strtolower($prefix))
        : fm_starts_with($manager, $prefix);

    if ($containsManager) {
        throw new RuntimeException('The manager cannot be renamed or deleted through one of its parent folders.');
    }
}

function fm_path_is_same(string $first, string $second): bool
{
    $first = fm_normalize_filesystem_path($first);
    $second = fm_normalize_filesystem_path($second);

    if (fm_is_windows()) {
        return strcasecmp($first, $second) === 0;
    }

    return $first === $second;
}

function fm_is_filesystem_root(string $path): bool
{
    $path = fm_normalize_filesystem_path($path);

    return $path === '/' || preg_match('/^[A-Za-z]:\/?$/', $path) === 1;
}

function fm_valid_basename(string $name): bool
{
    if ($name === '' || $name === '.' || $name === '..') {
        return false;
    }

    if (fm_contains($name, '/') || fm_contains($name, '\\') || fm_contains($name, "\0")) {
        return false;
    }

    if (preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
        return false;
    }

    return trim($name) !== '';
}

function fm_unique_upload_path(string $directory, string $name): string
{
    $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name;
    if (!file_exists($path)) {
        return $path;
    }

    $extension = '';
    $base = $name;
    $dot = strrpos($name, '.');
    if ($dot !== false && $dot > 0) {
        $base = substr($name, 0, $dot);
        $extension = substr($name, $dot);
    }

    $counter = 1;
    do {
        $candidate = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $base . ' (' . $counter . ')' . $extension;
        $counter++;
    } while (file_exists($candidate));

    return $candidate;
}

function fm_delete_recursively(string $path)
{
    if (is_link($path) || !is_dir($path)) {
        if (!@unlink($path)) {
            throw new RuntimeException('The item could not be deleted. Check file permissions.');
        }
        return;
    }

    $entries = @scandir($path);
    if ($entries === false) {
        throw new RuntimeException('The directory could not be read while deleting it.');
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        fm_delete_recursively($path . DIRECTORY_SEPARATOR . $entry);
    }

    if (!@rmdir($path)) {
        throw new RuntimeException('The directory could not be deleted. Check file permissions.');
    }
}

function fm_password_hash(): string
{
    $configured = getenv('FILE_MANAGER_PASSWORD_HASH');
    if ($configured !== false && trim($configured) !== '') {
        return trim($configured);
    }

    return FM_DEFAULT_PASSWORD_HASH;
}

function fm_verify_password(string $password): bool
{
    $stored = fm_password_hash();

    if (fm_starts_with($stored, 'pbkdf2-sha256$')) {
        $parts = explode('$', $stored);
        if (count($parts) !== 4) {
            return false;
        }

        $iterations = (int) $parts[1];
        $salt = $parts[2];
        $expected = $parts[3];
        if ($iterations < 100000 || !preg_match('/^[a-f0-9]{128}$/', $expected)) {
            return false;
        }

        $actual = hash_pbkdf2('sha256', $password, hex2bin($salt), $iterations, 64, false);

        return hash_equals($expected, $actual);
    }

    return password_verify($password, $stored);
}

function fm_request_path(string $currentDirectory, $root): string
{
    $requested = (string) ($_GET['path'] ?? $_POST['path'] ?? '');

    return fm_resolve_existing($requested, $currentDirectory, $root);
}

function fm_file_size($size): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $index = 0;
    $value = (float) $size;
    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }

    return number_format($value, $index === 0 ? 0 : 1) . ' ' . $units[$index];
}

function fm_read_text_file(string $path): string
{
    $size = filesize($path);
    if ($size === false || $size > FM_MAX_EDIT_BYTES) {
        throw new RuntimeException('The file is too large to edit. The limit is ' . fm_file_size(FM_MAX_EDIT_BYTES) . '.');
    }

    $contents = @file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('The file could not be read. Check file permissions.');
    }

    if (fm_contains($contents, "\0")) {
        throw new RuntimeException('This appears to be a binary file and cannot be edited as text.');
    }

    return $contents;
}

function fm_render_header(string $title)
{
    $safeTitle = fm_e($title);
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$safeTitle}</title>
    <style>
        :root { color-scheme: light dark; --bg:#0f172a; --panel:#1e293b; --panel2:#172033; --text:#e2e8f0; --muted:#94a3b8; --line:#334155; --accent:#38bdf8; --danger:#f87171; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--bg); color:var(--text); font:15px/1.5 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
        a { color:var(--accent); text-decoration:none; } a:hover { text-decoration:underline; }
        header { display:flex; align-items:center; justify-content:space-between; gap:20px; padding:16px 24px; background:#111c31; border-bottom:1px solid var(--line); }
        h1 { margin:0; font-size:20px; } h2 { margin:0 0 14px; font-size:17px; }
        main { max-width:1400px; margin:auto; padding:24px; }
        .panel { background:var(--panel); border:1px solid var(--line); border-radius:12px; padding:18px; margin-bottom:18px; }
        .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); gap:18px; }
        .muted { color:var(--muted); } .danger { color:var(--danger); }
        .notice { padding:11px 13px; border-radius:8px; margin-bottom:12px; border:1px solid; }
        .notice.error { color:#fecaca; background:#450a0a; border-color:#7f1d1d; }
        .notice.success { color:#bbf7d0; background:#052e16; border-color:#166534; }
        label { display:block; font-weight:600; margin-bottom:6px; }
        input[type=text],input[type=password],input[type=file],textarea { width:100%; border:1px solid var(--line); border-radius:7px; padding:10px; background:var(--panel2); color:var(--text); font:inherit; }
        input[type=file] { padding:8px; }
        textarea { min-height:420px; resize:vertical; font-family:ui-monospace,SFMono-Regular,Consolas,monospace; font-size:13px; tab-size:4; }
        button,.button { display:inline-block; border:0; border-radius:7px; padding:9px 13px; background:var(--accent); color:#082f49; font:700 14px inherit; cursor:pointer; }
        button:hover,.button:hover { filter:brightness(1.08); text-decoration:none; }
        button.danger { background:var(--danger); color:#450a0a; }
        .secondary { background:#475569; color:#f8fafc; }
        .row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
        .actions { display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
        .actions form { margin:0; }
        .inline-form { display:flex; gap:6px; align-items:center; }
        .inline-form input[type=text] { width:150px; padding:6px 8px; }
        .inline-form button { padding:6px 9px; }
        .check { display:flex; align-items:center; gap:6px; color:#fecaca; font-size:12px; font-weight:500; }
        .check input { width:auto; }
        .table-wrap { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; }
        th,td { text-align:left; padding:10px 8px; border-bottom:1px solid var(--line); vertical-align:middle; }
        th { color:var(--muted); font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
        .name { font-weight:650; } .folder { color:#fbbf24; }
        .login-wrap { min-height:100vh; display:grid; place-items:center; padding:20px; }
        .login-card { width:min(420px,100%); background:var(--panel); border:1px solid var(--line); border-radius:14px; padding:28px; box-shadow:0 20px 60px #0008; }
        .login-card p { color:var(--muted); }
        .login-card button { width:100%; margin-top:14px; }
        code { background:#020617; padding:2px 5px; border-radius:4px; }
        @media (max-width:700px) { header { align-items:flex-start; } main { padding:14px; } th:nth-child(2),td:nth-child(2),th:nth-child(3),td:nth-child(3) { display:none; } }
    </style>
</head>
<body>
HTML;
}

function fm_render_footer()
{
    echo '</body></html>';
}

function fm_render_login(string $error = '')
{
    fm_render_header(FM_APP_NAME);
    $errorHtml = $error !== '' ? '<div class="notice error">' . fm_e($error) . '</div>' : '';
    $csrfToken = fm_e(fm_csrf_token());
    echo <<<HTML
<div class="login-wrap">
    <form class="login-card" method="post" action="?action=login">
        <h1>Site Manager</h1>
        <p>Sign in to manage files on this server.</p>
        {$errorHtml}
        <input type="hidden" name="csrf" value="{$csrfToken}">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required autofocus>
        <button type="submit">Sign in</button>
    </form>
</div>
HTML;
    fm_render_footer();
}

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? 'browse');
$isPost = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
$root = null;
$currentDirectory = null;
$rendered = false;

try {
    $root = fm_configured_root();
    $currentDirectory = fm_current_directory($root);

    if ($action === 'login' && $isPost) {
        fm_verify_csrf();
        $lockUntil = (int) ($_SESSION['fm_lock_until'] ?? 0);
        if ($lockUntil > time()) {
            throw new RuntimeException('Too many failed attempts. Try again in ' . ($lockUntil - time()) . ' seconds.');
        }

        $password = (string) ($_POST['password'] ?? '');
        if (!fm_verify_password($password)) {
            $failures = (int) ($_SESSION['fm_login_failures'] ?? 0) + 1;
            $_SESSION['fm_login_failures'] = $failures;
            if ($failures >= 5) {
                $_SESSION['fm_lock_until'] = time() + 30;
                $_SESSION['fm_login_failures'] = 0;
            }
            usleep(400000);
            throw new RuntimeException('The password is incorrect.');
        }

        session_regenerate_id(true);
        $_SESSION['fm_authenticated'] = true;
        unset($_SESSION['fm_login_failures'], $_SESSION['fm_lock_until']);
        fm_redirect(['action' => 'browse', 'path' => $currentDirectory]);
    }

    if ($action === 'logout' && $isPost) {
        fm_verify_csrf();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            if (PHP_VERSION_ID >= 70300) {
                setcookie(session_name(), '', [
                    'expires' => time() - 42000,
                    'path' => $params['path'],
                    'domain' => $params['domain'],
                    'secure' => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => 'Lax',
                ]);
            } else {
                // PHP 7.2 and older only accept the positional form.
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            }
        }
        session_destroy();
        fm_redirect(['action' => 'login']);
    }

    if (!fm_is_authenticated()) {
        fm_render_login();
        exit;
    }

    if ($isPost) {
        fm_verify_csrf();

        if ($action === 'upload') {
            $destination = fm_request_path($currentDirectory, $root);
            if (!is_dir($destination)) {
                throw new RuntimeException('The upload destination is not a directory.');
            }

            $uploads = $_FILES['files'] ?? null;
            if (!is_array($uploads) || !isset($uploads['name']) || !is_array($uploads['name'])) {
                throw new RuntimeException('Choose one or more files to upload.');
            }

            $uploaded = 0;
            $failed = 0;
            $overwrite = isset($_POST['overwrite']);
            foreach (array_keys($uploads['name']) as $index) {
                $name = basename(str_replace('\\', '/', (string) $uploads['name'][$index]));
                $error = (int) ($uploads['error'][$index] ?? UPLOAD_ERR_NO_FILE);
                $temporary = (string) ($uploads['tmp_name'][$index] ?? '');

                if ($error === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($error !== UPLOAD_ERR_OK || !fm_valid_basename($name) || !is_uploaded_file($temporary)) {
                    $failed++;
                    continue;
                }

                $target = $overwrite
                    ? rtrim($destination, '/\\') . DIRECTORY_SEPARATOR . $name
                    : fm_unique_upload_path($destination, $name);

                if (fm_path_is_same($target, fm_manager_path())) {
                    $failed++;
                    continue;
                }

                if (@move_uploaded_file($temporary, $target)) {
                    $uploaded++;
                } else {
                    $failed++;
                }
            }

            if ($uploaded > 0) {
                fm_flash('success', $uploaded . ' file(s) uploaded.');
            }
            if ($failed > 0) {
                fm_flash('error', $failed . ' file(s) could not be uploaded.');
            }
            if ($uploaded === 0 && $failed === 0) {
                throw new RuntimeException('No files were selected.');
            }

            fm_redirect(['action' => 'browse', 'path' => $destination]);
        }

        if ($action === 'mkdir') {
            $destination = fm_request_path($currentDirectory, $root);
            $name = trim((string) ($_POST['name'] ?? ''));
            if (!is_dir($destination)) {
                throw new RuntimeException('The destination is not a directory.');
            }
            if (!fm_valid_basename($name)) {
                throw new RuntimeException('Enter a valid folder name without path separators.');
            }

            $target = rtrim($destination, '/\\') . DIRECTORY_SEPARATOR . $name;
            if (file_exists($target)) {
                throw new RuntimeException('An item with that name already exists.');
            }
            if (!@mkdir($target, 0755)) {
                throw new RuntimeException('The folder could not be created. Check file permissions.');
            }

            fm_flash('success', 'Folder created.');
            fm_redirect(['action' => 'browse', 'path' => $destination]);
        }

        if ($action === 'edit_save') {
            $path = fm_request_path($currentDirectory, $root);
            if (!is_file($path)) {
                throw new RuntimeException('Only existing files can be edited.');
            }
            fm_assert_not_manager($path);

            $originalHash = (string) ($_POST['original_hash'] ?? '');
            $currentHash = hash_file('sha256', $path);
            if ($originalHash === '' || $currentHash === false || !hash_equals($originalHash, $currentHash)) {
                throw new RuntimeException('The file changed after it was opened. Reopen it before saving to avoid overwriting newer content.');
            }

            $contents = (string) ($_POST['contents'] ?? '');
            if (strlen($contents) > FM_MAX_EDIT_BYTES) {
                throw new RuntimeException('The edited content exceeds the ' . fm_file_size(FM_MAX_EDIT_BYTES) . ' limit.');
            }
            if (fm_contains($contents, "\0")) {
                throw new RuntimeException('Binary content cannot be saved through the text editor.');
            }

            if (@file_put_contents($path, $contents, LOCK_EX) === false) {
                throw new RuntimeException('The file could not be saved. Check file permissions.');
            }

            fm_flash('success', 'File saved.');
            fm_redirect(['action' => 'browse', 'path' => dirname($path)]);
        }

        if ($action === 'rename') {
            $path = fm_request_path($currentDirectory, $root);
            $name = trim((string) ($_POST['name'] ?? ''));
            if (!file_exists($path)) {
                throw new RuntimeException('The item to rename does not exist.');
            }
            fm_assert_not_manager($path);
            fm_assert_manager_not_inside($path);
            if (!fm_valid_basename($name)) {
                throw new RuntimeException('Enter a valid name without path separators.');
            }

            $target = dirname($path) . DIRECTORY_SEPARATOR . $name;
            if (file_exists($target)) {
                throw new RuntimeException('An item with that name already exists.');
            }
            if (!@rename($path, $target)) {
                throw new RuntimeException('The item could not be renamed. Check permissions or an invalid filesystem name.');
            }

            fm_flash('success', 'Item renamed.');
            fm_redirect(['action' => 'browse', 'path' => dirname($path)]);
        }

        if ($action === 'delete') {
            $path = fm_request_path($currentDirectory, $root);
            if (!file_exists($path)) {
                throw new RuntimeException('The item to delete does not exist.');
            }
            fm_assert_not_manager($path);
            fm_assert_manager_not_inside($path);
            if (fm_is_filesystem_root($path)) {
                throw new RuntimeException('Deleting a filesystem root is blocked.');
            }
            if ((string) ($_POST['confirm'] ?? '') !== 'DELETE') {
                throw new RuntimeException('Type DELETE in the confirmation box before deleting this item.');
            }

            fm_delete_recursively($path);
            fm_flash('success', 'Item deleted.');
            fm_redirect(['action' => 'browse', 'path' => dirname($path)]);
        }

        throw new RuntimeException('Unknown action.');
    }

    if ($action === 'download') {
        $path = fm_request_path($currentDirectory, $root);
        if (!is_file($path)) {
            throw new RuntimeException('The requested file does not exist.');
        }

        $name = basename($path);
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . (string) filesize($path));
        header("Content-Disposition: attachment; filename=\"" . addcslashes($name, "\\\"\r\n") . "\"; filename*=UTF-8''" . rawurlencode($name));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    if ($action === 'edit') {
        $path = fm_request_path($currentDirectory, $root);
        if (!is_file($path)) {
            throw new RuntimeException('Only existing files can be edited.');
        }
        fm_assert_not_manager($path);
        $contents = fm_read_text_file($path);
        $hash = hash('sha256', $contents);
        $parent = dirname($path);

        fm_render_header('Edit ' . basename($path));
        echo '<main><div class="panel"><h1>Edit ' . fm_e(basename($path)) . '</h1>';
        echo '<p class="muted">' . fm_e($path) . '</p>';
        echo '<form method="post" action="' . fm_e(fm_url(['action' => 'edit_save'])) . '">';
        echo '<input type="hidden" name="csrf" value="' . fm_e(fm_csrf_token()) . '">';
        echo '<input type="hidden" name="path" value="' . fm_e($path) . '">';
        echo '<input type="hidden" name="original_hash" value="' . fm_e($hash) . '">';
        echo '<textarea name="contents" spellcheck="false">' . fm_e($contents) . '</textarea>';
        echo '<div class="row" style="margin-top:14px"><button type="submit">Save file</button><a class="button secondary" href="' . fm_e(fm_url(['action' => 'browse', 'path' => $parent])) . '">Cancel</a></div>';
        echo '</form></div></main>';
        fm_render_footer();
        exit;
    }

    $directory = fm_request_path($currentDirectory, $root);
    if (!is_dir($directory)) {
        throw new RuntimeException('The requested path is not a directory.');
    }

    $entries = @scandir($directory);
    if ($entries === false) {
        throw new RuntimeException('The directory could not be read. Check file permissions.');
    }

    $items = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $fullPath = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $entry;
        $isDirectory = is_dir($fullPath);
        $size = $isDirectory ? 0 : (filesize($fullPath) ?: 0);
        $modified = filemtime($fullPath) ?: 0;
        $items[] = [
            'name' => $entry,
            'path' => $fullPath,
            'directory' => $isDirectory,
            'size' => $size,
            'modified' => $modified,
        ];
    }

    usort($items, static function (array $first, array $second): int {
        if ($first['directory'] !== $second['directory']) {
            return $first['directory'] ? -1 : 1;
        }

        return strnatcasecmp($first['name'], $second['name']);
    });

    fm_render_header(FM_APP_NAME);
    echo '<header><div><h1>' . fm_e(FM_APP_NAME) . '</h1><div class="muted">Authenticated file manager</div></div><form method="post" action="' . fm_e(fm_url(['action' => 'logout'])) . '"><input type="hidden" name="csrf" value="' . fm_e(fm_csrf_token()) . '"><button class="secondary" type="submit">Sign out</button></form></header>';
    echo '<main>';

    foreach (fm_take_flashes() as $flash) {
        $class = $flash['type'] === 'error' ? 'error' : 'success';
        echo '<div class="notice ' . $class . '">' . fm_e($flash['message']) . '</div>';
    }

    if ($root !== null) {
        echo '<div class="notice success">Restricted root: <code>' . fm_e($root) . '</code></div>';
    }

    echo '<div class="panel"><h2>Current path</h2><form method="get" action="">';
    echo '<input type="hidden" name="action" value="browse">';
    echo '<div class="row"><input type="text" name="path" value="' . fm_e($directory) . '" spellcheck="false"><button type="submit">Go</button></div>';
    echo '</form></div>';

    echo '<div class="grid">';
    echo '<section class="panel"><h2>Upload files</h2><form method="post" enctype="multipart/form-data" action="' . fm_e(fm_url(['action' => 'upload'])) . '">';
    echo '<input type="hidden" name="csrf" value="' . fm_e(fm_csrf_token()) . '"><input type="hidden" name="path" value="' . fm_e($directory) . '">';
    echo '<label for="files">Files</label><input id="files" name="files[]" type="file" multiple required>';
    echo '<label style="margin-top:10px"><span class="check"><input type="checkbox" name="overwrite" value="1"> Overwrite files with the same name</span></label>';
    echo '<button type="submit" style="margin-top:12px">Upload</button></form></section>';

    echo '<section class="panel"><h2>Create folder</h2><form method="post" action="' . fm_e(fm_url(['action' => 'mkdir'])) . '">';
    echo '<input type="hidden" name="csrf" value="' . fm_e(fm_csrf_token()) . '"><input type="hidden" name="path" value="' . fm_e($directory) . '">';
    echo '<label for="folder-name">Folder name</label><input id="folder-name" name="name" type="text" placeholder="images" required>';
    echo '<button type="submit" style="margin-top:12px">Create folder</button></form></section>';
    echo '</div>';

    echo '<section class="panel"><h2>Files</h2><div class="table-wrap"><table><thead><tr><th>Name</th><th>Type</th><th>Size</th><th>Modified</th><th>Actions</th></tr></thead><tbody>';

    $parent = dirname($directory);
    if ($parent !== $directory && fm_path_is_within_root($parent, $root)) {
        echo '<tr><td><a href="' . fm_e(fm_url(['action' => 'browse', 'path' => $parent])) . '">.. (parent)</a></td><td>Folder</td><td>—</td><td>—</td><td></td></tr>';
    }

    foreach ($items as $item) {
        $name = $item['name'];
        $path = str_replace('\\', '/', $item['path']);
        $isProtected = fm_path_is_same($path, fm_manager_path());
        $openUrl = fm_url(['action' => 'browse', 'path' => $path]);
        $editUrl = fm_url(['action' => 'edit', 'path' => $path]);
        $downloadUrl = fm_url(['action' => 'download', 'path' => $path]);

        echo '<tr>';
        echo '<td class="name"><a class="' . ($item['directory'] ? 'folder' : '') . '" href="' . fm_e($openUrl) . '">' . fm_e($name) . '</a>' . ($isProtected ? ' <span class="muted">(manager)</span>' : '') . '</td>';
        echo '<td>' . ($item['directory'] ? 'Folder' : 'File') . '</td>';
        echo '<td>' . ($item['directory'] ? '—' : fm_e(fm_file_size($item['size']))) . '</td>';
        echo '<td>' . fm_e($item['modified'] ? date('Y-m-d H:i', $item['modified']) : '—') . '</td>';
        echo '<td><div class="actions">';

        if ($item['directory']) {
            echo '<a href="' . fm_e($openUrl) . '">Open</a>';
        } else {
            echo '<a href="' . fm_e($editUrl) . '">Edit</a>';
            echo '<a href="' . fm_e($downloadUrl) . '">Download</a>';
        }

        if (!$isProtected) {
            echo '<form class="inline-form" method="post" action="' . fm_e(fm_url(['action' => 'rename'])) . '">';
            echo '<input type="hidden" name="csrf" value="' . fm_e(fm_csrf_token()) . '"><input type="hidden" name="path" value="' . fm_e($path) . '">';
            echo '<input type="text" name="name" value="' . fm_e($name) . '" aria-label="New name" required>';
            echo '<button class="secondary" type="submit">Rename</button></form>';

            echo '<form method="post" action="' . fm_e(fm_url(['action' => 'delete'])) . '">';
            echo '<input type="hidden" name="csrf" value="' . fm_e(fm_csrf_token()) . '"><input type="hidden" name="path" value="' . fm_e($path) . '">';
            echo '<input type="text" name="confirm" value="" placeholder="Type DELETE" pattern="DELETE" required aria-label="Type DELETE to confirm">';
            echo '<button class="danger" type="submit" title="Delete this item">Delete</button></form>';
        }

        echo '</div></td></tr>';
    }

    if ($items === []) {
        echo '<tr><td colspan="5" class="muted">This directory is empty.</td></tr>';
    }

    echo '</tbody></table></div></section></main>';
    fm_render_footer();
} catch (Throwable $exception) {
    http_response_code($exception instanceof RuntimeException ? 400 : 500);
    fm_render_header('Site Manager error');
    echo '<main><div class="panel"><h1>Unable to complete the request</h1><div class="notice error">' . fm_e($exception->getMessage()) . '</div>';
    echo '<a class="button secondary" href="' . fm_e(fm_url(['action' => 'browse'])) . '">Return to files</a></div></main>';
    fm_render_footer();
}
