<?php
require_once __DIR__ . '/includes/bootstrap.php';

auth_require_admin();

try {
    $pdo = db();
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Database unavailable.';
    exit;
}

$idParam = $_GET['id'] ?? null;
if (!is_string($idParam) || !ctype_digit($idParam)) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}

$id = (int) $idParam;
if ($id <= 0) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}

$docType = $_GET['doc'] ?? 'cv';
$docIndex = (int) ($_GET['index'] ?? 0);

$stmt = $pdo->prepare('SELECT cv_path, cv_original_name, cv_mime, reviewer_supporting_documents_json FROM admin_applications WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!is_array($row)) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}

$relativePath = '';
$mime = 'application/octet-stream';
$filename = 'document';

if ($docType === 'supporting') {
    $docsJson = $row['reviewer_supporting_documents_json'] ?? '[]';
    $docs = json_decode($docsJson, true);
    if (!is_array($docs) || !isset($docs[$docIndex])) {
        http_response_code(404);
        echo 'Document not found.';
        exit;
    }
    $relativePath = (string) ($docs[$docIndex]['path'] ?? '');
    $mime = (string) ($docs[$docIndex]['mime'] ?? 'application/octet-stream');
    $filename = (string) ($docs[$docIndex]['original_name'] ?? 'supporting_doc');
} else {
    $relativePath = (string) ($row['cv_path'] ?? '');
    $mime = (string) ($row['cv_mime'] ?? 'application/octet-stream');
    $filename = (string) ($row['cv_original_name'] ?? 'cv');
}

$relativePath = str_replace('\\', '/', $relativePath);

if (strpos($relativePath, 'uploads/admin-applications/') !== 0) {
    http_response_code(400);
    echo 'Invalid file path.';
    exit;
}

$baseDir = realpath(__DIR__ . '/uploads/admin-applications');
$fullPath = realpath(__DIR__ . '/' . $relativePath);

if ($baseDir === false || $fullPath === false || strpos($fullPath, $baseDir) !== 0 || !is_file($fullPath)) {
    http_response_code(404);
    echo 'File not found.';
    exit;
}

if ($filename === '') {
    $filename = 'document';
}

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
header('Content-Length: ' . (string) filesize($fullPath));

readfile($fullPath);
