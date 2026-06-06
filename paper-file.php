<?php
require_once __DIR__ . '/includes/bootstrap.php';

try {
    $pdo = db();
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Database unavailable.';
    exit;
}

$idParam = $_GET['id'] ?? null;
$versionIdParam = $_GET['version_id'] ?? null;
$slugParam = $_GET['slug'] ?? null;
$attachmentIdParam = $_GET['attachment_id'] ?? null;

$submission = null;

if (is_string($versionIdParam) && ctype_digit($versionIdParam)) {
    $versionId = (int) $versionIdParam;
    try {
        $stmt = $pdo->prepare('SELECT v.id AS version_id, v.paper_submission_id, v.version_number, v.status, v.manuscript_path, v.manuscript_original_name, v.manuscript_mime, s.id, s.user_id, s.slug, s.title FROM paper_submission_versions v JOIN paper_submissions s ON s.id = v.paper_submission_id WHERE v.id = ? LIMIT 1');
        $stmt->execute([$versionId]);
        $row = $stmt->fetch();
        if (is_array($row)) {
            $submission = [
                'id' => (int) ($row['id'] ?? 0),
                'user_id' => (int) ($row['user_id'] ?? 0),
                'slug' => (string) ($row['slug'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'manuscript_path' => (string) ($row['manuscript_path'] ?? ''),
                'manuscript_original_name' => (string) ($row['manuscript_original_name'] ?? ''),
                'manuscript_mime' => (string) ($row['manuscript_mime'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        $submission = null;
    }
} elseif (is_string($idParam) && ctype_digit($idParam)) {
    $id = (int) $idParam;
    $stmt = $pdo->prepare('SELECT id, user_id, slug, title, status, manuscript_path, manuscript_original_name, manuscript_mime FROM paper_submissions WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (is_array($row)) {
        $submission = $row;
    }
} elseif (is_string($slugParam) && $slugParam !== '') {
    $slug = trim($slugParam);
    $stmt = $pdo->prepare('SELECT id, user_id, slug, title, status, manuscript_path, manuscript_original_name, manuscript_mime FROM paper_submissions WHERE slug = ? LIMIT 1');
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    if (is_array($row)) {
        $submission = $row;
    }
}

if (!$submission) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}

$status = (string) ($submission['status'] ?? '');
$isPublic = ($status === 'accepted');

if (!$isPublic) {
    $user = auth_current_user();
    if (!$user) {
        http_response_code(403);
        echo 'Forbidden.';
        exit;
    }

    $ownerId = (int) ($submission['user_id'] ?? 0);
    $userId = (int) ($user['id'] ?? 0);
    $isAdmin = ((string) ($user['role'] ?? '')) === 'admin';

    if (!$isAdmin && $ownerId !== $userId) {
        http_response_code(403);
        echo 'Forbidden.';
        exit;
    }
}

$relativePath = (string) ($submission['manuscript_path'] ?? '');
$mime = (string) ($submission['manuscript_mime'] ?? 'application/pdf');
$filename = (string) ($submission['manuscript_original_name'] ?? 'manuscript.pdf');

if ($attachmentIdParam !== null && ctype_digit((string)$attachmentIdParam)) {
    $attStmt = $pdo->prepare('SELECT file_path, original_name, mime_type FROM paper_submission_attachments WHERE id = ? AND paper_submission_id = ? LIMIT 1');
    $attStmt->execute([(int)$attachmentIdParam, (int)($submission['id'] ?? 0)]);
    $attRow = $attStmt->fetch();
    if (is_array($attRow)) {
        $relativePath = (string) ($attRow['file_path'] ?? '');
        $mime = (string) ($attRow['mime_type'] ?? 'application/pdf');
        $filename = (string) ($attRow['original_name'] ?? 'attachment.pdf');
    }
}

$relativePath = str_replace('\\', '/', $relativePath);

if (strpos($relativePath, 'uploads/submissions/') !== 0) {
    http_response_code(400);
    echo 'Invalid file path.';
    exit;
}

$baseDir = realpath(__DIR__ . '/uploads/submissions');
$fullPath = realpath(__DIR__ . '/' . $relativePath);

if ($baseDir === false || $fullPath === false || strpos($fullPath, $baseDir) !== 0 || !is_file($fullPath)) {
    http_response_code(404);
    echo 'File not found.';
    exit;
}

if ($filename === '') {
    $filename = 'manuscript.pdf';
}

$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$isPdf = ($mime === 'application/pdf') || ($ext === 'pdf');
$disposition = $isPdf ? 'inline' : 'attachment';

try {
    $upd = $pdo->prepare("UPDATE paper_submissions SET download_count = download_count + 1 WHERE id = ?");
    $upd->execute([(int)($submission['id'] ?? 0)]);
} catch (Throwable $e) {}

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $filename) . '"');
header('Content-Length: ' . (string) filesize($fullPath));

readfile($fullPath);
