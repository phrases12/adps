<?php
session_start();
error_reporting(0);
ini_set('display_errors', '0');

$PASSWORD = 'changeme';

function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$authed = isset($_SESSION['fm_auth']) && $_SESSION['fm_auth'] === true;

if (!$authed) {
    $loginError = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if (hash_equals($PASSWORD, (string) $_POST['password'])) {
            $_SESSION['fm_auth'] = true;
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }
        $loginError = 'Wrong password';
    }
    ?><!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login</title>
<style>
body{background:#fff;color:#000;font-family:sans-serif;font-size:14px;margin:40px}
input[type=password]{padding:4px}
.err{color:#900}
</style>
</head>
<body>
<h3>File Manager Login</h3>
<?php if ($loginError): ?><p class="err"><?php echo h($loginError); ?></p><?php endif; ?>
<form method="post">
<input type="password" name="password" placeholder="Password" autofocus required>
<input type="submit" value="Login">
</form>
</body>
</html>
<?php
    exit;
}

function human_size($bytes) {
    $units = ['B','KB','MB','GB','TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }
    return round($bytes, 2) . ' ' . $units[$i];
}

function is_within($base, $target) {
    $base = realpath($base);
    $target = realpath($target);
    if ($base === false || $target === false) return false;
    if ($base === DIRECTORY_SEPARATOR) return true;
    return $target === $base || strpos($target, $base . DIRECTORY_SEPARATOR) === 0;
}

function rrmdir($path) {
    if (is_dir($path)) {
        foreach (array_diff(scandir($path), ['.','..']) as $item) {
            rrmdir($path . DIRECTORY_SEPARATOR . $item);
        }
        return rmdir($path);
    }
    return unlink($path);
}

function is_text_file($path) {
    if (!is_file($path)) return false;
    $content = file_get_contents($path, false, null, 0, 4096);
    if ($content === false) return false;
    return strpos($content, "\0") === false;
}

function redirect($dir, $msg = '') {
    $url = '?dir=' . urlencode($dir);
    if ($msg !== '') $url .= '&msg=' . urlencode($msg);
    header('Location: ' . $url);
    exit;
}

$allowFullServer = true;
$root = $allowFullServer ? DIRECTORY_SEPARATOR : realpath('.');
$dir = isset($_REQUEST['dir']) ? $_REQUEST['dir'] : '.';
$dir = realpath($dir);
if ($dir === false || !is_dir($dir) || !is_within($root, $dir)) {
    $dir = $root;
}

if (isset($_GET['download'])) {
    $file = $dir . DIRECTORY_SEPARATOR . basename($_GET['download']);
    if (is_file($file) && is_within($root, $file)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
    http_response_code(404);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'mkdir' && !empty($_POST['name'])) {
        $name = basename($_POST['name']);
        if ($name !== '') { @mkdir($dir . DIRECTORY_SEPARATOR . $name); }
        redirect($dir);
    }

    if ($action === 'upload' && !empty($_FILES['files'])) {
        $files = $_FILES['files'];
        $count = is_array($files['name']) ? count($files['name']) : 0;
        for ($i = 0; $i < $count; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $name = basename($files['name'][$i]);
                if ($name !== '') {
                    move_uploaded_file($files['tmp_name'][$i], $dir . DIRECTORY_SEPARATOR . $name);
                }
            }
        }
        redirect($dir, 'uploaded');
    }

    if ($action === 'rename' && !empty($_POST['old']) && !empty($_POST['new'])) {
        $old = $dir . DIRECTORY_SEPARATOR . basename($_POST['old']);
        $new = $dir . DIRECTORY_SEPARATOR . basename($_POST['new']);
        if (file_exists($old) && is_within($root, $old)) { @rename($old, $new); }
        redirect($dir);
    }

    if ($action === 'delete' && !empty($_POST['name'])) {
        $target = $dir . DIRECTORY_SEPARATOR . basename($_POST['name']);
        if (file_exists($target) && is_within($root, $target)) { rrmdir($target); }
        redirect($dir);
    }

    if ($action === 'save' && isset($_POST['name'])) {
        $target = $dir . DIRECTORY_SEPARATOR . basename($_POST['name']);
        if (is_within($root, $dir)) {
            file_put_contents($target, isset($_POST['content']) ? $_POST['content'] : '');
        }
        redirect($dir);
    }

    if ($action === 'extract' && !empty($_POST['name'])) {
        $target = $dir . DIRECTORY_SEPARATOR . basename($_POST['name']);
        if (is_file($target) && is_within($root, $target) && class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($target) === true) {
                $zip->extractTo($dir);
                $zip->close();
            }
        }
        redirect($dir);
    }

    redirect($dir);
}

$editFile = null;
$editContent = '';
if (isset($_GET['edit'])) {
    $file = $dir . DIRECTORY_SEPARATOR . basename($_GET['edit']);
    if (is_file($file) && is_within($root, $file) && is_text_file($file)) {
        $editFile = basename($file);
        $editContent = file_get_contents($file);
    }
}

$parent = dirname($dir);
$hasParent = is_within($root, $parent) && $parent !== $dir;

$entries = @scandir($dir);
if ($entries === false) { $entries = []; $readError = true; } else { $readError = false; }
$entries = array_diff($entries, ['.','..']);
$dirs = [];
$files = [];
foreach ($entries as $e) {
    $full = $dir . DIRECTORY_SEPARATOR . $e;
    if (is_dir($full)) { $dirs[] = $e; } else { $files[] = $e; }
}
natcasesort($dirs);
natcasesort($files);
?><!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>File Manager</title>
<style>
body{background:#fff;color:#000;font-family:sans-serif;font-size:14px;margin:10px}
table{border-collapse:collapse;width:100%}
th,td{border:1px solid #999;padding:4px 8px;text-align:left}
th{background:#eee}
a{color:#00c;text-decoration:underline}
#crumb a{margin:0 1px}
form.inline{display:inline;margin:0}
button,input[type=submit]{font-size:13px;padding:2px 6px}
input[type=text]{padding:2px 4px}
textarea{width:100%;height:400px;font-family:monospace}
.bar{margin-bottom:10px}
.act form,.act a{margin-right:4px}
.ok{color:#080}
.err{color:#900}
.top{float:right}
</style>
</head>
<body>

<div class="top"><a href="?logout=1">Logout</a></div>

<?php if ($editFile !== null): ?>
<h3>Editing: <?php echo h($editFile); ?></h3>
<form method="post" action="?dir=<?php echo urlencode($dir); ?>">
<input type="hidden" name="action" value="save">
<input type="hidden" name="name" value="<?php echo h($editFile); ?>">
<textarea name="content"><?php echo h($editContent); ?></textarea><br>
<input type="submit" value="Save">
<a href="?dir=<?php echo urlencode($dir); ?>">Cancel</a>
</form>

<?php else: ?>
<h3>Directory: <span id="crumb"><?php
    echo '<a href="?dir=' . urlencode('/') . '">/</a>';
    $parts = explode('/', $dir);
    $cum = '';
    $firstPart = true;
    foreach ($parts as $p) {
        if ($p === '') continue;
        $cum .= '/' . $p;
        if (!$firstPart) echo '/';
        echo '<a href="?dir=' . urlencode($cum) . '">' . h($p) . '</a>';
        $firstPart = false;
    }
?></span></h3>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'uploaded'): ?>
<p class="ok">Uploaded successfully</p>
<?php endif; ?>
<?php if ($readError): ?>
<p class="err">Cannot read directory (permission denied or open_basedir)</p>
<?php endif; ?>

<div class="bar">
<form class="inline" method="post" action="?dir=<?php echo urlencode($dir); ?>">
<input type="hidden" name="action" value="mkdir">
<input type="text" name="name" placeholder="New folder" required>
<input type="submit" value="Create Folder">
</form>
</div>

<div class="bar">
<form class="inline" method="post" enctype="multipart/form-data" action="?dir=<?php echo urlencode($dir); ?>">
<input type="hidden" name="action" value="upload">
<input type="file" name="files[]" multiple required>
<input type="submit" value="Upload">
</form>
</div>

<table>
<tr><th>Name</th><th>Size</th><th>Modified</th><th>Type</th><th>Actions</th></tr>

<?php if ($hasParent): ?>
<tr>
<td><a href="?dir=<?php echo urlencode($parent); ?>">.. Parent Directory</a></td>
<td>-</td><td>-</td><td>DIR</td><td>-</td>
</tr>
<?php endif; ?>

<?php foreach ($dirs as $d):
    $full = $dir . DIRECTORY_SEPARATOR . $d; ?>
<tr>
<td>&#128193; <a href="?dir=<?php echo urlencode($full); ?>"><?php echo h($d); ?></a></td>
<td>-</td>
<td><?php echo date('Y-m-d H:i', filemtime($full)); ?></td>
<td>DIR</td>
<td class="act">
<a href="#" onclick="renameItem('<?php echo h($d); ?>');return false">Rename</a>
<form class="inline" method="post" action="?dir=<?php echo urlencode($dir); ?>" onsubmit="return confirm('Delete <?php echo h($d); ?>?')">
<input type="hidden" name="action" value="delete">
<input type="hidden" name="name" value="<?php echo h($d); ?>">
<input type="submit" value="Delete">
</form>
</td>
</tr>
<?php endforeach; ?>

<?php foreach ($files as $f):
    $full = $dir . DIRECTORY_SEPARATOR . $f;
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION)); ?>
<tr>
<td>&#128196; <?php echo h($f); ?></td>
<td><?php echo human_size(filesize($full)); ?></td>
<td><?php echo date('Y-m-d H:i', filemtime($full)); ?></td>
<td><?php echo h($ext !== '' ? $ext : 'file'); ?></td>
<td class="act">
<a href="?dir=<?php echo urlencode($dir); ?>&download=<?php echo urlencode($f); ?>">Download</a>
<?php if (is_text_file($full)): ?>
<a href="?dir=<?php echo urlencode($dir); ?>&edit=<?php echo urlencode($f); ?>">Edit</a>
<?php endif; ?>
<?php if ($ext === 'zip'): ?>
<form class="inline" method="post" action="?dir=<?php echo urlencode($dir); ?>">
<input type="hidden" name="action" value="extract">
<input type="hidden" name="name" value="<?php echo h($f); ?>">
<input type="submit" value="Extract">
</form>
<?php endif; ?>
<a href="#" onclick="renameItem('<?php echo h($f); ?>');return false">Rename</a>
<form class="inline" method="post" action="?dir=<?php echo urlencode($dir); ?>" onsubmit="return confirm('Delete <?php echo h($f); ?>?')">
<input type="hidden" name="action" value="delete">
<input type="hidden" name="name" value="<?php echo h($f); ?>">
<input type="submit" value="Delete">
</form>
</td>
</tr>
<?php endforeach; ?>

</table>

<form class="inline" method="post" id="renameForm" action="?dir=<?php echo urlencode($dir); ?>" style="display:none">
<input type="hidden" name="action" value="rename">
<input type="hidden" name="old" id="renameOld">
<input type="hidden" name="new" id="renameNew">
</form>

<script>
function renameItem(name){
    var nn = prompt('Rename to:', name);
    if(nn && nn !== name){
        document.getElementById('renameOld').value = name;
        document.getElementById('renameNew').value = nn;
        document.getElementById('renameForm').submit();
    }
}
</script>

<?php endif; ?>
</body>
</html>
