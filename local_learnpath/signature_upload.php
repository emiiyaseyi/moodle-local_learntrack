<?php
/**
 * LearnTrack — Signature Image Upload
 * Accepts an image upload and returns a serve URL to embed inline in the email signature.
 */
require_once(__DIR__ . '/../../config.php');
require_login();
$ctx = context_system::instance();
require_capability('local/learnpath:manage', $ctx);

global $CFG;

// Serve stored signature image
if (optional_param('serve', 0, PARAM_INT)) {
    $fname = optional_param('f', '', PARAM_FILE);
    if ($fname) {
        $path = $CFG->dataroot . '/local_learnpath_sig/' . $fname;
        if (file_exists($path)) {
            $mime = mime_content_type($path) ?: 'image/png';
            header('Content-Type: ' . $mime);
            header('Cache-Control: public, max-age=2592000');
            readfile($path);
            exit;
        }
    }
    http_response_code(404);
    exit;
}

// Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    header('Content-Type: application/json');

    if (empty($_FILES['sigimage']) || $_FILES['sigimage']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'No file or upload error.']);
        exit;
    }
    $file = $_FILES['sigimage'];
    if ($file['size'] > 2 * 1024 * 1024) {
        echo json_encode(['ok' => false, 'error' => 'File too large (max 2 MB).']);
        exit;
    }
    $allowed = ['image/png', 'image/jpeg', 'image/gif', 'image/svg+xml', 'image/webp'];
    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    $mime    = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed)) {
        echo json_encode(['ok' => false, 'error' => 'Only PNG, JPG, GIF, SVG or WebP allowed.']);
        exit;
    }
    $ext_map = ['image/png'=>'png','image/jpeg'=>'jpg','image/gif'=>'gif','image/svg+xml'=>'svg','image/webp'=>'webp'];
    $ext  = $ext_map[$mime] ?? 'png';
    $dir  = $CFG->dataroot . '/local_learnpath_sig';
    if (!is_dir($dir)) { make_writable_directory($dir); }
    $fname = 'sig_' . time() . '_' . random_int(1000, 9999) . '.' . $ext;
    $dest  = $dir . '/' . $fname;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        echo json_encode(['ok' => false, 'error' => 'Could not save file.']);
        exit;
    }
    $url = (new moodle_url('/local/learnpath/signature_upload.php', ['serve' => 1, 'f' => $fname]))->out(false);
    echo json_encode(['ok' => true, 'url' => $url, 'fname' => $fname]);
    exit;
}

redirect(new moodle_url('/local/learnpath/branding.php'));
