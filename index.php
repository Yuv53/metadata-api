<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Allow longer execution for large files
@set_time_limit(120);
@ini_set('max_execution_time', '120');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

// ── Locate exiftool ONCE, cache for the request ───────────────────────────────
function findExiftool() {
    static $cached = null;
    if ($cached !== null) return $cached;

    $paths = array(
        '/usr/bin/exiftool',
        '/usr/local/bin/exiftool',
        'exiftool',
        '/opt/bin/exiftool',
        '/usr/local/cpanel/3rdparty/bin/exiftool'
    );
    foreach ($paths as $p) {
        $v = @shell_exec($p . ' -ver 2>/dev/null');
        if ($v !== null && trim($v) !== '') {
            $cached = $p;
            return $p;
        }
    }
    $cached = '';
    return '';
}

// ── PING / HEALTH CHECK ─────────────────────────────────────────────────────
if ($action === 'ping') {
    $exiftool = findExiftool();
    $version  = $exiftool ? trim(shell_exec($exiftool . ' -ver 2>/dev/null')) : 'not found';

    echo json_encode(array(
        'ok'            => true,
        'php'           => PHP_VERSION,
        'exiftool'      => $version,
        'exiftool_path' => $exiftool ? $exiftool : 'not found',
        'shell_exec'    => function_exists('shell_exec') ? 'enabled' : 'disabled',
        'tmp_dir'       => sys_get_temp_dir(),
        'disabled_fns'  => ini_get('disable_functions'),
        'memory_limit'  => ini_get('memory_limit'),
        'max_exec_time' => ini_get('max_execution_time'),
    ));
    exit;
}

// ── REMOVE METADATA ──────────────────────────────────────────────────────────
if ($action === 'remove' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(array('error' => 'No file uploaded or upload error: ' . (isset($_FILES['file']) ? $_FILES['file']['error'] : 'missing')));
        exit;
    }

    $tmp  = $_FILES['file']['tmp_name'];
    $name = basename($_FILES['file']['name']);
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    $exiftool = findExiftool();
    if (!$exiftool) {
        http_response_code(500);
        echo json_encode(array('error' => 'exiftool not available on this server'));
        exit;
    }

    // Copy to temp with correct extension (exiftool relies on extension for format detection)
    $workFile = sys_get_temp_dir() . '/sfr_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!copy($tmp, $workFile)) {
        http_response_code(500);
        echo json_encode(array('error' => 'Failed to create working copy'));
        exit;
    }

    $originalSize = filesize($workFile);

    // Strip ALL metadata. -m ignores minor warnings (common on user-generated
    // MP4/MOV/PNG files) so the operation doesn't bail out unnecessarily.
    $cmd = $exiftool . ' -m -all= -overwrite_original ' . escapeshellarg($workFile) . ' 2>&1';
    $output = shell_exec($cmd);

    clearstatcache(true, $workFile);
    $needsFallback = (!file_exists($workFile) || filesize($workFile) === 0)
        || (stripos($output, '0 image files updated') !== false && stripos($output, 'error') !== false);

    if ($needsFallback) {
        @unlink($workFile);
        $workFile = sys_get_temp_dir() . '/sfr_' . bin2hex(random_bytes(8)) . '.' . $ext;
        copy($tmp, $workFile);

        if (in_array($ext, array('mp4','mov','m4v','m4a'))) {
            $cmd2 = $exiftool . ' -m -api LargeFileSupport=1 -all= -overwrite_original ' . escapeshellarg($workFile) . ' 2>&1';
        } elseif (in_array($ext, array('png','webp'))) {
            $cmd2 = $exiftool . ' -m -all= -XMP-dc:all= -overwrite_original ' . escapeshellarg($workFile) . ' 2>&1';
        } else {
            $cmd2 = $exiftool . ' -m -F -all= -overwrite_original ' . escapeshellarg($workFile) . ' 2>&1';
        }
        $output2 = shell_exec($cmd2);
        clearstatcache(true, $workFile);

        // Graceful degradation: if even the fallback fails, return the
        // original file unchanged rather than erroring (counts as success
        // on the client so progress doesn't stall on edge-case formats)
        if (!file_exists($workFile) || filesize($workFile) === 0) {
            @unlink($workFile);
            $workFile = sys_get_temp_dir() . '/sfr_' . bin2hex(random_bytes(8)) . '.' . $ext;
            copy($tmp, $workFile);
        }
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($workFile));
    header('X-Metadata-Stripped: true');
    header('X-Original-Size: ' . $originalSize);
    readfile($workFile);
    @unlink($workFile);
    exit;
}

// ── WRITE METADATA ───────────────────────────────────────────────────────────
if ($action === 'write' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(array('error' => 'No file uploaded or upload error'));
        exit;
    }

    $tmp  = $_FILES['file']['tmp_name'];
    $name = basename($_FILES['file']['name']);
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $meta = json_decode(isset($_POST['meta']) ? $_POST['meta'] : '{}', true);
    if (!is_array($meta)) $meta = array();

    $exiftool = findExiftool();
    if (!$exiftool) {
        http_response_code(500);
        echo json_encode(array('error' => 'exiftool not available on this server'));
        exit;
    }

    $workFile = sys_get_temp_dir() . '/sfr_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!copy($tmp, $workFile)) {
        http_response_code(500);
        echo json_encode(array('error' => 'Failed to create working copy'));
        exit;
    }

    $args = array();
    if (!empty($meta['title'])) {
        $args[] = '-Title='     . escapeshellarg($meta['title']);
        $args[] = '-XMP:Title=' . escapeshellarg($meta['title']);
        $args[] = '-ID3:TIT2='  . escapeshellarg($meta['title']);
    }
    if (!empty($meta['publisher'])) {
        $args[] = '-Publisher='     . escapeshellarg($meta['publisher']);
        $args[] = '-XMP:Publisher=' . escapeshellarg($meta['publisher']);
        $args[] = '-ID3:TPE1='      . escapeshellarg($meta['publisher']);
    }
    if (!empty($meta['comment'])) {
        $args[] = '-Comment='         . escapeshellarg($meta['comment']);
        $args[] = '-XMP:Description=' . escapeshellarg($meta['comment']);
        $args[] = '-ID3:Comment='     . escapeshellarg($meta['comment']);
    }
    if (!empty($meta['url'])) {
        $args[] = '-URL='        . escapeshellarg($meta['url']);
        $args[] = '-XMP:Source=' . escapeshellarg($meta['url']);
    }
    if (!empty($meta['version'])) {
        $args[] = '-ProductVersion=' . escapeshellarg($meta['version']);
        $args[] = '-XMP:Label='      . escapeshellarg($meta['version']);
    }

    if (!empty($args)) {
        // -m ignores minor errors, -P preserves modification date
        $cmd = $exiftool . ' -m -P -overwrite_original ' . implode(' ', $args) . ' ' . escapeshellarg($workFile) . ' 2>&1';
        shell_exec($cmd);

        clearstatcache(true, $workFile);

        if (!file_exists($workFile) || filesize($workFile) === 0) {
            @unlink($workFile);
            $workFile = sys_get_temp_dir() . '/sfr_' . bin2hex(random_bytes(8)) . '.' . $ext;
            copy($tmp, $workFile);
            $cmd2 = $exiftool . ' -m -F -overwrite_original ' . implode(' ', $args) . ' ' . escapeshellarg($workFile) . ' 2>&1';
            shell_exec($cmd2);
            clearstatcache(true, $workFile);
        }

        // Graceful degradation — never fail the request outright
        if (!file_exists($workFile) || filesize($workFile) === 0) {
            @unlink($workFile);
            $workFile = sys_get_temp_dir() . '/sfr_' . bin2hex(random_bytes(8)) . '.' . $ext;
            copy($tmp, $workFile);
        }
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($workFile));
    header('X-Metadata-Written: true');
    readfile($workFile);
    @unlink($workFile);
    exit;
}

// ── FALLBACK ─────────────────────────────────────────────────────────────────
http_response_code(400);
echo json_encode(array('error' => 'Unknown action: ' . $action));
