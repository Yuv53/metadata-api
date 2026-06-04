<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

// ── PING / HEALTH CHECK (works via GET for browser testing) ──────────────────
if ($action === 'ping') {
    $paths = array(
        'exiftool',
        '/usr/bin/exiftool',
        '/usr/local/bin/exiftool',
        '/opt/bin/exiftool',
        '/usr/local/cpanel/3rdparty/bin/exiftool'
    );

    $found_path    = '';
    $found_version = '';

    foreach ($paths as $p) {
        $out = shell_exec($p . ' -ver 2>/dev/null');
        if ($out !== null && trim($out) !== '') {
            $found_path    = $p;
            $found_version = trim($out);
            break;
        }
    }

    echo json_encode(array(
        'ok'            => true,
        'php'           => PHP_VERSION,
        'exiftool'      => $found_version ? $found_version : 'not found',
        'exiftool_path' => $found_path    ? $found_path    : 'not found',
        'shell_exec'    => function_exists('shell_exec') ? 'enabled' : 'disabled',
        'tmp_dir'       => sys_get_temp_dir(),
        'disabled_fns'  => ini_get('disable_functions')
    ));
    exit;
}

// ── REMOVE METADATA ──────────────────────────────────────────────────────────
if ($action === 'remove' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_FILES['file'])) {
        echo json_encode(array('error' => 'No file uploaded'));
        exit;
    }

    $tmp  = $_FILES['file']['tmp_name'];
    $name = basename($_FILES['file']['name']);
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    // Find exiftool
    $exiftool = '';
    $paths = array('exiftool', '/usr/bin/exiftool', '/usr/local/bin/exiftool', '/opt/bin/exiftool');
    foreach ($paths as $p) {
        $v = shell_exec($p . ' -ver 2>/dev/null');
        if ($v && trim($v) !== '') { $exiftool = $p; break; }
    }

    if (!$exiftool) {
        echo json_encode(array('error' => 'exiftool not available on this server'));
        exit;
    }

    // Copy to temp with correct extension
    $workFile = tempnam(sys_get_temp_dir(), 'sfr_') . '.' . $ext;
    copy($tmp, $workFile);

    // Strip ALL metadata, keep file intact
    shell_exec($exiftool . ' -all= -overwrite_original ' . escapeshellarg($workFile) . ' 2>/dev/null');

    if (!file_exists($workFile) || filesize($workFile) === 0) {
        @unlink($workFile);
        echo json_encode(array('error' => 'Processing failed'));
        exit;
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($workFile));
    header('X-Metadata-Stripped: true');
    readfile($workFile);
    @unlink($workFile);
    exit;
}

// ── WRITE METADATA ───────────────────────────────────────────────────────────
if ($action === 'write' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_FILES['file'])) {
        echo json_encode(array('error' => 'No file uploaded'));
        exit;
    }

    $tmp  = $_FILES['file']['tmp_name'];
    $name = basename($_FILES['file']['name']);
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $meta = json_decode(isset($_POST['meta']) ? $_POST['meta'] : '{}', true);
    if (!is_array($meta)) $meta = array();

    // Find exiftool
    $exiftool = '';
    $paths = array('exiftool', '/usr/bin/exiftool', '/usr/local/bin/exiftool', '/opt/bin/exiftool');
    foreach ($paths as $p) {
        $v = shell_exec($p . ' -ver 2>/dev/null');
        if ($v && trim($v) !== '') { $exiftool = $p; break; }
    }

    if (!$exiftool) {
        echo json_encode(array('error' => 'exiftool not available on this server'));
        exit;
    }

    $workFile = tempnam(sys_get_temp_dir(), 'sfr_') . '.' . $ext;
    copy($tmp, $workFile);

    $args = array();
    if (!empty($meta['title']))     { $args[] = '-Title='           . escapeshellarg($meta['title']);
                                      $args[] = '-XMP:Title='       . escapeshellarg($meta['title']);
                                      $args[] = '-ID3:TIT2='        . escapeshellarg($meta['title']); }
    if (!empty($meta['publisher'])) { $args[] = '-Publisher='       . escapeshellarg($meta['publisher']);
                                      $args[] = '-XMP:Publisher='   . escapeshellarg($meta['publisher']);
                                      $args[] = '-ID3:TPE1='        . escapeshellarg($meta['publisher']); }
    if (!empty($meta['comment']))   { $args[] = '-Comment='         . escapeshellarg($meta['comment']);
                                      $args[] = '-XMP:Description=' . escapeshellarg($meta['comment']);
                                      $args[] = '-ID3:Comment='     . escapeshellarg($meta['comment']); }
    if (!empty($meta['url']))       { $args[] = '-URL='             . escapeshellarg($meta['url']);
                                      $args[] = '-XMP:Source='      . escapeshellarg($meta['url']); }
    if (!empty($meta['version']))   { $args[] = '-ProductVersion='  . escapeshellarg($meta['version']);
                                      $args[] = '-XMP:Label='       . escapeshellarg($meta['version']); }

    if (!empty($args)) {
        $cmd = $exiftool . ' -overwrite_original ' . implode(' ', $args) . ' ' . escapeshellarg($workFile) . ' 2>/dev/null';
        shell_exec($cmd);
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($workFile));
    readfile($workFile);
    @unlink($workFile);
    exit;
}

// ── FALLBACK ─────────────────────────────────────────────────────────────────
echo json_encode(array('error' => 'Unknown action: ' . $action));
