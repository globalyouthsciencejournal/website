<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

auth_require_admin();
$admin = auth_current_user() ?: ['id' => 0, 'name' => '', 'email' => '', 'role' => 'admin'];
$gysj_current_admin_id = (int) ($admin['id'] ?? 0);
$GLOBALS['gysj_current_admin_id'] = $gysj_current_admin_id;

$success = '';
$error = '';
$flash = null;

if (isset($_SESSION['admin_dashboard_redesign_flash']) && is_array($_SESSION['admin_dashboard_redesign_flash'])) {
  $flash = $_SESSION['admin_dashboard_redesign_flash'];
  unset($_SESSION['admin_dashboard_redesign_flash']);
}

try {
  $pdo = db();
  gysj_redesign_ensure_assignment_column($pdo);
  gysj_redesign_ensure_reviewer_columns($pdo);
  try {
    $pdo->exec("ALTER TABLE paper_submissions MODIFY COLUMN status ENUM('submitted','needs_edits','accepted','rejected','escalated') NOT NULL DEFAULT 'submitted'");
    $pdo->exec("ALTER TABLE paper_submission_versions MODIFY COLUMN status ENUM('submitted','needs_edits','accepted','rejected','escalated') NOT NULL DEFAULT 'submitted'");
  } catch (Throwable $e) {}
  $pdo->exec("CREATE TABLE IF NOT EXISTS submission_messages (
      id INT AUTO_INCREMENT PRIMARY KEY,
      submission_id INT NOT NULL,
      sender_type VARCHAR(50) NOT NULL,
      sender_name VARCHAR(100) NOT NULL,
      message TEXT NOT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
  )");
  try {
      $pdo->exec("ALTER TABLE submission_messages CHANGE paper_submission_id submission_id INT NOT NULL");
  } catch (Throwable $e) {}
} catch (Throwable $e) {
  $pdo = null;
  $error = 'Database unavailable. Please try again later.';
}

function gysj_redesign_status_label(string $status): string
{
  switch ($status) {
    case 'escalated':
      return 'Requesting Other Editors for Review';
    case 'needs_edits':
      return 'Needs Edits';
    case 'accepted':
      return 'Accepted';
    case 'rejected':
      return 'Rejected';
    case 'submitted':
    default:
      return 'Submitted';
  }
}

function gysj_redesign_status_class(string $status): string
{
  switch ($status) {
    case 'escalated':
      return 'status-escalated';
    case 'accepted':
      return 'status-accepted';
    case 'rejected':
      return 'status-rejected';
    case 'needs_edits':
      return 'status-needs_edits';
    case 'submitted':
    default:
      return 'status-submitted';
  }
}

function gysj_redesign_format_datetime(string $value): string
{
  $value = trim($value);
  if ($value === '') {
    return 'Unknown';
  }

  $timestamp = strtotime($value);
  if ($timestamp === false) {
    return $value;
  }

  return date('Y-m-d H:i', $timestamp);
}

function gysj_redesign_format_card_datetime(string $value): string
{
  $value = trim($value);
  if ($value === '') {
    return 'Unknown';
  }

  $timestamp = strtotime($value);
  if ($timestamp === false) {
    return $value;
  }

  return date('d M Y \\· H:i', $timestamp);
}

function gysj_redesign_first_non_empty(array $row, array $keys): string
{
  foreach ($keys as $key) {
    if (!array_key_exists($key, $row)) {
      continue;
    }

    $value = trim((string) $row[$key]);
    if ($value !== '') {
      return $value;
    }
  }

  return '';
}

function gysj_redesign_display_value($value, string $fallback = '—'): string
{
  if (is_bool($value)) {
    return $value ? 'Yes' : 'No';
  }

  if (is_array($value)) {
    $value = implode(', ', array_map(static function($item) { return trim((string) $item); }, $value));
  }

  $value = trim((string) $value);
  return $value !== '' ? $value : $fallback;
}

function gysj_redesign_parse_details(string $details): array
{
  $details = trim($details);
  if ($details === '') {
    return [];
  }

  if ($details[0] === '{' || $details[0] === '[') {
    // Repair a common malformed payload pattern: "Key" "Value" -> "Key":"Value".
    $jsonSource = preg_replace('/"([^"\\]+)"\s*"([^"\\]*)"\s*(,|\})/u', '"$1":"$2"$3', $details);
    if (!is_string($jsonSource) || trim($jsonSource) === '') {
      $jsonSource = $details;
    }

    $decoded = json_decode($jsonSource, true);
    if (is_array($decoded)) {
      $pairs = [];
      foreach ($decoded as $label => $value) {
        $label = trim((string) $label);
        if ($label === '') {
          continue;
        }

        if (is_bool($value)) {
          $value = $value ? 'Yes' : 'No';
        } elseif (is_array($value)) {
          // If it's a simple list of strings, we could implode it. 
          // But if it's an array of objects (like Authors JSON), keep it as is.
          $isSimpleList = true;
          foreach ($value as $v) { if (is_array($v) || is_object($v)) { $isSimpleList = false; break; } }
          if ($isSimpleList) {
            $value = implode(', ', array_map(static function($item) { return trim((string) $item); }, $value));
          }
        }

        if (is_scalar($value)) {
          $value = trim((string) $value);
        }
        if ($value !== '') {
          $pairs[$label] = $value;
        }
      }

      if (!empty($pairs)) {
        return $pairs;
      }
    }
  }

  $pairs = [];
  foreach (preg_split('/\s*,\s*/', $details) ?: [] as $chunk) {
    $chunk = trim($chunk);
    if ($chunk === '') {
      continue;
    }

    $parts = explode(':', $chunk, 2);
    if (count($parts) !== 2) {
      continue;
    }

    $label = strtolower(trim($parts[0]));
    $value = trim($parts[1]);
    if ($label !== '' && $value !== '') {
      $pairs[$label] = $value;
    }
  }

  return $pairs;
}

function gysj_redesign_extract_journal(array $submission): string
{
  $submissionDetailsData = (string) ($submission['submission_details'] ?? '');
  if ($submissionDetailsData === '' || $submissionDetailsData === '{}' || $submissionDetailsData === '[]') { $submissionDetailsData = (string) ($submission['metadata'] ?? ''); }
  $details = gysj_redesign_parse_details($submissionDetailsData);
  $lowerDetails = [];
  foreach ($details as $k => $v) {
    $lowerDetails[strtolower($k)] = $v;
  }

  foreach (['journal', 'journal name', 'publication', 'venue'] as $key) {
    if (isset($lowerDetails[$key]) && trim((string) $lowerDetails[$key]) !== '') {
      return trim((string) $lowerDetails[$key]);
    }
  }

  $category = trim((string) ($submission['category'] ?? ''));
  if ($category !== '') {
    $parts = preg_split('/\s*\|\s*/', $category, 2);
    if (is_array($parts) && count($parts) === 2) {
      return trim((string) $parts[1]);
    }
    if (is_array($parts) && count($parts) === 1) {
      return trim((string) $parts[0]);
    }
  }

  return '';
}

function gysj_redesign_extract_type(array $submission): string
{
  $submissionDetailsData = (string) ($submission['submission_details'] ?? '');
  if ($submissionDetailsData === '' || $submissionDetailsData === '{}' || $submissionDetailsData === '[]') { $submissionDetailsData = (string) ($submission['metadata'] ?? ''); }
  $details = gysj_redesign_parse_details($submissionDetailsData);
  $lowerDetails = [];
  foreach ($details as $k => $v) {
    $lowerDetails[strtolower($k)] = $v;
  }

  if (isset($lowerDetails['type']) && trim((string) $lowerDetails['type']) !== '') {
    return trim((string) $lowerDetails['type']);
  }
  if (isset($lowerDetails['paper type']) && trim((string) $lowerDetails['paper type']) !== '') {
    return trim((string) $lowerDetails['paper type']);
  }

  $category = trim((string) ($submission['category'] ?? ''));
  if ($category !== '') {
    $parts = preg_split('/\s*\|\s*/', $category, 2);
    if (is_array($parts) && count($parts) >= 2) {
      return trim((string) $parts[0]);
    }
  }

  return '';
}

function gysj_redesign_review_note(string $feedback, string $internalNote): string
{
  $feedback = trim($feedback);
  $internalNote = trim($internalNote);
  $parts = [];

  if ($feedback !== '') {
    $parts[] = $feedback;
  }

  if ($internalNote !== '') {
    $parts[] = '[Internal note] ' . $internalNote;
  }

  return implode("\n\n", $parts);
}

function gysj_redesign_join_list($value, string $fallback = 'Not set'): string
{
  if (is_string($value)) {
    $value = trim($value);
    if ($value === '') {
      return $fallback;
    }

    $decoded = json_decode($value, true);
    if (is_array($decoded)) {
      $value = $decoded;
    } else {
      $value = preg_split('/\s*,\s*/', $value) ?: [];
    }
  }

  if (!is_array($value)) {
    return $fallback;
  }

  $items = [];
  foreach ($value as $item) {
    if (is_array($item)) {
      $label = trim((string) ($item['original'] ?? $item['name'] ?? $item['path'] ?? ''));
      if ($label === '' && isset($item['path'])) {
        $label = basename(str_replace('\\', '/', (string) $item['path']));
      }
    } else {
      $label = trim((string) $item);
    }

    if ($label !== '') {
      $items[] = $label;
    }
  }

  return !empty($items) ? implode(', ', array_values(array_unique($items))) : $fallback;
}

function gysj_redesign_file_label(string $path, string $original = ''): string
{
  $original = trim($original);
  if ($original !== '') {
    return $original;
  }

  $path = trim($path);
  if ($path === '') {
    return 'Not uploaded';
  }

  $name = basename($path);
  return $name !== '' ? $name : 'Not uploaded';
}

function gysj_redesign_decode_admin_ids($value): array
{
  if (is_string($value)) {
    $value = trim($value);
    if ($value === '') {
      return [];
    }

    $decoded = json_decode($value, true);
    if (is_array($decoded)) {
      $value = $decoded;
    } else {
      $value = preg_split('/\s*,\s*/', $value) ?: [];
    }
  }

  if (!is_array($value)) {
    return [];
  }

  $ids = [];
  foreach ($value as $item) {
    $id = (int) $item;
    if ($id > 0 && !in_array($id, $ids, true)) {
      $ids[] = $id;
    }
  }

  return $ids;
}

function gysj_redesign_encode_admin_ids(array $ids): ?string
{
  $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function($id) { return $id > 0; })));
  if (empty($ids)) {
    return null;
  }

  $json = json_encode($ids, JSON_UNESCAPED_UNICODE);
  return is_string($json) ? $json : null;
}

function gysj_redesign_admin_matches_journal(array $admin, string $journal): bool
{
  $journal = trim($journal);
  if ($journal === '') {
    return false;
  }

  $assigned = gysj_normalize_journal_selection($admin['assigned_journals_json'] ?? null);
  return empty($assigned) || in_array($journal, $assigned, true);
}

function gysj_redesign_admin_display_name(array $admin): string
{
  $name = trim((string) ($admin['name'] ?? ''));
  if ($name === '') {
    $name = 'Admin #' . (int) ($admin['id'] ?? 0);
  }

  $email = trim((string) ($admin['email'] ?? ''));
  return $email !== '' ? ($name . ' <' . $email . '>') : $name;
}

function gysj_redesign_assignment_summary(array $paper, array $adminDirectory): array
{
  $assignedIds = gysj_redesign_decode_admin_ids($paper['assigned_admin_ids_json'] ?? null);
  if (empty($assignedIds) && !empty($paper['reviewed_by'])) {
    $assignedIds = [(int) $paper['reviewed_by']];
  }

  $assignedAdmins = [];
  foreach ($assignedIds as $adminId) {
    if (isset($adminDirectory[$adminId])) {
      $assignedAdmins[] = $adminDirectory[$adminId];
    }
  }

  $names = [];
  foreach ($assignedAdmins as $admin) {
    $names[] = trim((string) ($admin['name'] ?? 'Admin'));
  }

  $currentAdminId = (int) ($GLOBALS['gysj_current_admin_id'] ?? 0);
  $isMine = $currentAdminId > 0 && in_array($currentAdminId, $assignedIds, true);

  if (empty($assignedAdmins)) {
    return [
      'label' => 'Unclaimed',
      'subcopy' => 'Click to claim or assign',
      'class' => 'assignment-unclaimed',
      'assigned_ids' => $assignedIds,
      'assigned_admins' => [],
      'is_mine' => false,
    ];
  }

  if ($isMine && count($assignedAdmins) === 1) {
    return [
      'label' => 'Claimed by you',
      'subcopy' => 'Assigned to you',
      'class' => 'assignment-owned',
      'assigned_ids' => $assignedIds,
      'assigned_admins' => $assignedAdmins,
      'is_mine' => true,
    ];
  }

  $label = implode(', ', array_slice($names, 0, 2));
  if (count($names) > 2) {
    $label .= ' +' . (count($names) - 2);
  }

  return [
    'label' => $label,
    'subcopy' => $isMine ? 'Assigned to you' : 'Assigned to admins',
    'class' => $isMine ? 'assignment-owned' : 'assignment-shared',
    'assigned_ids' => $assignedIds,
    'assigned_admins' => $assignedAdmins,
    'is_mine' => $isMine,
  ];
}

function gysj_redesign_ensure_assignment_column(PDO $pdo): bool
{
  try {
    if (!gysj_table_has_columns($pdo, 'paper_submissions', ['assigned_admin_ids_json'])) {
      $pdo->exec('ALTER TABLE paper_submissions ADD COLUMN assigned_admin_ids_json TEXT NULL');
    }
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function gysj_redesign_ensure_reviewer_columns(PDO $pdo): void
{
  $tables = ['admin_applications', 'users'];
  $columnsToAdd = [
    'admin_role' => "VARCHAR(50) NULL DEFAULT 'reviewer'",
    'assigned_journals_json' => 'TEXT NULL',
    'country' => 'VARCHAR(100) NULL',
    'institution' => 'VARCHAR(255) NULL',
    'grade_level' => 'VARCHAR(100) NULL',
    'reviewer_experience_text' => 'TEXT NULL',
    'reviewer_reason_text' => 'TEXT NULL',
    'reviewer_weekly_availability' => 'VARCHAR(32) NULL',
    'reviewer_profile_links' => 'TEXT NULL',
    'reviewer_cv_path' => 'VARCHAR(1024) NULL',
    'reviewer_cv_original_name' => 'VARCHAR(255) NULL',
    'reviewer_cv_mime' => 'VARCHAR(100) NULL',
    'reviewer_cv_size' => 'INT UNSIGNED NULL',
    'reviewer_supporting_documents_json' => 'TEXT NULL',
    'reviewer_declaration_confirmed' => 'TINYINT(1) NULL DEFAULT 0',
  ];

  foreach ($tables as $table) {
    try {
      $pdo->exec("ALTER TABLE {$table} MODIFY password_hash VARCHAR(255) NOT NULL");
    } catch (Throwable $e) {
      // ignore
    }

    foreach ($columnsToAdd as $col => $def) {
      if (!gysj_table_has_columns($pdo, $table, [$col])) {
        try {
          $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
        } catch (Throwable $e) {
          // ignore
        }
      }
    }
  }
}

function gysj_redesign_assigned_name(array $paper): string
{
  $names = ['Erin Alons', 'Eli Olson', 'Brian Cod'];
  $paperId = max(0, (int) ($paper['id'] ?? 0));
  if ($paperId <= 0) {
    return 'No one';
  }

  return $names[$paperId % count($names)];
}

function gysj_redesign_rating_for_status(string $status): string
{
  switch ($status) {
    case 'accepted':
      return '4.0 / 5.0';
    case 'needs_edits':
      return '3.0 / 5.0';
    case 'escalated':
      return '2.0 / 5.0';
    case 'rejected':
      return '1.0 / 5.0';
    default:
      return 'N/A';
  }
}

function gysj_redesign_extract_rating(array $paper): string
{
  $comment = trim((string) ($paper['admin_comment'] ?? ''));
  if (preg_match('/Overall Score:\s*([\d\.]+)/i', $comment, $matches)) {
    return number_format((float) $matches[1], 1) . ' / 5.0';
  }
  
  $history = is_array($paper['history'] ?? null) ? array_reverse($paper['history']) : [];
  foreach ($history as $item) {
    $hComment = trim((string) ($item['comment'] ?? ''));
    if (preg_match('/Overall Score:\s*([\d\.]+)/i', $hComment, $matches)) {
      return number_format((float) $matches[1], 1) . ' / 5.0';
    }
  }

  return gysj_redesign_rating_for_status((string) ($paper['status'] ?? 'submitted'));
}

function gysj_redesign_review_summary_counts(array $paper): array
{
  $historyCount = is_array($paper['history'] ?? null) ? count($paper['history']) : 0;
  $status = (string) ($paper['status'] ?? 'submitted');

  return [
    'mail' => max(0, min(3, $historyCount)),
    'thumb' => $status === 'accepted' ? 1 : ($status === 'needs_edits' ? 1 : 0),
    'doc' => (int) (!empty($paper['latest_version_id'])),
  ];
}

function gysj_redesign_comment_excerpt(array $paper, int $limit = 160): array
{
  $comment = trim((string) ($paper['admin_comment'] ?? ''));
  $history = is_array($paper['history'] ?? null) ? array_reverse($paper['history']) : [];

  foreach ($history as $item) {
    $historyComment = trim((string) ($item['comment'] ?? ''));
    if ($historyComment !== '') {
      $comment = $historyComment;
      break;
    }
  }

  if ($comment === '') {
    return ['text' => 'No comments yet', 'full' => 'No comments yet', 'empty' => true];
  }

  $full = $comment;
  if (function_exists('mb_strlen') && function_exists('mb_substr')) {
    if (mb_strlen($comment) > $limit) {
      $comment = rtrim(mb_substr($comment, 0, $limit - 1)) . '…';
    }
  } elseif (strlen($comment) > $limit) {
    $comment = rtrim(substr($comment, 0, $limit - 1)) . '…';
  }

  return ['text' => $comment, 'full' => $full, 'empty' => false];
}

function gysj_redesign_issue_number_for_month(PDO $pdo, int $year, int $month): int
{
  $driver = '';
  try {
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
  } catch (Throwable $e) {
    $driver = '';
  }

  if ($driver === 'sqlite') {
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT CAST(strftime('%m', published_at) AS INTEGER)) AS c FROM paper_submissions WHERE status = 'accepted' AND published_at IS NOT NULL AND CAST(strftime('%Y', published_at) AS INTEGER) = ? AND CAST(strftime('%m', published_at) AS INTEGER) < ?");
    $stmt->execute([$year, $month]);
    $row = $stmt->fetch();
    return ((int) ($row['c'] ?? 0)) + 1;
  }

  $stmt = $pdo->prepare("SELECT COUNT(DISTINCT MONTH(published_at)) AS c FROM paper_submissions WHERE status = 'accepted' AND published_at IS NOT NULL AND YEAR(published_at) = ? AND MONTH(published_at) < ?");
  $stmt->execute([$year, $month]);
  $row = $stmt->fetch();
  return ((int) ($row['c'] ?? 0)) + 1;
}

function gysj_generate_published_slug(PDO $pdo, int $year, int $month, int $submissionId): string {
  $driver = '';
  try {
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
  } catch (Throwable $e) {}
  
  $volumeNumber = ($year - 2023) * 12 + $month;
  
  if ($driver === 'sqlite') {
    $stmtSeq = $pdo->prepare("SELECT COUNT(*) AS c FROM paper_submissions WHERE status = 'accepted' AND published_at IS NOT NULL AND CAST(strftime('%Y', published_at) AS INTEGER) = ? AND CAST(strftime('%m', published_at) AS INTEGER) = ? AND id != ?");
  } else {
    $stmtSeq = $pdo->prepare("SELECT COUNT(*) AS c FROM paper_submissions WHERE status = 'accepted' AND published_at IS NOT NULL AND YEAR(published_at) = ? AND MONTH(published_at) = ? AND id != ?");
  }
  $stmtSeq->execute([$year, $month, $submissionId]);
  $rowSeq = $stmtSeq->fetch();
  $articleIssue = ((int) ($rowSeq['c'] ?? 0)) + 1;
  
  $slugCounter = $articleIssue;
  $newSlug = $volumeNumber . '-' . $slugCounter;
  while (true) {
    $checkStmt = $pdo->prepare("SELECT 1 FROM paper_submissions WHERE slug = ? AND id != ?");
    $checkStmt->execute([$newSlug, $submissionId]);
    if (!$checkStmt->fetch()) {
      break;
    }
    $slugCounter++;
    $newSlug = $volumeNumber . '-' . $slugCounter;
  }
  return $newSlug;
}

function gysj_redesign_visible_submission(array $submission, array $assignedJournals, bool $canSeeAll): bool
{
  if ($canSeeAll) {
    return true;
  }

  $journal = gysj_redesign_extract_journal($submission);
  if ($journal === '') {
    return false;
  }

  return in_array($journal, $assignedJournals, true);
}

function gysj_redesign_submission_payload(array $submission, array $history, string $paperUrl): array
{
  $submissionDetailsData = (string) ($submission['submission_details'] ?? '');
  if ($submissionDetailsData === '' || $submissionDetailsData === '{}' || $submissionDetailsData === '[]') { $submissionDetailsData = (string) ($submission['metadata'] ?? ''); }
  $detailPairs = gysj_redesign_parse_details($submissionDetailsData);
  $keywords = array_values(array_filter(array_map('trim', preg_split('/\s*[,;\r\n]+\s*/', (string) ($submission['keywords'] ?? '')) ?: [])));
  $assignedAdminIds = gysj_redesign_decode_admin_ids($submission['assigned_admin_ids_json'] ?? null);
  if (empty($assignedAdminIds)) {
    $reviewedBy = (int) ($submission['reviewed_by'] ?? 0);
    if ($reviewedBy > 0) {
      $assignedAdminIds = [$reviewedBy];
    }
  }
  $authorBio = trim((string) ($submission['author_bio'] ?? ''));
  if ($submissionDetailsData !== '' && $authorBio !== '') {
    $parts = preg_split('/\R+\s*Submission details:\s*\R+/i', $authorBio, 2);
    if (is_array($parts) && count($parts) >= 1) {
      $authorBio = trim((string) $parts[0]);
    }
  }

  return [
    'id' => (int) ($submission['id'] ?? 0),
    'title' => trim((string) ($submission['title'] ?? 'Untitled Submission')),
    'authors' => trim((string) ($submission['authors'] ?? '')),
    'abstract' => trim((string) ($submission['abstract'] ?? '')),
    'references_html' => trim((string) ($submission['references_html'] ?? '')),
    'author_bio' => $authorBio,
    'journal' => gysj_redesign_extract_journal($submission),
    'type' => gysj_redesign_extract_type($submission),
    'category' => trim((string) ($submission['category'] ?? '')),
    'keywords' => $keywords,
    'submission_details_raw' => trim($submissionDetailsData),
    'submission_details_pairs' => $detailPairs,
    'tracking_id' => trim((string) ($submission['tracking_id'] ?? '')),
    'status' => trim((string) ($submission['status'] ?? 'submitted')),
    'status_label' => (trim((string) ($submission['status'] ?? 'submitted')) === 'submitted' && gysj_redesign_extract_rating(['status' => trim((string) ($submission['status'] ?? 'submitted')), 'admin_comment' => $submission['admin_comment'] ?? '', 'history' => $history]) !== 'N/A') ? 'Waiting for Action' : gysj_redesign_status_label((string) ($submission['status'] ?? 'submitted')),
    'status_class' => gysj_redesign_status_class((string) ($submission['status'] ?? 'submitted')),
    'submitter_name' => trim((string) ($submission['submitter_name'] ?? '')),
    'submitter_email' => trim((string) ($submission['submitter_email'] ?? '')),
    'created_at' => trim((string) ($submission['created_at'] ?? '')),
    'reviewed_at' => trim((string) ($submission['reviewed_at'] ?? '')),
    'admin_comment' => trim((string) ($submission['admin_comment'] ?? '')),
    'assigned_admin_ids' => $assignedAdminIds,
    'assigned_admin_ids_json' => trim((string) ($submission['assigned_admin_ids_json'] ?? '')),
    'eligible_admins' => array_values(is_array($submission['eligible_admins'] ?? null) ? $submission['eligible_admins'] : []),
    'assignment_summary' => is_array($submission['assignment_summary'] ?? null) ? $submission['assignment_summary'] : [],
    'version' => (int) ($submission['version'] ?? 1),
    'latest_version_id' => (int) ($submission['latest_version_id'] ?? 0),
    'pdf_url' => $paperUrl,
    'attachments' => is_array($submission['attachments'] ?? null) ? $submission['attachments'] : [],
    'history' => $history,
  ];
}

function gysj_redesign_submission_bucket(string $status): string
{
  switch ($status) {
    case 'needs_edits':
      return 'needs_edits';
    case 'escalated':
      return 'escalated';
    case 'rejected':
      return 'rejected';
    case 'accepted':
    case 'submitted':
    default:
      return 'active';
  }
}

function gysj_redesign_render_submission_row(array $paper, string $extraClass = '', bool $isHidden = false): void
{
  $rowSearch = strtolower(trim(implode(' ', [
    (string) ($paper['title'] ?? ''),
    (string) ($paper['authors'] ?? ''),
    (string) ($paper['submitter_name'] ?? ''),
    (string) ($paper['submitter_email'] ?? ''),
    (string) ($paper['tracking_id'] ?? ''),
    (string) ($paper['journal'] ?? ''),
    (string) ($paper['category'] ?? ''),
  ])));
  $rowClasses = ['submission-row'];
  if ($extraClass !== '') {
    $rowClasses[] = $extraClass;
  }
  if ($isHidden) {
    $rowClasses[] = 'submission-row-hidden';
  }
  ?>
  <tr class="<?php echo e(implode(' ', $rowClasses)); ?>" data-status="<?php echo e((string) ($paper['status'] ?? 'submitted')); ?>" data-search="<?php echo e($rowSearch); ?>">
    <td>
      <?php if (($paper['status'] ?? 'submitted') === 'submitted'): ?>
        <span class="pending-dot" title="Awaiting review"></span>
      <?php endif; ?>
      <span class="paper-title <?php echo trim((string) ($paper['title'] ?? '')) === '' ? 'paper-no-title' : ''; ?>"><?php echo e(trim((string) ($paper['title'] ?? '')) !== '' ? (string) $paper['title'] : 'Untitled'); ?></span>
      <?php if (trim((string) ($paper['journal'] ?? '')) !== '' || trim((string) ($paper['type'] ?? '')) !== ''): ?>
        <div class="paper-mini"><?php echo e(trim((string) ($paper['type'] ?? '')) !== '' ? (string) $paper['type'] : 'Submission'); ?><?php echo trim((string) ($paper['journal'] ?? '')) !== '' ? ' - ' . e((string) $paper['journal']) : ''; ?></div>
      <?php endif; ?>
    </td>
    <td><span class="tracking-id"><?php echo e(trim((string) ($paper['tracking_id'] ?? '')) !== '' ? (string) $paper['tracking_id'] : '—'); ?></span></td>
    <td>
      <div class="author-name"><?php echo e((string) ($paper['submitter_name'] ?? '')); ?></div>
      <div class="author-email"><?php echo e((string) ($paper['submitter_email'] ?? '')); ?></div>
    </td>
    <td><span class="db-status <?php echo e((string) ($paper['status_class'] ?? 'status-submitted')); ?>"><?php echo e((string) ($paper['status_label'] ?? 'Submitted')); ?></span></td>
    <td><span class="date-cell"><?php echo e(gysj_redesign_format_datetime((string) ($paper['created_at'] ?? ''))); ?></span></td>
    <td>
      <div class="tbl-actions">
        <?php $chatTitle = trim((string) ($paper['title'] ?? '')) !== '' ? trim((string) $paper['title']) : 'Untitled'; ?>
        <?php $unreadCnt = $unreadAdminCounts[$paper['id']] ?? 0; ?>
        <?php $chatLabel = $unreadCnt > 0 ? "Chat ($unreadCnt)" : "Chat"; ?>
        <button class="db-btn" type="button" data-chat-id="<?php echo (int)($paper['id'] ?? 0); ?>" data-chat-title="<?php echo htmlspecialchars($chatTitle, ENT_QUOTES, 'UTF-8'); ?>" onclick="openChatModal(this.dataset.chatId, this.dataset.chatTitle)" style="color: #0284c7;">
          <i class="fa fa-comments"></i> <?php echo e($chatLabel); ?>
        </button>
        <a class="db-btn" href="<?php echo e((string) ($paper['pdf_url'] ?? '#')); ?>" target="_blank" rel="noopener" title="Open PDF">
          <i class="fa fa-file-pdf-o"></i> PDF
        </a>
        <button class="db-btn db-btn-primary" type="button" onclick="openDrawer(<?php echo (int) ($paper['id'] ?? 0); ?>)">
          <i class="fa fa-eye"></i> Review
        </button>
      </div>
    </td>
  </tr>
  <?php
}

$adminAssignedJournals = [];
$adminDirectory = [];
if ($pdo instanceof PDO) {
  try {
    $usersHasAssignedJournalsColumn = gysj_table_has_columns($pdo, 'users', ['assigned_journals_json']);
    if (gysj_table_has_columns($pdo, 'users', ['assigned_journals_json'])) {
      $stmt = $pdo->prepare('SELECT assigned_journals_json FROM users WHERE id = ? LIMIT 1');
      $stmt->execute([(int) ($admin['id'] ?? 0)]);
      $adminRow = $stmt->fetch();
      if (is_array($adminRow)) {
        $adminAssignedJournals = gysj_normalize_journal_selection($adminRow['assigned_journals_json'] ?? null);
      }
    }

    $adminSelectColumns = 'id, name, email';
    if ($usersHasAssignedJournalsColumn) {
      $adminSelectColumns .= ', assigned_journals_json';
    }

    $stmt = $pdo->query("SELECT {$adminSelectColumns} FROM users WHERE role = 'admin' ORDER BY name ASC, id ASC");
    $rows = $stmt->fetchAll();
    if (is_array($rows)) {
      foreach ($rows as $row) {
        $adminId = (int) ($row['id'] ?? 0);
        if ($adminId <= 0) {
          continue;
        }

        $adminDirectory[$adminId] = [
          'id' => $adminId,
          'name' => trim((string) ($row['name'] ?? '')),
          'email' => trim((string) ($row['email'] ?? '')),
          'assigned_journals_json' => $usersHasAssignedJournalsColumn ? (string) ($row['assigned_journals_json'] ?? '') : '',
          'assigned_journals' => gysj_normalize_journal_selection($row['assigned_journals_json'] ?? null),
        ];
      }
    }
  } catch (Throwable $e) {
    $adminAssignedJournals = [];
    $adminDirectory = [];
  }
}

$adminCanSeeAllJournals = empty($adminAssignedJournals);
$hasAssignmentColumn = $pdo instanceof PDO && gysj_table_has_columns($pdo, 'paper_submissions', ['assigned_admin_ids_json']);
$hasReviewedByColumn = $pdo instanceof PDO && gysj_table_has_columns($pdo, 'paper_submissions', ['reviewed_by']);
$hasUpdatedAtColumn = $pdo instanceof PDO && gysj_table_has_columns($pdo, 'paper_submissions', ['updated_at']);
file_put_contents(__DIR__ . '/admin_chat_debug.log', date('Y-m-d H:i:s') . " - METHOD: {$_SERVER['REQUEST_METHOD']}\nPOST: " . json_encode($_POST) . "\n", FILE_APPEND);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO) {
    $action = (string) ($_POST['action'] ?? '');
    if ($action !== 'get_chat' && $action !== 'set_typing') {
        csrf_validate();
    }

    
    if ($action === 'set_typing') {
        $subId = (int)($_POST['submission_id'] ?? 0);
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS chat_typing (submission_id INT, sender_type VARCHAR(50), last_typed DATETIME, PRIMARY KEY(submission_id, sender_type))");
            $stmt = $pdo->prepare("REPLACE INTO chat_typing (submission_id, sender_type, last_typed) VALUES (?, 'admin', CURRENT_TIMESTAMP)");
            $stmt->execute([$subId]);
        } catch (Throwable $e) {}
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'get_chat') {
        $subId = (int)($_POST['submission_id'] ?? 0);
        
        try {
            $pdo->exec("ALTER TABLE submission_messages ADD COLUMN is_read TINYINT(1) DEFAULT 0");
        } catch (Throwable $e) {}
        
        $pdo->prepare("UPDATE submission_messages SET is_read = 1 WHERE submission_id = ? AND sender_type = 'user'")->execute([$subId]);
        
        $stmt = $pdo->prepare("SELECT sender_name, sender_type, message, created_at FROM submission_messages WHERE submission_id = ? ORDER BY created_at ASC, id ASC");
        $stmt->execute([$subId]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $isTyping = false;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS chat_typing (submission_id INT, sender_type VARCHAR(50), last_typed DATETIME, PRIMARY KEY(submission_id, sender_type))");
            $stmtTyping = $pdo->prepare("SELECT last_typed, CURRENT_TIMESTAMP as db_now FROM chat_typing WHERE submission_id = ? AND sender_type = 'user'");
            $stmtTyping->execute([$subId]);
            $typingRow = $stmtTyping->fetch(PDO::FETCH_ASSOC);
            if ($typingRow && !empty($typingRow['last_typed'])) {
                $lastTs = strtotime($typingRow['last_typed']);
                $nowTs = strtotime($typingRow['db_now']);
                if ($nowTs - $lastTs <= 5 && $nowTs - $lastTs >= -5) {
                    $isTyping = true;
                }
            }
        } catch (Throwable $e) {}
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'messages' => $messages, 'is_typing' => $isTyping]);
        exit;
    }
    
    if ($action === 'send_chat') {
        $subId = (int)($_POST['submission_id'] ?? 0);
        $msg = trim((string)($_POST['message'] ?? ''));
        if ($subId > 0 && $msg !== '') {
            $senderName = !empty($admin['name']) ? trim((string)$admin['name']) : 'Editor';
            $stmt = $pdo->prepare("INSERT INTO submission_messages (submission_id, sender_type, sender_name, message) VALUES (?, 'admin', ?, ?)");
            $stmt->execute([$subId, $senderName, $msg]);
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false]);
        }
        exit;
    }

  if (in_array($action, ['claim_assignment', 'update_assignment'], true)) {
    $submissionId = (string) ($_POST['submission_id'] ?? '');

    if (!ctype_digit($submissionId)) {
      $error = 'Invalid submission.';
    } else {
      $id = (int) $submissionId;

      try {
        $stmt = $pdo->prepare('SELECT * FROM paper_submissions WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $submission = $stmt->fetch();

        if (!is_array($submission)) {
          $error = 'Submission not found.';
        } else {
          $paperJournal = gysj_redesign_extract_journal($submission);
          $eligibleIds = [];

          foreach ($adminDirectory as $adminItem) {
            if (gysj_redesign_admin_matches_journal($adminItem, $paperJournal)) {
              $eligibleIds[] = (int) ($adminItem['id'] ?? 0);
            }
          }

          $selectedIds = $action === 'claim_assignment'
            ? [$gysj_current_admin_id]
            : gysj_redesign_decode_admin_ids($_POST['assigned_admin_ids'] ?? []);

          $adminIds = array_keys($adminDirectory);
          $selectedIds = array_values(array_unique(array_filter(array_map('intval', $selectedIds), static function (int $adminId) use ($adminIds, $gysj_current_admin_id): bool {
            return $adminId > 0 && ($adminId === $gysj_current_admin_id || in_array($adminId, $adminIds, true));
          })));

          if (empty($selectedIds)) {
            $error = 'Select at least one eligible admin or claim the paper for yourself.';
          } else {
            $setParts = [];
            $params = [];

            if ($hasAssignmentColumn) {
              $setParts[] = 'assigned_admin_ids_json = ?';
              $params[] = gysj_redesign_encode_admin_ids($selectedIds);
            }

            if ($hasReviewedByColumn) {
              $primaryAdmin = (int) ($selectedIds[0] ?? 0);
              $setParts[] = 'reviewed_by = ?';
              $params[] = $primaryAdmin > 0 ? $primaryAdmin : null;
            }

            if ($hasUpdatedAtColumn) {
              $setParts[] = 'updated_at = CURRENT_TIMESTAMP';
            }

            if (empty($setParts)) {
              throw new RuntimeException('No assignment-compatible columns found on paper_submissions.');
            }

            $params[] = $id;
            $stmt = $pdo->prepare('UPDATE paper_submissions SET ' . implode(', ', $setParts) . ' WHERE id = ?');
            $stmt->execute($params);

            $_SESSION['admin_dashboard_redesign_claimed_submission_id'] = $id;

            if (isset($_POST['ajax_assignment'])) {
              echo json_encode(['success' => true, 'message' => $action === 'claim_assignment' ? 'You claimed this paper.' : 'Assignment updated.']);
              exit;
            }

            $_SESSION['admin_dashboard_redesign_flash'] = [
              'type' => 'success',
              'message' => $action === 'claim_assignment' ? 'You claimed this paper.' : 'Assignment updated.',
            ];
            redirect('admin-dashboard.php#submissions');
          }
        }
      } catch (Throwable $e) {
        $error = 'Assignment update failed. Please try again.';
      }
    }

    if (isset($_POST['ajax_assignment'])) {
      if (!empty($error)) {
        echo json_encode(['success' => false, 'error' => $error]);
      }
      exit;
    }
  } elseif ($action === 'bulk_action') {
    $bulkType = (string) ($_POST['bulk_type'] ?? '');
    $submissionIds = $_POST['submission_ids'] ?? [];
    $adminRole = $admin['admin_role'] ?? 'all';
    
    if (!is_array($submissionIds) || empty($submissionIds)) {
      $error = 'No submissions selected.';
    } elseif (!in_array($bulkType, ['accept', 'needs_edits', 'reject', 'delete'], true)) {
      $error = 'Invalid bulk action.';
    } elseif ($bulkType === 'accept' && !in_array($adminRole, ['editor_in_chief', 'all'], true)) {
      $error = 'You do not have permission to accept submissions.';
    } elseif ($bulkType === 'reject' && !in_array($adminRole, ['reviewer', 'editor_in_chief', 'all'], true)) {
      $error = 'You do not have permission to reject submissions.';
    } elseif ($bulkType === 'delete' && !in_array($adminRole, ['all'], true)) {
      $error = 'You do not have permission to delete submissions.';
    } else {
      $successCount = 0;
      $pdo->beginTransaction();
      try {
        $adminId = (int) ($admin['id'] ?? 0);
        
        foreach ($submissionIds as $sid) {
          if (!ctype_digit((string)$sid)) continue;
          $id = (int)$sid;
          
          if ($bulkType === 'delete') {
            $stmt = $pdo->prepare('SELECT manuscript_path FROM paper_submission_versions WHERE paper_submission_id = ?');
            $stmt->execute([$id]);
            $versions = $stmt->fetchAll();
            if (is_array($versions)) {
              foreach ($versions as $v) {
                $path = trim((string)($v['manuscript_path'] ?? ''));
                if ($path !== '') {
                  $fullPath = __DIR__ . '/' . ltrim($path, '/');
                  if (is_file($fullPath)) {
                    @unlink($fullPath);
                  }
                }
              }
            }
            $stmt = $pdo->prepare('DELETE FROM paper_submission_versions WHERE paper_submission_id = ?');
            $stmt->execute([$id]);
            $stmt = $pdo->prepare('DELETE FROM paper_submissions WHERE id = ?');
            $stmt->execute([$id]);
            $successCount++;
          } else {
            $submissionStatus = $bulkType === 'accept' ? 'accepted' : ($bulkType === 'reject' ? 'rejected' : $bulkType);
            $comment = gysj_redesign_review_note('Bulk ' . $submissionStatus . ' by Admin', 'Processed via bulk action.');
            
            $stmt = $pdo->prepare('SELECT status, user_id FROM paper_submissions WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $submission = $stmt->fetch();
            
            if (is_array($submission)) {
              if ($submissionStatus === 'accepted') {
                $currentStatus = (string) ($submission['status'] ?? 'submitted');
                if ($currentStatus !== 'accepted') {
                  $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
                  $year = (int) (new DateTimeImmutable($now))->format('Y');
                  $month = (int) (new DateTimeImmutable($now))->format('n');
                  $issueNumber = gysj_redesign_issue_number_for_month($pdo, $year, $month);
                  $country3 = 'XXX';
                  $userId = (int) ($submission['user_id'] ?? 0);
                  if ($userId > 0) {
                    $uStmt = $pdo->prepare('SELECT country FROM users WHERE id = ? LIMIT 1');
                    $uStmt->execute([$userId]);
                    $uRow = $uStmt->fetch();
                    if (is_array($uRow)) {
                      $country3 = gysj_country_to_country3((string) ($uRow['country'] ?? ''));
                    }
                  }
                  $trackingId = gysj_generate_tracking_id($country3, $year, $month, $id, $issueNumber);
                  $newSlug = gysj_generate_published_slug($pdo, $year, $month, $id);
                  $stmt = $pdo->prepare('UPDATE paper_submissions SET status = ?, admin_comment = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP, published_at = CURRENT_TIMESTAMP, tracking_id = ?, volume_year = ?, issue_number = ?, slug = ? WHERE id = ?');
                  $stmt->execute([$submissionStatus, $comment, $adminId, $trackingId, $year, $issueNumber, $newSlug, $id]);
                }
              } else {
                $stmt = $pdo->prepare('UPDATE paper_submissions SET status = ?, admin_comment = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP, published_at = NULL WHERE id = ?');
                $stmt->execute([$submissionStatus, $comment, $adminId, $id]);
              }
              $successCount++;
            }
          }
        }
        $pdo->commit();
        $_SESSION['admin_dashboard_redesign_flash'] = [
          'type' => 'success',
          'message' => 'Successfully processed ' . $successCount . ' submission(s).'
        ];
        redirect('admin-dashboard.php#submissions');
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        $error = 'Bulk action failed: ' . $e->getMessage();
      }
    }
  } elseif ($action === 'update_metadata') {
    $submissionId = (string) ($_POST['submission_id'] ?? '');
    $title = trim((string) ($_POST['title'] ?? ''));
    $abstract = trim((string) ($_POST['abstract'] ?? ''));
    $keywords = trim((string) ($_POST['keywords'] ?? ''));
    $authorsJson = trim((string) ($_POST['authors_json'] ?? ''));
    $referencesHtml = trim((string) ($_POST['references_html'] ?? ''));
    if (!ctype_digit($submissionId)) {
        echo json_encode(['success' => false, 'error' => 'Invalid submission ID']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("UPDATE paper_submissions SET title = ?, abstract = ?, keywords = ?, authors_json = ?, references_html = ? WHERE id = ?");
        $stmt->execute([$title, $abstract, $keywords, $authorsJson, $referencesHtml, $submissionId]);
        echo json_encode(['success' => true]);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error']);
        exit;
    }
  } elseif ($action === 'delete_paper') {
    $adminRole = $admin['admin_role'] ?? 'all';
    $submissionId = (string) ($_POST['submission_id'] ?? '');
    if (!in_array($adminRole, ['all'], true)) {
      $error = 'You do not have permission to delete submissions.';
    } elseif (!ctype_digit($submissionId)) {
      $error = 'Invalid submission.';
    } else {
      $id = (int) $submissionId;
      try {
        $stmt = $pdo->prepare('SELECT * FROM paper_submissions WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        if (!is_array($stmt->fetch())) {
          $error = 'Submission not found.';
        } else {
          $pdo->beginTransaction();
          
          $stmt = $pdo->prepare('SELECT manuscript_path FROM paper_submission_versions WHERE paper_submission_id = ?');
          $stmt->execute([$id]);
          $versions = $stmt->fetchAll();
          if (is_array($versions)) {
            foreach ($versions as $v) {
              $path = trim((string)($v['manuscript_path'] ?? ''));
              if ($path !== '') {
                $fullPath = __DIR__ . '/' . ltrim($path, '/');
                if (is_file($fullPath)) {
                  @unlink($fullPath);
                }
              }
            }
          }
          
          $stmt = $pdo->prepare('DELETE FROM paper_submission_versions WHERE paper_submission_id = ?');
          $stmt->execute([$id]);
          
          $stmt = $pdo->prepare('DELETE FROM paper_submissions WHERE id = ?');
          $stmt->execute([$id]);
          
          $pdo->commit();
          $_SESSION['admin_dashboard_redesign_flash'] = [
            'type' => 'success',
            'message' => 'Submission permanently deleted.',
          ];
          redirect('admin-dashboard.php#submissions');
        }
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        $error = 'Could not delete submission. Please try again.';
      }
    }
  } elseif (in_array($action, ['accept', 'accepted', 'reject', 'rejected', 'needs_edits', 'submitted', 'escalated'], true)) {
    $adminRole = $admin['admin_role'] ?? 'all';
    $submissionId = (string) ($_POST['submission_id'] ?? '');
    $feedback = trim((string) ($_POST['comment'] ?? ''));
    $internalNote = trim((string) ($_POST['internal_note'] ?? ''));
    $finalTitle = trim((string) ($_POST['final_title'] ?? ''));
    $finalAbstract = trim((string) ($_POST['final_abstract'] ?? ''));
    $finalKeywords = trim((string) ($_POST['final_keywords'] ?? ''));
    $finalAuthorsJson = trim((string) ($_POST['final_authors_json'] ?? ''));
    $finalReferencesHtml = trim((string) ($_POST['final_references_html'] ?? ''));
    $finalPublicationDate = trim((string) ($_POST['final_publication_date'] ?? ''));
    $submissionStatus = $action === 'accept' ? 'accepted' : ($action === 'reject' ? 'rejected' : $action);

    if (!ctype_digit($submissionId)) {
      $error = 'Invalid submission.';
    } elseif ($submissionStatus === 'accepted' && !in_array($adminRole, ['editor_in_chief', 'all'], true)) {
      $error = 'You do not have permission to accept submissions.';
    } elseif ($submissionStatus === 'rejected' && !in_array($adminRole, ['reviewer', 'editor_in_chief', 'all'], true)) {
      $error = 'You do not have permission to reject submissions.';
    } elseif ($feedback === '' && !in_array($action, ['submitted', 'escalated'], true)) {
      $error = 'Please write feedback for the author before submitting a decision.';
    } else {
      $id = (int) $submissionId;

      try {
        $stmt = $pdo->prepare('SELECT * FROM paper_submissions WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $submission = $stmt->fetch();

        if (!is_array($submission)) {
          $error = 'Submission not found.';
        } else {
          $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
          $comment = gysj_redesign_review_note($feedback, $internalNote);
          $adminId = (int) ($admin['id'] ?? 0);

          if (in_array($submissionStatus, ['rejected', 'needs_edits', 'submitted', 'escalated'], true)) {
            $stmt = $pdo->prepare('UPDATE paper_submissions SET status = ?, admin_comment = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP, published_at = NULL WHERE id = ?');
            $stmt->execute([$submissionStatus, $comment, $adminId, $id]);
          } else {
            $currentStatus = (string) ($submission['status'] ?? 'submitted');

            if ($currentStatus === 'accepted') {
              $stmt = $pdo->prepare('UPDATE paper_submissions SET admin_comment = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP, title = ?, abstract = ?, keywords = ?, authors_json = ?, references_html = ? WHERE id = ?');
              $stmt->execute([$comment, $adminId, $finalTitle, $finalAbstract, $finalKeywords, $finalAuthorsJson, $finalReferencesHtml, $id]);
            } else {
              $publishedAtValue = $now;
              if ($submissionStatus === 'accepted' && $finalPublicationDate !== '') {
                try {
                  $pubDt = new DateTimeImmutable($finalPublicationDate);
                  $publishedAtValue = $pubDt->format('Y-m-d H:i:s');
                } catch (Throwable $e) {
                  // Ignore invalid dates, fallback to $now
                }
              }

              $year = (int) (new DateTimeImmutable($publishedAtValue))->format('Y');
              $month = (int) (new DateTimeImmutable($publishedAtValue))->format('n');
              $issueNumber = gysj_redesign_issue_number_for_month($pdo, $year, $month);
              $country3 = 'XXX';

              $userId = (int) ($submission['user_id'] ?? 0);
              if ($userId > 0) {
                try {
                  $uStmt = $pdo->prepare('SELECT country FROM users WHERE id = ? LIMIT 1');
                  $uStmt->execute([$userId]);
                  $uRow = $uStmt->fetch();
                  if (is_array($uRow)) {
                    $country3 = gysj_country_to_country3((string) ($uRow['country'] ?? ''));
                  }
                } catch (Throwable $e) {
                  $country3 = 'XXX';
                }
              }

              $published = false;
              $attempts = 0;
              // Require admin to upload final published PDF when accepting
              $publishedPdfPath = null;
              $publishedPdfName = '';
              $publishedPdfMime = '';
              $publishedPdfSize = 0;
              if (!is_array($_FILES['admin_published_pdf'] ?? null) || (int) ($_FILES['admin_published_pdf']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                $error = 'When accepting, please upload the final published PDF.';
              } else {
                $pdfFile = $_FILES['admin_published_pdf'];
                $pdfErr = (int) ($pdfFile['error'] ?? UPLOAD_ERR_NO_FILE);
                $pdfTmp = (string) ($pdfFile['tmp_name'] ?? '');
                $pdfSize = (int) ($pdfFile['size'] ?? 0);

                if ($pdfErr !== UPLOAD_ERR_OK || $pdfTmp === '' || !is_file($pdfTmp)) {
                  $error = 'Uploaded PDF is invalid. Please try again.';
                } elseif ($pdfSize <= 0 || $pdfSize > 50 * 1024 * 1024) {
                  $error = 'Published PDF must be between 1 byte and 50 MB.';
                } else {
                  $finfo = new finfo(FILEINFO_MIME_TYPE);
                  $pdfMime = (string) $finfo->file($pdfTmp);
                  if ($pdfMime !== 'application/pdf') {
                    $error = 'Published file must be a PDF.';
                  } else {
                    $slug = trim((string) ($submission['slug'] ?? '')) ?: ('submission-' . $id);
                    $safeSlug = preg_replace('/[^a-z0-9\-]/', '-', strtolower($slug));
                    $uploadDir = __DIR__ . '/uploads/submissions/published';
                    if (!is_dir($uploadDir)) {
                      @mkdir($uploadDir, 0755, true);
                    }
                    $targetName = $safeSlug . '-published-' . time() . '-' . bin2hex(random_bytes(6)) . '.pdf';
                    $targetRel = 'uploads/submissions/published/' . $targetName;
                    $targetFull = __DIR__ . '/' . $targetRel;
                    if (!@move_uploaded_file($pdfTmp, $targetFull)) {
                      $error = 'Failed to save uploaded PDF. Check file permissions.';
                    } else {
                      $publishedPdfPath = $targetRel;
                      $publishedPdfName = trim((string) ($pdfFile['name'] ?? $targetName));
                      $publishedPdfMime = $pdfMime;
                      $publishedPdfSize = $pdfSize;
                    }
                  }
                }
              }

              while (!$published && $attempts < 3 && $error === '') {
                $attempts++;
                $seq = gysj_next_tracking_seq($pdo, $year);
                $trackingId = gysj_format_tracking_id($country3, $year, $seq);

                try {
                  // If a published PDF was uploaded, record version and update manuscript info
                  if ($publishedPdfPath !== null) {
                    // compute next version
                    $currentVersion = (int) ($submission['version'] ?? 1);
                    $newVersion = $currentVersion + 1;

                    // insert into versions table
                    $vstmt = $pdo->prepare('INSERT INTO paper_submission_versions (paper_submission_id, version_number, title, authors, abstract, submission_details, keywords, category, manuscript_path, manuscript_original_name, manuscript_mime, manuscript_size, status, admin_comment, reviewed_by, reviewed_at, published_at, created_at, archived_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
                    $vstmt->execute([
                      $id,
                      $newVersion,
                      trim((string) ($submission['title'] ?? '')),
                      trim((string) ($submission['authors'] ?? '')),
                      trim((string) ($submission['abstract'] ?? '')),
                      trim((string) ($submission['metadata'] ?? $submission['submission_details'] ?? '')),
                      trim((string) ($submission['keywords'] ?? '')),
                      trim((string) ($submission['category'] ?? '')),
                      $publishedPdfPath,
                      $publishedPdfName,
                      $publishedPdfMime,
                      $publishedPdfSize,
                      'accepted',
                      $comment,
                      $adminId,
                      $now,
                      $publishedAtValue,
                    ]);

                    // update paper_submissions manuscript info and version
                    $newSlug = gysj_generate_published_slug($pdo, $year, $month, $id);
                    $stmt = $pdo->prepare('UPDATE paper_submissions SET status = ?, admin_comment = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP, published_at = ?, volume_year = ?, issue_number = ?, tracking_id = ?, tracking_country3 = ?, tracking_year = ?, tracking_seq = ?, manuscript_path = ?, manuscript_original_name = ?, manuscript_mime = ?, manuscript_size = ?, version = ?, title = ?, abstract = ?, keywords = ?, authors_json = ?, references_html = ?, slug = ? WHERE id = ?');
                    $stmt->execute([
                      'accepted',
                      $comment,
                      $adminId,
                      $publishedAtValue,
                      $year,
                      $issueNumber,
                      $trackingId,
                      $country3,
                      $year,
                      $seq,
                      $publishedPdfPath,
                      $publishedPdfName,
                      $publishedPdfMime,
                      $publishedPdfSize,
                      $newVersion,
                      $finalTitle,
                      $finalAbstract,
                      $finalKeywords,
                      $finalAuthorsJson,
                      $finalReferencesHtml,
                      $newSlug,
                      $id,
                    ]);
                  } else {
                    $newSlug = gysj_generate_published_slug($pdo, $year, $month, $id);
                    $stmt = $pdo->prepare('UPDATE paper_submissions SET status = ?, admin_comment = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP, published_at = ?, volume_year = ?, issue_number = ?, tracking_id = ?, tracking_country3 = ?, tracking_year = ?, tracking_seq = ?, title = ?, abstract = ?, keywords = ?, authors_json = ?, references_html = ?, slug = ? WHERE id = ?');
                    $stmt->execute([
                      'accepted',
                      $comment,
                      $adminId,
                      $publishedAtValue,
                      $year,
                      $issueNumber,
                      $trackingId,
                      $country3,
                      $year,
                      $seq,
                      $finalTitle,
                      $finalAbstract,
                      $finalKeywords,
                      $finalAuthorsJson,
                      $finalReferencesHtml,
                      $newSlug,
                      $id,
                    ]);
                  }
                  $published = true;
                } catch (Throwable $e) {
                  $msg = strtolower((string) $e->getMessage());
                  $code = strtolower((string) $e->getCode());
                  $schemaMissing = strpos($msg, 'unknown column') !== false || strpos($msg, 'no such column') !== false;
                  $duplicate = strpos($msg, 'duplicate') !== false || $code === '23000';

                  if ($schemaMissing) {
                    $newSlug = gysj_generate_published_slug($pdo, $year, $month, $id);
                    $stmt = $pdo->prepare('UPDATE paper_submissions SET status = ?, admin_comment = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP, published_at = ?, slug = ? WHERE id = ?');
                    $stmt->execute(['accepted', $comment, $adminId, $publishedAtValue, $newSlug, $id]);
                    $published = true;
                    break;
                  }

                  if ($duplicate && $attempts < 3) {
                    continue;
                  }

                  throw $e;
                }
              }
            }
          }

          if ($error === '') {
            $flashMsg = 'Decision recorded successfully.';
            if ($submissionStatus === 'accepted') $flashMsg = 'Submission accepted.';
            elseif ($submissionStatus === 'rejected') $flashMsg = 'Submission rejected.';
            elseif ($submissionStatus === 'needs_edits') $flashMsg = 'Requested edits from the author.';
            elseif ($submissionStatus === 'escalated') $flashMsg = 'Requested review from other editors.';
            elseif ($submissionStatus === 'submitted') $flashMsg = 'Evaluation submitted successfully.';
            
            $_SESSION['admin_dashboard_redesign_flash'] = [
              'type' => 'success',
              'message' => $flashMsg,
            ];
            redirect('admin-dashboard.php#submissions');
          }
        }
      } catch (Throwable $e) {
        $error = 'Action failed. Please try again.';
      }
    }
  } elseif ($action === 'approve_admin' || $action === 'reject_admin') {
    $adminRole = $admin['admin_role'] ?? 'all';
    $applicationId = (string) ($_POST['application_id'] ?? '');

    if (!in_array($adminRole, ['editor_in_chief', 'all'], true)) {
      $error = 'You do not have permission to manage admin applications.';
    } elseif (!ctype_digit($applicationId)) {
      $error = 'Invalid application.';
    } else {
      $appId = (int) $applicationId;
      $adminId = (int) ($admin['id'] ?? 0);

      try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT * FROM admin_applications WHERE id = ? LIMIT 1');
        $stmt->execute([$appId]);
        $app = $stmt->fetch();

        if (!is_array($app)) {
          $error = 'Application not found.';
        } elseif ((string) ($app['status'] ?? '') !== 'pending') {
          $error = 'This application is no longer pending.';
        } elseif ($action === 'reject_admin') {
          $stmt = $pdo->prepare("UPDATE admin_applications SET status = 'rejected', reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP WHERE id = ?");
          $stmt->execute([$adminId, $appId]);
          $success = 'Admin application rejected.';
        } else {
          $appEmail = trim((string) ($app['email'] ?? ''));
          $appName = trim((string) ($app['name'] ?? ''));
          $appHash = (string) ($app['password_hash'] ?? '');
          $appAssignedJournals = gysj_normalize_journal_selection($app['assigned_journals_json'] ?? null);
          if (empty($appAssignedJournals)) {
            $appAssignedJournals = gysj_submission_journal_options();
          }

          $stmt = $pdo->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
          $stmt->execute([$appEmail]);
          if ($stmt->fetch()) {
            $error = 'A user with this email already exists.';
          } else {
            if (gysj_table_has_columns($pdo, 'users', [
              'country',
              'institution',
              'grade_level',
              'reviewer_experience_text',
              'reviewer_reason_text',
              'reviewer_weekly_availability',
              'reviewer_profile_links',
              'reviewer_cv_path',
              'reviewer_cv_original_name',
              'reviewer_cv_mime',
              'reviewer_cv_size',
              'reviewer_supporting_documents_json',
              'reviewer_declaration_confirmed',
              'assigned_journals_json',
            ])) {
              $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role, country, institution, grade_level, reviewer_experience_text, reviewer_reason_text, reviewer_weekly_availability, reviewer_profile_links, reviewer_cv_path, reviewer_cv_original_name, reviewer_cv_mime, reviewer_cv_size, reviewer_supporting_documents_json, reviewer_declaration_confirmed, assigned_journals_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
              $stmt->execute([
                $appName,
                $appEmail,
                $appHash,
                'admin',
                trim((string) ($app['country'] ?? '')),
                trim((string) ($app['institution'] ?? '')),
                trim((string) ($app['grade_level'] ?? '')),
                trim((string) ($app['reviewer_experience_text'] ?? '')),
                trim((string) ($app['reviewer_reason_text'] ?? '')),
                trim((string) ($app['reviewer_weekly_availability'] ?? '')),
                trim((string) ($app['reviewer_profile_links'] ?? '')),
                trim((string) ($app['reviewer_cv_path'] ?? ($app['cv_path'] ?? ''))) ?: null,
                trim((string) ($app['reviewer_cv_original_name'] ?? ($app['cv_original_name'] ?? ''))) ?: null,
                trim((string) ($app['reviewer_cv_mime'] ?? ($app['cv_mime'] ?? ''))) ?: null,
                (int) ($app['reviewer_cv_size'] ?? ($app['cv_size'] ?? 0)) ?: null,
                trim((string) ($app['reviewer_supporting_documents_json'] ?? '')) ?: null,
                !empty($app['reviewer_declaration_confirmed']) ? 1 : 0,
                gysj_journal_selection_json($appAssignedJournals),
              ]);
            } else {
              $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role, assigned_journals_json) VALUES (?, ?, ?, ?, ?)');
              $stmt->execute([$appName, $appEmail, $appHash, 'admin', gysj_journal_selection_json($appAssignedJournals)]);
            }

            $stmt = $pdo->prepare("UPDATE admin_applications SET status = 'approved', reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$adminId, $appId]);
            $success = 'Admin application approved.';
          }
        }

        if ($error !== '') {
          $pdo->rollBack();
        } else {
          $pdo->commit();
          $_SESSION['admin_dashboard_redesign_flash'] = [
            'type' => 'success',
            'message' => $success,
          ];
          redirect('admin-dashboard.php#admin-applications');
        }
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        $error = 'Application action failed. Please try again.';
      }
    }
  } else {
    $error = 'Invalid action.';
  }
}

$submissions = [];
$submissionIds = [];
if ($pdo instanceof PDO) {
  try {
    $stmt = $pdo->query('SELECT s.*, u.name AS submitter_name, u.email AS submitter_email FROM paper_submissions s JOIN users u ON u.id = s.user_id ORDER BY s.created_at DESC');
    $rows = $stmt->fetchAll();
    if (is_array($rows)) {
      foreach ($rows as $row) {
        if (!is_array($row)) {
          continue;
        }

        if (!gysj_redesign_visible_submission($row, $adminAssignedJournals, $adminCanSeeAllJournals)) {
          continue;
        }

        $submissions[] = $row;
        $submissionId = (int) ($row['id'] ?? 0);
        if ($submissionId > 0) {
          $submissionIds[$submissionId] = $submissionId;
        }
      }
    }
  } catch (Throwable $e) {
    $submissions = [];
  }
}

$versionsBySubmission = [];
if ($pdo instanceof PDO && !empty($submissionIds)) {
  try {
    $placeholders = implode(',', array_fill(0, count($submissionIds), '?'));
    $sql = 'SELECT id, paper_submission_id, version_number, title, authors, abstract, submission_details, keywords, category, manuscript_path, manuscript_original_name, manuscript_mime, manuscript_size, status, admin_comment, reviewed_at, published_at, created_at, archived_at FROM paper_submission_versions WHERE paper_submission_id IN (' . $placeholders . ') ORDER BY paper_submission_id ASC, version_number DESC, id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($submissionIds));
    $rows = $stmt->fetchAll();
    if (is_array($rows)) {
      foreach ($rows as $row) {
        if (!is_array($row)) {
          continue;
        }

        $submissionId = (int) ($row['paper_submission_id'] ?? 0);
        if ($submissionId <= 0) {
          continue;
        }

        if (!isset($versionsBySubmission[$submissionId])) {
          $versionsBySubmission[$submissionId] = [];
        }

        $versionsBySubmission[$submissionId][] = $row;
      }
    }
  } catch (Throwable $e) {
    $versionsBySubmission = [];
  }
}

$attachmentsBySubmission = [];
if ($pdo instanceof PDO && !empty($submissionIds)) {
  try {
    $placeholders = implode(',', array_fill(0, count($submissionIds), '?'));
    $sql = 'SELECT id, paper_submission_id, category, description, original_name, file_size FROM paper_submission_attachments WHERE paper_submission_id IN (' . $placeholders . ') ORDER BY paper_submission_id ASC, id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($submissionIds));
    $rows = $stmt->fetchAll();
    if (is_array($rows)) {
      foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $submissionId = (int) ($row['paper_submission_id'] ?? 0);
        if ($submissionId <= 0) continue;
        if (!isset($attachmentsBySubmission[$submissionId])) {
          $attachmentsBySubmission[$submissionId] = [];
        }
        $attachmentsBySubmission[$submissionId][] = $row;
      }
    }
  } catch (Throwable $e) {
  }
}

$unreadAdminCounts = [];
if ($pdo instanceof PDO && !empty($submissionIds)) {
  try {
    $placeholders = implode(',', array_fill(0, count($submissionIds), '?'));
    $sql = "SELECT submission_id, COUNT(*) as count FROM submission_messages WHERE submission_id IN ($placeholders) AND sender_type = 'user' AND is_read = 0 GROUP BY submission_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($submissionIds));
    $rows = $stmt->fetchAll();
    if (is_array($rows)) {
      foreach ($rows as $row) {
        $unreadAdminCounts[(int)$row['submission_id']] = (int)$row['count'];
      }
    }
  } catch (Throwable $e) {}
}

$pendingAdminApplications = [];
if ($pdo instanceof PDO) {
  try {
    $stmt = $pdo->query("SELECT * FROM admin_applications WHERE status = 'pending' ORDER BY created_at ASC, id ASC");
    $rows = $stmt->fetchAll();
    if (is_array($rows)) {
      $pendingAdminApplications = $rows;
    }
  } catch (Throwable $e) {
    $pendingAdminApplications = [];
  }
}

$submissionCount = count($submissions);
$pendingAdminApplicationCount = count($pendingAdminApplications);
$submittedCount = 0;
$acceptedCount = 0;
$needsEditsCount = 0;
$rejectedCount = 0;

foreach ($submissions as $submissionRow) {
  $status = (string) ($submissionRow['status'] ?? 'submitted');
  if ($status === 'accepted') {
    $acceptedCount++;
  } elseif ($status === 'needs_edits') {
    $needsEditsCount++;
  } elseif ($status === 'rejected') {
    $rejectedCount++;
  } else {
    $submittedCount++;
  }
}

$adminName = trim((string) ($admin['name'] ?? 'Admin'));
$adminEmail = trim((string) ($admin['email'] ?? ''));
$adminRole = trim((string) ($admin['role'] ?? 'Administrator'));
$adminInitials = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $adminName) ?: 'AD', 0, 2));
if ($adminInitials === '') {
  $adminInitials = 'AD';
}
$adminAssignedJournalLabel = $adminCanSeeAllJournals ? 'All journals' : implode(', ', $adminAssignedJournals);

$flashType = is_array($flash) ? (string) ($flash['type'] ?? '') : '';
$flashMessage = is_array($flash) ? (string) ($flash['message'] ?? '') : '';

$submissionPayload = [];
$claimedSubmissionId = (int) ($_SESSION['admin_dashboard_redesign_claimed_submission_id'] ?? 0);
if ($claimedSubmissionId > 0) {
  unset($_SESSION['admin_dashboard_redesign_claimed_submission_id']);
}
foreach ($submissions as $submissionRow) {
  $submissionId = (int) ($submissionRow['id'] ?? 0);
  if ($claimedSubmissionId > 0 && $submissionId === $claimedSubmissionId && $gysj_current_admin_id > 0) {
    $submissionRow['assigned_admin_ids_json'] = gysj_redesign_encode_admin_ids([$gysj_current_admin_id]);
    $submissionRow['reviewed_by'] = $gysj_current_admin_id;
  }

  $submissionVersions = $versionsBySubmission[$submissionId] ?? [];
  $history = [];
  $paperJournal = gysj_redesign_extract_journal($submissionRow);
  $strictlyEligibleAdmins = [];
  $otherAdmins = [];

  foreach ($adminDirectory as $adminItem) {
    if (gysj_redesign_admin_matches_journal($adminItem, $paperJournal)) {
      $strictlyEligibleAdmins[] = $adminItem;
    } else {
      $otherAdmins[] = $adminItem;
    }
  }
  $eligibleAdmins = array_merge($strictlyEligibleAdmins, $otherAdmins);

  if (trim((string) ($submissionRow['admin_comment'] ?? '')) !== '' || trim((string) ($submissionRow['reviewed_at'] ?? '')) !== '' || (string) ($submissionRow['status'] ?? 'submitted') !== 'submitted') {
    $history[] = [
      'label' => 'Current submission',
      'kind' => 'current',
      'version_number' => (int) ($submissionRow['version'] ?? 1),
      'status' => (string) ($submissionRow['status'] ?? 'submitted'),
      'status_label' => gysj_redesign_status_label((string) ($submissionRow['status'] ?? 'submitted')),
      'status_class' => gysj_redesign_status_class((string) ($submissionRow['status'] ?? 'submitted')),
      'when' => gysj_redesign_format_datetime((string) ($submissionRow['reviewed_at'] ?? ($submissionRow['created_at'] ?? ''))),
      'comment' => trim((string) ($submissionRow['admin_comment'] ?? '')),
      'file_url' => '',
    ];
  }

  foreach ($submissionVersions as $versionRow) {
    $history[] = [
      'label' => 'Archived version ' . (int) ($versionRow['version_number'] ?? 0),
      'kind' => 'version',
      'version_number' => (int) ($versionRow['version_number'] ?? 0),
      'status' => (string) ($versionRow['status'] ?? 'submitted'),
      'status_label' => gysj_redesign_status_label((string) ($versionRow['status'] ?? 'submitted')),
      'status_class' => gysj_redesign_status_class((string) ($versionRow['status'] ?? 'submitted')),
      'when' => gysj_redesign_format_datetime((string) ($versionRow['archived_at'] ?? ($versionRow['created_at'] ?? ''))),
      'comment' => trim((string) ($versionRow['admin_comment'] ?? '')),
      'file_url' => 'paper-file.php?version_id=' . (int) ($versionRow['id'] ?? 0),
    ];
  }

  $latestVersionId = 0;
  if (!empty($submissionVersions)) {
    $latestVersionId = (int) ($submissionVersions[0]['id'] ?? 0);
  }

  $paperUrl = $latestVersionId > 0 ? 'paper-file.php?version_id=' . $latestVersionId : 'paper-file.php?id=' . $submissionId;
  $submissionPayload[] = gysj_redesign_submission_payload(array_merge($submissionRow, [
    'journal' => $paperJournal,
    'type' => gysj_redesign_extract_type($submissionRow),
    'latest_version_id' => $latestVersionId,
    'attachments' => $attachmentsBySubmission[$submissionId] ?? [],
    'eligible_admins' => $eligibleAdmins,
    'assignment_summary' => gysj_redesign_assignment_summary(array_merge($submissionRow, ['assigned_admin_ids_json' => $submissionRow['assigned_admin_ids_json'] ?? null]), $adminDirectory),
  ]), $history, $paperUrl);
}

$submissionGroups = [
  'active' => [],
  'needs_edits' => [],
  'escalated' => [],
  'rejected' => [],
];

foreach ($submissionPayload as $paper) {
  $bucket = gysj_redesign_submission_bucket((string) ($paper['status'] ?? 'submitted'));
  $submissionGroups[$bucket][] = $paper;
}

$activeSubmissionCount = count($submissionGroups['active']);
$needsEditsSubmissionCount = count($submissionGroups['needs_edits']);
$escalatedSubmissionCount = count($submissionGroups['escalated']);
$rejectedSubmissionCount = count($submissionGroups['rejected']);
$archivePreviewLimit = 5;

$pendingApplicationsPayload = [];
foreach ($pendingAdminApplications as $application) {
  $pendingApplicationsPayload[] = [
    'id' => (int) ($application['id'] ?? 0),
    'name' => trim((string) ($application['name'] ?? '')),
    'email' => trim((string) ($application['email'] ?? '')),
    'country' => trim((string) ($application['country'] ?? '')),
    'institution' => trim((string) ($application['institution'] ?? '')),
    'grade_level' => trim((string) ($application['grade_level'] ?? '')),
    'cv_path' => trim((string) ($application['reviewer_cv_path'] ?? ($application['cv_path'] ?? ''))),
    'cv_name' => trim((string) ($application['reviewer_cv_original_name'] ?? ($application['cv_original_name'] ?? ''))),
    'reviewer_profile_links' => trim((string) ($application['reviewer_profile_links'] ?? '')),
    'experience' => trim((string) ($application['reviewer_experience_text'] ?? '')),
    'reason' => trim((string) ($application['reviewer_reason_text'] ?? '')),
  ];
}

$submissionJson = json_encode($submissionPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS);
if (!is_string($submissionJson)) {
  $submissionJson = '[]';
}

$applicationJson = json_encode($pendingApplicationsPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS);
if (!is_string($applicationJson)) {
  $applicationJson = '[]';
}

$adminDirectoryPayload = array_values($adminDirectory);
$adminDirectoryJson = json_encode($adminDirectoryPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS);
if (!is_string($adminDirectoryJson)) {
  $adminDirectoryJson = '[]';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <link rel="shortcut icon" type="image/jpg" href="images/iysjournal.png">
  <title>Admin Dashboard | Global Youth Science Journal</title>
  <link href="css/media_query.css" rel="stylesheet" type="text/css">
  <link href="css/style.css" rel="stylesheet" type="text/css">
  <link href="css/bootstrap.css" rel="stylesheet" type="text/css">
  <link href="css/font-awesome.min.css" rel="stylesheet" crossorigin="anonymous">
  <link href="css/animate.css" rel="stylesheet" type="text/css">
  <link href="https://fonts.googleapis.com/css?family=Poppins" rel="stylesheet">
  <link href="css/owl.carousel.css" rel="stylesheet" type="text/css">
  <link href="css/owl.theme.default.css" rel="stylesheet" type="text/css">
  <link href="css/style_1.css" rel="stylesheet" type="text/css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <script src="js/modernizr-3.5.0.min.js"></script>
  <style>
    .admin-dashboard-shell {
      background: #f7f7f5;
      padding: 28px 0 56px;
    }

    .dashboard-wrap {
      max-width: 1240px;
      margin: 0 auto;
      padding: 0 18px;
    }

    .dashboard-hero {
      display: flex;
      align-items: flex-end;
      justify-content: space-between;
      gap: 20px;
      margin-bottom: 18px;
    }

    .dashboard-hero h1 {
      margin: 0;
      font-size: 34px;
      line-height: 1.05;
      color: #111;
      letter-spacing: -0.03em;
    }

    .eyebrow {
      margin: 0 0 8px;
      color: #666;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.2em;
      text-transform: uppercase;
    }

    .page-lead {
      margin: 10px 0 0;
      max-width: 760px;
      color: #555;
      font-size: 15px;
      line-height: 1.7;
    }

    .dashboard-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      border-radius: 0;
      padding: 10px 16px;
      font-size: 13px;
      font-weight: 700;
      text-decoration: none;
      transition: transform 0.12s ease, background-color 0.12s ease, border-color 0.12s ease, color 0.12s ease;
    }

    .dashboard-btn:hover {
      transform: translateY(-1px);
      text-decoration: none;
    }

    .dashboard-btn-primary {
      background: #111;
      color: #fff;
      border: 1px solid #111;
    }

    .dashboard-btn-primary:hover {
      background: #e5c84a;
      color: #111;
      border-color: #e5c84a;
    }

    .dashboard-btn-secondary {
      background: #fff;
      color: #111;
      border: 1px solid #d6d6d6;
    }

    .dashboard-btn-secondary:hover {
      border-color: #111;
      background: #f6f6f6;
      color: #111;
    }

    .dashboard-btn-ghost {
      background: transparent;
      color: #111;
      border: 1px solid #c7c7c7;
    }

    .dashboard-btn-ghost:hover {
      background: #fff;
      border-color: #111;
      color: #111;
    }

    .dashboard-alert {
      border-radius: 0;
      border: 1px solid #d8d8d8;
      background: #fff;
      color: #111;
      padding: 14px 16px;
      margin-bottom: 16px;
      box-shadow: none;
    }

    .dashboard-alert-success {
      border-color: #111;
    }

    .dashboard-alert-error {
      border-color: #8f8f8f;
    }

    .dashboard-grid {
      display: grid;
      grid-template-columns: minmax(0, 1fr) 320px;
      gap: 20px;
      align-items: start;
    }

    .dashboard-main {
      min-width: 0;
      display: grid;
      gap: 18px;
    }

    .dashboard-sidebar {
      display: grid;
      gap: 18px;
      position: sticky;
      top: 18px;
    }

    .dashboard-card,
    .sidebar-card {
      background: #fff;
      border: 1px solid #d8d8d8;
      border-radius: 0;
      overflow: visible;
      box-shadow: none;
    }

    .review-elevated {
      border-color: #111;
      box-shadow: none;
      overflow: visible;
      position: relative;
      z-index: 1;
    }

    .review-elevated::before {
      display: none;
    }

    .card-head {
      padding: 18px 20px;
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 16px;
      border-bottom: 1px solid #ececec;
      background: #fff;
    }

    .card-head-actions {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      justify-content: flex-end;
    }

    .review-close-x {
      width: 34px;
      height: 34px;
      border-radius: 0;
      border: 1px solid #d5d5d5;
      color: #111;
      background: #fff;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      text-decoration: none;
      font-size: 16px;
      transition: background-color 0.12s ease, border-color 0.12s ease, transform 0.12s ease;
    }

    .review-close-x:hover {
      background: #f1f1f1;
      border-color: #111;
      color: #111;
      text-decoration: none;
      transform: translateY(-1px);
    }

    .card-eyebrow {
      margin: 0 0 6px;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.16em;
      text-transform: uppercase;
      color: #777;
    }

    .card-head h2 {
      margin: 0;
      font-size: 19px;
      color: #111;
      letter-spacing: -0.02em;
    }

    .card-subtitle {
      margin: 6px 0 0;
      color: #666;
      font-size: 13px;
      line-height: 1.6;
    }

    .card-body {
      padding: 20px;
    }

    .meta-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 14px;
      margin-bottom: 18px;
    }

    .meta-tile,
    .stat-card {
      background: #fafafa;
      border: 1px solid #ececec;
      border-radius: 0;
      padding: 14px;
    }

    .meta-label,
    .stat-label,
    .info-label {
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.14em;
      color: #777;
    }

    .meta-value,
    .info-value {
      margin-top: 6px;
      font-size: 14px;
      color: #111;
      word-break: break-word;
    }

    .content-block {
      margin-top: 16px;
    }

    .content-block:first-child {
      margin-top: 0;
    }

    .content-block h3 {
      margin: 0 0 8px;
      font-size: 12px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.14em;
      color: #555;
    }

    .file-line {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
    }

    .file-name {
      color: #111;
      font-weight: 700;
      word-break: break-word;
    }

    .content-box {
      background: #fafafa;
      border: 1px solid #ececec;
      border-radius: 0;
      padding: 16px;
      white-space: pre-wrap;
      color: #111;
      line-height: 1.7;
    }

    .review-meta-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 14px;
      margin-bottom: 18px;
    }

    .review-meta-grid .meta-tile.full-width {
      grid-column: 1 / -1;
    }

    .review-meta-grid .meta-tile.compact-type {
      max-width: 240px;
    }

    .info-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 14px;
    }

    .review-summary {
      display: grid;
      gap: 18px;
    }

    .version-list {
      display: grid;
      gap: 12px;
    }

    .version-accordion {
      background: #fafafa;
      border: 1px solid #e4e4e4;
      border-radius: 0;
      overflow: visible;
    }

    .version-accordion summary {
      list-style: none;
      cursor: pointer;
      padding: 14px 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      font-weight: 700;
      color: #111;
    }

    .version-accordion summary::-webkit-details-marker {
      display: none;
    }

    .version-toggle {
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }

    .version-body {
      padding: 0 16px 16px;
      border-top: 1px solid #ececec;
    }

    .comment-box {
      display: grid;
      gap: 12px;
    }

    .comment-box textarea {
      width: 100%;
      min-height: 130px;
      border-radius: 0;
      border: 1px solid #d9d9d9;
      padding: 14px;
      font: inherit;
      resize: vertical;
      background: #fff;
      color: #111;
    }

    .review-actions {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 12px;
    }

    .version-dropdown-row {
      margin-top: 12px;
      display: grid;
      gap: 10px;
    }

    .version-dropdown-label {
      font-size: 12px;
      font-weight: 700;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: #505050;
    }

    .content-link {
      color: #111;
      font-weight: 700;
      text-decoration: underline;
      text-decoration-color: #e5c84a;
      text-underline-offset: 3px;
    }

    .content-link:hover {
      color: #111;
    }

    .action-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      .review-actions .dashboard-btn {
        justify-content: center;
        width: 100%;
      }
      gap: 10px;
      flex-wrap: wrap;
    }

    .dashboard-status {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      border-radius: 0;
      padding: 6px 10px;
      font-size: 12px;
      font-weight: 700;
      border: 1px solid transparent;
    }

    .status-submitted {
      background: #f3f3f3;
      color: #111;
      border-color: #d6d6d6;
    }

    .status-accepted {
      background: #e8f6ee;
      color: #16794c;
      border-color: #9fd5b7;
    }

    .status-rejected {
      background: #fbeaec;
      color: #b42318;
      border-color: #f1a7ac;
    }

    .status-needs_edits {
      background: #fff4db;
      color: #9a5b00;
      border-color: #e1c15a;
    }

    .review-status-pill {
      display: inline-flex;
      align-items: center;
      border-radius: 0;
      padding: 6px 10px;
      border: 1px solid transparent;
      font-size: 12px;
      font-weight: 700;
      line-height: 1.2;
    }

    .review-status-submitted {
      background: #f3f3f3;
      color: #111;
      border-color: #d6d6d6;
    }

    .review-status-accepted {
      background: #e8f6ee;
      color: #16794c;
      border-color: #9fd5b7;
    }

    .review-status-rejected {
      background: #fbeaec;
      color: #b42318;
      border-color: #f1a7ac;
    }

    .review-status-needs_edits {
      background: #fff4db;
      color: #9a5b00;
      border-color: #e1c15a;
    }

    .review-comment-tile {
      grid-column: 1 / -1;
    }

    .comment-history-box {
      margin-top: 6px;
      min-height: 140px;
      border-radius: 0;
      border: 1px solid #e0e0e0;
      background: #fffef6;
      padding: 14px;
      line-height: 1.7;
      white-space: pre-wrap;
      color: #111;
    }

    .table-toolbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
    }

    .table-count {
      color: #555;
      font-size: 12px;
      margin-top: 6px;
    }

    .dashboard-table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0;
    }

    .dashboard-table th,
    .dashboard-table td {
      padding: 14px 12px;
      border-top: 1px solid #ececec;
      vertical-align: top;
    }

    .dashboard-table thead th {
      border-top: none;
      background: #fcfcfc;
      color: #777;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.14em;
    }

    .dashboard-table tbody tr:hover {
      background: #fafafa;
    }

    .status-escalated {
      background: #eef2ff;
      color: #3b4cca;
      border-color: #c7d2fe;
    }

    .table-actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }

    .small-muted {
      color: #666;
      font-size: 12px;
    }

    .profile-top {
      padding: 20px;
      text-align: center;
      border-bottom: 1px solid #ececec;
      background: #fff;
    }

    .profile-avatar {
      width: 54px;
      height: 54px;
      border-radius: 0;
      background: #111;
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 12px;
      font-weight: 700;
    }

    .profile-name {
      margin: 0;
      font-size: 18px;
      font-weight: 700;
      color: #111;
    }

    .profile-role {
      margin: 4px 0 0;
      font-size: 13px;
      color: #666;
    }

    .profile-body {
      padding: 18px 20px;
      display: grid;
      gap: 14px;
    }

    .sidebar-note {
      padding: 16px 20px 20px;
      border-top: 1px solid #ececec;
      background: #fafafa;
      color: #555;
      font-size: 13px;
      line-height: 1.6;
    }

    .empty-state {
      padding: 32px 18px;
      text-align: center;
      color: #666;
    }

    .empty-state i {
      display: block;
      margin-bottom: 10px;
      font-size: 28px;
      color: #111;
    }

    .empty-state h3 {
      margin: 0 0 6px;
      font-size: 18px;
      color: #111;
    }

    .empty-state p {
      margin: 0 auto;
      max-width: 560px;
    }

    .submission-archive-stack {
      display: grid;
      gap: 16px;
    }

    .submission-archive {
      border: 1px solid #d8d8d8;
      background: #fff;
    }

    .submission-archive[data-submission-group="needs_edits"] {
      border-color: #e1c15a;
      background: #fffdf2;
    }

    .submission-archive[data-submission-group="rejected"] {
      border-color: #f1a7ac;
      background: #fff7f8;
    }

    .submission-archive[data-submission-group="needs_edits"] .submission-archive-header {
      background: #fff4db;
      border-bottom-color: #e1c15a;
    }

    .submission-archive[data-submission-group="rejected"] .submission-archive-header {
      background: #fbeaec;
      border-bottom-color: #f1a7ac;
    }

    .submission-archive[data-submission-group="needs_edits"] .submission-archive-count {
      background: #fff4db;
      border-color: #e1c15a;
      color: #9a5b00;
    }

    .submission-archive[data-submission-group="rejected"] .submission-archive-count {
      background: #fbeaec;
      border-color: #f1a7ac;
      color: #b42318;
    }

    .submission-archive-header {
      padding: 16px 20px;
      border-bottom: 1px solid #ececec;
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 12px;
      background: #fff;
    }

    .submission-archive-header h3 {
      margin: 0;
      font-size: 18px;
      color: #111;
      letter-spacing: -0.02em;
    }

    .submission-archive-header p {
      margin: 6px 0 0;
      color: #666;
      font-size: 13px;
      line-height: 1.55;
    }

    .submission-archive-count {
      flex-shrink: 0;
      border: 1px solid #d8d8d8;
      background: #fafafa;
      color: #111;
      padding: 6px 10px;
      font-size: 12px;
      font-weight: 700;
      white-space: nowrap;
    }

    .submission-archive-body {
      padding: 18px 20px 20px;
    }

    .submission-archive-body + .submission-archive-body {
      border-top: 1px solid #ececec;
    }

    .submission-archive-empty {
      padding: 16px 0 4px;
      color: #666;
      font-size: 13px;
      font-style: italic;
    }

    .submission-row-hidden {
      display: none;
    }

    .archive-view-more {
      margin-top: 12px;
      justify-content: center;
      width: 100%;
    }

    .archive-view-more.is-hidden {
      display: none;
    }

    .bulk-actions-bar {
      position: static;
      background: var(--white);
      border: 1px solid var(--border-light);
      box-shadow: 0 10px 40px rgba(17,17,17,0.15);
      border-radius: 0;
      padding: 12px 20px;
      display: none;
      align-items: center;
      gap: 16px;
      z-index: 5000;
      margin: 12px 24px 20px;
    }
    .bulk-actions-bar.visible {
      display: flex;
    }
    .bulk-actions-count {
      font-weight: 600;
      font-size: 14px;
      color: var(--ink);
    }
    .bulk-actions-btns {
      display: flex;
      gap: 8px;
    }

    @media (max-width: 991px) {
      .dashboard-grid {
        grid-template-columns: 1fr;
      }

      .dashboard-sidebar {
        position: static;
      }

      .action-grid,
      .meta-grid {
        grid-template-columns: 1fr;
      }

      .review-meta-grid,
      .info-grid,
      .review-actions {
        grid-template-columns: 1fr;
      }

      .dashboard-hero {
        flex-direction: column;
        align-items: flex-start;
      }

    }

    @media (max-width: 767px) {
      .dashboard-wrap {
        padding: 0 12px;
      }

      .dashboard-hero h1 {
        font-size: 28px;
      }

      .card-head,
      .card-body {
        padding: 16px;
      }

      .dashboard-table th,
      .dashboard-table td {
        padding: 12px 10px;
      }
    }

  </style>
<style>
    *, *::before, *::after { box-sizing: border-box; }

    :root {
      --bg: #f7f7f5;
      --white: #fff;
      --ink: #111;
      --ink-2: #333;
      --muted: #666;
      --muted-light: #999;
      --border: #d8d8d8;
      --border-light: #ececec;
      --surface: #fafafa;
      --accent: #e5c84a;
      --accent-dark: #c9a824;

      --acc-bg: #e8f6ee; --acc-fg: #16794c; --acc-bd: #9fd5b7;
      --rej-bg: #fbeaec; --rej-fg: #b42318; --rej-bd: #f1a7ac;
      --ned-bg: #fff4db; --ned-fg: #9a5b00; --ned-bd: #e1c15a;
      --sub-bg: #f3f3f3; --sub-fg: #111; --sub-bd: #d6d6d6;
      --esc-bg: #eef2ff; --esc-fg: #3b4cca; --esc-bd: #c7d2fe;

      --sans: 'Poppins', sans-serif;
      --mono: 'Poppins', sans-serif;
      --display: 'Poppins', sans-serif;
    }

    html, body { height: 100%; overflow-x: hidden; }
    body { font-family: var(--sans); background: var(--bg); color: var(--ink); margin: 0; }

    .db-hero,
    .stats-row,
    .db-grid {
      display: none;
    }

    .preview-shell {
      display: grid;
      gap: 18px;
    }

    .preview-topbar {
      display: none;
    }

    .preview-topbar .journal-select {
      min-width: 270px;
      border: 1px solid var(--border);
      background: #fbfbfa;
      color: var(--ink);
      font-size: 13px;
      padding: 9px 12px;
      outline: none;
      font-family: var(--sans);
    }

    .mode-tabs {
      display: flex;
      gap: 10px;
      align-items: center;
      flex-wrap: wrap;
      justify-content: center;
      flex: 1;
    }

    .mode-tab {
      font-size: 13px;
      color: var(--muted);
      text-decoration: none;
      font-weight: 600;
      padding: 8px 2px;
      border-bottom: 2px solid transparent;
    }

    .mode-tab.active {
      color: var(--ink);
      border-bottom-color: var(--ink);
    }

    .settings-link {
      font-size: 13px;
      color: var(--ink);
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-weight: 600;
      padding: 8px 0;
    }

    .preview-tabs {
      display: none;
    }

    .preview-tab {
      padding: 14px 18px;
      border: 0;
      border-top: 3px solid transparent;
      background: transparent;
      color: var(--muted);
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      transition: background 0.12s ease, color 0.12s ease, border-color 0.12s ease;
      text-decoration: none;
    }

    .preview-tab.active {
      color: var(--ink);
      border-top-color: #34b27a;
      background: #fff;
    }

    .workspace-panel {
      background: var(--white);
      border: 1px solid var(--border);
      box-shadow: 0 10px 30px rgba(17, 17, 17, 0.05);
    }

    .workspace-panel-head {
      padding: 16px 18px 14px;
      border-bottom: 1px solid var(--border-light);
      display: grid;
      gap: 12px;
    }

    .workspace-title-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      flex-wrap: wrap;
    }

    .workspace-kicker {
      display: none;
    }

    .workspace-heading {
      margin: 0;
      font-size: 18px;
      color: var(--ink);
      font-family: var(--display);
    }

    .workspace-subtitle {
      margin: 6px 0 0;
      font-size: 12px;
      color: var(--muted);
      line-height: 1.5;
    }

    .workspace-actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      align-items: center;
    }

    .workspace-actions .workspace-link {
      font-size: 13px;
      color: #4b6277;
      text-decoration: none;
      font-weight: 500;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }

    .workspace-actions .workspace-link:hover { color: var(--ink); }

    .workspace-toolbar {
      padding: 10px 18px 16px;
      display: flex;
      gap: 12px;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      border-bottom: 1px solid var(--border-light);
      background: #fff;
    }

    .workspace-toolbar-left,
    .workspace-toolbar-right {
      display: flex;
      gap: 10px;
      align-items: center;
      flex-wrap: wrap;
    }

    .bulk-select,
    .view-select {
      height: 36px;
      border: 1px solid var(--border);
      background: #fafafa;
      color: var(--muted);
      padding: 0 12px;
      font-size: 13px;
      outline: none;
      min-width: 180px;
      font-family: var(--sans);
    }

    .view-select { min-width: 160px; }

    .search-wrap.scholastica {
      min-width: 280px;
    }

    .search-wrap.scholastica .search-input {
      width: 100%;
      min-width: 280px;
      border-radius: 0;
    }

    .preview-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      height: 36px;
      padding: 0 16px;
      border: 1px solid transparent;
      cursor: pointer;
      font-size: 13px;
      font-weight: 700;
      text-decoration: none;
      transition: transform 0.12s ease, opacity 0.12s ease, border-color 0.12s ease;
      font-family: var(--sans);
    }

    .preview-btn:hover { transform: translateY(-1px); }
    .preview-btn.primary { background: #efb9a7; color: #fff; }
    .preview-btn.primary:hover { opacity: 0.95; }
    .preview-btn.secondary { background: #2e7ef7; color: #fff; }
    .preview-btn.secondary:hover { opacity: 0.95; }

    .workspace-table-wrap {
      overflow-x: hidden;
      background: #fff;
    }

    .workspace-table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0;
      min-width: 0;
      table-layout: fixed;
    }

    .workspace-table thead th {
      background: #262626;
      color: #fff;
      font-size: 12px;
      letter-spacing: 0.03em;
      text-transform: none;
      padding: 11px 10px;
      border-right: 1px solid rgba(255, 255, 255, 0.08);
      font-family: var(--sans);
      white-space: nowrap;
    }

    .workspace-table thead th:last-child { border-right: none; }

    .workspace-table tbody td {
      padding: 14px 10px;
      border-top: 1px solid #ececec;
      font-size: 13px;
      vertical-align: top;
      background: #fff;
    }

    .workspace-table tbody tr:nth-child(even) td { background: #fcfcfc; }
    .workspace-table tbody tr:hover td { background: #f6f8fa; }

    .workspace-row {
      cursor: pointer;
    }

    .workspace-row:focus-visible {
      outline: 2px solid var(--ink);
      outline-offset: -2px;
    }

    .checkbox-cell {
      width: 30px;
      text-align: center;
    }

    .select-box {
      width: 16px;
      height: 16px;
      accent-color: #111;
      vertical-align: middle;
    }

    .submitted-date {
      font-size: 13px;
      color: var(--ink-2);
      white-space: nowrap;
    }

    .submitted-mini {
      font-size: 11px;
      color: var(--muted);
      margin-top: 3px;
      font-family: var(--mono);
    }

    .author-cell .author-name {
      color: #3b6fa3;
      font-weight: 500;
      font-size: 13px;
    }

    .workspace-paper-title {
      color: #5a97d4;
      font-size: 13px;
      font-weight: 500;
      line-height: 1.45;
      margin: 0 0 4px;
      cursor: pointer;
    }

    .workspace-paper-meta {
      font-size: 11px;
      color: var(--muted);
      display: inline-flex;
      gap: 8px;
      flex-wrap: wrap;
    }

    .workspace-paper-tag {
      display: inline-flex;
      align-items: center;
      padding: 2px 6px;
      background: #8e98a6;
      color: #fff;
      font-size: 10px;
      border-radius: 0;
      font-family: var(--mono);
      margin-top: 6px;
    }

    .assigned-pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 4px 8px 4px 4px;
      border: 1px solid #d8d8d8;
      background: #fff;
      min-width: 120px;
      max-width: 180px;
    }

    .assignment-pill {
      border: 1px solid #cfd6dd;
      background: #f7f9fb;
      color: var(--ink);
      width: 100%;
      min-width: 0;
      max-width: 220px;
      padding: 6px 10px;
      text-align: left;
      display: grid;
      gap: 3px;
      cursor: pointer;
      transition: border-color 0.12s ease, background 0.12s ease, transform 0.12s ease;
    }

    .assignment-pill:hover {
      border-color: #9aa7b3;
      transform: translateY(-1px);
    }

    .assignment-pill.assignment-unclaimed {
      background: #eef1f4;
      color: #6d7883;
    }

    .assignment-pill.assignment-shared {
      background: #fafafa;
      color: #5f6a75;
    }

    .assignment-pill.assignment-owned {
      background: #edf7f2;
      border-color: #a8d3bb;
      color: #176a43;
    }

    .assignment-pill-label {
      font-size: 12px;
      font-weight: 700;
      line-height: 1.35;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .assignment-pill-subcopy {
      font-size: 10px;
      line-height: 1.35;
      color: inherit;
      opacity: 0.82;
    }

    .assignment-section {
      display: grid;
      gap: 12px;
    }

    .assignment-form {
      display: grid;
      gap: 12px;
    }

    .assignment-tools {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      align-items: center;
    }

    .assignment-search {
      flex: 1 1 240px;
      min-width: 220px;
      border: 1px solid var(--border);
      background: var(--white);
      padding: 10px 12px;
      font-size: 13px;
      outline: none;
      font-family: var(--sans);
    }

    .assignment-summary {
      font-size: 13px;
      color: var(--ink-2);
      line-height: 1.6;
    }

    .assignment-chip-list {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }

    .assignment-chip {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 9px;
      background: #eef6ff;
      border: 1px solid #cfe0f5;
      color: #235081;
      font-size: 12px;
      font-weight: 600;
    }

    .assignment-chip.current {
      background: #edf7f2;
      border-color: #b8dfc7;
      color: #176a43;
    }

    .assignment-choices {
      display: grid;
      gap: 8px;
      max-height: 220px;
      overflow: auto;
      border: 1px solid var(--border-light);
      background: #fff;
      padding: 10px;
    }

    .assignment-choice {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 10px 12px;
      border: 1px solid var(--border-light);
      background: var(--surface);
      cursor: pointer;
    }

    .assignment-choice:hover {
      border-color: #c9d4dd;
      background: #fff;
    }

    .assignment-choice input {
      margin: 0;
      accent-color: var(--ink);
    }

    .assignment-choice.is-selected {
      border-color: #9dc9b5;
      background: #edf7f2;
    }

    .assignment-choice-meta {
      min-width: 0;
      display: grid;
      gap: 2px;
    }

    .assignment-choice-name {
      font-size: 13px;
      font-weight: 600;
      color: var(--ink);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .assignment-choice-email {
      font-size: 11px;
      color: var(--muted);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .assignment-empty {
      padding: 12px;
      font-size: 12px;
      color: var(--muted);
      text-align: center;
      border: 1px dashed var(--border);
      background: #fafafa;
    }

    .assignment-actions-bottom {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      justify-content: flex-end;
    }

    .assigned-avatar {
      width: 24px;
      height: 24px;
      border-radius: 50%;
      background: linear-gradient(135deg, #d6dce2, #b5c1cd);
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 11px;
      font-weight: 700;
      flex-shrink: 0;
    }

    .assigned-text {
      min-width: 0;
      font-size: 12px;
      color: #3a3f45;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .status-stack {
      display: grid;
      gap: 3px;
    }

    .status-subcopy {
      font-size: 11px;
      color: var(--muted);
      line-height: 1.45;
    }

    .reviews-summary {
      display: block;
    }

    .comment-snippet {
      display: -webkit-box;
      -webkit-box-orient: vertical;
      -webkit-line-clamp: 2;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: normal;
      line-height: 1.45;
      font-size: 12px;
      color: var(--ink-2);
      max-width: 240px;
    }

    .comment-snippet.empty {
      color: var(--muted);
      font-style: italic;
    }

    .rating-cell {
      font-size: 13px;
      color: #a67717;
      letter-spacing: 0.03em;
      white-space: nowrap;
      font-family: var(--mono);
    }

    .workspace-empty {
      padding: 34px 18px;
      text-align: center;
      color: var(--muted);
      display: none;
      border-top: 1px solid var(--border-light);
    }

    .workspace-empty .fa {
      display: block;
      font-size: 24px;
      color: var(--ink);
      margin-bottom: 10px;
    }

    .workspace-empty h3 {
      margin: 0 0 4px;
      font-size: 17px;
      color: var(--ink);
      font-family: var(--display);
    }

    .workspace-empty p {
      margin: 0 auto;
      max-width: 360px;
      font-size: 13px;
      line-height: 1.6;
    }

    .preview-footnote {
      padding: 10px 18px 16px;
      font-size: 12px;
      color: var(--muted);
      border-top: 1px solid var(--border-light);
      background: #fff;
    }

    .preview-footnote strong { color: var(--ink); }

 

    .db-shell { background: var(--bg); padding: 28px 0 64px; }
    .db-wrap { max-width: 1280px; margin: 0 auto; padding: 0 24px; }

    .dashboard-alert { border-radius: 0; border: 1px solid var(--border); background: var(--white); color: var(--ink); margin-bottom: 18px; }

    .db-hero { margin-bottom: 22px; }
    .db-eyebrow {
      font-size: 10px; font-weight: 700; letter-spacing: 0.22em;
      text-transform: uppercase; color: var(--muted); margin: 0 0 8px;
      font-family: var(--mono);
    }
    .db-title {
      font-family: var(--display); font-size: 36px; color: var(--ink);
      margin: 0; line-height: 1.05;
    }
    .db-lead { color: var(--muted); font-size: 14px; margin: 8px 0 0; line-height: 1.7; max-width: 700px; }

    .stats-row { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; margin-bottom: 22px; }
    .stat-tile {
      background: var(--white); border: 1px solid var(--border);
      padding: 16px 18px; cursor: default; transition: border-color 0.15s, transform 0.12s;
    }
    .stat-tile.clickable { cursor: pointer; }
    .stat-tile.clickable:hover { border-color: var(--ink); transform: translateY(-1px); }
    .stat-tile.active { background: var(--ink); border-color: var(--ink); }
    .stat-tile.active .stat-n { color: var(--accent); }
    .stat-tile.active .stat-lbl { color: #bbb; }
    .stat-n { font-family: var(--display); font-size: 30px; color: var(--ink); line-height: 1; margin: 0 0 5px; }
    .stat-lbl {
      font-size: 10px; font-weight: 700; text-transform: uppercase;
      letter-spacing: 0.18em; color: var(--muted); font-family: var(--mono);
    }
    .stat-dot { display: inline-block; width: 6px; height: 6px; border-radius: 50%; margin-right: 5px; vertical-align: middle; }

    .db-grid { display: grid; grid-template-columns: minmax(0, 1fr) 296px; gap: 20px; align-items: start; }
    .db-main { display: grid; gap: 20px; min-width: 0; }
    .db-sidebar { display: grid; gap: 18px; position: sticky; top: 18px; }

    .db-card { background: var(--white); border: 1px solid var(--border); }
    .card-head {
      padding: 18px 20px; border-bottom: 1px solid var(--border-light);
      display: flex; align-items: flex-start; justify-content: space-between; gap: 16px;
    }
    .card-eyebrow {
      font-size: 10px; font-weight: 700; letter-spacing: 0.18em;
      text-transform: uppercase; color: var(--muted); margin: 0 0 5px; font-family: var(--mono);
    }
    .card-head h2 { margin: 0; font-size: 18px; font-family: var(--display); color: var(--ink); }
    .card-sub { font-size: 13px; color: var(--muted); margin: 5px 0 0; line-height: 1.55; }
    .card-body { padding: 20px; }

    .table-toolbar {
      display: flex; align-items: center; justify-content: space-between;
      gap: 12px; margin-bottom: 14px; flex-wrap: wrap;
    }
    .filter-tabs { display: flex; gap: 2px; flex-wrap: wrap; }
    .filter-tab {
      padding: 6px 12px; font-size: 11.5px; font-weight: 600; background: none;
      border: 1px solid transparent; color: var(--muted); cursor: pointer;
      transition: all 0.12s; font-family: var(--sans); letter-spacing: 0;
    }
    .filter-tab:hover { color: var(--ink); border-color: var(--border); background: var(--surface); }
    .filter-tab.active { color: var(--white); background: var(--ink); border-color: var(--ink); }

    .search-wrap { position: relative; }
    .search-wrap .fa { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--muted-light); font-size: 12px; }
    .search-input {
      padding: 7px 10px 7px 28px; border: 1px solid var(--border); background: var(--white);
      font-size: 13px; font-family: var(--sans); color: var(--ink); width: 240px;
      outline: none; transition: border-color 0.12s;
    }
    .search-input:focus { border-color: var(--ink); }

    .db-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 13px; }
    .db-table th {
      padding: 10px 12px; text-align: left; font-size: 10px; font-weight: 700;
      text-transform: uppercase; letter-spacing: 0.16em; color: var(--muted);
      background: #fcfcfc; border-bottom: 1px solid var(--border-light);
      font-family: var(--mono); white-space: nowrap;
    }
    .db-table td { padding: 13px 12px; border-top: 1px solid var(--border-light); vertical-align: top; }
    .db-table tbody tr { transition: background 0.12s; }
    .db-table tbody tr:hover { background: #f9f9f7; }

    .paper-title { font-weight: 600; color: var(--ink); line-height: 1.4; }
    .paper-no-title { color: var(--muted-light); font-style: italic; font-weight: 400; }
    .paper-mini { margin-top: 4px; font-size: 11px; color: var(--muted); }
    .tracking-id { font-family: var(--mono); font-size: 11px; color: var(--muted); }
    .author-name { font-weight: 500; }
    .author-email { font-size: 11px; color: var(--muted); margin-top: 2px; }
    .date-cell { font-size: 11px; color: var(--muted); font-family: var(--mono); white-space: nowrap; }

    .pending-dot {
      display: inline-block; width: 7px; height: 7px; border-radius: 50%;
      background: #aaa; margin-right: 6px; vertical-align: middle;
      animation: blink 2s ease-in-out infinite;
    }
    @keyframes blink { 0%,100%{opacity:1} 50%{opacity:0.35} }

    .db-status {
      display: inline-flex; align-items: center; justify-content: center; text-align: center; gap: 5px; padding: 4px 9px;
      font-size: 11px; font-weight: 700; border: 1px solid transparent; line-height: 1.2;
    }
    .status-submitted { background: var(--sub-bg); color: var(--sub-fg); border-color: var(--sub-bd); }
    .status-accepted  { background: var(--acc-bg); color: var(--acc-fg); border-color: var(--acc-bd); }
    .status-rejected  { background: var(--rej-bg); color: var(--rej-fg); border-color: var(--rej-bd); }
    .status-needs_edits { background: var(--ned-bg); color: var(--ned-fg); border-color: var(--ned-bd); }
    .status-escalated { background: var(--esc-bg); color: var(--esc-fg); border-color: var(--esc-bd); }

    .db-btn {
      display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px;
      font-size: 12px; font-weight: 700; border: 1px solid var(--border);
      background: var(--white); color: var(--ink); cursor: pointer;
      text-decoration: none; transition: all 0.12s; font-family: var(--sans); letter-spacing: 0.01em;
    }
    .db-btn:hover { border-color: var(--ink); text-decoration: none; color: var(--ink); transform: translateY(-1px); }
    .db-btn-primary { background: var(--ink); color: var(--white); border-color: var(--ink); }
    .db-btn-primary:hover { background: var(--accent); color: var(--ink); border-color: var(--accent); }
    .db-btn-success { color: var(--acc-fg); border-color: var(--acc-bd); }
    .db-btn-success:hover { background: var(--acc-bg); border-color: var(--acc-fg); color: var(--acc-fg); }
    .db-btn-warn { color: var(--ned-fg); border-color: var(--ned-bd); }
    .db-btn-warn:hover { background: var(--ned-bg); border-color: var(--ned-fg); }
    .db-btn-danger { color: var(--rej-fg); border-color: var(--rej-bd); }
    .db-btn-danger:hover { background: var(--rej-bg); border-color: var(--rej-fg); color: var(--rej-fg); }
    .tbl-actions { display: flex; gap: 6px; flex-wrap: wrap; }

    .empty-state { padding: 40px 20px; text-align: center; color: var(--muted); }
    .empty-state i { font-size: 26px; color: var(--ink); display: block; margin-bottom: 12px; }
    .empty-state h3 { font-size: 17px; color: var(--ink); margin: 0 0 6px; font-family: var(--display); }
    .empty-state p { font-size: 13px; max-width: 380px; margin: 0 auto; line-height: 1.6; }

    .sidebar-card { background: var(--white); border: 1px solid var(--border); }
    .profile-top { padding: 20px; text-align: center; border-bottom: 1px solid var(--border-light); }
    .profile-avatar {
      width: 50px; height: 50px; background: var(--ink); color: var(--white);
      display: flex; align-items: center; justify-content: center;
      font-weight: 700; font-size: 16px; margin: 0 auto 12px;
    }
    .profile-name { font-size: 17px; font-weight: 700; color: var(--ink); margin: 0; }
    .profile-role { font-size: 11px; color: var(--muted); margin: 4px 0 0; font-family: var(--mono); text-transform: uppercase; letter-spacing: 0.1em; }
    .profile-body { padding: 16px 20px; display: grid; gap: 11px; }
    .info-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
    .info-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.14em; color: var(--muted); font-family: var(--mono); white-space: nowrap; padding-top: 1px; }
    .info-value { color: var(--ink); font-size: 12px; text-align: right; }

    .sidebar-section { padding: 16px 20px; border-top: 1px solid var(--border-light); }
    .sidebar-section-title { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.16em; color: var(--muted); font-family: var(--mono); margin: 0 0 12px; }
    .checklist { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
    .checklist li { display: flex; gap: 10px; font-size: 12.5px; color: var(--ink-2); line-height: 1.5; }
    .step-n {
      flex-shrink: 0; width: 20px; height: 20px; background: var(--ink); color: var(--white);
      font-size: 10px; font-weight: 700; display: flex; align-items: center;
      justify-content: center; font-family: var(--mono); margin-top: 1px;
    }
    .criteria-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
    .criteria-list li { font-size: 12px; color: var(--ink-2); display: flex; gap: 8px; line-height: 1.55; }
    .criteria-list li::before { content: '-'; color: var(--accent-dark); font-weight: 700; flex-shrink: 0; }

    .drawer-backdrop {
      position: fixed; inset: 0; background: rgba(17,17,17,0.45); z-index: 6000;
      opacity: 0; pointer-events: none; transition: opacity 0.25s ease;
    }
    .drawer-backdrop.open { opacity: 1; pointer-events: auto; }

    .review-drawer {
      position: fixed; top: 0; right: 0; height: 100vh; width: 100vw; max-width: 100vw;
      background: var(--white); border-left: 2px solid var(--ink); z-index: 6100;
      display: flex; flex-direction: column;
      transform: translateX(100%);
      transition: transform 0.28s cubic-bezier(0.32, 0, 0.08, 1);
      overflow: hidden;
    }
    .review-drawer.open { transform: translateX(0); }
    .drawer-header {
      padding: 14px 20px; border-bottom: 1px solid var(--border-light);
      display: flex; align-items: center; justify-content: space-between; gap: 12px;
      flex-shrink: 0; background: var(--white);
    }
    .drawer-header-left { display: flex; align-items: center; gap: 10px; min-width: 0; overflow: hidden; }
    .drawer-tracking { font-family: var(--mono); font-size: 11px; color: var(--muted); white-space: nowrap; }
    .drawer-close {
      width: 32px; height: 32px; border: 1px solid var(--border); background: none;
      display: flex; align-items: center; justify-content: center; cursor: pointer;
      color: var(--ink); font-size: 15px; transition: all 0.12s; flex-shrink: 0;
    }
    .drawer-close:hover { background: var(--surface); border-color: var(--ink); }
    .drawer-body { flex: 1; overflow-y: auto; scroll-behavior: smooth; -webkit-overflow-scrolling: touch; }
    .drawer-section { padding: 18px 22px; border-bottom: 1px solid var(--border-light); }
    .drawer-section:last-child { border-bottom: none; }
    .dsec-label {
      font-size: 10px; font-weight: 700; letter-spacing: 0.18em; text-transform: uppercase;
      color: var(--muted); font-family: var(--mono); margin: 0 0 12px;
    }
    .drawer-paper-title { font-family: var(--display); font-size: 22px; color: var(--ink); line-height: 1.3; margin: 0 0 12px; }
    .drawer-meta-row { display: flex; gap: 20px; flex-wrap: wrap; font-size: 13px; color: var(--muted); }
    .drawer-meta-row strong { color: var(--ink); }

    .drawer-kv-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
    }
    .drawer-kv {
      border: 1px solid var(--border-light);
      background: var(--surface);
      padding: 12px 14px;
      min-width: 0;
    }
    .drawer-kv-label { font-size: 10px; font-weight: 700; letter-spacing: 0.16em; text-transform: uppercase; color: var(--muted); font-family: var(--mono); }
    .drawer-kv-value { margin-top: 6px; font-size: 13px; color: var(--ink-2); line-height: 1.55; }

    .chip-list { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
    .chip {
      display: inline-flex; align-items: center; padding: 4px 8px;
      border: 1px solid var(--border); background: var(--white);
      font-size: 11px; font-family: var(--mono); color: var(--ink-2);
    }

    .abstract-box {
      background: var(--surface); border: 1px solid var(--border-light);
      padding: 14px; font-size: 13px; line-height: 1.78; color: var(--ink-2);
    }
    .abstract-box.no-abstract { color: var(--muted-light); font-style: italic; }

    .rubric-grid { display: grid; gap: 14px; }
    .rubric-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
    .rubric-left { min-width: 0; }
    .rubric-criterion { font-size: 13px; font-weight: 600; color: var(--ink); }
    .rubric-desc { font-size: 11px; color: var(--muted); margin: 3px 0 0; line-height: 1.5; }
    .rubric-right { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
    .rubric-scores { display: flex; gap: 4px; }
    .score-btn {
      width: 44px; height: 36px; border: 1px solid var(--border); background: var(--white);
      font-size: 14px; font-weight: 700; font-family: var(--mono); color: var(--muted);
      cursor: pointer; transition: all 0.1s; display: flex; align-items: center; justify-content: center;
    }
    .score-btn:hover { border-color: var(--ink); color: var(--ink); }
    .score-btn.selected { background: var(--ink); border-color: var(--ink); color: var(--white); }
    .score-label-text { font-size: 10px; color: var(--muted); font-family: var(--mono); min-width: 58px; text-align: right; }
    .overall-bar {
      margin-top: 14px; padding: 14px 16px; background: #f8fafc; color: #0f172a; border: 1px solid #e2e8f0; border-radius: 0;
      display: flex; align-items: center; justify-content: space-between;
    }
    .overall-label-txt { font-size: 10px; font-weight: 700; letter-spacing: 0.16em; text-transform: uppercase; font-family: var(--mono); color: #888; }
    .overall-score-num { font-family: var(--display); font-size: 26px; color: var(--accent); }
    .overall-score-max { font-size: 14px; color: #555; }
    .overall-level { font-size: 11px; color: #888; font-family: var(--mono); margin-top: 3px; }

    .review-form { display: block; }
    .db-label {
      font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.16em;
      color: var(--muted); font-family: var(--mono); display: block; margin-bottom: 7px;
    }
    .db-label .lnote { font-size: 9px; font-weight: 400; text-transform: none; letter-spacing: 0; color: var(--muted-light); }
    .db-textarea {
      width: 100%; border: 1px solid var(--border); background: var(--white);
      padding: 12px 14px; font-size: 13px; font-family: var(--sans); color: var(--ink);
      resize: vertical; min-height: 96px; outline: none; transition: border-color 0.12s; line-height: 1.65;
    }
    .db-textarea:focus { border-color: var(--ink); }
    .db-textarea.internal { background: #fffef6; border-color: var(--ned-bd); }
    .db-textarea.internal:focus { border-color: var(--ned-fg); }

    .comment-history { display: grid; gap: 10px; }
    .comment-item { background: var(--surface); border: 1px solid var(--border-light); padding: 12px 14px; }
    .comment-meta { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; font-size: 11px; flex-wrap: wrap; }
    .comment-author { font-weight: 700; color: var(--ink); }
    .comment-date { color: var(--muted); font-family: var(--mono); }
    .comment-badge { margin-left: auto; }
    .comment-text { font-size: 13px; color: var(--ink-2); line-height: 1.65; white-space: pre-wrap; }

    .drawer-footer {
      padding: 16px 22px; border-top: 2px solid var(--ink); display: grid;
      gap: 10px; flex-shrink: 0; background: var(--white);
    }
    .footer-label { font-size: 10px; font-weight: 700; letter-spacing: 0.18em; text-transform: uppercase; font-family: var(--mono); color: var(--muted); }
    .decision-btns { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }
    .decision-btns .db-btn { justify-content: center; padding: 12px; font-size: 13px; }

    .modal-veil {
      position: fixed; inset: 0; background: rgba(17,17,17,0.6); z-index: 9999;
      display: flex; align-items: center; justify-content: center;
      opacity: 0; pointer-events: none; transition: opacity 0.2s ease;
    }
    .modal-veil.open { opacity: 1; pointer-events: auto; }
    .modal-box {
      background: var(--white); border: 2px solid var(--ink); padding: 28px;
      max-width: 440px; width: 90%; transform: scale(0.97); transition: transform 0.2s ease;
    }
    .modal-veil.open .modal-box { transform: scale(1); }
    .modal-box h3 { font-family: var(--display); font-size: 20px; margin: 0 0 10px; }
    .modal-box p { font-size: 13px; color: var(--muted); line-height: 1.65; margin: 0 0 20px; }
    .modal-btns { display: flex; gap: 10px; justify-content: flex-end; }

    #toast {
      position: fixed; bottom: 28px; left: 50%; transform: translateX(-50%) translateY(16px);
      background: var(--ink); color: var(--white); padding: 12px 20px; font-size: 13px;
      font-family: var(--sans); font-weight: 600; opacity: 0; transition: all 0.28s ease;
      z-index: 2000; pointer-events: none; border-left: 4px solid var(--accent);
      white-space: nowrap;
    }

    .applications-grid { display: grid; gap: 16px; }
    .application-card {
      border: 1px solid rgba(17, 17, 17, 0.1);
      background: var(--white);
      border-radius: 0;
      overflow: hidden;
      box-shadow: 0 10px 26px rgba(17, 17, 17, 0.04);
    }
    .application-card__header {
      padding: 18px 20px 16px;
      border-bottom: 1px solid var(--border-light);
      display: grid;
      gap: 14px;
    }
    .application-card__top {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 12px;
    }
    .application-card__identity { display: flex; align-items: center; gap: 12px; min-width: 0; }
    .application-card__avatar {
      width: 40px;
      height: 40px;
      border-radius: 0;
      background: #31486d;
      color: #fff;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 13px;
      font-weight: 700;
      flex-shrink: 0;
      letter-spacing: 0.04em;
    }
    .application-card__name {
      font-size: 15px;
      font-weight: 700;
      color: var(--ink);
      margin: 0;
      line-height: 1.2;
    }
    .application-card__email {
      margin: 3px 0 0;
      font-size: 12px;
      color: var(--muted);
      word-break: break-word;
    }
    .application-card__pill {
      flex-shrink: 0;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      border-radius: 0;
      padding: 5px 12px;
      font-size: 12px;
      font-weight: 700;
      border: 1px solid transparent;
      white-space: nowrap;
    }
    .application-card__pill--pending {
      background: #fff4db;
      color: #9a5b00;
      border-color: #e1c15a;
    }
    .application-card__meta {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }
    .application-card__meta span {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 4px 10px;
      border: 1px solid var(--border-light);
      background: #fbfbfb;
      border-radius: 0;
      font-size: 12px;
      color: var(--muted);
    }
    .application-card__alert {
      padding: 10px 20px;
      background: #fff4db;
      border-bottom: 1px solid #e1c15a;
      display: flex;
      align-items: center;
      gap: 8px;
      color: #9a5b00;
      font-size: 13px;
    }
    .application-card__sections { padding: 0 20px; }
    .application-card__section {
      padding: 14px 0 10px;
      border-bottom: 1px solid var(--border-light);
    }
    .application-card__section:last-child { border-bottom: none; }
    .application-card__section-title {
      margin: 0 0 2px;
      font-size: 12px;
      font-weight: 700;
      letter-spacing: 0.03em;
      color: var(--muted-light);
      text-transform: none;
    }
    .application-card__rows { display: grid; }
    .application-card__row {
      min-height: 42px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      border-bottom: 1px solid var(--border-light);
      padding: 6px 0;
    }
    .application-card__row:last-child { border-bottom: none; }
    .application-card__label {
      min-width: 160px;
      flex-shrink: 0;
      font-size: 13px;
      color: var(--ink-2);
      font-weight: 700;
    }
    .application-card__value {
      font-size: 13px;
      color: var(--muted);
      min-width: 0;
      text-align: right;
      word-break: break-word;
    }
    .application-card__value--muted { color: var(--muted-light); }
    .application-card__value--status {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      border-radius: 0;
      background: #fff4db;
      color: #9a5b00;
      border: 1px solid #e1c15a;
      font-size: 12px;
      font-weight: 700;
    }
    .application-card__documents { display: grid; gap: 12px; }
    .application-card__document-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
    .application-card__document-name {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      min-width: 0;
      font-size: 13px;
      color: var(--ink);
      font-weight: 700;
      word-break: break-word;
    }
    .application-card__document-name i { color: #4c7edc; }
    .application-card__button {
      flex-shrink: 0;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 7px 12px;
      border-radius: 0;
      font-size: 12px;
      font-weight: 700;
      border: 1px solid transparent;
      text-decoration: none;
      cursor: pointer;
    }
    .application-card__button--download {
      background: #edf4ff;
      color: #3766b3;
      border-color: #b9cff2;
    }
    .application-card__footer {
      padding: 16px 20px 18px;
      border-top: 1px solid var(--border-light);
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }
    .application-card__footer-form {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
      margin: 0;
    }
    .application-card__button--approve {
      background: #edf8ef;
      color: #1f7a3a;
      border-color: #a9d8b7;
    }
    .application-card__button--reject {
      background: #fff0f0;
      color: #b42318;
      border-color: #eda7a7;
    }
    .application-actions form { display: inline; }

    @media (max-width: 640px) {
      .application-card__top,
      .application-card__document-row,
      .application-card__row,
      .application-card__footer {
        flex-direction: column;
        align-items: flex-start;
      }

      .application-card__value { text-align: left; }
      .application-card__button { width: 100%; justify-content: center; }
    }

    @media (min-width: 768px) {
      .gysj-navbar .navbar-toggler {
        display: none;
      }

      .gysj-navbar .navbar-collapse.collapse {
        display: flex !important;
      }

      .gysj-navbar .navbar-collapse {
        flex-basis: auto;
      }

      .gysj-navbar .navbar-nav {
        flex-direction: row;
        align-items: center;
      }

      .gysj-nav-links {
        margin-top: 0 !important;
      }
    }

    @media (max-width: 1060px) {
      .db-grid { grid-template-columns: 1fr; }
      .db-sidebar { position: static; }
      .stats-row { grid-template-columns: repeat(3, 1fr); }
    }
    @media (max-width: 700px) {
      .db-wrap { padding: 0 14px; }
      .db-title { font-size: 28px; }
      .stats-row { grid-template-columns: repeat(2, 1fr); }
      .review-drawer { width: 100vw; }
      .decision-btns { grid-template-columns: 1fr; }
      .rubric-top { flex-direction: column; gap: 10px; }
      .drawer-kv-grid { grid-template-columns: 1fr; }
    }

    /* Shared Submission UI (Modal & Drawer) */
    .sub-modal-section { margin-bottom: 28px; background: #fff; border: 1px solid #e2e8f0; border-radius: 0; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.02); }
    .sub-modal-section-header { background: #f8fafc; padding: 14px 20px; border-bottom: 1px solid #e2e8f0; font-size: 14px; font-weight: 700; color: #1e293b; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 8px; }
    .sub-modal-section-body { padding: 20px; }
    
    .sub-core-title { font-size: 24px; font-weight: 800; color: #0f172a; line-height: 1.3; margin-bottom: 12px; }
    .sub-core-meta { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; }
    .sub-pill { background: #f1f5f9; color: #334155; padding: 6px 12px; border-radius: 0; font-size: 13px; font-weight: 600; border: 1px solid #cbd5e1; display: inline-flex; align-items: center; gap: 6px; }
    
    .sub-text-block { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 0; padding: 16px; font-size: 14px; color: #334155; line-height: 1.6; white-space: pre-wrap; margin-bottom: 16px; }
    .sub-label { color: #64748b; font-size: 12px; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px; margin-bottom: 6px; }
    
    .sub-checklist-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 12px; }
    .sub-checklist-item { display: flex; align-items: center; gap: 10px; font-size: 13px; color: #166534; background: #f0fdf4; border: 1px solid #bbf7d0; padding: 10px 14px; border-radius: 0; font-weight: 600; }
    .sub-checklist-item i { color: #22c55e; font-size: 16px; }
    
    .sub-author-grid { display: grid; gap: 16px; }
    .sub-author-card { border: 1px solid #e2e8f0; border-radius: 0; overflow: hidden; }
    .sub-author-header { background: #f8fafc; padding: 12px 16px; border-bottom: 1px solid #e2e8f0; font-weight: 700; color: #0f172a; display: flex; justify-content: space-between; align-items: center; }
    .sub-author-body { padding: 16px; display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 16px; font-size: 13px; }
    
    .sub-kv { display: flex; flex-direction: column; gap: 4px; }
    .sub-kv-label { color: #64748b; font-size: 11px; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px; }
    .sub-kv-value { color: #0f172a; font-weight: 500; }
    .sub-kv.full-width { grid-column: 1 / -1; }
    .drawer-columns { display:flex; gap:20px; align-items: flex-start; }
    .drawer-left { flex: 1 1 60%; padding: 0 24px; }
    .drawer-right { flex: 0 0 380px; padding: 0 16px; border-left: 1px solid #eef2f7; max-height: none; overflow: visible; background: #fff; }
    @media (max-width: 900px) { .drawer-columns { flex-direction: column; } .drawer-right { border-left: none; max-height: none; width: 100%; } }
    .review-drawer .drawer-body { flex: 1 1 auto; min-height: 0; overflow-y: auto; overflow-x: hidden; }
    .review-drawer { overflow: hidden; }
    .drawer-left, .drawer-right { box-sizing: border-box; }
    .drawer-right, .drawer-right * { box-sizing: border-box; }
    .drawer-right input, .drawer-right textarea, .drawer-right select, .drawer-right button { max-width: 100%; width: 100%; }
    .assignment-choices { overflow-x: hidden; }
    .assignment-choice { width: 100%; box-sizing: border-box; }
    .sub-modal-section-body { box-sizing: border-box; }
    /* Prevent the right column from creating its own vertical scrollbar; allow drawer-body to handle scrolling */
    .drawer-right { overflow-y: visible; }
  </style>
</head>
<body>

<div class="container-fluid bg-faded fh5co_padd_mediya padding_786">
    <div class="container padding_786">
      <nav class="navbar navbar-toggleable-md navbar-light gysj-navbar flex-column align-items-start">
        <div class="d-flex w-100 align-items-center justify-content-between">
          <a class="navbar-brand mobile_logo_width" href="index.php">
            <img src="images/iysjournal.png" alt="Global Youth Science Journal" class="gysj-nav-icon">
            <span class="gysj-nav-title">Global Youth Science Journal</span>
          </a>
          <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarSupportedContent"
            aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation"><span
              class="navbar-toggler-icon"></span></button>
        </div>

        <div class="collapse navbar-collapse w-100 mt-3 gysj-nav-links" id="navbarSupportedContent">
          <ul class="navbar-nav mx-auto">
                        <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>">
                            <a class="nav-link" href="index.php">Home</a>
                        </li>
                        <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'publication.php' ? 'active' : ''; ?>">
                            <a class="nav-link" href="publication.php">Publications</a>
                        </li>
                        <li class="nav-item dropdown <?php echo in_array(basename($_SERVER['PHP_SELF']), ['user-dashboard.php', 'call-for-paper.php', 'authorguidelines.php', 'copyright.php']) ? 'active' : ''; ?>">
                            <a class="nav-link dropdown-toggle" href="#" id="dropdownMenuButton3" data-toggle="dropdown"
                                aria-haspopup="true" aria-expanded="false">Paper Submissions</a>
                            <div class="dropdown-menu" aria-labelledby="dropdownMenuButton3">
                                <a class="dropdown-item" href="user-dashboard.php?view=submit">Online Submission</a>
                                <a class="dropdown-item" href="call-for-paper.php">Call for Paper</a>
                                <a class="dropdown-item" href="authorguidelines.php">Guidelines for authors</a>
                                <a class="dropdown-item" href="copyright.php">Copyright</a>
                            </div>
                        </li>
                        <li class="nav-item dropdown <?php echo in_array(basename($_SERVER['PHP_SELF']), ['our-founders.php', 'our-mission.php', 'our-funding.php']) ? 'active' : ''; ?>">
                            <a class="nav-link dropdown-toggle" href="#" id="dropdownMenuButton2" data-toggle="dropdown"
                                aria-haspopup="true" aria-expanded="false">About Us</a>
                            <div class="dropdown-menu" aria-labelledby="dropdownMenuButton2">
                                <a class="dropdown-item" href="our-founders.php">Our Founders</a>
                                <a class="dropdown-item" href="our-mission.php">Our Mission</a>
                                <a class="dropdown-item" href="our-funding.php">Our Funding</a>
                            </div>
                        </li>
                        <li class="nav-item dropdown <?php echo in_array(basename($_SERVER['PHP_SELF']), ['editorial-board.php', 'editorial-members.php']) ? 'active' : ''; ?>">
                            <a class="nav-link dropdown-toggle" href="#" id="dropdownEditorialBoard" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Editorial Board</a>
                            <div class="dropdown-menu" aria-labelledby="dropdownEditorialBoard">
                                <a class="dropdown-item" href="editorial-board.php">About the Board</a>
                                <a class="dropdown-item" href="editorial-members.php">Members</a>
                            </div>
                        </li>
                        <li class="nav-item dropdown <?php echo in_array(basename($_SERVER['PHP_SELF']), ['contribute.php', 'partners.php']) ? 'active' : ''; ?>">
                            <a class="nav-link dropdown-toggle" href="#" id="dropdownSupport" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Support Us</a>
                            <div class="dropdown-menu" aria-labelledby="dropdownSupport">
                                <a class="dropdown-item" href="contribute.php">Contribute</a>
                                <a class="dropdown-item" href="partners.php">Partners</a>
                            </div>
                        </li>
                        <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'contact.php' ? 'active' : ''; ?>">
                            <a class="nav-link" href="contact.php">Contact</a>
                        </li>

                        <?php if (auth_is_logged_in()): $navUser = auth_current_user(); ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="accountMenu" data-toggle="dropdown"
                                aria-haspopup="true" aria-expanded="false"><?php echo e(($navUser['name'] ?? '') !== '' ? $navUser['name'] : ($navUser['email'] ?? 'Account')); ?></a>
                            <div class="dropdown-menu" aria-labelledby="accountMenu">
                                <a class="dropdown-item" href="<?php echo e((($navUser['role'] ?? '') === 'admin') ? 'admin-dashboard.php' : 'user-dashboard.php'); ?>">Dashboard</a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="account.php">Account Settings</a>
                                <a class="dropdown-item" href="logout.php">Log Out</a>
                            </div>
                        </li>
                        <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link btn btn-primary btn-sm text-white px-3" href="login.php"
                                style="margin-top:4px; margin-left:8px;">Login / Sign Up</a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </nav>
    </div>
  </div>

  <div class="db-shell">
    <div class="db-wrap">
      <?php if ($flashMessage !== ''): ?>
        <div class="alert alert-success dashboard-alert mb-3" role="alert"><?php echo e($flashMessage); ?></div>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <div class="alert alert-danger dashboard-alert mb-3" role="alert"><?php echo e($error); ?></div>
      <?php endif; ?>

      <div class="preview-shell">
        <section class="workspace-panel">
          <div class="workspace-panel-head">
            <div class="workspace-title-row">
              <div>
                <h2 class="workspace-heading">Admin Dashboard - Manuscript Queue</h2>
                <p class="workspace-subtitle">Peer Review Workspace / Submissions</p>
              </div>
            </div>

            <div class="workspace-toolbar">
              <div class="workspace-toolbar-left">
                <div class="search-wrap scholastica">
                  <i class="fa fa-search"></i>
                  <input class="search-input" type="search" id="searchBox" placeholder="Search title, author, id, v2" oninput="applyFilters()">
                </div>
              </div>
            </div>
          </div>

          <div class="workspace-table-wrap">
            <table class="workspace-table">
              <thead>
                <tr>
                  <th class="checkbox-cell"><input class="select-box" type="checkbox" id="selectAllSubmissions" aria-label="Select all submissions"></th>
                  <th>Submitted</th>
                  <th>Author</th>
                  <th>Title</th>
                  <th>Assigned to</th>
                  <th>Status</th>
                  <th>Comments</th>
                  <th>Rating</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="workspaceTableBody">
                <?php foreach ($submissionPayload as $paper): ?>
                  <?php
                    $assignmentSummary = is_array($paper['assignment_summary'] ?? null) ? $paper['assignment_summary'] : gysj_redesign_assignment_summary($paper, $adminDirectory);
                    $eligibleAdmins = is_array($paper['eligible_admins'] ?? null) ? $paper['eligible_admins'] : [];
                    $assignedAdminIds = gysj_redesign_decode_admin_ids($paper['assigned_admin_ids_json'] ?? null);
                    $paperType = trim((string) ($paper['type'] ?? ''));
                    $paperJournal = trim((string) ($paper['journal'] ?? ''));
                    if ($paperType !== '' && $paperJournal !== '' && strcasecmp($paperType, $paperJournal) === 0) {
                      $paperJournal = '';
                    }
                    $assignedNames = [];
                    foreach (is_array($assignmentSummary['assigned_admins'] ?? null) ? $assignmentSummary['assigned_admins'] : [] as $assignedAdmin) {
                      $assignedNames[] = trim((string) ($assignedAdmin['name'] ?? ''));
                    }

                    $rowSearch = strtolower(trim(implode(' ', [
                      (string) ($paper['title'] ?? ''),
                      (string) ($paper['authors'] ?? ''),
                      (string) ($paper['submitter_name'] ?? ''),
                      (string) ($paper['submitter_email'] ?? ''),
                      (string) ($paper['tracking_id'] ?? ''),
                      (string) ($paper['journal'] ?? ''),
                      (string) ($paper['category'] ?? ''),
                      (string) ($paper['type'] ?? ''),
                      implode(' ', $assignedNames),
                    ])));
                    $historyCount = is_array($paper['history'] ?? null) ? count($paper['history']) : 0;
                    $summaryCounts = gysj_redesign_review_summary_counts($paper);
                    $commentPreview = gysj_redesign_comment_excerpt($paper);
                    $createdDate = gysj_redesign_format_datetime((string) ($paper['created_at'] ?? ''));
                    $rating = gysj_redesign_extract_rating($paper);
                    $statusLabel = (string) ($paper['status_label'] ?? 'Submitted');
                  ?>
                  <tr class="workspace-row" tabindex="0" role="button" aria-label="Open review for <?php echo e(trim((string) ($paper['title'] ?? '')) !== '' ? (string) $paper['title'] : 'Untitled submission'); ?>" onclick="openSubmissionDrawer(event, <?php echo (int) ($paper['id'] ?? 0); ?>)" onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); openSubmissionDrawer(event, <?php echo (int) ($paper['id'] ?? 0); ?>); }" data-paper-id="<?php echo (int) ($paper['id'] ?? 0); ?>" data-status="<?php echo e((string) ($paper['status'] ?? 'submitted')); ?>" data-search="<?php echo e($rowSearch); ?>">
                    <td class="checkbox-cell"><input class="select-box" type="checkbox" aria-label="Select row <?php echo (int) ($paper['id'] ?? 0); ?>"></td>
                    <td>
                      <div class="submitted-date"><?php echo e($createdDate); ?></div>
                      <div class="submitted-mini">ID <?php echo e(trim((string) ($paper['tracking_id'] ?? '')) !== '' ? (string) $paper['tracking_id'] : '—'); ?></div>
                    </td>
                    <td class="author-cell">
                      <div class="author-name"><?php echo e((string) ($paper['submitter_name'] ?? '')); ?></div>
                      <div class="author-email"><?php echo e((string) ($paper['submitter_email'] ?? '')); ?></div>
                    </td>
                    <td>
                      <div class="workspace-paper-title"><?php echo e(trim((string) ($paper['title'] ?? '')) !== '' ? (string) $paper['title'] : 'Untitled'); ?></div>
                      <?php if ($paperType !== '' || $paperJournal !== ''): ?>
                        <div class="workspace-paper-meta">
                          <?php if ($paperType !== ''): ?><span><?php echo e($paperType); ?></span><?php endif; ?>
                          <?php if ($paperJournal !== ''): ?><span><?php echo e($paperJournal); ?></span><?php endif; ?>
                        </div>
                      <?php endif; ?>
                      <?php $pCategory = trim((string) ($paper['category'] ?? '')); ?>
                      <?php if ($pCategory !== '' && strcasecmp($pCategory, $paperType) !== 0 && strcasecmp($pCategory, trim((string) ($paper['journal'] ?? ''))) !== 0): ?>
                        <div class="workspace-paper-tag"><?php echo e($pCategory); ?></div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <button class="assignment-pill <?php echo e((string) ($assignmentSummary['class'] ?? 'assignment-unclaimed')); ?>" type="button" onclick="event.stopPropagation(); openAssignmentDrawer(<?php echo (int) ($paper['id'] ?? 0); ?>);" aria-label="Open assignment list for <?php echo e(trim((string) ($paper['title'] ?? '')) !== '' ? (string) $paper['title'] : 'Untitled submission'); ?>">
                        <span class="assignment-pill-label"><?php echo e((string) ($assignmentSummary['label'] ?? 'Unclaimed')); ?></span>
                        <span class="assignment-pill-subcopy"><?php echo e((string) ($assignmentSummary['subcopy'] ?? 'Click to claim or assign')); ?></span>
                      </button>
                    </td>
                    <td>
                      <div class="status-stack">
                        <span class="db-status <?php echo e((string) ($paper['status_class'] ?? 'status-submitted')); ?>"><?php echo e($statusLabel); ?></span>
                        <span class="status-subcopy"><?php echo e($statusLabel === 'Submitted' ? 'Awaiting review' : ($statusLabel === 'Waiting for Action' ? 'Review Completed' : ($statusLabel === 'Requesting Other Editors for Review' ? 'Awaiting peer review' : ($statusLabel === 'Accepted' ? 'Approved for publication' : ($statusLabel === 'Rejected' ? 'Closed' : 'Needs author edits'))))); ?></span>
                      </div>
                    </td>
                    <td>
                      <div class="reviews-summary">
                        <div class="comment-snippet<?php echo $commentPreview['empty'] ? ' empty' : ''; ?>" title="<?php echo e((string) $commentPreview['full']); ?>"><?php echo e((string) $commentPreview['text']); ?></div>
                      </div>
                      <div class="status-subcopy" style="margin-top:6px;"><?php echo (int) $historyCount; ?> event<?php echo $historyCount === 1 ? '' : 's'; ?></div>
                    </td>
                    <td class="rating-cell"><?php echo e($rating); ?></td>
                    <td class="actions-cell">
                      <?php $currentPaperId = (int) ($paper['id'] ?? 0); ?>
                      <?php $chatTitle = trim((string)($paper['title'] ?? '')) !== '' ? trim((string)$paper['title']) : 'Untitled'; ?>
                      <?php $unreadCnt = $unreadAdminCounts[$currentPaperId] ?? 0; ?>
                      <?php $chatLabel = $unreadCnt > 0 ? "Chat ($unreadCnt)" : "Chat"; ?>
                      <div style="margin-top: 12px; border-top: 1px solid #f1f5f9; padding-top: 8px;">
                        <button class="db-btn" type="button" data-chat-id="<?php echo $currentPaperId; ?>" data-chat-title="<?php echo htmlspecialchars($chatTitle, ENT_QUOTES, 'UTF-8'); ?>" onclick="event.stopPropagation(); openChatModal(this.dataset.chatId, this.dataset.chatTitle);" style="color: #0284c7; padding: 6px 10px;">
                          <i class="fa fa-comments"></i> <?php echo e($chatLabel); ?>
                        </button>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div id="emptyMsg" class="workspace-empty">
            <i class="fa fa-search"></i>
            <h3>No results</h3>
            <p>Try a different search term or switch the table view.</p>
          </div>

         
        </section>

        <?php if (in_array($admin['admin_role'] ?? 'all', ['editor_in_chief', 'all'], true)): ?>
        <section class="workspace-panel" id="admin-applications">
          <div class="workspace-panel-head">
            <div class="workspace-title-row">
              <div>
                <h2 class="workspace-heading">Pending Admin Applications</h2>
                <p class="workspace-subtitle">Review applicant details and approve or reject editor access requests.</p>
              </div>
              <span class="db-status status-submitted"><?php echo (int) $pendingAdminApplicationCount; ?> pending</span>
            </div>
          </div>

          <div class="card-body">
            <?php if ($pendingAdminApplicationCount === 0): ?>
              <div class="empty-state">
                <i class="fa fa-users" aria-hidden="true"></i>
                <h3>No pending admin applications</h3>
                <p>New applications will appear here for your review.</p>
              </div>
            <?php else: ?>
              <div class="applications-grid">
                <?php foreach ($pendingAdminApplications as $app): ?>
                  <?php
                    $appId = (int) ($app['id'] ?? 0);
                    $appName = trim((string) ($app['name'] ?? ''));
                    $appEmail = trim((string) ($app['email'] ?? ''));
                    $appNameSource = preg_replace('/[^A-Za-z0-9]/', '', $appName) ?: preg_replace('/[^A-Za-z0-9]/', '', $appEmail) ?: 'AP';
                    $appInitials = strtoupper(substr($appNameSource, 0, 2));
                    if ($appInitials === '') {
                      $appInitials = 'AP';
                    }

                    $appAppliedAt = gysj_redesign_format_card_datetime((string) ($app['created_at'] ?? ''));
                    $appAssignedJournals = gysj_redesign_join_list($app['assigned_journals_json'] ?? null, 'All journals');
                    $appCountry = gysj_redesign_first_non_empty($app, ['country', 'reviewer_country']);
                    $appInstitution = gysj_redesign_first_non_empty($app, ['institution', 'reviewer_institution']);
                    $appGrade = gysj_redesign_first_non_empty($app, ['grade_level', 'reviewer_grade_level']);
                    $appExperience = gysj_redesign_first_non_empty($app, ['reviewer_experience_text', 'experience_text']);
                    $appReason = gysj_redesign_first_non_empty($app, ['reviewer_reason_text', 'reason_text']);
                    $appAvailability = gysj_redesign_first_non_empty($app, ['reviewer_weekly_availability', 'weekly_availability']);
                    $appProfileLinks = gysj_redesign_first_non_empty($app, ['reviewer_profile_links', 'profile_links']);
                    $appCvPath = trim((string) ($app['reviewer_cv_path'] ?? ($app['cv_path'] ?? '')));
                    $appCvName = trim((string) ($app['reviewer_cv_original_name'] ?? ($app['cv_original_name'] ?? '')));
                    $appSupportingDocs = gysj_redesign_join_list($app['reviewer_supporting_documents_json'] ?? null, '');
                    $appDeclarationConfirmed = !empty($app['reviewer_declaration_confirmed']);
                    $appHasCv = $appCvPath !== '' || $appCvName !== '';

                    $emptyFieldsCount = 0;
                    if ($appGrade === '') $emptyFieldsCount++;
                    if ($app['reviewer_experience_text'] === null || $app['reviewer_experience_text'] === '') $emptyFieldsCount++;
                    if ($app['reviewer_reason_text'] === null || $app['reviewer_reason_text'] === '') $emptyFieldsCount++;
                    if ($app['reviewer_weekly_availability'] === null || $app['reviewer_weekly_availability'] === '') $emptyFieldsCount++;
                    if ($appProfileLinks === '') $emptyFieldsCount++;
                    
                    $isMostlyEmpty = $emptyFieldsCount >= 3;
                  ?>
                  <article class="application-card">
                    <div class="application-card__header">
                      <div class="application-card__top">
                        <div class="application-card__identity">
                          <div>
                            <h3 class="application-card__name"><?php echo e($appName !== '' ? $appName : 'Unnamed applicant'); ?></h3>
                            <p class="application-card__email"><?php echo e($appEmail !== '' ? $appEmail : 'No email provided'); ?></p>
                          </div>
                        </div>
                        <span class="application-card__pill application-card__pill--pending"><i class="fa fa-clock-o" aria-hidden="true"></i> Pending</span>
                      </div>

                      <div class="application-card__meta">
                        <span><i class="fa fa-calendar" aria-hidden="true"></i> Applied: <?php echo e($appAppliedAt); ?></span>
                      </div>
                    </div>

                    <?php if ($isMostlyEmpty): ?>
                    <div class="application-card__alert">
                      <i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
                      <span>Most reviewer profile fields are incomplete. <?php echo $appHasCv ? 'Only a CV has been provided.' : ''; ?></span>
                    </div>
                    <?php endif; ?>

                    <div class="application-card__sections">
                      <div class="application-card__section">
                        <p class="application-card__section-title">Reviewer profile</p>
                        <div class="application-card__rows">
                          <div class="application-card__row">
                            <span class="application-card__label">Journals</span>
                            <span class="application-card__value"><?php echo e($appAssignedJournals); ?></span>
                          </div>
                          <div class="application-card__row">
                            <span class="application-card__label">Country</span>
                            <span class="application-card__value"><?php echo e($appCountry !== '' ? $appCountry : 'Not provided'); ?></span>
                          </div>
                          <div class="application-card__row">
                            <span class="application-card__label">Institution</span>
                            <span class="application-card__value"><?php echo e($appInstitution !== '' ? $appInstitution : 'Not provided'); ?></span>
                          </div>
                          <div class="application-card__row">
                            <span class="application-card__label">Grade / Qualification</span>
                            <span class="application-card__value"><?php echo e(gysj_redesign_display_value($appGrade)); ?></span>
                          </div>
                          <div class="application-card__row application-card__row--large">
                            <span class="application-card__label">Experience</span>
                            <span class="application-card__value"><?php echo e(gysj_redesign_display_value($appExperience)); ?></span>
                          </div>
                          <div class="application-card__row application-card__row--large">
                            <span class="application-card__label">Why a good fit</span>
                            <span class="application-card__value"><?php echo e(gysj_redesign_display_value($appReason)); ?></span>
                          </div>
                          <div class="application-card__row">
                            <span class="application-card__label">Availability</span>
                            <span class="application-card__value"><?php echo e(gysj_redesign_display_value($appAvailability)); ?></span>
                          </div>
                          <div class="application-card__row">
                            <span class="application-card__label">Profile links</span>
                            <span class="application-card__value">
                              <?php 
                                $linksText = htmlspecialchars(trim((string) ($appProfileLinks ?? '')));
                                if ($linksText !== '' && $linksText !== 'None') {
                                  echo preg_replace('!(((f|ht)tp(s)?://)[-a-zA-Z0-9@:%_+.~#?&;//=]+)!i', '<a href="$1" target="_blank" rel="noopener">$1</a>', $linksText);
                                } else {
                                  echo 'None';
                                }
                              ?>
                            </span>
                          </div>
                        </div>
                      </div>

                      <div class="application-card__section">
                        <p class="application-card__section-title">Documents & declaration</p>
                        <div class="application-card__documents">
                          <div class="application-card__document-row">
                            <div class="application-card__document-name">
                              <i class="fa fa-file-text-o" aria-hidden="true"></i>
                              <span>CV: <?php echo e(gysj_redesign_file_label($appCvPath, $appCvName)); ?></span>
                            </div>
                            <?php if ($appHasCv && $appId > 0): ?>
                              <a class="application-card__button application-card__button--download" href="admin-application-file.php?id=<?php echo $appId; ?>" target="_blank" rel="noopener"><i class="fa fa-download" aria-hidden="true"></i> Download</a>
                            <?php endif; ?>
                          </div>
                          <div class="application-card__row application-card__row--large">
                            <span class="application-card__label">Supporting docs</span>
                            <span class="application-card__value">
                              <?php
                                $suppDocsArr = json_decode($app['reviewer_supporting_documents_json'] ?? '[]', true);
                                if (is_array($suppDocsArr) && count($suppDocsArr) > 0): ?>
                                  <div style="display:flex; flex-direction:column; gap:6px; margin-top:4px; width:100%;">
                                    <?php foreach ($suppDocsArr as $idx => $doc): ?>
                                      <div class="application-card__document-row" style="margin-bottom:0; padding-bottom:0; border:none; justify-content:space-between;">
                                        <div class="application-card__document-name">
                                          <i class="fa fa-file-text-o"></i> <span><?php echo e($doc['original_name'] ?? 'Doc'); ?></span>
                                        </div>
                                        <a class="application-card__button application-card__button--download" href="admin-application-file.php?id=<?php echo $appId; ?>&doc=supporting&index=<?php echo $idx; ?>" target="_blank" rel="noopener"><i class="fa fa-download" aria-hidden="true"></i> DL</a>
                                      </div>
                                    <?php endforeach; ?>
                                  </div>
                                <?php else: ?>
                                  Not uploaded
                                <?php endif; ?>
                            </span>
                          </div>
                          <div class="application-card__row application-card__row--large">
                            <span class="application-card__label">Declaration</span>
                            <span class="application-card__value">
                              <?php if ($appDeclarationConfirmed): ?>
                                I confirm that I have reviewed the qualifications required for editorial positions at the Global Youth Science Journal. I certify that all information provided in this application is accurate and complete.
                              <?php else: ?>
                                <span style="color:var(--danger)">Not confirmed</span>
                              <?php endif; ?>
                            </span>
                          </div>
                        </div>
                      </div>
                    </div>

                    <div class="application-card__footer">
                      <form method="post" action="admin-dashboard.php#admin-applications" class="application-card__footer-form" id="adminAppForm-<?php echo $appId; ?>">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="application_id" value="<?php echo $appId; ?>">
                        <button type="submit" class="application-card__button application-card__button--approve" name="action" value="approve_admin"><i class="fa fa-check" aria-hidden="true"></i> Approve</button>
                        <button type="button" class="application-card__button application-card__button--reject" onclick="triggerRejectAdmin(<?php echo $appId; ?>)"><i class="fa fa-times" aria-hidden="true"></i> Reject</button>
                      </form>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </section>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="bulk-actions-bar" id="bulkActionsBar">
    <div class="bulk-actions-count" id="bulkActionsCount">0 selected</div>
    <div class="bulk-actions-btns">
      <form id="bulkActionForm" method="post" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="bulk_action">
        <input type="hidden" name="bulk_type" id="bulkTypeField" value="">
        <div id="bulkSubmissionInputs"></div>
      </form>
      <?php if (in_array($admin['admin_role'] ?? 'all', ['editor_in_chief', 'all'], true)): ?>
      <button class="db-btn db-btn-success" onclick="triggerBulkAction('accept')"><i class="fa fa-check"></i> Accept</button>
      <?php endif; ?>
      <?php if (in_array($admin['admin_role'] ?? 'all', ['junior_editor', 'reviewer', 'editor_in_chief', 'all'], true)): ?>
      <button class="db-btn db-btn-warn" onclick="triggerBulkAction('needs_edits')"><i class="fa fa-pencil"></i> Request Edits</button>
      <?php endif; ?>
      <?php if (in_array($admin['admin_role'] ?? 'all', ['reviewer', 'editor_in_chief', 'all'], true)): ?>
      <button class="db-btn" onclick="triggerBulkAction('reject')"><i class="fa fa-times"></i> Reject</button>
      <?php endif; ?>
      <?php if (in_array($admin['admin_role'] ?? 'all', ['all'], true)): ?>
      <button class="db-btn db-btn-danger" onclick="triggerBulkAction('delete')"><i class="fa fa-trash"></i> Delete</button>
      <?php endif; ?>
    </div>
  </div>

  <div class="drawer-backdrop" id="dBackdrop" onclick="closeDrawer()"></div>
  <div class="review-drawer" id="rDrawer">
    <div class="drawer-header">
      <div class="drawer-header-left">
        <span class="drawer-tracking" id="dTrackingId"></span>
        <span class="db-status" id="dStatusBadge"></span>
      </div>
      <button class="drawer-close" onclick="closeDrawer()" title="Close (Esc)">X</button>
    </div>

    <div class="drawer-body" style="padding: 0;">
      <div class="drawer-columns">
        <div class="drawer-left">
          <div id="dMetaBox" style="background: #f8fafc; padding: 24px; margin-bottom: 24px;"></div>
        </div>
        <aside class="drawer-right">
          <div class="drawer-section assignment-section" style="padding: 0 0;">
          <br> 
          <p class="dsec-label">Assigned To</p>
            <form class="assignment-form" id="assignmentForm" method="post">
              <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
              <input type="hidden" name="submission_id" id="assignmentSubmissionId" value="">
              <input type="hidden" name="action" id="assignmentActionField" value="update_assignment">

              <div class="assignment-summary" id="assignmentSummary"></div>

              <div class="assignment-tools">
                <input class="assignment-search" type="search" id="assignmentSearch" placeholder="Search admins by name or email" oninput="filterAssignmentChoices()">
                <button class="db-btn db-btn-success" type="button" onclick="claimCurrentPaper()"><i class="fa fa-hand-pointer-o"></i> Claim</button>
                <button class="db-btn db-btn-primary" type="button" onclick="saveAssignment()"><i class="fa fa-save"></i> Save Assignment</button>
              </div>

              <div class="assignment-chip-list" id="assignmentSelectedChips"></div>
              <div class="assignment-choices" id="assignmentChoices"></div>
            </form>
          </div>

          <form class="review-form" id="reviewForm" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="submission_id" id="submissionIdField" value="">
            <input type="hidden" name="action" id="decisionActionField" value="">

            <div class="drawer-section">
              <p class="dsec-label">Evaluation Rubric - score each criterion 1 (Poor) to 5 (Excellent)</p>
              <div class="rubric-grid" id="rubricGrid"></div>
              <div class="overall-bar">
                <div>
                  <div class="overall-label-txt">Overall Score</div>
                  <div class="overall-level" id="overallLevel">Not yet scored</div>
                </div>
                <div style="text-align:right;">
                  <span class="overall-score-num" id="overallNum">-</span>
                  <span class="overall-score-max"> / 5.0</span>
                </div>
              </div>
              <div class="decision-btns" style="margin-top: 16px; display: flex; flex-direction: column; gap: 8px;">
                <button class="db-btn db-btn-secondary" type="button" onclick="triggerDecision('escalated')" style="padding: 8px; width: 100%; justify-content: center;"><i class="fa fa-users"></i> Ask Other Editors for Review</button>
                <button class="db-btn db-btn-primary" type="button" onclick="triggerDecision('submitted')" style="padding: 8px; width: 100%; justify-content: center;"><i class="fa fa-paper-plane"></i> Submit Evaluation</button>
              </div>
            </div>

            <div class="drawer-section">
              <label class="db-label" for="fInternal">Internal Notes <span class="lnote">- visible to admins only</span></label>
              <textarea class="db-textarea internal" name="internal_note" id="fInternal" placeholder="Flag concerns for co-admins: plagiarism suspicions, borderline cases, reviewer conflicts, data quality issues, etc." rows="3"></textarea>
            </div>

            <div class="drawer-section">
              <label class="db-label" for="fAuthor">Feedback to Author <span class="lnote">- sent to submitter upon decision</span></label>
              <textarea class="db-textarea" name="comment" id="fAuthor" placeholder="Describe the paper's strengths, specific weaknesses, and clear suggestions for improvement. Be constructive and precise." rows="5"></textarea>
              <div class="decision-btns" style="margin-top: 16px;">
                <?php if (in_array($admin['admin_role'] ?? 'all', ['junior_editor', 'reviewer', 'editor_in_chief', 'all'], true)): ?>
                <button class="db-btn db-btn-warn" type="button" onclick="triggerDecision('needs_edits')"><i class="fa fa-pencil"></i> Request Edit</button>
                <?php endif; ?>
                <?php if (in_array($admin['admin_role'] ?? 'all', ['reviewer', 'editor_in_chief', 'all'], true)): ?>
                <button class="db-btn db-btn-danger" type="button" onclick="triggerDecision('rejected')"><i class="fa fa-times"></i> Reject</button>
                <?php endif; ?>
              </div>
            </div>

            <?php if (in_array($admin['admin_role'] ?? 'all', ['editor_in_chief', 'all'], true)): ?>
            <div class="drawer-section sub-modal-section" style="margin-top: 24px; padding: 0;">
              <div class="sub-modal-section-header">
                <i class="fa fa-edit" style="color: #3b82f6;"></i> Edit Public Metadata & Files
              </div>
              <div class="sub-modal-section-body" style="background: #f8fafc; border-bottom: none;">
                
                <div class="sub-kv full-width" style="margin-bottom: 16px;">
                  <div class="sub-kv-label">Title <span class="lnote" style="font-weight: 400; color: #64748b;">- editable before accepting</span></div>
                  <input type="text" class="db-input" name="final_title" id="finalTitle" value="" style="width: 100%; margin-top: 4px; background: #fff;">
                </div>

                <div class="sub-kv full-width" style="margin-bottom: 16px;">
                  <div class="sub-kv-label">Abstract <span class="lnote" style="font-weight: 400; color: #64748b;">- editable before accepting</span></div>
                  <textarea class="db-textarea" name="final_abstract" id="finalAbstract" rows="5" style="width: 100%; margin-top: 4px; background: #fff;"></textarea>
                </div>

                <div class="sub-kv full-width" style="margin-bottom: 16px;">
                  <div class="sub-kv-label">Keywords <span class="lnote" style="font-weight: 400; color: #64748b;">- editable before accepting</span></div>
                  <input type="text" class="db-input" name="final_keywords" id="finalKeywords" value="" style="width: 100%; margin-top: 4px; background: #fff;">
                </div>

                <div class="sub-kv full-width" style="margin-bottom: 16px;">
                  <div class="sub-kv-label">References (HTML) <span class="lnote" style="font-weight: 400; color: #64748b;">- editable before accepting</span></div>
                  <textarea class="db-textarea" name="final_references_html" id="finalReferencesHtml" rows="5" style="width: 100%; margin-top: 4px; background: #fff; font-family: monospace;"></textarea>
                </div>

                <div class="sub-kv full-width" style="margin-bottom: 16px;">
                  <div class="sub-kv-label">Authors <span class="lnote" style="font-weight: 400; color: #64748b;">- editable before accepting</span></div>
                  <div id="finalAuthorsContainer" style="margin-top: 8px;"></div>
                  <button type="button" class="db-btn db-btn-secondary" onclick="addAuthorField()" style="margin-top: 8px; font-size: 12px; padding: 4px 10px; border-radius: 4px;"><i class="fa fa-plus"></i> Add Author</button>
                  <input type="hidden" id="finalAuthorsJson" name="final_authors_json" value="">
                </div>

                <div class="sub-kv full-width" style="margin-bottom: 16px;">
                  <div class="sub-kv-label">Publication Date <span class="lnote" style="font-weight: 400; color: #64748b;">- defaults to today if left blank</span></div>
                  <input type="date" class="db-input" name="final_publication_date" id="finalPublicationDate" style="width: 100%; margin-top: 4px; background: #fff;">
                </div>

                <div class="sub-kv full-width" style="margin-bottom: 0;">
                  <div class="sub-kv-label">Upload final published PDF <span class="lnote" style="font-weight: 400; color: #64748b;">(required when accepting)</span></div>
                  <input type="file" name="admin_published_pdf" id="fPublishedPdf" accept="application/pdf" style="font-size: 14px; width: 100%; padding: 10px; border: 1px dashed #94a3b8; border-radius: 4px; background: #fff; margin-top: 4px; cursor: pointer;">
                  <div class="ps-hint" style="margin-top: 6px;">Upload the corrected/final PDF that will be published (PDF only, max 50 MB)</div>
                </div>

              </div>
              
              <hr style="border: 0; border-top: 1px solid #94a3b8; margin: 0;">
              
              <div class="sub-modal-section-body" style="background: #fff; padding-top: 16px;">
                <div class="decision-btns" style="display: block;">
                  <button class="db-btn db-btn-success" type="button" onclick="triggerDecision('accepted')" style="padding: 10px; width: 100%; justify-content: center; font-size: 14px;"><i class="fa fa-check"></i> Accept and Publish</button>
                </div>
              </div>
            </div>
            <?php endif; ?>
          </form>

          <?php if (in_array($admin['admin_role'] ?? 'all', ['all'], true)): ?>
          <form class="delete-paper-form" id="deletePaperForm" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="submission_id" id="deleteSubmissionIdField" value="">
            <input type="hidden" name="action" value="delete_paper">
            <div style="padding: 16px; border-top: 1px solid var(--color-border-secondary); text-align: right; background: #fff; border-radius: 0; margin-top: -12px;">
              <button type="button" class="db-btn" style="color: #d32f2f; border-color: #d32f2f; background: transparent;" onclick="triggerDeletePaper()"><i class="fa fa-trash"></i> Delete Paper</button>
            </div>
          </form>
          <?php endif; ?>
          
          <div class="drawer-section" style="margin-top: 24px;">
            <p class="dsec-label">Review Histories</p>
            <div class="comment-history" id="dHistory"></div>
          </div>
        </aside>
      </div>
    </div>
  </div>

  <div class="modal-veil" id="modalVeil">
    <div class="modal-box">
      <h3 id="mTitle"></h3>
      <p id="mBody"></p>
      <div class="modal-btns">
        <button class="db-btn" type="button" onclick="closeModal()">Cancel</button>
        <button class="db-btn db-btn-primary" type="button" onclick="finalizeDecision()">Confirm Decision</button>
      </div>
    </div>
  </div>

  <div id="toast"></div>

  <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
  const papers = <?php echo $submissionJson; ?>;
  const applications = <?php echo $applicationJson; ?>;
  const adminDirectory = <?php echo $adminDirectoryJson; ?>;
  const currentAdminId = <?php echo (int) $gysj_current_admin_id; ?>;
  const STATUS_LABELS = { submitted:'Submitted', accepted:'Accepted', rejected:'Rejected', needs_edits:'Needs Edits', escalated:'Requesting Other Editors for Review' };
  const SCORE_LABELS = { 1:'Poor', 2:'Fair', 3:'Good', 4:'Very Good', 5:'Excellent' };
  const CRITERIA = [
    { key:'accuracy', name:'Scientific Accuracy', desc:'Are the claims accurate, evidence-based, and free of factual errors?' },
    { key:'methodology', name:'Methodology', desc:'Is the research approach appropriate, rigorous, and well-described?' },
    { key:'originality', name:'Originality', desc:'Does the work contribute new knowledge, perspectives, or findings?' },
    { key:'clarity', name:'Writing Clarity', desc:'Is the paper well-organized, clearly written, and properly structured?' },
    { key:'relevance', name:'Journal Relevance', desc:'Does the topic align with the scope and mission of GYSJ?' }
  ];
  const LEVEL_NAMES = ['', 'Poor', 'Below Average', 'Average', 'Above Average', 'Excellent'];

  let currentFilter = 'all';
  let currentPaper = null;
  let scores = {};
  let pendingDecision = null;

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function init() {
    buildRubric();
    applyFilters();
    if (<?php echo $flashMessage !== '' ? 'true' : 'false'; ?>) {
      showToast(<?php echo json_encode($flashMessage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>);
    }
  }

  function parseAuthorsJsonValue(value) {
    if (Array.isArray(value)) {
      return value;
    }

    if (value && typeof value === 'object') {
      return [value];
    }

    if (typeof value !== 'string') {
      return null;
    }

    const text = value.trim();
    if (!text) {
      return null;
    }

    try {
      const parsed = JSON.parse(text);
      if (Array.isArray(parsed)) {
        return parsed;
      }
      if (parsed && typeof parsed === 'object') {
        return [parsed];
      }
    } catch (e) {}

    const start = text.indexOf('[');
    const end = text.lastIndexOf(']');
    if (start >= 0 && end > start) {
      const slice = text.slice(start, end + 1);
      try {
        const parsed = JSON.parse(slice);
        if (Array.isArray(parsed)) {
          return parsed;
        }
      } catch (e) {}
    }

    return null;
  }

  function extractAuthorsJsonFromRaw(raw) {
    if (typeof raw !== 'string') {
      return null;
    }

    let text = raw.trim();
    if (!text) {
      return null;
    }

    // Mirror server-side repair for malformed adjacent quoted key/value pairs.
    text = text.replace(/"([^"\\]+)"\s*"([^"\\]*)"\s*(,|})/g, '"$1":"$2"$3');

    try {
      const parsed = JSON.parse(text);
      if (parsed && typeof parsed === 'object') {
        const direct = parsed['Authors JSON'] ?? parsed['authors json'] ?? parsed['authors_json'] ?? null;
        const authors = parseAuthorsJsonValue(direct);
        if (authors) {
          return authors;
        }
      }
    } catch (e) {}

    const match = text.match(/"Authors JSON"\s*:\s*(\[[\s\S]*?\])\s*(,|})/i);
    if (match && match[1]) {
      return parseAuthorsJsonValue(match[1]);
    }

    return null;
  }

  function cleanAuthorText(value) {
    let text = String(value ?? '').replace(/\\r\\n/g, '\n').replace(/\r\n/g, '\n').trim();
    if (!text) {
      return '';
    }

    // Drop obvious payload dumps that accidentally flow into a single field.
    if ((text.startsWith('{') || text.startsWith('["')) && text.includes('"Authors JSON"')) {
      return '';
    }

    return text;
  }

  function cleanAuthorName(value, fallbackName) {
    let name = cleanAuthorText(value);
    if (!name) {
      return cleanAuthorText(fallbackName) || 'Unnamed Author';
    }

    name = name
      .split(/\n|Age\s*:|Personal\s+Email\s*:|Phone\s+Code\s*:|\{"|","/i)[0]
      .trim();

    if (!name || name.length > 120) {
      return cleanAuthorText(fallbackName) || 'Unnamed Author';
    }

    return name;
  }

  function normalizeAuthorRecord(author, fallbackName) {
    if (!author || typeof author !== 'object' || Array.isArray(author)) {
      return null;
    }

    const fieldAliases = {
      name: 'name',
      'first name': 'name',
      'author name': 'name',
      age: 'age',
      email: 'email',
      'personal email': 'email',
      phone_code: 'phone_code',
      'phone code': 'phone_code',
      phone_number: 'phone_number',
      'phone number': 'phone_number',
      bio: 'bio',
      'short author biography': 'bio',
      'author biography': 'bio',
      'author bio': 'bio',
      school_name: 'school_name',
      'school name': 'school_name',
      grade_level: 'grade_level',
      'grade level': 'grade_level',
      school_email: 'school_email',
      'school email': 'school_email',
      admission_number: 'admission_number',
      'admission number': 'admission_number',
      orcid: 'orcid',
      'orcid id': 'orcid',
      scholar: 'scholar',
      'google scholar': 'scholar'
    };

    const normalized = {};
    const explicitName = cleanAuthorText(author.Name || author.name || author['First Name'] || '');
    let mappedCount = 0;
    Object.entries(author).forEach(([rawKey, rawValue]) => {
      const key = String(rawKey ?? '').trim().toLowerCase();
      if (!key || key.includes('{') || key.includes('}')) {
        return;
      }

      const canonical = fieldAliases[key];
      if (!canonical) {
        return;
      }

      const value = cleanAuthorText(rawValue);
      if (!value) {
        return;
      }

      if (!normalized[canonical]) {
        normalized[canonical] = value;
        mappedCount++;
      }
    });

    if (!explicitName && mappedCount === 0) {
      return null;
    }

    normalized.name = cleanAuthorName(normalized.name || explicitName, fallbackName);
    return normalized.name ? normalized : null;
  }

  function decodeBase64Utf8(value) {
    const binary = atob(value);
    try {
      const encoded = Array.from(binary).map(ch => '%' + ch.charCodeAt(0).toString(16).padStart(2, '0')).join('');
      return decodeURIComponent(encoded);
    } catch (e) {
      return binary;
    }
  }

  function parseAdvancedDetails(details) {
    const raw = details['advanced details'];
    if (typeof raw !== 'string') {
      return {};
    }

    const text = raw.trim();
    if (!text) {
      return {};
    }

    const tryDecode = payload => {
      try {
        const parsed = JSON.parse(payload);
        return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : null;
      } catch (e) {
        return null;
      }
    };

    const direct = tryDecode(text);
    if (direct) {
      return direct;
    }

    let normalizedBase64 = text.replace(/\s+/g, '').replace(/-/g, '+').replace(/_/g, '/');
    while (normalizedBase64.length % 4 !== 0) {
      normalizedBase64 += '=';
    }

    try {
      const decoded = decodeBase64Utf8(normalizedBase64);
      return tryDecode(decoded) || {};
    } catch (e) {
      return {};
    }
  }

  function normalizeDetailKey(key) {
    return String(key ?? '')
      .trim()
      .replace(/^[\{\[\("'`\s]+|[\}\]\)"'`\s:]+$/g, '')
      .replace(/\s+/g, ' ')
      .toLowerCase();
  }

  function parseSubmissionDetailsRaw(raw) {
    if (typeof raw !== 'string') {
      return {};
    }

    let text = raw.trim();
    if (!text) {
      return {};
    }

    // Repair malformed adjacent quoted key/value fragments.
    text = text.replace(/"([^"\\]+)"\s*"([^"\\]*)"\s*(,|})/g, '"$1":"$2"$3');

    const tryParseObject = source => {
      try {
        const parsed = JSON.parse(source);
        return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : null;
      } catch (e) {
        return null;
      }
    };

    const direct = tryParseObject(text);
    if (direct) {
      return direct;
    }

    // Fallback: recover key/value tokens even if one field breaks strict JSON.
    const recovered = {};
    const body = text.replace(/^\{\s*/, '').replace(/\s*\}$/, '');
    const pairRegex = /"([^"\\]+)"\s*:\s*([\s\S]*?)(?=,\s*"[^"\\]+"\s*:|$)/g;
    let match;
    while ((match = pairRegex.exec(body)) !== null) {
      const key = match[1];
      let rawValue = String(match[2] ?? '').trim();
      if (!key || !rawValue) {
        continue;
      }

      if (rawValue.endsWith(',')) {
        rawValue = rawValue.slice(0, -1).trim();
      }

      let value = rawValue;
      if (rawValue.startsWith('"')) {
        try {
          value = JSON.parse(rawValue);
        } catch (e) {
          value = rawValue.replace(/^"|"$/g, '').replace(/\\"/g, '"');
        }
      } else if (rawValue.startsWith('[') || rawValue.startsWith('{')) {
        try {
          value = JSON.parse(rawValue);
        } catch (e) {
          value = rawValue;
        }
      }

      recovered[key] = value;
    }

    return recovered;
  }

  function extractKnownFieldsFromText(text, labels) {
    if (typeof text !== 'string') {
      return {};
    }

    const source = text.replace(/\\r\\n/g, '\n').replace(/\r\n/g, '\n');
    const out = {};

    labels.forEach(label => {
      const escaped = label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      const patterns = [
        new RegExp('"' + escaped + '"\\s*:\\s*"([^"\\n\\r]*)"', 'i'),
        new RegExp(escaped + '\\s*:\\s*([^\\n\\r,]+)', 'i')
      ];

      for (const pattern of patterns) {
        const match = source.match(pattern);
        if (match && match[1]) {
          const key = normalizeDetailKey(label);
          const value = String(match[1]).trim();
          if (key && value) {
            out[key] = value;
            break;
          }
        }
      }
    });

    return out;
  }

  function renderMetadata(paper) {
    const box = document.getElementById('dMetaBox');
    let authorsJson = null;
    let details = {};

    if (paper.submission_details_pairs && Object.keys(paper.submission_details_pairs).length) {
      Object.entries(paper.submission_details_pairs).forEach(([label, value]) => {
        const cleanLabel = normalizeDetailKey(label);
        if (cleanLabel === 'authors json') {
          authorsJson = value;
          return;
        }
        if (cleanLabel) {
          details[cleanLabel] = value;
        }
      });
    }

    const rawDetails = parseSubmissionDetailsRaw(paper.submission_details_raw || '');
    Object.entries(rawDetails).forEach(([label, value]) => {
      const cleanLabel = normalizeDetailKey(label);
      if (!cleanLabel) {
        return;
      }
      if (cleanLabel === 'authors json') {
        if (!authorsJson) {
          authorsJson = value;
        }
        return;
      }
      if (details[cleanLabel] === undefined || details[cleanLabel] === null || String(details[cleanLabel]).trim() === '') {
        details[cleanLabel] = value;
      }
    });

    const advancedDetails = parseAdvancedDetails(details);
    Object.entries(advancedDetails).forEach(([key, value]) => {
      const cleanKey = normalizeDetailKey(key);
      if (!cleanKey) {
        return;
      }
      if (details[cleanKey] === undefined || details[cleanKey] === null || String(details[cleanKey]).trim() === '') {
        details[cleanKey] = value;
      }
    });

    authorsJson = parseAuthorsJsonValue(authorsJson) || extractAuthorsJsonFromRaw(paper.submission_details_raw || '') || null;

    paper.author_bio = paper.author_bio || getVal(['author biography', 'author_bio', 'author bio', 'short author biography']);
    if (!authorsJson && paper.author_bio && /Author \d+:/i.test(paper.author_bio)) {
      authorsJson = [];
      const chunks = paper.author_bio.split(/Author \d+:/i);
      chunks.forEach(chunk => {
        chunk = chunk.trim();
        if (!chunk) return;
        const lines = chunk.split('\n');
        const author = { Name: lines[0].trim() };
        for (let i = 1; i < lines.length; i++) {
          const line = lines[i].trim();
          const idx = line.indexOf(':');
          if (idx > -1) {
            const k = line.substring(0, idx).trim();
            const v = line.substring(idx + 1).trim();
            author[k] = v;
          } else if (line) {
            const keys = Object.keys(author);
            if (keys.length > 0) {
              const lastKey = keys[keys.length - 1];
              author[lastKey] += '\n' + line;
            }
          }
        }
        authorsJson.push(author);
      });
    }
    
    // Helper to get val
    function getVal(keys) {
      for (let k of keys) {
        const normalized = normalizeDetailKey(k);
        if (details[normalized] !== undefined && details[normalized] !== null) return details[normalized];
      }
      return '';
    }

    let html = '';

    // SECTION A: Core Manuscript Details
    html += '<div class="sub-modal-section" style="background: transparent; border: none; box-shadow: none; margin-bottom: 32px;">';
    html += '<div class="sub-core-title">' + escapeHtml(paper.title || 'Untitled Manuscript') + '</div>';
    html += '<div class="sub-core-meta">';
    if (paper.type) html += '<span class="sub-pill"><i class="fa fa-file-text-o"></i> ' + escapeHtml(paper.type) + '</span>';
    if (paper.journal) {
      let dJournal = paper.journal;
      if (dJournal.indexOf('Journal of') === -1 && dJournal !== 'Advance Research (General)') { dJournal = 'Journal of Advance Research in ' + dJournal; }
      if (dJournal === 'Advance Research (General)') { dJournal = 'Journal of Advance Research (General)'; }
      html += '<span class="sub-pill"><i class="fa fa-book"></i> ' + escapeHtml(dJournal) + '</span>';
    }
    const country = getVal(['country']);
    if (country) html += '<span class="sub-pill"><i class="fa fa-globe"></i> ' + escapeHtml(country) + '</span>';
    html += '</div>';

    html += '<div class="sub-label">Abstract</div>';
    html += '<div class="sub-text-block">' + escapeHtml(paper.abstract || 'No abstract provided.') + '</div>';
    
    // Public Metadata Editor has been merged into the final decision section.

    const story = getVal(['project story', 'project_story']);
    if (story) {
      html += '<div class="sub-label">Project Story</div>';
      html += '<div class="sub-text-block">' + escapeHtml(story) + '</div>';
    }

    const keywordsStr = getVal(['keywords']) || (paper.keywords && paper.keywords.join(', ')) || '';
    if (keywordsStr) {
      html += '<div class="sub-label">Keywords</div>';
      html += '<div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px;">';
      keywordsStr.split(',').forEach(kw => {
        if (kw.trim()) html += '<span style="background: #e2e8f0; color: #334155; padding: 4px 10px; border-radius: 0; font-size: 13px; font-weight: 500;">' + escapeHtml(kw.trim()) + '</span>';
      });
      html += '</div>';
    }
    html += '</div>';

    const normalizedAuthors = [];
    if (Array.isArray(authorsJson)) {
      authorsJson.forEach(author => {
        const normalized = normalizeAuthorRecord(author, paper.submitter_name || '');
        if (!normalized) {
          return;
        }

        const hasDetailField = ['age', 'email', 'phone_code', 'phone_number', 'bio', 'school_name', 'grade_level', 'school_email', 'admission_number', 'orcid', 'scholar']
          .some(key => String(normalized[key] || '').trim() !== '');

        if (hasDetailField) {
          normalizedAuthors.push(normalized);
        }
      });
    }

    // SECTION B: Author Roster
    html += '<div class="sub-modal-section">';
    html += '<div class="sub-modal-section-header"><i class="fa fa-users"></i> Author Information</div>';
    html += '<div class="sub-modal-section-body">';
    if (normalizedAuthors.length > 0) {
      const fieldOrder = [
        ['age', 'Age'],
        ['email', 'Email'],
        ['phone_code', 'Phone Code'],
        ['phone_number', 'Phone Number'],
        ['bio', 'Biography'],
        ['school_name', 'School'],
        ['grade_level', 'Grade'],
        ['school_email', 'School Email'],
        ['admission_number', 'Admission No'],
        ['orcid', 'ORCID'],
        ['scholar', 'Google Scholar']
      ];

      html += '<div class="sub-author-grid">';
      normalizedAuthors.forEach((author, index) => {
        html += '<div class="sub-author-card ' + (index > 0 ? 'collapsed' : '') + '">';
        html += '<div class="sub-author-header" style="cursor: pointer;" onclick="this.parentElement.classList.toggle(\'collapsed\')">';
        const authorName = author.name || 'Unnamed Author';
        html += '<div>' + escapeHtml(authorName) + '</div>';
        html += '<div>';
        if (index === 0) html += '<span style="background: #dbeafe; color: #1e40af; font-size: 11px; padding: 2px 8px; border-radius: 0; margin-right: 8px;">Primary Author</span>';
        html += '<i class="fa fa-chevron-down toggle-icon" style="transition: transform 0.2s; color: #64748b;"></i>';
        html += '</div></div>';
        html += '<div class="sub-author-body">';
        fieldOrder.forEach(([key, label]) => {
          const value = cleanAuthorText(author[key]);
          if (!value) {
            return;
          }

          let displayHtml = escapeHtml(value);
          if (key === 'orcid') {
            let orcidUrl = value.startsWith('http') ? value : 'https://orcid.org/' + value;
            displayHtml = '<a href="' + escapeHtml(orcidUrl) + '" target="_blank" rel="noopener" style="color:#2563eb; text-decoration:underline;">' + escapeHtml(value) + '</a>';
          } else if (key === 'scholar') {
            let scholarUrl = value.startsWith('http') ? value : 'https://' + value;
            displayHtml = '<a href="' + escapeHtml(scholarUrl) + '" target="_blank" rel="noopener" style="color:#2563eb; text-decoration:underline;">' + escapeHtml(value) + '</a>';
          }

          const isFull = key === 'bio' || value.length > 50;
          html += '<div class="sub-kv' + (isFull ? ' full-width' : '') + '"><div class="sub-kv-label">' + escapeHtml(label) + '</div><div class="sub-kv-value"' + (isFull ? ' style="white-space: pre-wrap; font-size: 13px; color: #475569; margin-top: 4px; line-height: 1.5;"' : '') + '>' + displayHtml + '</div></div>';
        });
        html += '</div></div>';
      });
      html += '</div>';
    } else {
      html += '<div class="sub-kv" style="margin-bottom: 16px;"><div class="sub-kv-label">Submitter</div><div class="sub-kv-value">' + escapeHtml(paper.submitter_name || '') + ' (' + escapeHtml(paper.submitter_email || '') + ')</div></div>';
      if (paper.author_bio) {
        html += '<div class="sub-kv full-width"><div class="sub-kv-label">Author Bibliography / Biography</div><div class="sub-kv-value" style="white-space: pre-wrap; font-size: 13px; color: #475569; margin-top: 4px; line-height: 1.5;">' + escapeHtml(paper.author_bio) + '</div></div>';
      }
    }
    html += '</div></div>';

    // SECTION C: Attached Files
    html += '<div class="sub-modal-section">';
    html += '<div class="sub-modal-section-header"><i class="fa fa-folder-open"></i> Attached Files</div>';
    html += '<div class="sub-modal-section-body" style="background: #f8fafc; display: flex; flex-direction: column; gap: 8px;">';
    if (!paper.attachments || !paper.attachments.length) {
      const fileName = paper.manuscript_original_name || paper.manuscript_path || 'Attached manuscript';
      const isPdf = fileName.toLowerCase().endsWith('.pdf') || paper.pdf_url;
      const isDocx = fileName.toLowerCase().endsWith('.docx');
      html += '<div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 0; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center;">';
      html += '<div>';
      html += '<div style="font-weight: 600; color: #0f172a; font-size: 14px; margin-bottom: 2px;"><i class="fa fa-file-text-o" style="color: #64748b; margin-right: 6px;"></i> ' + escapeHtml(fileName) + '</div>';
      html += '<div style="font-size: 12px; color: #64748b;">Manuscript</div>';
      html += '</div>';
      html += '<div>';
      if (isPdf) {
        html += '<a class="db-btn db-btn-secondary" href="' + escapeHtml(paper.pdf_url || '#') + '" target="_blank">Read PDF</a>';
      } else if (isDocx) {
        html += '<a class="db-btn db-btn-secondary" href="' + escapeHtml(paper.pdf_url || '#') + '">Download DOCX</a>';
      } else {
        html += '<a class="db-btn db-btn-secondary" href="' + escapeHtml(paper.pdf_url || '#') + '">Open File</a>';
      }
      html += '</div></div>';
    } else {
      paper.attachments.forEach(att => {
        const attName = att.original_name || 'Attached file';
        const attCat = att.category || 'Manuscript';
        const attExt = attName.split('.').pop().toLowerCase();
        const attMime = (att.mime_type || '').toLowerCase();
        const isPdf = attMime === 'application/pdf' || attExt === 'pdf';
        const isDocx = attMime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' || attExt === 'docx';
        const attSizeKb = Math.max(1, Math.floor((att.file_size || 0) / 1024));
        html += '<div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 0; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center;">';
        html += '<div>';
        html += '<div style="font-weight: 600; color: #0f172a; font-size: 14px; margin-bottom: 2px;"><i class="fa fa-file-text-o" style="color: #64748b; margin-right: 6px;"></i> ' + escapeHtml(attName) + '</div>';
        html += '<div style="font-size: 12px; color: #64748b;">' + escapeHtml(attCat) + ' &bull; ' + attSizeKb + ' KB</div>';
        html += '</div>';
        html += '<div>';
        if (isPdf) {
          html += '<a class="db-btn db-btn-secondary" href="paper-file.php?id=' + paper.id + '&attachment_id=' + att.id + '" target="_blank">Read PDF</a>';
        } else if (isDocx) {
          html += '<a class="db-btn db-btn-secondary" href="paper-file.php?id=' + paper.id + '&attachment_id=' + att.id + '">Download DOCX</a>';
        } else {
          html += '<a class="db-btn db-btn-secondary" href="paper-file.php?id=' + paper.id + '&attachment_id=' + att.id + '">Open File</a>';
        }
        html += '</div></div>';
      });
    }
    html += '</div></div>';

    // SECTION D: Methods & Tools
    const litTools = getVal(['literature tools']);
    const swTools = getVal(['software tools']);
    if (litTools || swTools) {
      html += '<div class="sub-modal-section">';
      html += '<div class="sub-modal-section-header"><i class="fa fa-wrench"></i> Methods & Tools</div>';
      html += '<div class="sub-modal-section-body">';
      if (litTools) {
        html += '<div class="sub-label">Literature Tools Used</div>';
        html += '<div class="sub-text-block">' + escapeHtml(litTools) + '</div>';
      }
      if (swTools) {
        html += '<div class="sub-label">Software Tools Used</div>';
        html += '<div class="sub-text-block" style="margin-bottom: 0;">' + escapeHtml(swTools) + '</div>';
      }
      html += '</div></div>';
    }

    // SECTION E: Submission Questionnaire & Declarations
    const questionnaireFields = [
      ['guidelines confirmed', 'Guidelines confirmed'],
      ['mentorship confirmation', 'Mentorship confirmation'],
      ['editorial manager access', 'Editorial Manager access'],
      ['corresponding author responsibilities', 'Corresponding author responsibilities'],
      ['not enrolled in university', 'Not enrolled in university'],
      ['age requirement', 'Age requirement'],
      ['age eligibility', 'Age eligibility'],
      ['permission to publish', 'Permission to publish'],
      ['publication timeline', 'Publication timeline'],
      ['ethical approval', 'Ethical approval'],
      ['no duplicate submission', 'No duplicate submission'],
      ['original work', 'Original work'],
      ['ai policy', 'AI policy'],
      ['formatting guidelines', 'Formatting guidelines'],
      ['publication agreement', 'Publication agreement'],
      ['template reviewed', 'Template reviewed'],
      ['breach of contract', 'Breach of contract'],
      ['preprint server', 'Preprint server'],
      ['preprint link', 'Preprint link'],
      ['how heard about gysj', 'How heard about GYSJ'],
      ['research setting', 'Research setting'],
      ['student ages', 'Student ages'],
      ['school type', 'School type']
    ];

    const questionnaireLabelList = questionnaireFields.map(item => item[0]);
    const textFallbacks = [
      parseSubmissionDetailsRaw(paper.submission_details_raw || ''),
      extractKnownFieldsFromText(paper.submission_details_raw || '', questionnaireLabelList),
      extractKnownFieldsFromText(paper.author_bio || '', questionnaireLabelList)
    ];

    textFallbacks.forEach(source => {
      Object.entries(source || {}).forEach(([k, v]) => {
        const key = normalizeDetailKey(k);
        if (!key) {
          return;
        }
        if (details[key] === undefined || details[key] === null || String(details[key]).trim() === '') {
          details[key] = v;
        }
      });
    });

    const questionnaireValues = questionnaireFields
      .map(([key, label]) => ({ key, label, value: getVal([key]) }))
      .filter(item => String(item.value || '').trim() !== '');

    if (questionnaireValues.length > 0) {
      html += '<div class="sub-modal-section">';
      html += '<div class="sub-modal-section-header"><i class="fa fa-check-square-o"></i> Submission Questionnaire</div>';
      html += '<div class="sub-modal-section-body"><div class="drawer-kv-grid" style="grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px;">';
      questionnaireValues.forEach(item => {
        const value = String(item.value);
        const isLong = value.length > 70;
        const isPreprintLink = item.key === 'preprint link' && /^https?:\/\//i.test(value.trim());
        html += '<div class="sub-kv' + (isLong ? ' full-width' : '') + '">';
        html += '<div class="sub-kv-label">' + escapeHtml(item.label) + '</div>';
        if (isPreprintLink) {
          html += '<div class="sub-kv-value" style="margin-top:4px;"><a href="' + escapeHtml(value.trim()) + '" target="_blank" rel="noopener" class="content-link">' + escapeHtml(value.trim()) + '</a></div>';
        } else {
          html += '<div class="sub-kv-value"' + (isLong ? ' style="white-space: pre-wrap; margin-top:4px; line-height:1.5;"' : '') + '>' + escapeHtml(value) + '</div>';
        }
        html += '</div>';
      });
      html += '</div></div></div>';
    }

    // Additional Details
    const skipKeys = ['type', 'journal', 'title', 'abstract', 'keywords', 'author age', 'personal email', 'phone code', 'phone number', 'phone', 'country', 'grade level', 'school name', 'school email', 'admission number', 'author bio', 'authors json', 'literature tools', 'software tools', 'guidelines confirmed', 'mentorship confirmation', 'editorial manager access', 'corresponding author responsibilities', 'not enrolled in university', 'age requirement', 'age eligibility', 'permission to publish', 'publication timeline', 'ethical approval', 'no duplicate submission', 'original work', 'ai policy', 'formatting guidelines', 'publication agreement', 'template reviewed', 'breach of contract', 'preprint server', 'preprint link', 'how heard about gysj', 'research setting', 'student ages', 'school type', 'advanced details'];
    const additionalDetails = [];
    Object.entries(details).forEach(([k, v]) => {
      if (!skipKeys.includes(k) && v && String(v).trim()) {
        additionalDetails.push({ key: k, value: v });
      }
    });

    if (additionalDetails.length > 0) {
      html += '<div class="sub-modal-section">';
      html += '<div class="sub-modal-section-header"><i class="fa fa-info-circle"></i> Additional Details</div>';
      html += '<div class="sub-modal-section-body"><div class="drawer-kv-grid" style="grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px;">';
      additionalDetails.forEach(pair => {
        const isFull = String(pair.value).length > 50;
        html += '<div class="sub-kv' + (isFull ? ' full-width' : '') + '"><div class="sub-kv-label">' + escapeHtml(pair.key) + '</div><div class="sub-kv-value"' + (isFull ? ' style="white-space: pre-wrap; margin-top: 4px; line-height: 1.5;"' : '') + '>' + escapeHtml(String(pair.value)) + '</div></div>';
      });
      html += '</div></div></div>';
    }

    // (Removed Compliance & Settings as per request)

    box.innerHTML = html;
  }

  function renderHistory(paper) {
    const hist = document.getElementById('dHistory');
    if (!paper.history || !paper.history.length) {
      hist.innerHTML = '<p style="font-size:13px;color:var(--muted);font-style:italic;margin:0;">No previous review activity for this submission.</p>';
      return;
    }

    hist.innerHTML = paper.history.map((item, index) => {
      const editorName = item.label || 'Review';
      const html = `
        <div class="history-dropdown-card" style="border: 1px solid #e2e8f0; margin-bottom: 8px; border-radius: 4px; overflow: hidden; background: #fff;">
          <div class="history-dropdown-header" style="padding: 12px; background: #f8fafc; cursor: pointer; display: flex; justify-content: space-between; align-items: center;" onclick="this.nextElementSibling.style.display = this.nextElementSibling.style.display === 'none' ? 'block' : 'none';">
            <div style="font-weight: 600; font-size: 14px; color: #0f172a;">Review History ${index + 1}</div>
            <i class="fa fa-chevron-down" style="color: #64748b; font-size: 12px;"></i>
          </div>
          <div class="history-dropdown-body" style="display: none; padding: 12px; border-top: 1px solid #e2e8f0; font-size: 13px; color: #334155;">
            <div style="margin-bottom: 8px;"><strong>Editor Name:</strong> ${escapeHtml(editorName)}</div>
            <div style="margin-bottom: 8px;"><strong>Status:</strong> <span class="db-status ${escapeHtml(item.status_class || 'status-submitted')}">${escapeHtml(item.status_label || 'Submitted')}</span></div>
            <div style="margin-bottom: 8px;"><strong>Date:</strong> ${escapeHtml(item.when || '')}</div>
            <div style="margin-bottom: 8px;"><strong>Internal Note / Note to Author / Rubrics:</strong></div>
            <div style="white-space: pre-wrap; font-family: inherit;">${escapeHtml(item.comment || 'No comment recorded.')}</div>
            ${item.file_url ? `<a class="db-btn db-btn-secondary" style="margin-top:10px;" href="${escapeHtml(item.file_url)}" target="_blank" rel="noopener"><i class="fa fa-file-pdf-o"></i> Open file</a>` : ''}
          </div>
        </div>
      `;
      return html;
    }).join('');
  }

  function renderAuthorFields(jsonStr) {
    const container = document.getElementById('finalAuthorsContainer');
    if (!container) return;
    container.innerHTML = '';
    let arr = [];
    try {
      if (jsonStr) arr = JSON.parse(jsonStr);
    } catch(e) {}
    if (!Array.isArray(arr) || arr.length === 0) {
      arr = [{}];
    }
    arr.forEach((author) => {
      addAuthorField(
        author.name || author.Name || '', 
        author.affiliation || author.Affiliation || author.school_name || author.School_Name || '', 
        author.orcid || author.Orcid || '',
        author.scholar || author.Scholar || ''
      );
    });
  }

  function addAuthorField(name = '', affiliation = '', orcid = '', scholar = '') {
    const container = document.getElementById('finalAuthorsContainer');
    if (!container) return;
    const index = container.children.length + 1;
    const div = document.createElement('div');
    div.className = 'author-edit-block';
    div.style.cssText = 'background: #fff; padding: 12px; margin-bottom: 8px; border: 1px solid #cbd5e1; border-radius: 4px; position: relative;';
    div.innerHTML = `
      <div style="font-size: 13px; font-weight: 600; color: #1e293b; margin-bottom: 12px;">Author <span class="author-index">${index}</span></div>
      <button type="button" onclick="this.parentElement.remove(); updateAuthorIndices();" style="position: absolute; top: 12px; right: 12px; background: none; border: none; color: #ef4444; cursor: pointer; font-size: 14px;"><i class="fa fa-trash"></i></button>
      <input type="text" class="db-input author-name" placeholder="Author Name" value="${escapeHtml(name)}" style="width: 100%; margin-bottom: 8px; background: #f8fafc;">
      <input type="text" class="db-input author-affiliation" placeholder="Author Affiliation (e.g. School Name)" value="${escapeHtml(affiliation)}" style="width: 100%; margin-bottom: 8px; background: #f8fafc;">
      <div style="display:flex; gap:10px;">
        <input type="text" class="db-input author-orcid" placeholder="ORCID iD" value="${escapeHtml(orcid)}" style="flex:1; background: #f8fafc;">
        <input type="url" class="db-input author-scholar" placeholder="Google Scholar URL" value="${escapeHtml(scholar)}" style="flex:1; background: #f8fafc;">
      </div>
    `;
    container.appendChild(div);
  }

  function updateAuthorIndices() {
    const container = document.getElementById('finalAuthorsContainer');
    if (!container) return;
    Array.from(container.children).forEach((child, idx) => {
      const idxSpan = child.querySelector('.author-index');
      if (idxSpan) idxSpan.textContent = idx + 1;
    });
  }

  function collectAuthorsJson() {
    const container = document.getElementById('finalAuthorsContainer');
    if (!container) return '[]';
    const arr = [];
    Array.from(container.children).forEach(child => {
      const name = child.querySelector('.author-name').value.trim();
      const affiliation = child.querySelector('.author-affiliation').value.trim();
      const orcid = child.querySelector('.author-orcid').value.trim();
      const scholar = child.querySelector('.author-scholar').value.trim();
      if (name || affiliation || orcid || scholar) {
        arr.push({ name, affiliation, orcid, scholar });
      }
    });
    return JSON.stringify(arr);
  }

  function saveMetadata(event) {
    if (event) event.preventDefault();
    const id = document.getElementById('submissionIdField').value;
    const title = document.getElementById('finalTitle').value;
    const abstract = document.getElementById('finalAbstract').value;
    const keywords = document.getElementById('finalKeywords').value;
    const authorsJson = collectAuthorsJson();
    const authorsJsonHidden = document.getElementById('finalAuthorsJson');
    if (authorsJsonHidden) authorsJsonHidden.value = authorsJson;
    const statusEl = document.getElementById('editMetaStatus');
    
    statusEl.style.color = '#3b82f6';
    statusEl.textContent = 'Saving...';
    
    const formData = new FormData();
    formData.append('action', 'update_metadata');
    formData.append('submission_id', id);
    formData.append('title', title);
    formData.append('abstract', abstract);
    formData.append('keywords', keywords);
    formData.append('authors_json', authorsJson);
    
    const refHtmlEl = document.getElementById('finalReferencesHtml');
    if (refHtmlEl) {
      formData.append('references_html', refHtmlEl.value);
    }
    const csrfEl = document.querySelector('input[name="csrf_token"]');
    if (csrfEl) formData.append('csrf_token', csrfEl.value);

    fetch('admin-dashboard.php', { method: 'POST', body: formData })
      .then(r => r.json())
      .then(res => {
        if (res.success) {
          statusEl.style.color = '#10b981';
          statusEl.textContent = 'Saved successfully!';
          // Update local paper object
          const p = papers.find(item => Number(item.id) === Number(id));
          if (p) {
            p.title = title;
            p.abstract = abstract;
            p.keywords = keywords;
            p.authors_json = authorsJson;
            renderMetadata(p);
          }
        } else {
          statusEl.style.color = '#ef4444';
          statusEl.textContent = res.error || 'Failed to save.';
        }
      })
      .catch(err => {
        statusEl.style.color = '#ef4444';
        statusEl.textContent = 'Error occurred.';
      });
  }

  function openDrawer(id) {
    closeNavbarOverlay();

    const paper = papers.find(item => Number(item.id) === Number(id));
    if (!paper) return;

    currentPaper = paper;
    scores = {};
    pendingDecision = null;

    document.getElementById('submissionIdField').value = paper.id;
    const delField = document.getElementById('deleteSubmissionIdField');
    if (delField) delField.value = paper.id;
    document.getElementById('dTrackingId').textContent = paper.tracking_id || ('#' + paper.id);
    const badge = document.getElementById('dStatusBadge');
    badge.className = 'db-status ' + (paper.status_class || 'status-submitted');
    badge.textContent = paper.status_label || 'Submitted';

    document.querySelectorAll('.score-btn').forEach(button => button.classList.remove('selected'));
    document.querySelectorAll('.score-label-text').forEach(label => label.textContent = '-');
    document.getElementById('overallNum').textContent = '-';
    document.getElementById('overallLevel').textContent = 'Not yet scored';
    document.getElementById('fAuthor').value = '';
    document.getElementById('fInternal').value = '';
    // Extract submission details for fallback autofill
    let mTitle = '', mAbstract = '', mKeywords = '', mAuthorsJson = '', mAuthorsStr = '';
    let details = {};
    if (paper.submission_details_pairs && Object.keys(paper.submission_details_pairs).length) {
      Object.entries(paper.submission_details_pairs).forEach(([k, v]) => {
        const clean = normalizeDetailKey(k);
        if (clean) details[clean] = v;
      });
    }
    const rawDetails = parseSubmissionDetailsRaw(paper.submission_details_raw || '');
    Object.entries(rawDetails).forEach(([k, v]) => {
      const clean = normalizeDetailKey(k);
      if (clean && !details[clean]) details[clean] = v;
    });

    mTitle = details['title'] || '';
    mAbstract = details['abstract'] || '';
    mKeywords = details['keywords'] || '';
    mAuthorsJson = details['authors json'] || '';
    mAuthorsStr = details['authors'] || '';

    const titleEl = document.getElementById('finalTitle');
    if (titleEl) titleEl.value = paper.title || mTitle || '';
    
    const abstractEl = document.getElementById('finalAbstract');
    if (abstractEl) abstractEl.value = paper.abstract || mAbstract || '';
    
    const referencesHtmlEl = document.getElementById('finalReferencesHtml');
    if (referencesHtmlEl) referencesHtmlEl.value = paper.references_html || '';
    
    const keywordsEl = document.getElementById('finalKeywords');
    if (keywordsEl) keywordsEl.value = paper.keywords || mKeywords || '';
    
    let mAuthorsPayload = details['authors payload'] || details['authors_payload'] || '';
    if (mAuthorsPayload && typeof mAuthorsPayload !== 'string') {
      try { mAuthorsPayload = JSON.stringify(mAuthorsPayload); } catch(e) { mAuthorsPayload = ''; }
    }

    let finalAuthorsJson = paper.authors_json || mAuthorsJson || '';
    
    // Fallback logic to merge empty attributes from paper.authors_json if payload exists
    if (!finalAuthorsJson || finalAuthorsJson.length < 10) {
      finalAuthorsJson = mAuthorsPayload || finalAuthorsJson;
    } else if (mAuthorsPayload && finalAuthorsJson) {
      try {
        let pArr = JSON.parse(finalAuthorsJson);
        let mArr = JSON.parse(mAuthorsPayload);
        if (Array.isArray(pArr) && Array.isArray(mArr) && pArr.length > 0) {
          pArr.forEach((p, idx) => {
             if (mArr[idx]) {
               if (!p.orcid && mArr[idx].orcid) p.orcid = mArr[idx].orcid;
               if (!p.scholar && mArr[idx].scholar) p.scholar = mArr[idx].scholar;
               if (!p.affiliation && mArr[idx].school_name) p.affiliation = mArr[idx].school_name;
             }
          });
          finalAuthorsJson = JSON.stringify(pArr);
        }
      } catch(e) {}
    }

    if (!finalAuthorsJson) {
      const rawAuthors = paper.authors || mAuthorsStr || '';
      if (rawAuthors) {
        // Fallback: Convert comma-separated string to dynamic fields
        const arr = rawAuthors.split(',').map(n => ({ name: n.trim(), affiliation: '', orcid: '', scholar: '' })).filter(a => a.name);
        if (arr.length > 0) finalAuthorsJson = JSON.stringify(arr);
      }
    } else {
       // Make sure to map `school_name` to `affiliation` if needed, since the user-dashboard payload uses `school_name`
       try {
         let arr = JSON.parse(finalAuthorsJson);
         if (Array.isArray(arr)) {
           arr = arr.map(a => {
             return {
               name: a.name || a.Name || '',
               affiliation: a.affiliation || a.Affiliation || a.school_name || a.School_Name || '',
               orcid: a.orcid || a.Orcid || '',
               scholar: a.scholar || a.Scholar || ''
             };
           });
           finalAuthorsJson = JSON.stringify(arr);
         }
       } catch(e) {}
    }

    const authorsJsonEl = document.getElementById('finalAuthorsJson');
    if (authorsJsonEl) {
      authorsJsonEl.value = finalAuthorsJson;
      renderAuthorFields(finalAuthorsJson);
    }
    const statusEl = document.getElementById('editMetaStatus');
    if (statusEl) statusEl.textContent = '';

    renderMetadata(paper);
    renderHistory(paper);
    renderAssignmentPanel(paper);

    document.getElementById('rDrawer').classList.add('open');
    document.getElementById('dBackdrop').classList.add('open');
    document.body.style.overflow = 'hidden';
  }

  function openAssignmentDrawer(id) {
    openDrawer(id);
    window.setTimeout(() => {
      const search = document.getElementById('assignmentSearch');
      if (search) {
        search.focus();
        search.select();
      }
    }, 0);
  }

  function renderAssignmentPanel(paper) {
    const summaryBox = document.getElementById('assignmentSummary');
    const chipsBox = document.getElementById('assignmentSelectedChips');
    const choicesBox = document.getElementById('assignmentChoices');
    const assignmentSubmissionId = document.getElementById('assignmentSubmissionId');
    const assignmentActionField = document.getElementById('assignmentActionField');
    const searchInput = document.getElementById('assignmentSearch');

    if (!summaryBox || !chipsBox || !choicesBox || !assignmentSubmissionId || !assignmentActionField) {
      return;
    }

    assignmentSubmissionId.value = String(paper.id || '');
    assignmentActionField.value = 'update_assignment';
    if (searchInput) {
      searchInput.value = '';
    }

    const eligibleAdmins = Array.isArray(paper.eligible_admins) ? paper.eligible_admins : [];
    const assignedIds = Array.isArray(paper.assigned_admin_ids) ? paper.assigned_admin_ids.map(Number) : [];
    const selectedIds = new Set(assignedIds);
    const currentIsSelected = currentAdminId > 0 && selectedIds.has(Number(currentAdminId));

    const selectedAdmins = eligibleAdmins.filter(admin => selectedIds.has(Number(admin.id)));

    if (!eligibleAdmins.length) {
      summaryBox.innerHTML = '<div class="assignment-empty">No admins are eligible for this journal yet.</div>';
      chipsBox.innerHTML = '';
      choicesBox.innerHTML = '<div class="assignment-empty">No eligible admins were found for this paper.</div>';
      return;
    }

    summaryBox.innerHTML = paper.assignment_summary && paper.assignment_summary.label
      ? '<strong>' + escapeHtml(paper.assignment_summary.label) + '</strong> ' + escapeHtml(paper.assignment_summary.subcopy || '')
      : '<strong>Unclaimed</strong> Click to claim or assign';

    chipsBox.innerHTML = selectedAdmins.length
      ? selectedAdmins.map(admin => {
          const isCurrent = Number(admin.id) === Number(currentAdminId);
          return '<span class="assignment-chip' + (isCurrent ? ' current' : '') + '">' + escapeHtml(admin.name || 'Admin') + (isCurrent ? ' (you)' : '') + '</span>';
        }).join('')
      : '<span class="assignment-chip">Unclaimed</span>';

    choicesBox.innerHTML = eligibleAdmins.map(admin => {
      const adminId = Number(admin.id);
      const checked = selectedIds.has(adminId) ? ' checked' : '';
      const currentClass = adminId === Number(currentAdminId) ? ' is-current' : '';
      return '<label class="assignment-choice' + (checked ? ' is-selected' : '') + currentClass + '" data-search="' + escapeHtml((admin.name || '') + ' ' + (admin.email || '')) + '">'
        + '<input type="checkbox" name="assigned_admin_ids[]" value="' + adminId + '"' + checked + ' onchange="syncAssignmentSelection()">'
        + '<div class="assignment-choice-meta">'
        + '<div class="assignment-choice-name">' + escapeHtml(admin.name || 'Admin') + (adminId === Number(currentAdminId) ? ' (you)' : '') + '</div>'
        + '<div class="assignment-choice-email">' + escapeHtml(admin.email || '') + '</div>'
        + '</div>'
        + '</label>';
    }).join('');

    syncAssignmentSelection();

    const claimBtn = document.querySelector('.assignment-section .db-btn-success');
    if (claimBtn) {
      claimBtn.disabled = currentIsSelected;
      claimBtn.style.opacity = currentIsSelected ? '0.6' : '1';
      claimBtn.style.pointerEvents = currentIsSelected ? 'none' : 'auto';
      
      if (currentIsSelected) {
        claimBtn.innerHTML = '<i class="fa fa-hand-pointer-o"></i> Claimed';
      } else if (selectedIds.size > 0) {
        claimBtn.innerHTML = '<i class="fa fa-plus"></i> Add Myself';
      } else {
        claimBtn.innerHTML = '<i class="fa fa-hand-pointer-o"></i> Claim';
      }
    }
  }

  function syncAssignmentSelection() {
    const chipsBox = document.getElementById('assignmentSelectedChips');
    const choices = Array.from(document.querySelectorAll('.assignment-choice'));
    const selected = [];

    choices.forEach(choice => {
      const checkbox = choice.querySelector('input[type="checkbox"]');
      const isChecked = !!checkbox && checkbox.checked;
      choice.classList.toggle('is-selected', isChecked);
      if (isChecked) {
        const nameEl = choice.querySelector('.assignment-choice-name');
        selected.push({
          name: nameEl ? nameEl.textContent.replace(' (you)', '').trim() : 'Admin',
          current: nameEl ? nameEl.textContent.includes('(you)') : false,
        });
      }
    });

    if (chipsBox) {
      chipsBox.innerHTML = selected.length
        ? selected.map(item => '<span class="assignment-chip' + (item.current ? ' current' : '') + '">' + escapeHtml(item.name) + (item.current ? ' (you)' : '') + '</span>').join('')
        : '<span class="assignment-chip">Unclaimed</span>';
    }

    filterAssignmentChoices();
  }

  function filterAssignmentChoices() {
    const query = (document.getElementById('assignmentSearch')?.value || '').trim().toLowerCase();
    document.querySelectorAll('.assignment-choice').forEach(choice => {
      const search = (choice.dataset.search || '').toLowerCase();
      choice.style.display = !query || search.includes(query) ? '' : 'none';
    });
  }

  function claimCurrentPaper() {
    const form = document.getElementById('assignmentForm');
    if (!form || !currentPaper) {
      return;
    }

    if (Number(currentAdminId) <= 0) {
      showToast('Unable to claim right now. Please refresh and try again.');
      return;
    }

    document.querySelectorAll('.assignment-choice input[type="checkbox"]').forEach(input => {
      if (Number(input.value) === Number(currentAdminId)) {
        input.checked = true;
      }
    });

    syncAssignmentSelection();
    markClaimedUi(currentPaper.id);
    document.getElementById('assignmentSubmissionId').value = String(currentPaper.id || '');
    document.getElementById('assignmentActionField').value = 'update_assignment';
    
    submitAssignmentAjax(form);
  }

  function saveAssignment() {
    const form = document.getElementById('assignmentForm');
    if (!form || !currentPaper) {
      return;
    }

    document.getElementById('assignmentSubmissionId').value = String(currentPaper.id || '');
    document.getElementById('assignmentActionField').value = 'update_assignment';
    
    submitAssignmentAjax(form);
  }

  function submitAssignmentAjax(form) {
    const formData = new FormData(form);
    formData.append('ajax_assignment', '1');
    const saveBtn = document.querySelector('.assignment-section .db-btn-primary');
    const oldSaveText = saveBtn ? saveBtn.innerHTML : '';
    if (saveBtn) saveBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';

    fetch('admin-dashboard.php', { method: 'POST', body: formData })
      .then(r => r.json())
      .then(res => {
        if (saveBtn) saveBtn.innerHTML = oldSaveText;
        if (res.success) {
          showToast(res.message);
        } else {
          showToast(res.error || 'Failed to update assignment.');
        }
      })
      .catch(err => {
        if (saveBtn) saveBtn.innerHTML = oldSaveText;
        showToast('Error occurred while updating assignment.');
      });
  }

  function markClaimedUi(paperId) {
    const row = document.querySelector('.workspace-row[data-paper-id="' + String(Number(paperId)) + '"]');
    if (row) {
      const pill = row.querySelector('.assignment-pill');
      if (pill) {
        pill.classList.remove('assignment-unclaimed', 'assignment-shared');
        pill.classList.add('assignment-owned');
        const label = pill.querySelector('.assignment-pill-label');
        const subcopy = pill.querySelector('.assignment-pill-subcopy');
        if (label) label.textContent = 'Claimed by you';
        if (subcopy) subcopy.textContent = 'Assigned to you';
      }
    }

    const summaryBox = document.getElementById('assignmentSummary');
    if (summaryBox) {
      summaryBox.innerHTML = '<strong>Claimed by you</strong> Assigned to you';
    }

    const claimBtn = document.querySelector('.assignment-section .db-btn-success');
    if (claimBtn) {
      claimBtn.disabled = true;
      claimBtn.style.opacity = '0.6';
      claimBtn.style.pointerEvents = 'none';
    }
  }

  function closeNavbarOverlay() {
    const nav = document.getElementById('navbarSupportedContent');
    if (!nav) {
      return;
    }

    nav.classList.remove('show');
    nav.setAttribute('aria-expanded', 'false');

    const toggler = document.querySelector('.gysj-navbar .navbar-toggler');
    if (toggler) {
      toggler.classList.add('collapsed');
      toggler.setAttribute('aria-expanded', 'false');
    }
  }

  function openSubmissionDrawer(event, id) {
    if (event && event.target) {
      const interactive = event.target.closest('button, a, input, select, textarea, label');
      if (interactive) {
        return;
      }
    }

    openDrawer(id);
  }

  function closeDrawer() {
    document.getElementById('rDrawer').classList.remove('open');
    document.getElementById('dBackdrop').classList.remove('open');
    document.body.style.overflow = '';
    currentPaper = null;
  }

  function buildRubric() {
    const grid = document.getElementById('rubricGrid');
    grid.innerHTML = CRITERIA.map(criterion => {
      return '<div class="rubric-row">'
        + '<div class="rubric-top">'
        + '<div class="rubric-left">'
        + '<div class="rubric-criterion">' + escapeHtml(criterion.name) + '</div>'
        + '<div class="rubric-desc">' + escapeHtml(criterion.desc) + '</div>'
        + '</div>'
        + '<div class="rubric-right">'
        + '<div class="rubric-scores">'
        + [1,2,3,4,5].map(score => '<button type="button" class="score-btn" data-c="' + escapeHtml(criterion.key) + '" data-v="' + score + '" onclick="setScore(\'' + criterion.key + '\',' + score + ',this)">' + score + '</button>').join('')
        + '</div>'
        + '<span class="score-label-text" id="slabel-' + escapeHtml(criterion.key) + '">-</span>'
        + '</div>'
        + '</div>'
        + '</div>';
    }).join('');
  }

  function setScore(criterion, value) {
    scores[criterion] = value;
    document.querySelectorAll('.score-btn[data-c="' + criterion + '"]').forEach(button => {
      button.classList.toggle('selected', Number(button.dataset.v) === Number(value));
    });
    document.getElementById('slabel-' + criterion).textContent = SCORE_LABELS[value];
    refreshOverall();
  }

  function refreshOverall() {
    const values = Object.values(scores);
    const numEl = document.getElementById('overallNum');
    const lvlEl = document.getElementById('overallLevel');

    if (!values.length) {
      numEl.textContent = '-';
      lvlEl.textContent = 'Not yet scored';
      return;
    }

    const average = values.reduce((sum, value) => sum + Number(value), 0) / values.length;
    numEl.textContent = average.toFixed(1);
    lvlEl.textContent = values.length < CRITERIA.length
      ? 'Partial (' + values.length + '/' + CRITERIA.length + ' criteria scored)'
      : (LEVEL_NAMES[Math.round(average)] || '');
  }

  function setFilter(status, button) {
    currentFilter = status;
    document.querySelectorAll('.stat-tile.clickable').forEach(tile => tile.classList.remove('active'));
    if (button) {
      button.classList.add('active');
    }
    if (status !== 'all') {
      const tile = document.getElementById('stile' + cap(status));
      if (tile) tile.classList.add('active');
    }
    applyFilters();
  }

  function clickStat(status) {
    setFilter(status);
  }

  function applyFilters() {
    const query = (document.getElementById('searchBox')?.value || '').toLowerCase();
    let visible = 0;
    document.querySelectorAll('#workspaceTableBody tr').forEach(row => {
      const statusOk = currentFilter === 'all' || row.dataset.status === currentFilter;
      const searchOk = !query || (row.dataset.search || '').includes(query);
      const rowVisible = statusOk && searchOk;
      row.style.display = rowVisible ? '' : 'none';
      if (rowVisible) {
        visible++;
      }
    });

    const emptyMessage = document.getElementById('emptyMsg');
    if (emptyMessage) {
      emptyMessage.style.display = visible === 0 ? 'block' : 'none';
    }
  }

  function toggleArchive(group, button) {
    const section = document.querySelector('.submission-archive[data-submission-group="' + group + '"]');
    if (!section) return;

    const isExpanded = section.dataset.expanded === 'true';
    section.dataset.expanded = isExpanded ? 'false' : 'true';
    button.textContent = isExpanded ? 'View More' : 'Show Less';
    applyFilters();
  }

  function triggerDecision(decision) {
    if (!currentPaper) return;

    const feedback = document.getElementById('fAuthor').value.trim();
    if (!feedback && decision !== 'submitted' && decision !== 'escalated') {
      showToast('Please write feedback for the author before submitting a decision.');
      document.getElementById('fAuthor').focus();
      return;
    }

    // If accepting, require a published PDF upload and validate metadata fields
    if (decision === 'accepted') {
      const title = document.getElementById('finalTitle').value.trim();
      const abstract = document.getElementById('finalAbstract').value.trim();
      const keywords = document.getElementById('finalKeywords').value.trim();
      const authorsJson = collectAuthorsJson();

      if (!title || !abstract || !keywords || authorsJson === '[]') {
        showToast('Please fill out all metadata fields (Title, Abstract, Keywords, Authors) before accepting.');
        return;
      }
      document.getElementById('finalAuthorsJson').value = authorsJson;

      const fileInput = document.getElementById('fPublishedPdf');
      if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
        showToast('Please upload the final published PDF before accepting.');
        return;
      }
    }
    pendingDecision = decision;
    const titles = { 
      accepted:'Accept Submission', 
      needs_edits:'Request Edits', 
      rejected:'Reject Submission',
      escalated:'Ask Other Editors for Review',
      submitted:'Submit Evaluation'
    };
    const bodies = {
      accepted: 'You are about to accept this submission. The author will be notified and the paper will be eligible for publication.',
      needs_edits: 'You are about to request edits. The author will receive your feedback and be asked to revise and resubmit.',
      rejected: 'You are about to reject this submission. The author will be notified with your feedback. This cannot be undone.',
      escalated: 'You are about to ask other editors for review. They will be notified.',
      submitted: 'You are about to submit the evaluation rubric.'
    };
    document.getElementById('mTitle').textContent = titles[decision];
    document.getElementById('mBody').textContent = bodies[decision];
    document.getElementById('modalVeil').classList.add('open');
  }

  function closeModal() {
    document.getElementById('modalVeil').classList.remove('open');
    pendingDecision = null;
  }

  function triggerRejectAdmin(appId) {
    document.getElementById('mTitle').textContent = 'Reject Application';
    document.getElementById('mBody').textContent = 'Are you sure you want to reject this admin application?';
    const btns = document.querySelector('.modal-btns');
    btns.innerHTML = `
      <button class="db-btn" type="button" onclick="closeModal()">Cancel</button>
      <button class="db-btn db-btn-danger" type="button" onclick="confirmRejectAdmin(${appId})">Confirm Reject</button>
    `;
    document.getElementById('modalVeil').classList.add('open');
  }

  function confirmRejectAdmin(appId) {
    const form = document.getElementById('adminAppForm-' + appId);
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'reject_admin';
    form.appendChild(actionInput);
    form.submit();
  }

  function triggerDeletePaper() {
    if (!currentPaper) return;
    document.getElementById('mTitle').textContent = 'Delete Submission';
    document.getElementById('mBody').textContent = 'Are you sure you want to permanently delete this paper? This action cannot be undone.';
    const btns = document.querySelector('.modal-btns');
    btns.innerHTML = `
      <button class="db-btn" type="button" onclick="closeModal()">Cancel</button>
      <button class="db-btn db-btn-danger" type="button" onclick="document.getElementById('deletePaperForm').submit()">Confirm Delete</button>
    `;
    document.getElementById('modalVeil').classList.add('open');
  }

  function finalizeDecision() {
    if (!currentPaper || !pendingDecision) return;
    document.getElementById('decisionActionField').value = pendingDecision;
    
    const scoreKeys = Object.keys(scores);
    if (scoreKeys.length > 0 && (pendingDecision === 'submitted' || pendingDecision === 'escalated')) {
      let rubricText = "\n\n=== Rubric Evaluation ===\n";
      const numEl = document.getElementById('overallNum').textContent;
      const lvlEl = document.getElementById('overallLevel').textContent;
      rubricText += "Overall Score: " + numEl + " / 5.0 (" + lvlEl + ")\n";
      
      CRITERIA.forEach(c => {
        if (scores[c.key]) {
          rubricText += "- " + c.name + ": " + scores[c.key] + "/5 (" + SCORE_LABELS[scores[c.key]] + ")\n";
        }
      });
      
      const internalInput = document.getElementById('fInternal');
      internalInput.value = internalInput.value + rubricText;
    }

    document.getElementById('reviewForm').submit();
  }

  function showToast(message) {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.style.opacity = '1';
    toast.style.transform = 'translateX(-50%) translateY(0)';
    clearTimeout(toast._timer);
    toast._timer = setTimeout(() => {
      toast.style.opacity = '0';
      toast.style.transform = 'translateX(-50%) translateY(16px)';
    }, 3400);
  }

  function cap(value) {
    return value.charAt(0).toUpperCase() + value.slice(1);
  }

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      if (document.getElementById('modalVeil').classList.contains('open')) {
        closeModal();
      } else if (document.getElementById('rDrawer').classList.contains('open')) {
        closeDrawer();
      }
    }
  });

  const selectAllCheckbox = document.getElementById('selectAllSubmissions');
  const rowCheckboxes = document.querySelectorAll('.workspace-table tbody .checkbox-cell input[type="checkbox"]');
  const bulkActionsBar = document.getElementById('bulkActionsBar');
  const bulkActionsCount = document.getElementById('bulkActionsCount');

  function updateBulkActionsBar() {
    const checkedCount = document.querySelectorAll('.workspace-table tbody .checkbox-cell input[type="checkbox"]:checked').length;
    if (checkedCount > 0) {
      bulkActionsCount.textContent = checkedCount + (checkedCount === 1 ? ' paper selected' : ' papers selected');
      bulkActionsBar.classList.add('visible');
    } else {
      bulkActionsBar.classList.remove('visible');
    }
    
    const visibleCheckboxes = Array.from(rowCheckboxes).filter(cb => cb.closest('tr').style.display !== 'none');
    const checkedVisibleCheckboxes = visibleCheckboxes.filter(cb => cb.checked);
    if (selectAllCheckbox) {
      selectAllCheckbox.checked = visibleCheckboxes.length > 0 && visibleCheckboxes.length === checkedVisibleCheckboxes.length;
      selectAllCheckbox.indeterminate = checkedVisibleCheckboxes.length > 0 && checkedVisibleCheckboxes.length < visibleCheckboxes.length;
    }
  }

  if (selectAllCheckbox) {
    selectAllCheckbox.addEventListener('change', function(e) {
      const isChecked = e.target.checked;
      document.querySelectorAll('.workspace-table tbody tr').forEach(row => {
        if (row.style.display !== 'none') {
          const cb = row.querySelector('.checkbox-cell input[type="checkbox"]');
          if (cb) cb.checked = isChecked;
        }
      });
      updateBulkActionsBar();
    });
  }

  rowCheckboxes.forEach(cb => {
    cb.addEventListener('change', updateBulkActionsBar);
    cb.addEventListener('click', function(e) { e.stopPropagation(); });
  });

  document.querySelectorAll('.workspace-table tbody .checkbox-cell').forEach(cell => {
    cell.addEventListener('click', function(e) {
      e.stopPropagation();
      if (e.target.tagName !== 'INPUT') {
        const cb = this.querySelector('input[type="checkbox"]');
        if (cb) {
          cb.checked = !cb.checked;
          updateBulkActionsBar();
        }
      }
    });
  });

  function triggerBulkAction(type) {
    const checkedBoxes = document.querySelectorAll('.workspace-table tbody .checkbox-cell input[type="checkbox"]:checked');
    if (checkedBoxes.length === 0) return;
    
    const count = checkedBoxes.length;
    document.getElementById('mTitle').textContent = 'Confirm Bulk Action';
    
    let actionLabel = '';
    if (type === 'accept') actionLabel = 'ACCEPT';
    if (type === 'needs_edits') actionLabel = 'REQUEST EDITS for';
    if (type === 'reject') actionLabel = 'REJECT';
    if (type === 'delete') actionLabel = 'PERMANENTLY DELETE';
    
    document.getElementById('mBody').textContent = `Are you sure you want to ${actionLabel} ${count} selected ${count === 1 ? 'paper' : 'papers'}?`;
    
    const btns = document.querySelector('.modal-btns');
    btns.innerHTML = `
      <button class="db-btn" type="button" onclick="closeModal()">Cancel</button>
      <button class="db-btn ${type === 'delete' ? 'db-btn-danger' : 'db-btn-primary'}" type="button" onclick="confirmBulkAction('${type}')">Confirm ${type === 'needs_edits' ? 'Request Edits' : type.charAt(0).toUpperCase() + type.slice(1)}</button>
    `;
    
    document.getElementById('modalVeil').classList.add('open');
  }

  function confirmBulkAction(type) {
    const checkedBoxes = document.querySelectorAll('.workspace-table tbody .checkbox-cell input[type="checkbox"]:checked');
    const inputsContainer = document.getElementById('bulkSubmissionInputs');
    inputsContainer.innerHTML = '';
    
    checkedBoxes.forEach(cb => {
      const row = cb.closest('tr');
      const id = row.getAttribute('data-paper-id');
      if (id) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'submission_ids[]';
        input.value = id;
        inputsContainer.appendChild(input);
      }
    });
    
    document.getElementById('bulkTypeField').value = type;
    document.getElementById('bulkActionForm').submit();
  }

  init();
  </script>
  <!-- Chat Modal -->
  <div id="chatModalVeil" class="modal-veil" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); z-index:9999; align-items:center; justify-content:center;">
    <div class="db-modal" style="display:flex; flex-direction:column; width:95%; max-width:1200px; height:90vh; max-height:90vh; background:#fff; border-radius:0; box-shadow:0 10px 25px -5px rgba(0,0,0,0.1); overflow:hidden;">
      <div style="padding:16px 20px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; background:#f8fafc;">
        <div>
          <h3 style="margin:0; font-size:16px; font-weight:600; color:#0f172a;">Chat: <span id="chatModalTitle" style="font-weight:400;"></span></h3>
        </div>
        <button type="button" onclick="closeChatModal()" style="background:none; border:none; cursor:pointer; font-size:20px; color:#64748b; padding:0;">&times;</button>
      </div>
      <div id="chatMessageList" style="flex:1; padding:20px; overflow-y:auto; background:#fff; display:flex; flex-direction:column; gap:16px;">
        <!-- Messages loaded via AJAX -->
      </div>
      <div style="padding:16px 20px; border-top:1px solid #e2e8f0; background:#f8fafc;">
        <form id="chatForm" onsubmit="submitChatMessage(event)" style="display:flex; gap:12px;">
          <?php echo csrf_field(); ?>
          <input type="hidden" id="chatSubmissionId">
          <input type="text" id="chatInputMessage" style="flex:1; padding:10px 14px; border:1px solid #cbd5e1; border-radius:0; font-size:14px; outline:none;" placeholder="Type a message..." required>
          <button type="submit" class="db-btn db-btn-primary" style="padding:0 20px; background:#0284c7; border:none; border-radius:0; color:#fff; cursor:pointer; font-weight:600;"><i class="fa fa-paper-plane"></i> Send</button>
        </form>
      </div>
    </div>
  </div>

  <script>
    let currentChatSubId = null;
    let chatPollInterval = null;

    function openChatModal(id, title) {
      currentChatSubId = id;
      document.getElementById('chatSubmissionId').value = id;
      document.getElementById('chatModalTitle').textContent = title;
      document.getElementById('chatMessageList').innerHTML = '<div style="text-align:center; color:#94a3b8; padding:20px;">Loading chat...</div>';
      document.getElementById('chatModalVeil').classList.add('open');
      loadChatMessages();
      chatPollInterval = setInterval(loadChatMessages, 5000);
    }

    function closeChatModal() {
      document.getElementById('chatModalVeil').classList.remove('open');
      if (chatPollInterval) clearInterval(chatPollInterval);
    }

    function loadChatMessages() {
      if (!currentChatSubId) return;
      const formData = new FormData();
      formData.append('action', 'get_chat');
      formData.append('submission_id', currentChatSubId);
      const csrfToken = document.querySelector('#chatForm input[name="csrf_token"]') ? document.querySelector('#chatForm input[name="csrf_token"]').value : (document.querySelector('input[name="csrf_token"]') ? document.querySelector('input[name="csrf_token"]').value : '');
      if (csrfToken) formData.append('csrf_token', csrfToken);
      
      fetch('admin-dashboard.php', {
        method: 'POST',
        body: formData
      })
      .then(res => res.text())
      .then(text => {
          try {
              let data = JSON.parse(text);
              if (data.success) {
                  window._currentIsTyping = data.is_typing || false;
                  renderChatMessages(data.messages);
              } else {
                  document.getElementById('chatMessageList').innerHTML = '<div style="color:red; padding:20px;">Server returned success: false</div>';
              }
          } catch (e) {
              document.getElementById('chatMessageList').innerHTML = '<div style="color:red; padding:20px;">JSON Error: ' + e.message + '<br><br>Raw response:<br>' + escapeHtml(text.substring(0, 500)) + '</div>';
          }
      })
      .catch(err => {
          document.getElementById('chatMessageList').innerHTML = '<div style="color:red; padding:20px;">Fetch Error: ' + err.message + '</div>';
      });
    }

    function renderChatMessages(messages) {
      const list = document.getElementById('chatMessageList');
      if (messages.length === 0) {
        list.innerHTML = '<div style="text-align:center; color:#94a3b8; padding:20px; font-style:italic;">No messages yet. Send a message to the author!</div>';
        return;
      }
      
      let html = '';
      messages.forEach(msg => {
        const isAdmin = msg.sender_type === 'admin';
        const justify = isAdmin ? 'flex-end' : 'flex-start';
        const bg = isAdmin ? '#e0f2fe' : '#f1f5f9';
        
        html += '<div style="display:flex; justify-content:' + justify + '; margin-bottom: 12px;">';
        html += '<div style="max-width:80%; display:flex; flex-direction:column; align-items:' + (isAdmin ? 'flex-end' : 'flex-start') + ';">';
        html += '<div style="font-size:11px; color:#64748b; margin-bottom:4px; margin-left:2px; margin-right:2px;"><strong>' + escapeHtml(msg.sender_name) + '</strong> &bull; ' + escapeHtml(msg.created_at) + '</div>';
        html += '<div style="background:' + bg + '; padding:10px 14px; border-radius:0; font-size:14px; color:#334155; line-height:1.4; word-wrap:break-word;">' + escapeHtml(msg.message) + '</div>';
        html += '</div></div>';
      });
      if (window._currentIsTyping) {
        html += '<div style="display:flex; justify-content:flex-start; margin-bottom: 12px;">';
        html += '<div style="max-width:80%; display:flex; flex-direction:column; align-items:flex-start;">';
        html += '<div style="font-size:12px; color:#94a3b8; font-style:italic; padding:4px 0;">Author is typing...</div>';
        html += '</div></div>';
      }
      
      const wasAtBottom = list.scrollHeight - list.scrollTop <= list.clientHeight + 50;
      
      list.innerHTML = html;
      
      if (wasAtBottom) {
        list.scrollTop = list.scrollHeight;
      }
    }

    let typingTimeout = null;
    let lastTypingTime = 0;
    
    document.addEventListener('DOMContentLoaded', () => {
      const chatInput = document.getElementById('chatInputMessage');
      if (chatInput) {
        chatInput.addEventListener('input', () => {
          if (!currentChatSubId) return;
          const now = Date.now();
          if (now - lastTypingTime > 2000) {
            lastTypingTime = now;
            const formData = new FormData();
            formData.append('action', 'set_typing');
            formData.append('submission_id', currentChatSubId);
            const csrfToken = document.querySelector('#chatForm input[name="csrf_token"]') ? document.querySelector('#chatForm input[name="csrf_token"]').value : (document.querySelector('input[name="csrf_token"]') ? document.querySelector('input[name="csrf_token"]').value : '');
            if (csrfToken) formData.append('csrf_token', csrfToken);
            fetch('admin-dashboard.php', { method: 'POST', body: formData }).catch(err => console.error(err));
          }
        });
      }
    });

    function submitChatMessage(e) {
      e.preventDefault();
      const input = document.getElementById('chatInputMessage');
      const msg = input.value.trim();
      if (!msg) return;
      
      const formData = new FormData();
      formData.append('action', 'send_chat');
      formData.append('submission_id', currentChatSubId);
      const csrfToken = document.querySelector('#chatForm input[name="csrf_token"]') ? document.querySelector('#chatForm input[name="csrf_token"]').value : (document.querySelector('input[name="csrf_token"]') ? document.querySelector('input[name="csrf_token"]').value : '');
      if (csrfToken) formData.append('csrf_token', csrfToken);
      formData.append('message', msg);
      
      fetch('admin-dashboard.php', {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          input.value = '';
          loadChatMessages();
        } else {
          alert('Failed to send message.');
        }
      })
      .catch(err => alert("Send Error: " + err.message));
    }
  </script>
</body>
</html>
