<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * LearnTrack - Certificate Logo Upload Handler
 */
require_once(__DIR__ . '/../../config.php');
require_login();
$ctx = context_system::instance();
require_capability('local/learnpath:manage', $ctx);

// Serve stored logo
if (optional_param('serve', 0, PARAM_INT)) {
    $path = get_config('local_learnpath', 'cert_logo_path');
    if ($path && file_exists($path)) {
        $mime = mime_content_type($path) ?: 'image/png';
        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=86400');
        readfile($path);
        exit;
    }
    http_response_code(404);
    exit;
}

// Remove logo
if (optional_param('remove', 0, PARAM_INT) && confirm_sesskey()) {
    $path = get_config('local_learnpath', 'cert_logo_path');
    if ($path && file_exists($path)) {
        @unlink($path);
    }
    set_config('cert_logo_path', '', 'local_learnpath');
    redirect(new moodle_url('/local/learnpath/branding.php'), 'Logo removed.');
}

// Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    header('Content-Type: application/json');

    if (empty($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'No file or upload error.']);
        exit;
    }
    $file = $_FILES['logo'];

    if ($file['size'] > 2 * 1024 * 1024) {
        echo json_encode(['ok' => false, 'error' => 'File too large (max 2 MB).']);
        exit;
    }

    $allowed  = ['image/png', 'image/jpeg', 'image/gif', 'image/svg+xml', 'image/webp'];
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mime     = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed)) {
        echo json_encode(['ok' => false, 'error' => 'Only PNG, JPG, GIF, SVG or WebP allowed.']);
        exit;
    }

    $ext_map = ['image/png'=>'png','image/jpeg'=>'jpg','image/gif'=>'gif',
                'image/svg+xml'=>'svg','image/webp'=>'webp'];
    $ext = $ext_map[$mime] ?? 'png';

    global $CFG;
    $store_dir = $CFG->dataroot . '/local_learnpath_logos';
    if (!is_dir($store_dir)) {
        make_writable_directory($store_dir);
    }

    $old = get_config('local_learnpath', 'cert_logo_path');
    if ($old && file_exists($old)) { @unlink($old); }

    $dest = $store_dir . '/cert_logo_' . time() . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        echo json_encode(['ok' => false, 'error' => 'Could not save file.']);
        exit;
    }

    set_config('cert_logo_path', $dest, 'local_learnpath');
    $serve_url = (new moodle_url('/local/learnpath/logo_upload.php', ['serve'=>1]))->out(false)
               . '&t=' . time();
    echo json_encode(['ok' => true, 'url' => $serve_url]);
    exit;
}

redirect(new moodle_url('/local/learnpath/branding.php'));
