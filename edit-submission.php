<?php
require_once __DIR__ . '/includes/bootstrap.php';

auth_require_login();
$user = auth_current_user();
if (!$user) {
  redirect('login.php');
}

if (($user['role'] ?? '') === 'admin') {
  redirect('admin-dashboard.php');
}

$success = '';
$error = '';

try {
  $pdo = db();
} catch (Throwable $e) {
  $pdo = null;
  $error = 'Database unavailable. Please try again later.';
}

$idParam = $_GET['id'] ?? '';
$idParam = is_string($idParam) ? trim($idParam) : '';
if ($idParam === '' || !ctype_digit($idParam)) {
  http_response_code(404);
  echo 'Not found.';
  exit;
}

$submissionId = (int) $idParam;
if ($submissionId <= 0) {
  http_response_code(404);
  echo 'Not found.';
  exit;
}

function validate_pdf_upload(array $file, int $maxBytes): array
{
  if (!isset($file['error'])) {
    return [false, 'Please upload a PDF file.'];
  }

  $err = (int) $file['error'];
  if ($err === UPLOAD_ERR_NO_FILE) {
    return [false, ''];
  }

  if ($err !== UPLOAD_ERR_OK) {
    return [false, 'Please upload a PDF file.'];
  }

  $size = (int) ($file['size'] ?? 0);
  if ($size <= 0) {
    return [false, 'Uploaded file is empty.'];
  }

  if ($size > $maxBytes) {
    return [false, 'File is too large.'];
  }

  $tmp = (string) ($file['tmp_name'] ?? '');
  if ($tmp === '' || !is_file($tmp)) {
    return [false, 'Upload failed.'];
  }

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = (string) $finfo->file($tmp);

  if ($mime !== 'application/pdf' && $mime !== 'application/x-pdf') {
    return [false, 'Only PDF files are allowed.'];
  }

  $original = (string) ($file['name'] ?? 'manuscript.pdf');
  if ($original === '') {
    $original = 'manuscript.pdf';
  }

  return [true, '', $mime, $size, $tmp, $original];
}

function edit_submission_parse_details(string $details): array
{
  $details = trim($details);
  if (str_starts_with($details, '{')) {
    $decoded = json_decode($details, true);
    if (is_array($decoded)) {
      return $decoded;
    }
  }

  $parsed = [];
  foreach (explode(',', $details) as $part) {
    $part = trim($part);
    if ($part === '') {
      continue;
    }

    $segments = explode(':', $part, 2);
    if (count($segments) !== 2) {
      continue;
    }

    $label = strtolower(trim($segments[0]));
    $value = trim($segments[1]);
    if ($label !== '' && $value !== '') {
      $parsed[$label] = $value;
    }
  }

  return $parsed;
}

function edit_submission_detail_value(array $details, array $labels, string $fallback = ''): string
{
  foreach ($labels as $label) {
    $key = strtolower(trim((string) $label));
    if ($key !== '' && isset($details[$key])) {
      return trim((string) $details[$key]);
    }
  }

  return $fallback;
}

function edit_submission_split_legacy_bio(string $authorBio, string $details = ''): array
{
  $authorBio = trim($authorBio);
  $details = trim($details);

  if ($details === '' && $authorBio !== '') {
    $parts = preg_split('/\R+\s*Submission details:\s*\R+/i', $authorBio, 2);
    if (is_array($parts) && count($parts) === 2) {
      $authorBio = trim((string) $parts[0]);
      $details = trim((string) $parts[1]);
    }
  }

  return [$authorBio, $details];
}

function edit_submission_split_phone(string $phone): array
{
  $phone = trim($phone);
  if ($phone === '') {
    return ['', ''];
  }

  if (preg_match('/^(\+\d{1,4})\s*(.*)$/', $phone, $match) === 1) {
    return [trim((string) $match[1]), trim((string) ($match[2] ?? ''))];
  }

  return ['', $phone];
}

function edit_submission_build_details(array $fields): string
{
  $parts = [];
  foreach ($fields as $label => $value) {
    $value = trim((string) $value);
    if ($value !== '') {
      $parts[] = $label . ': ' . $value;
    }
  }

  return trim(implode(', ', $parts));
}

function edit_submission_bool($value): bool
{
  if (is_bool($value)) {
    return $value;
  }

  $value = strtolower(trim((string) $value));
  return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function edit_submission_advanced_decode(array $details): array
{
  $raw = edit_submission_detail_value($details, ['advanced details'], '');
  if ($raw === '') {
    return [];
  }

  $json = base64_decode($raw, true);
  if ($json === false || $json === '') {
    return [];
  }

  $decoded = json_decode($json, true);
  return is_array($decoded) ? $decoded : [];
}

function edit_submission_advanced_get(array $advanced, string $key, string $fallback = ''): string
{
  if (!array_key_exists($key, $advanced)) {
    return $fallback;
  }

  $value = $advanced[$key];
  if (is_array($value) || is_object($value)) {
    return $fallback;
  }

  return trim((string) $value);
}

function edit_submission_advanced_get_bool(array $advanced, string $key, bool $fallback = false): bool
{
  if (!array_key_exists($key, $advanced)) {
    return $fallback;
  }

  return edit_submission_bool($advanced[$key]);
}

function edit_submission_advanced_encode(array $advanced): string
{
  $json = json_encode($advanced, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  if ($json === false) {
    return '';
  }

  return base64_encode($json);
}

$submitPaperTypes = [
  'Research Paper',
  'Case Study',
  'Survey Paper',
  'Ex Version Paper',
];
$submitJournals = [
  'Journal of Advance Research in Computer Science & Engineering',
  'Journal of Advance Research in Mathematics & Mathematical Sciences',
  'Journal of Advance Research in Applied Physics',
  'Journal of Advance Research in Applied Chemistry',
  'Journal of Advance Research in Civil Engineering',
  'Journal of Advance Research in Mechanical Engineering',
  'Journal of Advance Research in Business, Management & Accounting',
  'Journal of Advance Research in Electronics & Communication Engineering',
  'Journal of Advance Research in Humanities & Social Science',
  'Journal of Advance Research (General)',
  'Journal of Advance Research in Biology & Pharmacy',
  'Journal of Advance Research in Environmental Science',
];
$submitPhoneCodes = [
  '+91','+1','+44','+61','+64','+27',
  '+234','+254','+971','+65','+60','+63',
  '+62','+92','+880','+94','+977','+20',
  '+212','+49','+33','+39','+34','+55',
  '+52','+86','+81','+82','+66','+84',
];

function edit_submission_has_columns(PDO $pdo, array $required): bool
{
  static $cached = [];

  try {
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
  } catch (Throwable $e) {
    $driver = '';
  }

  if (!isset($cached[$driver])) {
    $cached[$driver] = [];

    try {
      if ($driver === 'sqlite') {
        $rows = $pdo->query('PRAGMA table_info(paper_submissions)')->fetchAll();
        if (is_array($rows)) {
          foreach ($rows as $row) {
            $name = strtolower((string) ($row['name'] ?? ''));
            if ($name !== '') {
              $cached[$driver][$name] = true;
            }
          }
        }
      } else {
        $rows = $pdo->query('SHOW COLUMNS FROM paper_submissions')->fetchAll();
        if (is_array($rows)) {
          foreach ($rows as $row) {
            $name = strtolower((string) ($row['Field'] ?? $row['field'] ?? ''));
            if ($name !== '') {
              $cached[$driver][$name] = true;
            }
          }
        }
      }
    } catch (Throwable $e) {
      $cached[$driver] = [];
    }
  }

  foreach ($required as $column) {
    $column = strtolower((string) $column);
    if ($column === '' || !isset($cached[$driver][$column])) {
      return false;
    }
  }

  return true;
}

$submission = null;
if ($pdo instanceof PDO) {
  try {
    $stmt = $pdo->prepare('SELECT * FROM paper_submissions WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$submissionId, (int) ($user['id'] ?? 0)]);
    $row = $stmt->fetch();
    if (is_array($row)) {
      $submission = $row;
    }
  } catch (Throwable $e) {
    $submission = null;
    $error = $error !== '' ? $error : 'Could not load submission.';
  }
}

if (!is_array($submission)) {
  http_response_code(404);
  echo 'Not found.';
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO) {
  csrf_validate();

  $currentStatus = (string) ($submission['status'] ?? 'submitted');
  if ($currentStatus !== 'needs_edits' && $currentStatus !== 'accepted' && $currentStatus !== 'submitted') {
    if ($currentStatus === 'rejected') {
      $error = 'This submission already has a decision and cannot be edited.';
    } else {
      $error = 'This submission is being processed and cannot be edited.';
    }
  } else {
    $title = trim((string) ($_POST['title'] ?? ''));
    $authors = trim((string) ($_POST['authors'] ?? ''));
    $abstract = trim((string) ($_POST['abstract'] ?? ''));
    $authorBio = trim((string) ($_POST['author_bio'] ?? ''));
    $paperType = trim((string) ($_POST['paper_type'] ?? ''));
    $journal = trim((string) ($_POST['journal'] ?? ''));
    $age = trim((string) ($_POST['age'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $phoneCode = trim((string) ($_POST['phone_code'] ?? ''));
    $phoneNumber = trim((string) ($_POST['phone_number'] ?? ''));
    $country = trim((string) ($_POST['country'] ?? ''));
    $gradeLevel = trim((string) ($_POST['grade_level'] ?? ''));
    $schoolName = trim((string) ($_POST['school_name'] ?? ''));
    $schoolEmail = trim((string) ($_POST['school_email'] ?? ''));
    $admissionNumber = trim((string) ($_POST['admission_number'] ?? ''));
    $keywords = trim((string) ($_POST['keywords'] ?? ''));
    $category = trim((string) ($_POST['category'] ?? ''));
    $guidelinesConfirmed = isset($_POST['guidelines_confirm']) && (string) $_POST['guidelines_confirm'] === '1';
    $authorConsent = isset($_POST['author_consent']) && (string) $_POST['author_consent'] === '1';
    $correspAuthorResp = isset($_POST['corresp_author_resp']) && (string) $_POST['corresp_author_resp'] === '1';
    $ageEligibility = isset($_POST['age_eligibility']) && (string) $_POST['age_eligibility'] === '1';
    $permissionSupervision = isset($_POST['permission_supervision']) && (string) $_POST['permission_supervision'] === '1';
    $originality = isset($_POST['originality']) && (string) $_POST['originality'] === '1';
    $concurrentSubmission = isset($_POST['concurrent_submission']) && (string) $_POST['concurrent_submission'] === '1';
    $ethicalCompliance = isset($_POST['ethical_compliance']) && (string) $_POST['ethical_compliance'] === '1';
    $aiPolicy = isset($_POST['ai_policy']) && (string) $_POST['ai_policy'] === '1';
    $formattingGuidelines = isset($_POST['formatting_guidelines']) && (string) $_POST['formatting_guidelines'] === '1';
    $publicationAgreement = isset($_POST['publication_agreement']) && (string) $_POST['publication_agreement'] === '1';
    $preprintServer = trim((string) ($_POST['preprint_server'] ?? 'no'));
    if ($preprintServer !== 'yes') {
      $preprintServer = 'no';
    }
    $preprintLink = trim((string) ($_POST['preprint_link'] ?? ''));
    $projectStory = trim((string) ($_POST['project_story'] ?? ''));
    $copyrightConfirmed = isset($_POST['copyright_confirm']) && (string) $_POST['copyright_confirm'] === '1';

    if (
      $paperType === '' || $journal === '' || $title === '' || $abstract === '' || $authors === '' ||
      $age === '' || $email === '' || $phoneCode === '' || $phoneNumber === '' || $country === '' ||
      $gradeLevel === '' || $schoolName === '' || $schoolEmail === '' || $admissionNumber === '' || $authorBio === ''
    ) {
      $error = 'Please complete all submission fields before saving.';
    } elseif (!in_array($paperType, $submitPaperTypes, true)) {
      $error = 'Please choose a valid paper type.';
    } elseif (!in_array($journal, $submitJournals, true)) {
      $error = 'Please choose a valid journal.';
    } elseif (!preg_match('/^[1-9][0-9]?$/', $age) || (int) $age >= 20) {
      $error = 'You must be under 20 years old to submit to this journal.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $error = 'Please enter a valid personal email address.';
    } elseif (!preg_match('/^\+?[0-9]{1,4}$/', $phoneCode)) {
      $error = 'Please enter a valid phone country code.';
    } elseif (!preg_match('/^[0-9][0-9\s\-()]{5,24}$/', $phoneNumber)) {
      $error = 'Please enter a valid phone number.';
    } elseif (!filter_var($schoolEmail, FILTER_VALIDATE_EMAIL)) {
      $error = 'Please enter a valid institutional email address.';
    } elseif ($schoolName === '' || $country === '' || $gradeLevel === '' || $admissionNumber === '') {
      $error = 'Please complete all institution and contact fields.';
    } elseif (!$guidelinesConfirmed || !$authorConsent || !$correspAuthorResp || !$ageEligibility || !$permissionSupervision || !$originality || !$concurrentSubmission || !$aiPolicy || !$formattingGuidelines || !$publicationAgreement || !$copyrightConfirmed) {
      $error = 'Please confirm all required submission statements before saving.';
    } else {
      $upload = $_FILES['manuscript'] ?? null;
      $hasFile = is_array($upload) && isset($upload['error']) && (int) $upload['error'] !== UPLOAD_ERR_NO_FILE;

      if ($hasFile && !in_array($currentStatus, ['needs_edits', 'accepted', 'submitted'])) {
        $error = 'A revised manuscript can only be uploaded when edits are requested.';
      } else {
        $movedPath = '';
        $destination = '';
        $hasSubmissionDetailsColumn = edit_submission_has_columns($pdo, ['submission_details']);

        try {
          $pdo->beginTransaction();

          $authorsPayloadRaw = trim((string) ($_POST['authors_payload'] ?? '[]'));
          $authorsData = json_decode($authorsPayloadRaw, true);
          if (!is_array($authorsData)) {
              $authorsData = [];
          }
          $authorsList = [];
          foreach ($authorsData as $author) {
              if (!empty($author['name'])) {
                  $authorsList[] = trim($author['name']);
              }
          }
          // The database 'authors' column expects a comma-separated list of names.
          $authors = implode(', ', $authorsList);

          $howHeard = trim((string) ($_POST['how_heard'] ?? ''));
          $setting = isset($_POST['setting']) && is_array($_POST['setting']) ? implode(', ', array_map('trim', $_POST['setting'])) : '';
          $agesArr = isset($_POST['ages']) && is_array($_POST['ages']) ? array_map('trim', $_POST['ages']) : [];
          $agesStr = implode(', ', $agesArr);
          $schoolTypeArr = isset($_POST['school_type']) && is_array($_POST['school_type']) ? array_map('trim', $_POST['school_type']) : [];
          $schoolTypeStr = implode(', ', $schoolTypeArr);
          $literatureTools = trim((string) ($_POST['literature_tools'] ?? ''));
          $softwareTools = trim((string) ($_POST['software_tools'] ?? ''));

          $phone = trim($phoneCode . ' ' . $phoneNumber);

          // We now save the JSON payload to match user-dashboard.php format
          $submissionDetails = json_encode([
            'Type' => $paperType,
            'Journal' => $journal,
            'how_heard' => $howHeard,
            'setting' => $setting,
            'ages' => $agesStr,
            'school_type' => $schoolTypeStr,
            'literature_tools' => $literatureTools,
            'software_tools' => $softwareTools,
            'Authors JSON' => $authorsData,
            'authors_payload' => $authorsPayloadRaw,
            'advanced_details' => [
                'guidelines_confirm' => $guidelinesConfirmed,
                'author_consent' => $authorConsent,
                'corresp_author_resp' => $correspAuthorResp,
                'age_eligibility' => $ageEligibility,
                'permission_supervision' => $permissionSupervision,
                'originality' => $originality,
                'concurrent_submission' => $concurrentSubmission,
                'ethical_compliance' => $ethicalCompliance,
                'ai_policy' => $aiPolicy,
                'formatting_guidelines' => $formattingGuidelines,
                'publication_agreement' => $publicationAgreement,
                'preprint_server' => $preprintServer,
                'preprint_link' => $preprintLink,
                'project_story' => $projectStory,
                'copyright_confirm' => $copyrightConfirmed,
            ]
          ]);

          $categoryValue = trim($paperType . ' | ' . $journal);

          if ($hasSubmissionDetailsColumn) {
            $stmt = $pdo->prepare('UPDATE paper_submissions SET title = ?, authors = ?, abstract = ?, author_bio = ?, submission_details = ?, keywords = ?, category = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?');
            $stmt->execute([
              $title,
              $authors,
              $abstract,
              $authorBio !== '' ? $authorBio : null,
              $submissionDetails !== '' ? $submissionDetails : null,
              $keywords !== '' ? $keywords : null,
              $categoryValue !== '' ? $categoryValue : null,
              $submissionId,
              (int) ($user['id'] ?? 0),
            ]);
          } else {
            $legacyAuthorBio = $authorBio;
            if ($submissionDetails !== '') {
              $legacyAuthorBio = trim($legacyAuthorBio);
              if ($legacyAuthorBio !== '') {
                $legacyAuthorBio .= "

";
              }
              $legacyAuthorBio .= "Submission details:
" . $submissionDetails;
            }

            $stmt = $pdo->prepare('UPDATE paper_submissions SET title = ?, authors = ?, abstract = ?, author_bio = ?, keywords = ?, category = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?');
            $stmt->execute([
              $title,
              $authors,
              $abstract,
              $legacyAuthorBio !== '' ? $legacyAuthorBio : null,
              $keywords !== '' ? $keywords : null,
              $categoryValue !== '' ? $categoryValue : null,
              $submissionId,
              (int) ($user['id'] ?? 0),
            ]);
          }

          $newStatus = $currentStatus;
          $newVersion = (int) ($submission['version'] ?? 1);

          if ($hasFile) {
            [$ok, $uploadError, $mime, $size, $tmp, $original] = validate_pdf_upload((array) $upload, 25 * 1024 * 1024);
            if (!$ok) {
              throw new RuntimeException($uploadError !== '' ? $uploadError : 'Please upload a PDF manuscript.');
            }

            $slug = (string) ($submission['slug'] ?? 'paper');
            if ($slug === '') {
              $slug = 'paper';
            }

            $newVersion = max(1, $newVersion + 1);

            $uploadDir = __DIR__ . '/uploads/submissions';
            if (!is_dir($uploadDir)) {
              mkdir($uploadDir, 0755, true);
            }

            $filename = $slug . '-v' . $newVersion . '-' . bin2hex(random_bytes(8)) . '.pdf';
            $movedPath = 'uploads/submissions/' . $filename;
            $destination = $uploadDir . '/' . $filename;

            if (!move_uploaded_file($tmp, $destination)) {
              throw new RuntimeException('Failed to save file.');
            }

            paper_archive_submission_version($pdo, $submission);

            $stmt = $pdo->prepare('UPDATE paper_submissions SET version = ?, manuscript_path = ?, manuscript_original_name = ?, manuscript_mime = ?, manuscript_size = ?, status = ?, reviewed_by = NULL, reviewed_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?');
            $stmt->execute([
              $newVersion,
              $movedPath,
              $original,
              $mime,
              (int) $size,
              'submitted',
              $submissionId,
              (int) ($user['id'] ?? 0),
            ]);

            $newStatus = 'submitted';
          }

          $pdo->commit();

          $view = 'overview';
          if ($newStatus === 'needs_edits') {
            $view = ($newVersion > 1) ? 'needs_revision' : 'sent_back';
          } elseif ($newStatus === 'submitted') {
            $view = ($newVersion > 1) ? 'revision_processing' : 'processing';
          } elseif ($newStatus === 'accepted' || $newStatus === 'rejected') {
            $view = 'decisions';
          }
          $target = 'user-dashboard.php' . ($view !== 'overview' ? ('?view=' . urlencode($view)) : '');
          redirect($target);
        } catch (Throwable $e) {
          if ($pdo->inTransaction()) {
            $pdo->rollBack();
          }
          if ($destination !== '' && is_file($destination)) {
            @unlink($destination);
          }
          $msg = trim((string) $e->getMessage());
          $error = $msg !== '' ? $msg : 'Could not update submission. Please try again.';
        }
      }
    }
  }

  // Reload submission after POST (if we didn\'t redirect).
  try {
    $stmt = $pdo->prepare('SELECT * FROM paper_submissions WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$submissionId, (int) ($user['id'] ?? 0)]);
    $row = $stmt->fetch();
    if (is_array($row)) {
      $submission = $row;
    }
  } catch (Throwable $e) {
    // Ignore.
  }
}

$titleVal = (string) ($submission['title'] ?? '');
$authorsVal = (string) ($submission['authors'] ?? '');
$abstractVal = (string) ($submission['abstract'] ?? '');
$authorBioVal = (string) ($submission['author_bio'] ?? '');
$keywordsVal = (string) ($submission['keywords'] ?? '');
$categoryVal = (string) ($submission['category'] ?? '');
$status = (string) ($submission['status'] ?? 'submitted');
$version = (int) ($submission['version'] ?? 1);
$originalName = (string) ($submission['manuscript_original_name'] ?? '');
$isEditable = in_array($status, ['needs_edits', 'accepted', 'submitted']);
$statusLabel = 'Submitted';
if ($status === 'needs_edits') {
  $statusLabel = 'Edits Requested';
} elseif ($status === 'accepted') {
  $statusLabel = 'Accepted';
} elseif ($status === 'rejected') {
  $statusLabel = 'Rejected';
}

$statusBadgeClass = 'secondary';
if ($status === 'accepted') {
  $statusBadgeClass = 'success';
} elseif ($status === 'rejected') {
  $statusBadgeClass = 'danger';
} elseif ($status === 'needs_edits') {
  $statusBadgeClass = 'warning';
}

$profile = [
  'email' => (string) ($user['email'] ?? ''),
  'phone' => '',
  'country' => '',
  'grade_level' => '',
  'school_name' => '',
  'school_email' => '',
  'admission_number' => '',
];
if ($pdo instanceof PDO) {
  try {
    $stmt = $pdo->prepare('SELECT email, phone, country, grade_level, school_name, school_email, admission_number FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int) ($user['id'] ?? 0)]);
    $row = $stmt->fetch();
    if (is_array($row)) {
      foreach ($profile as $key => $_value) {
        if (array_key_exists($key, $row) && $row[$key] !== null) {
          $profile[$key] = (string) $row[$key];
        }
      }
    }
  } catch (Throwable $e) {
    // Keep profile defaults.
  }
}

$submissionDetailsRaw = (string) ($submission['submission_details'] ?? '');
[$authorBioVal, $submissionDetailsRaw] = edit_submission_split_legacy_bio((string) ($submission['author_bio'] ?? ''), $submissionDetailsRaw);
$submissionDetails = edit_submission_parse_details($submissionDetailsRaw);

// NEW JSON LOGIC
if (isset($submissionDetails['authors_payload']) || isset($submissionDetails['Authors JSON']) || isset($submissionDetails['Authors payload'])) {
    $advancedDetails = $submissionDetails['advanced_details'] ?? [];
    if (!is_array($advancedDetails)) $advancedDetails = [];

    $paperTypeVal = $submissionDetails['type'] ?? $submissionDetails['Type'] ?? '';
    $journalVal = $submissionDetails['journal'] ?? $submissionDetails['Journal'] ?? '';
    
    $howHeardVal = $submissionDetails['how_heard'] ?? $submissionDetails['How heard'] ?? '';
    $settingVal = $submissionDetails['setting'] ?? $submissionDetails['Setting'] ?? '';
    $agesVal = $submissionDetails['ages'] ?? $submissionDetails['Ages'] ?? '';
    $schoolTypeVal = $submissionDetails['school_type'] ?? $submissionDetails['School type'] ?? '';
    $literatureToolsVal = $submissionDetails['literature_tools'] ?? $submissionDetails['Literature tools'] ?? '';
    $softwareToolsVal = $submissionDetails['software_tools'] ?? $submissionDetails['Software tools'] ?? '';
    
    $authorsData = $submissionDetails['authors_payload'] ?? $submissionDetails['Authors JSON'] ?? $submissionDetails['Authors payload'] ?? '[]';
    if (is_array($authorsData)) {
        $authorsPayloadVal = json_encode($authorsData);
    } else {
        $authorsPayloadVal = $authorsData;
    }
    
    // Default placeholders
    $emailVal = '';
    $phoneCodeVal = '';
    $phoneNumberVal = '';
    $countryVal = '';
    $ageVal = '';
    $gradeLevelVal = '';
    $schoolNameVal = '';
    $schoolEmailVal = '';
    $admissionNumberVal = '';
    
} else {
    // LEGACY COMMA-SEPARATED LOGIC
    $advancedDetails = edit_submission_advanced_decode($submissionDetails);
    $paperTypeVal = edit_submission_detail_value($submissionDetails, ['type'], '');
$journalVal = edit_submission_detail_value($submissionDetails, ['journal'], '');
$legacyCategory = trim((string) ($submission['category'] ?? ''));
if ($paperTypeVal === '' && $legacyCategory !== '') {
  $legacyParts = preg_split('/\s*\|\s*/', $legacyCategory, 2);
  if (is_array($legacyParts) && isset($legacyParts[0])) {
    $paperTypeVal = trim((string) $legacyParts[0]);
  }
  if ($journalVal === '' && is_array($legacyParts) && count($legacyParts) === 2) {
    $journalVal = trim((string) $legacyParts[1]);
  }
}
$ageVal = edit_submission_detail_value($submissionDetails, ['age'], '');
$emailVal = edit_submission_detail_value($submissionDetails, ['email'], (string) $profile['email']);
$phoneVal = edit_submission_detail_value($submissionDetails, ['phone'], (string) $profile['phone']);
[$phoneCodeVal, $phoneNumberVal] = edit_submission_split_phone($phoneVal);
$countryVal = edit_submission_detail_value($submissionDetails, ['country'], (string) $profile['country']);
$gradeLevelVal = edit_submission_detail_value($submissionDetails, ['grade', 'grade level', 'grade_level'], (string) $profile['grade_level']);
$schoolNameVal = edit_submission_detail_value($submissionDetails, ['school name', 'school_name'], (string) $profile['school_name']);
$schoolEmailVal = edit_submission_detail_value($submissionDetails, ['school email', 'school_email'], (string) $profile['school_email']);
$admissionNumberVal = edit_submission_detail_value($submissionDetails, ['admission number', 'admission_number'], (string) $profile['admission_number']);
    
    // Convert legacy author to payload
    $legacyAuthors = trim((string) ($submission['authors'] ?? ''));
    $authorsArr = [];
    $authorsArr[] = [
        'name' => $legacyAuthors,
        'age' => $ageVal,
        'email' => $emailVal,
        'phone_code' => $phoneCodeVal,
        'phone_number' => $phoneNumberVal,
        'country' => $countryVal,
        'grade_level' => $gradeLevelVal,
        'school_name' => $schoolNameVal,
        'school_email' => $schoolEmailVal,
        'admission_number' => $admissionNumberVal,
        'bio' => $authorBioVal,
        'orcid' => '',
        'scholar' => ''
    ];
    $authorsPayloadVal = json_encode($authorsArr);
    
    $howHeardVal = '';
    $settingVal = '';
    $agesVal = '';
    $schoolTypeVal = '';
    $literatureToolsVal = '';
    $softwareToolsVal = '';
} // END LOGIC BRANCH
$paperTypeFallback = $submitPaperTypes[0] ?? 'Research Paper';
if ($paperTypeVal === '') {
  $paperTypeVal = $paperTypeFallback;
}
$guidelinesConfirmedVal = edit_submission_advanced_get_bool($advancedDetails, 'guidelines_confirm', true);
$authorConsentVal = edit_submission_advanced_get_bool($advancedDetails, 'author_consent', true);
$correspAuthorRespVal = edit_submission_advanced_get_bool($advancedDetails, 'corresp_author_resp', true);
$ageEligibilityVal = edit_submission_advanced_get_bool($advancedDetails, 'age_eligibility', true);
$permissionSupervisionVal = edit_submission_advanced_get_bool($advancedDetails, 'permission_supervision', true);
$originalityVal = edit_submission_advanced_get_bool($advancedDetails, 'originality', true);
$concurrentSubmissionVal = edit_submission_advanced_get_bool($advancedDetails, 'concurrent_submission', true);
$ethicalComplianceVal = edit_submission_advanced_get_bool($advancedDetails, 'ethical_compliance', false);
$aiPolicyVal = edit_submission_advanced_get_bool($advancedDetails, 'ai_policy', true);
$formattingGuidelinesVal = edit_submission_advanced_get_bool($advancedDetails, 'formatting_guidelines', true);
$publicationAgreementVal = edit_submission_advanced_get_bool($advancedDetails, 'publication_agreement', true);
$preprintServerVal = edit_submission_advanced_get($advancedDetails, 'preprint_server', 'no');
if ($preprintServerVal !== 'yes') {
  $preprintServerVal = 'no';
}
$preprintLinkVal = edit_submission_advanced_get($advancedDetails, 'preprint_link', '');
$projectStoryVal = edit_submission_advanced_get($advancedDetails, 'project_story', '');
$copyrightConfirmedVal = edit_submission_advanced_get_bool($advancedDetails, 'copyright_confirm', true);
?>
<!DOCTYPE html>
<html lang="en" class="no-js">

<head>
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <link rel="shortcut icon" type="image/jpg" href="images/iysjournal.png">
  <title>Edit Submission | Global Youth Science Journal</title>
  <link href="css/media_query.css" rel="stylesheet" type="text/css">
  <link href="css/style.css" rel="stylesheet" type="text/css">
  <link href="css/bootstrap.css" rel="stylesheet" type="text/css">
  <link href="css/font-awesome.min.css" rel="stylesheet" crossorigin="anonymous">
  <link href="css/animate.css" rel="stylesheet" type="text/css">
  <link href="https://fonts.googleapis.com/css?family=Poppins" rel="stylesheet">
  <link href="css/owl.carousel.css" rel="stylesheet" type="text/css">
  <link href="css/owl.theme.default.css" rel="stylesheet" type="text/css">
  <link href="css/style_1.css" rel="stylesheet" type="text/css">
  <script src="js/modernizr-3.5.0.min.js"></script>
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

        <style>
          .edit-submission-shell {
            --edit-bg: #f5f5f3;
            --edit-card: #ffffff;
            --edit-border: #d9d9d4;
            --edit-border-strong: #b8b8b1;
            --edit-text: #111111;
            --edit-muted: #666660;
            --edit-soft: #efefec;
            --edit-focus: #111111;
            background: #d8d8d3;
            padding: 20px 0 32px;
          }

          .edit-submission-shell * {
            box-sizing: border-box;
          }

          .edit-submission-shell .edit-submission-wrap {
            width: min(1120px, calc(100% - 30px));
            margin: 0 auto;
          }

          .edit-submission-shell .edit-submission-header {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 18px;
          }

          .edit-submission-shell .edit-submission-kicker {
            font-size: 12px;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--edit-muted);
            margin-bottom: 8px;
          }

          .edit-submission-shell .edit-submission-title {
            margin: 0;
            font-size: clamp(23px, 2.4vw, 30px);
            line-height: 1.08;
            color: var(--edit-text);
            font-weight: 600;
          }

          .edit-submission-shell .edit-submission-subtitle {
            margin: 6px 0 0;
            color: var(--edit-muted);
            font-size: 13px;
            line-height: 1.6;
            max-width: 720px;
          }

          .edit-submission-shell .edit-submission-meta {
            font-size: 13px;
            color: var(--edit-muted);
            text-align: right;
            line-height: 1.6;
          }

          .edit-submission-shell .edit-submission-meta strong {
            color: var(--edit-text);
            font-weight: 600;
          }

          .edit-submission-shell .edit-submission-alert {
            border-radius: 0;
            border: 1px solid var(--edit-border);
            background: #fff;
            color: var(--edit-text);
            box-shadow: 0 12px 30px rgba(17, 17, 17, 0.04);
            margin-bottom: 16px;
          }

          .edit-submission-shell .edit-submission-alert-success {
            border-color: #bfc7bf;
            background: #fbfbfa;
          }

          .edit-submission-shell .edit-submission-alert-danger {
            border-color: #c8c8c4;
            background: #fff;
          }

          .edit-submission-shell .edit-submission-note {
            border-radius: 0;
            border: 1px solid var(--edit-border);
            background: var(--edit-card);
            padding: 12px 14px;
            margin-bottom: 16px;
            color: var(--edit-muted);
            font-size: 13px;
            line-height: 1.55;
          }

          .edit-submission-shell .edit-submission-form {
            display: grid;
            gap: 12px;
          }

          .edit-submission-shell .edit-card {
            background: var(--edit-card);
            border: 1px solid var(--edit-border);
            border-radius: 0;
            overflow: hidden;
            box-shadow: 0 16px 40px rgba(17, 17, 17, 0.05);
          }

          .edit-submission-shell .edit-card-head {
            padding: 13px 15px;
            border-bottom: 1px solid var(--edit-border);
            background: #f7f7f5;
          }

          .edit-submission-shell .edit-card-title {
            margin: 0;
            font-size: 14px;
            font-weight: 600;
            color: var(--edit-text);
          }

          .edit-submission-shell .edit-card-body {
            padding: 15px;
            display: grid;
            gap: 12px;
          }

          .edit-submission-shell .edit-grid {
            display: grid;
            gap: 10px;
          }

          .edit-submission-shell .edit-grid-2 {
            grid-template-columns: repeat(2, minmax(0, 1fr));
          }

          .edit-submission-shell .edit-grid-3 {
            grid-template-columns: repeat(3, minmax(0, 1fr));
          }

          .edit-submission-shell .edit-field {
            display: grid;
            gap: 5px;
          }

          .edit-submission-shell .edit-field label {
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: var(--edit-muted);
            margin: 0;
          }

          .edit-submission-shell .edit-required::after {
            content: ' *';
            color: var(--edit-text);
          }

          .edit-submission-shell input[type="text"],
          .edit-submission-shell input[type="email"],
          .edit-submission-shell input[type="number"],
          .edit-submission-shell input[type="tel"],
          .edit-submission-shell select,
          .edit-submission-shell textarea {
            width: 100%;
            border: 1px solid var(--edit-border);
            border-radius: 0;
            background: #fcfcfb;
            color: var(--edit-text);
            padding: 10px 12px;
            font-size: 14px;
            line-height: 1.5;
            transition: border-color 0.15s ease, box-shadow 0.15s ease, background-color 0.15s ease;
          }

          .edit-submission-shell textarea {
            min-height: 96px;
            resize: vertical;
          }

          .edit-submission-shell input[type="text"]:focus,
          .edit-submission-shell input[type="email"]:focus,
          .edit-submission-shell input[type="number"]:focus,
          .edit-submission-shell select:focus,
          .edit-submission-shell textarea:focus {
            border-color: var(--edit-focus);
            box-shadow: 0 0 0 3px rgba(17, 17, 17, 0.08);
            background: #fff;
            outline: none;
          }

          .edit-submission-shell select {
            appearance: none;
            -webkit-appearance: none;
            background-image: linear-gradient(45deg, transparent 50%, #666 50%), linear-gradient(135deg, #666 50%, transparent 50%);
            background-position: calc(100% - 18px) 55%, calc(100% - 12px) 55%;
            background-size: 6px 6px, 6px 6px;
            background-repeat: no-repeat;
            padding-right: 36px;
          }

          .edit-submission-shell .choice-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
          }

          .edit-submission-shell .choice-card {
            position: relative;
            display: block;
            border: 1px solid var(--edit-border);
            border-radius: 0;
            background: #fff;
            padding: 0;
            transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
            cursor: pointer;
            min-height: 48px;
          }

          .edit-submission-shell .choice-card:hover {
            border-color: var(--edit-border-strong);
            transform: translateY(-1px);
          }

          .edit-submission-shell .choice-card input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
          }

          .edit-submission-shell .choice-card span {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 48px;
            padding: 8px 12px;
            text-align: center;
            font-size: 13px;
            font-weight: 500;
            color: var(--edit-text);
            border-radius: 0;
            transition: background-color 0.15s ease, color 0.15s ease, border-color 0.15s ease;
          }

          .edit-submission-shell .choice-card input:checked + span {
            background: #111;
            color: #fff;
            box-shadow: inset 0 0 0 1px #111;
          }

          .edit-submission-shell .file-card {
            border: 1px solid var(--edit-border);
            border-radius: 0;
            background: #fcfcfb;
            padding: 12px;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
          }

          .edit-submission-shell .file-card-main {
            min-width: 0;
          }

          .edit-submission-shell .file-label {
            font-size: 11px;
            color: var(--edit-muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 6px;
          }

          .edit-submission-shell .file-name {
            font-size: 14px;
            font-weight: 600;
            color: var(--edit-text);
            word-break: break-word;
          }

          .edit-submission-shell .file-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 14px;
          }

          .edit-submission-shell .edit-link,
          .edit-submission-shell .edit-button,
          .edit-submission-shell .edit-button-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 0 14px;
            border-radius: 0;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: background-color 0.15s ease, color 0.15s ease, border-color 0.15s ease, transform 0.15s ease;
            border: 1px solid transparent;
          }

          .edit-submission-shell .edit-link,
          .edit-submission-shell .edit-button-secondary {
            background: #fff;
            border-color: var(--edit-border);
            color: var(--edit-text);
          }

          .edit-submission-shell .edit-link:hover,
          .edit-submission-shell .edit-button-secondary:hover {
            border-color: var(--edit-border-strong);
            background: #f7f7f6;
            color: var(--edit-text);
            text-decoration: none;
          }

          .edit-submission-shell .edit-button {
            background: #111;
            color: #fff;
            border-color: #111;
          }

          .edit-submission-shell .edit-button:hover {
            background: #000;
            color: #fff;
            text-decoration: none;
            transform: translateY(-1px);
          }

          .edit-submission-shell .edit-button:disabled {
            background: #c7c7c3;
            border-color: #c7c7c3;
            color: #fff;
            cursor: not-allowed;
            transform: none;
          }

          .edit-submission-shell .upload-card {
            border: 1.5px dashed var(--edit-border-strong);
            border-radius: 0;
            background: #fdfdfc;
            padding: 14px;
            display: grid;
            gap: 10px;
          }

          .edit-submission-shell .upload-card strong {
            color: var(--edit-text);
          }

          .edit-submission-shell .upload-card small {
            color: var(--edit-muted);
            font-size: 12px;
            line-height: 1.6;
          }

          .edit-submission-shell .edit-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            margin-top: 2px;
            padding: 4px 2px 0;
          }

          .edit-submission-shell .edit-footer-note {
            color: var(--edit-muted);
            font-size: 12px;
            line-height: 1.6;
          }

          .edit-submission-shell .edit-footer-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
          }

          @media (max-width: 900px) {
            .edit-submission-shell .edit-grid-2,
            .edit-submission-shell .edit-grid-3,
            .edit-submission-shell .choice-grid {
              grid-template-columns: 1fr;
            }

            .edit-submission-shell .edit-submission-meta {
              text-align: left;
            }

            .edit-submission-shell .file-card {
              flex-direction: column;
            }
          }

          @media (max-width: 640px) {
            .edit-submission-shell {
              padding: 20px 0 34px;
            }

            .edit-submission-shell .edit-submission-wrap {
              width: min(100%, calc(100% - 20px));
            }

            .edit-submission-shell .edit-card-body {
              padding: 14px;
            }

            .edit-submission-shell .edit-footer-actions,
            .edit-submission-shell .file-actions {
              width: 100%;
            }

            .edit-submission-shell .edit-link,
            .edit-submission-shell .edit-button,
            .edit-submission-shell .edit-button-secondary {
              width: 100%;
            }
          }

          .edit-submission-shell .edit-toggle-grid {
            display: grid;
            gap: 8px;
          }

          .edit-submission-shell .edit-check-row {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            border: 1px solid var(--edit-border);
            background: #fff;
            border-radius: 0;
            padding: 10px 12px;
            cursor: pointer;
          }

          .edit-submission-shell .edit-check-row input {
            margin-top: 3px;
            flex: 0 0 auto;
          }

          .edit-submission-shell .edit-check-row span {
            display: block;
            color: var(--edit-text);
            font-size: 13px;
            line-height: 1.45;
          }

          .edit-submission-shell .edit-check-row strong {
            font-weight: 600;
          }

          .edit-submission-shell .edit-radio-row {
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid var(--edit-border);
            background: #fff;
            border-radius: 0;
            padding: 10px 12px;
            font-size: 13px;
            color: var(--edit-text);
          }

          .edit-submission-shell .edit-radio-row input {
            margin: 0;
            flex: 0 0 auto;
          }

          .edit-submission-shell .edit-inline-help {
            color: var(--edit-muted);
            font-size: 12px;
            line-height: 1.55;
          }
        
.author-section {
      display: grid;
      gap: 14px;
    }

    .author-empty-box {
      padding: 18px;
      border: 1px dashed var(--color-border-secondary);
      background: var(--color-background-secondary);
      color: var(--color-text-secondary);
      font-size: 13px;
      line-height: 1.6;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      min-height: 120px;
      text-align: center;
    }

    .author-list {
      display: grid;
      gap: 14px;
    }

    .author-card {
      border: 1px solid var(--color-border-secondary);
      background: #fff;
      padding: 16px;
      display: grid;
      gap: 14px;
    }

    .author-card-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }

    .author-card-title {
      font-size: 14px;
      font-weight: 600;
      color: var(--color-text-primary);
    }

    .author-card-remove {
      border: 0.5px solid var(--color-border-secondary);
      background: var(--color-background-secondary);
      color: var(--color-text-secondary);
      padding: 7px 11px;
      font-size: 12px;
      cursor: pointer;
      border-radius: 0;
      font-family: var(--font-sans);
    }

    .author-card-remove:hover {
      background: #fff1f1;
      color: #991b1b;
      border-color: #f0b8b8;
    }

    .author-add-btn {
      width: fit-content;
    }

    .author-card .submit-field {
      margin-bottom: 0;
    }

    .submit-guidelines-box {
      border: 1px solid var(--color-border-secondary);
      padding: 16px;
      margin-bottom: 16px;
      background: var(--color-background-secondary);
    }

    .submit-guidelines-header {
      display: flex;
      gap: 12px;
      margin-bottom: 12px;
    }

    .submit-guidelines-icon {
      font-size: 28px;
      flex-shrink: 0;
    }

    .submit-guidelines-title {
      font-size: 14px;
      font-weight: 600;
      color: var(--color-text-primary);
      margin-bottom: 4px;
    }

    .submit-guidelines-text {
      font-size: 12px;
      color: var(--color-text-secondary);
      line-height: 1.5;
      margin: 0;
    }

    .submit-guidelines-link {
      display: inline-block;
      font-size: 12px;
      font-weight: 600;
      color: var(--color-accent);
      text-decoration: none;
      padding: 8px 12px;
      border: 1px solid var(--color-accent);
      background: transparent;
      transition: all 0.15s ease;
    }

    .submit-guidelines-link:hover {
      background: var(--color-accent);
      color: var(--color-text-primary);
      text-decoration: none;
    }

    .submit-country-box {
      border: 1px solid var(--color-border-secondary);
      padding: 0;
      background: var(--color-background-primary);
    }

    .submit-country-box:not(.open) .submit-country-list-wrapper {
      display: none;
    }

    .submit-country-box.open .submit-country-search {
      border-bottom-color: var(--color-accent);
      background: var(--color-background-secondary);
    }

    .submit-country-search {
      width: 100%;
      border: none;
      border-bottom: 1px solid var(--color-border-secondary);
      padding: 11px 14px;
      background: var(--color-background-primary);
      color: var(--color-text-primary);
      font-size: 14px;
      font-family: var(--font-sans);
      outline: none;
      box-sizing: border-box;
    }

    .submit-country-search:focus {
      background: var(--color-background-secondary);
      border-bottom-color: var(--color-accent);
    }

    .submit-country-search::placeholder {
      color: var(--color-text-tertiary);
    }

    .submit-country-list-wrapper {
      max-height: 300px;
      overflow-y: auto;
      display: flex;
      flex-direction: column;
    }

    .submit-country-item {
      padding: 11px 14px;
      cursor: pointer;
      border-bottom: 1px solid var(--color-border-tertiary);
      font-size: 14px;
      color: var(--color-text-primary);
      background: var(--color-background-primary);
      transition: background-color 0.15s ease;
      text-align: left;
      border: none;
      width: 100%;
      text-align: left;
      font-family: var(--font-sans);
    }

    .submit-country-item:hover {
      background: var(--color-background-secondary);
    }

    .submit-country-item.selected {
      background: var(--color-accent);
      color: var(--color-text-primary);
      font-weight: 600;
    }

    .submit-country-item:last-child {
      border-bottom: none;
    }

    .submit-country-empty {
      padding: 20px 14px;
      text-align: center;
      color: var(--color-text-tertiary);
      font-size: 13px;
    }

    .submit-country-box .submit-select {
      display: none;
    }

    .submit-label {
      display: block;
      font-size: 11.5px;
      font-weight: 600;
      color: var(--color-text-secondary);
      text-transform: uppercase;
      letter-spacing: 0.06em;
      margin-bottom: 7px;
    }

    .submit-req {
      color: #b45309;
      margin-left: 2px;
    }

    .submit-hint {
      font-size: 12px;
      color: var(--color-text-tertiary);
      line-height: 1.5;
      margin-top: 6px;
    }

    .submit-err,
    .submit-ineligible {
      display: none;
      margin-top: 8px;
      padding: 12px 14px;
      border-radius: 0;
      font-size: 12.5px;
      line-height: 1.55;
    }

    .submit-err.on,
    .submit-ineligible.on {
      display: block;
    }

    .submit-err {
      color: #8b1d1d;
      border: 0.5px solid #f3b8b8;
      background: #fef2f2;
    }

    .submit-ineligible {
      color: #8f4e00;
      border: 0.5px solid #f0d5a3;
      background: #fff8ec;
    }

    .submit-input,
    .submit-select,
    .submit-textarea {
      width: 100%;
      border: 0.5px solid var(--color-border-secondary);
      border-radius: 0;
      padding: 11px 14px;
      background: var(--color-background-primary);
      color: var(--color-text-primary);
      font-size: 14px;
      line-height: 1.55;
      font-family: var(--font-sans);
      outline: none;
      transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }

    .submit-input:focus,
    .submit-select:focus,
    .submit-textarea:focus {
      border-color: var(--color-accent);
      box-shadow: 0 0 0 3px rgba(215, 155, 0, 0.14);
    }

    .submit-input.bad,
    .submit-select.bad,
    .submit-textarea.bad {
      border-color: #b42318;
      box-shadow: 0 0 0 3px rgba(180, 35, 24, 0.08);
    }

    .submit-select {
      appearance: none;
      -webkit-appearance: none;
      background-image: none;
      background-position: calc(100% - 17px) calc(50% - 3px), calc(100% - 11px) calc(50% - 3px);
      background-size: 6px 6px, 6px 6px;
      background-repeat: no-repeat;
      padding-right: 34px;
      cursor: pointer;
    }

    .submit-textarea {
      min-height: 124px;
      resize: vertical;
    }

    .submit-upload-layout {
      display: grid;
      grid-template-columns: minmax(220px, 280px) 1fr;
      gap: 18px;
      align-items: stretch;
    }

    #submitManuscriptInput {
      position: absolute;
      left: -9999px;
      width: 1px;
      height: 1px;
      opacity: 0;
      pointer-events: none;
    }

    .submit-upload-copy {
      font-size: 13px;
      color: var(--color-text-primary);
      line-height: 1.55;
      font-style: italic;
      font-weight: 600;
    }

    .submit-upload-copy p {
      margin: 0 0 12px;
    }

    .submit-upload-copy ul {
      margin: 8px 0 12px 18px;
      padding: 0;
    }

    .submit-upload-copy li {
      margin-bottom: 4px;
    }

    .submit-upload-copy .submit-upload-note {
      font-style: normal;
      font-weight: 600;
      margin-top: 10px;
    }

    .submit-upload-shell {
      position: relative;
      min-height: 150px;
      border: 1px solid #cad5e1;
      border-radius: 0;
      background: #e2e9f1;
      padding: 24px 22px;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 28px;
      flex-wrap: wrap;
      transition: background 180ms ease, border-color 180ms ease, box-shadow 180ms ease;
    }

    .submit-upload-shell.over {
      background: #dce7f5;
      border-color: #bfcdda;
    }

    .submit-upload-shell.has-file {
      background: #d9e3ee;
      border-color: #b8c6d4;
      align-items: stretch;
      justify-content: stretch;
    }

    .submit-upload-attachments {
      display: none;
      margin-top: 14px;
      border: 1px solid #ccd6e2;
      border-radius: 0;
      background: #f7fafc;
      overflow: hidden;
    }

    .submit-upload-attachments.open {
      display: block;
    }

    .submit-upload-attachments-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 14px 16px;
      background: linear-gradient(180deg, #ffffff 0%, #f5f8fb 100%);
      border-bottom: 1px solid #dde5ee;
      font-size: 13px;
      font-weight: 700;
      color: var(--color-text-primary);
    }

    .submit-upload-attachments-subtitle {
      font-size: 11px;
      font-weight: 600;
      color: var(--color-text-secondary);
    }

    .submit-upload-attachments-grid {
      display: grid;
      grid-template-columns: 56px 180px minmax(220px, 1fr) minmax(180px, 1fr) 120px 120px 70px;
      gap: 0;
      align-items: stretch;
    }

    .submit-upload-attachments-grid > div {
      border-right: 1px solid #e2e8f0;
      border-bottom: 1px solid #e2e8f0;
      background: #ffffff;
      padding: 12px 10px;
      font-size: 12px;
      color: var(--color-text-primary);
      min-width: 0;
      box-sizing: border-box;
    }

    .submit-upload-attachments-grid > div:nth-child(7n) {
      border-right: none;
    }

    .submit-upload-attachments-grid .submit-upload-attachments-headcell {
      background: #edf3f8;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: #415164;
    }

    .submit-upload-attachment-order,
    .submit-upload-attachment-size,
    .submit-upload-attachment-actions,
    .submit-upload-attachment-select {
      display: flex;
      align-items: center;
      justify-content: center;
      text-align: center;
    }

    .submit-upload-attachment-file {
      font-weight: 600;
      word-break: break-word;
      line-height: 1.45;
    }

    .submit-upload-attachment-type,
    .submit-upload-attachment-desc {
      width: 100%;
      border: 1px solid #cfd8e3;
      border-radius: 0;
      padding: 8px 10px;
      font-size: 12px;
      background: #fff;
      color: var(--color-text-primary);
      box-sizing: border-box;
    }

    .submit-upload-attachment-desc::placeholder {
      color: #94a3b8;
    }

    .submit-upload-attachment-warning {
      display: none;
      margin-top: 10px;
      font-size: 12px;
      line-height: 1.45;
      color: #b91c1c;
      font-weight: 700;
    }

    .submit-upload-attachment-warning.on {
      display: block;
    }

    .submit-upload-attachment-actions {
      gap: 8px;
      flex-wrap: wrap;
      justify-content: center;
    }

    .submit-upload-attachment-link {
      border: 0;
      background: transparent;
      color: var(--color-accent-dark);
      font-size: 12px;
      font-weight: 700;
      padding: 0;
      cursor: pointer;
    }

    .submit-upload-attachment-link:hover {
      text-decoration: underline;
    }

    @media (max-width: 991px) {
      .submit-upload-attachments-grid {
        grid-template-columns: 48px minmax(140px, 160px) minmax(180px, 1fr);
      }

      .submit-upload-attachments-grid .submit-upload-attachments-headcell:nth-child(n + 4),
      .submit-upload-attachments-grid > div:nth-child(n + 4) {
        grid-column: span 3;
      }
    }

    @media (max-width: 575px) {
      .submit-upload-attachments-head {
        flex-direction: column;
        align-items: flex-start;
      }

      .submit-upload-attachments-grid {
        grid-template-columns: 1fr;
      }

      .submit-upload-attachments-grid > div {
        border-right: none;
      }
    }

    .submit-upload-cta {
      display: flex;
      align-items: center;
      gap: 22px;
      flex-wrap: wrap;
      justify-content: center;
      text-align: center;
    }

    .submit-upload-browse {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 9px 16px;
      border: 0;
      border-radius: 0;
      background: #3d6ea8;
      color: #ffffff;
      font-family: var(--font-sans);
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      box-shadow: 0 2px 0 rgba(0, 0, 0, 0.18);
    }

    .submit-upload-browse:hover {
      background: #315d92;
    }

    .submit-upload-or {
      font-size: 12px;
      font-weight: 700;
      color: var(--color-text-primary);
      letter-spacing: 0.04em;
    }

    .submit-upload-drop {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 8px;
      min-width: 130px;
      color: #1f2937;
      font-size: 12px;
      line-height: 1.15;
      text-align: center;
      user-select: none;
    }

    .submit-upload-drop-icon {
      width: 44px;
      height: 54px;
      border-radius: 0;
      background: rgba(255, 255, 255, 0.4);
      border: 0.5px solid rgba(0, 0, 0, 0.08);
      position: relative;
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.55);
    }

    .submit-upload-drop-icon::before {
      content: '';
      position: absolute;
      left: 50%;
      top: 11px;
      width: 12px;
      height: 12px;
      border-left: 3px solid #b4bcc7;
      border-top: 3px solid #b4bcc7;
      transform: translateX(-50%) rotate(45deg);
    }

    .submit-upload-drop-icon::after {
      content: '';
      position: absolute;
      left: 50%;
      bottom: 8px;
      width: 18px;
      height: 16px;
      border-top: 4px solid #b4bcc7;
      transform: translateX(-50%);
    }

    .submit-upload-status {
      margin-top: 14px;
      padding-top: 12px;
      border-top: 0.5px solid rgba(0, 0, 0, 0.08);
      font-size: 12px;
      color: var(--color-text-secondary);
      text-align: center;
      min-height: 18px;
      word-break: break-word;
    }

    .submit-upload-selected {
      display: none;
      width: 100%;
      min-height: 150px;
      padding: 0;
      align-items: center;
      justify-content: center;
    }

    .submit-upload-selected.open {
      display: flex;
      flex-direction: column;
      gap: 14px;
    }

    .submit-upload-selected-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
    }

    .submit-upload-selected-title {
      font-size: 13px;
      font-weight: 700;
      color: var(--color-text-primary);
    }

    .submit-upload-selected-row {
      display: grid;
      grid-template-columns: 44px 1fr auto;
      gap: 12px;
      align-items: center;
      padding: 16px 18px;
      border: 1px solid #cfd8e3;
      border-radius: 0;
      background: rgba(255, 255, 255, 0.82);
      width: 100%;
      max-width: 100%;
      box-shadow: 0 1px 0 rgba(15, 23, 42, 0.03);
    }

    .submit-upload-selected-file {
      width: 44px;
      height: 54px;
      border: 1px solid #cbd5e1;
      background: linear-gradient(180deg, #f8fafc 0%, #edf3f8 100%);
      position: relative;
      border-radius: 0;
    }

    .submit-upload-selected-file::before {
      content: '';
      position: absolute;
      right: 0;
      top: 0;
      width: 12px;
      height: 12px;
      background: #d7e1ec;
      clip-path: polygon(0 0, 100% 0, 100% 100%);
    }

    .submit-upload-selected-file::after {
      content: 'DOCX';
      position: absolute;
      left: 50%;
      bottom: 10px;
      transform: translateX(-50%);
      font-size: 8px;
      font-weight: 700;
      letter-spacing: 0.08em;
      color: #64748b;
    }

    .submit-upload-selected-name {
      font-size: 13px;
      font-weight: 600;
      color: var(--color-text-primary);
      word-break: break-word;
    }

    .submit-upload-selected-meta {
      font-size: 11px;
      color: var(--color-text-secondary);
      margin-top: 4px;
    }

    .submit-upload-selected-actions {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      justify-content: flex-end;
    }

    .submit-upload-selected-footer {
      width: 100%;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
      font-size: 12px;
      color: var(--color-text-secondary);
    }

    .submit-upload-link {
      border: 0;
      background: transparent;
      color: var(--color-accent-dark);
      font-size: 12px;
      font-weight: 700;
      padding: 0;
      cursor: pointer;
    }

    .submit-upload-link:hover {
      text-decoration: underline;
    }

    .submit-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 16px;
    }

    .submit-grid.three {
      grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .submit-type-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
    }

    .submit-type-card {
      position: relative;
      border: 0.5px solid var(--color-border-secondary);
      border-radius: 0;
      padding: 16px;
      background: var(--color-background-primary);
      cursor: pointer;
      transition: border-color 0.15s ease, background-color 0.15s ease, transform 0.15s ease;
      min-height: 118px;
      user-select: none;
    }

    .submit-type-card:hover {
      border-color: var(--color-accent);
      background: #fffaf0;
      transform: translateY(-1px);
    }

    .submit-type-card.sel {
      border-color: var(--color-accent);
      background: rgba(240, 180, 41, 0.09);
    }

    .submit-type-card input {
      position: absolute;
      opacity: 0;
      pointer-events: none;
    }

    .submit-type-icon {
      font-size: 26px;
      margin-bottom: 10px;
      display: block;
    }

    .submit-type-name {
      font-size: 14px;
      font-weight: 600;
      color: var(--color-text-primary);
      margin-bottom: 4px;
    }

    .submit-type-desc {
      font-size: 12px;
      color: var(--color-text-secondary);
      line-height: 1.45;
    }

    .submit-check {
      position: absolute;
      top: 12px;
      right: 12px;
      width: 18px;
      height: 18px;
      border-radius: 0;
      border: 0.5px solid var(--color-border-secondary);
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--color-text-primary);
      font-size: 10px;
      transition: all 0.15s ease;
    }

    .submit-type-card.sel .submit-check {
      background: var(--color-accent);
      border-color: var(--color-accent);
    }

    .submit-list {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .submit-choice {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 11px 14px;
      border: 0.5px solid var(--color-border-secondary);
      border-radius: 0;
      cursor: pointer;
      transition: border-color 0.15s ease, background-color 0.15s ease;
      background: var(--color-background-primary);
      user-select: none;
      position: relative;
    }

    .submit-choice input[type="radio"] {
      position: absolute;
      opacity: 0;
      pointer-events: none;
    }

    .submit-choice:hover {
      border-color: var(--color-accent);
      background: #fffaf0;
    }

    .submit-choice.sel {
      border-color: var(--color-accent);
      background: rgba(240, 180, 41, 0.09);
    }

    .submit-choice-dot {
      width: 16px;
      height: 16px;
      border-radius: 0;
      border: 2px solid var(--color-border-secondary);
      flex-shrink: 0;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .submit-choice.sel .submit-choice-dot {
      border-color: var(--color-accent);
      background: var(--color-accent);
    }

    .submit-choice-dot::after {
      content: '';
      width: 6px;
      height: 6px;
      border-radius: 0;
      background: var(--color-text-primary);
      opacity: 0;
    }

    .submit-choice.sel .submit-choice-dot::after {
      opacity: 1;
    }

    .submit-choice-text {
      font-size: 13.5px;
      line-height: 1.45;
      color: var(--color-text-primary);
    }

    .submit-choice-prefix {
      color: var(--color-text-tertiary);
      font-size: 11px;
      font-weight: 500;
    }

    .submit-phone-row {
      display: flex;
      gap: 8px;
      align-items: flex-start;
    }

    .submit-phone-row .submit-select {
      width: 170px;
      flex-shrink: 0;
    }

    .submit-drop-zone {
      border: 1px dashed var(--color-border-secondary);
      border-radius: 0;
      background: var(--color-background-secondary);
      padding: 30px 18px;
      text-align: center;
      cursor: pointer;
      transition: border-color 0.15s ease, background-color 0.15s ease;
    }

    .submit-drop-zone:hover,
    .submit-drop-zone.over {
      border-color: var(--color-accent);
      background: rgba(240, 180, 41, 0.09);
    }

    .submit-drop-icon {
      font-size: 34px;
      margin-bottom: 8px;
      display: block;
    }

    .submit-drop-main {
      font-size: 14px;
      font-weight: 500;
      color: var(--color-text-primary);
      margin-bottom: 4px;
    }

    .submit-drop-or,
    .submit-drop-note {
      font-size: 12px;
      color: var(--color-text-tertiary);
      line-height: 1.5;
    }

    .submit-browse {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 8px 18px;
      border-radius: 0;
      background: var(--color-accent);
      color: var(--color-text-primary);
      font-size: 13px;
      font-weight: 500;
      margin: 8px 0 10px;
    }

    .submit-file-picked {
      display: none;
      align-items: center;
      gap: 8px;
      margin-top: 12px;
      padding: 10px 12px;
      border-radius: 0;
      background: #edf8f1;
      border: 0.5px solid #b8dfc5;
      font-size: 12.5px;
      color: #17643c;
    }

    .submit-file-picked.on {
      display: flex;
    }

    .submit-file-remove {
      margin-left: auto;
      cursor: pointer;
      color: var(--color-text-tertiary);
      font-size: 15px;
    }

    .submit-copy-box {
      padding: 16px 18px;
      border-radius: 0;
      border: 0.5px solid var(--color-border-secondary);
      background: var(--color-background-secondary);
      color: var(--color-text-secondary);
      font-size: 13px;
      line-height: 1.7;
    }

    .submit-copy-link {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      margin: 10px 0 12px;
      padding: 8px 14px;
      border-radius: 0;
      background: var(--color-accent);
      color: var(--color-text-primary);
      text-decoration: none;
      font-size: 13px;
      font-weight: 500;
    }

    .submit-check-row {
      display: flex;
      gap: 12px;
      align-items: flex-start;
      padding: 14px 16px;
      border-radius: 0;
      border: 0.5px solid var(--color-border-secondary);
      background: var(--color-background-primary);
      cursor: pointer;
    }

    .submit-check-row input[type="checkbox"] {
      width: 17px;
      height: 17px;
      margin-top: 2px;
      flex-shrink: 0;
      accent-color: #0284c7;
      cursor: pointer;
    }

    .submit-check-row label {
      font-size: 13px;
      color: var(--color-text-secondary);
      line-height: 1.6;
      cursor: pointer;
    }

    .submit-checkbox-group {
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .submit-checkbox-item {
      padding: 0;
      border: none;
      background: none;
    }

    .submit-checkbox-item .submit-check-row {
      padding: 12px 14px;
      margin: 0;
    }

    .submit-checkbox-item .submit-check-row input[type="radio"] {
      accent-color: #0284c7;
    }

    .submit-checkbox-item .submit-check-row span {
      font-size: 13px;
      line-height: 1.6;
    }

    .submit-nav {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 18px 24px 22px;
      border-top: 0.5px solid var(--color-border-tertiary);
      background: var(--color-background-secondary);
    }

    .submit-nav-info {
      font-size: 12.5px;
      color: var(--color-text-tertiary);
    }

    .submit-nav-actions {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .submit-btn {
      border: none;
      border-radius: 0;
      padding: 11px 18px;
      font-family: var(--font-sans);
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: transform 0.15s ease, background-color 0.15s ease, opacity 0.15s ease;
      text-decoration: none;
    }

    .submit-btn:hover {
      transform: translateY(-1px);
      text-decoration: none;
    }

    .submit-btn.back {
      background: transparent;
      color: var(--color-text-secondary);
      border: 0.5px solid var(--color-border-secondary);
    }

    .submit-btn.next {
      background: var(--color-accent);
      color: var(--color-text-primary);
    }

    .submit-btn.submit {
      background: #d79b00;
      color: var(--color-text-primary);
    }

    .submit-btn.submit:hover {
      background: #c58c00;
    }

    .submit-success {
      padding: 64px 32px;
      text-align: center;
    }

    .submit-success-circle {
      width: 76px;
      height: 76px;
      border-radius: 0;
      border: 3px solid #b8dfc5;
      background: #edf8f1;
      margin: 0 auto 22px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 32px;
    }

    .submit-success-title {
      font-size: 26px;
      font-weight: 600;
      color: var(--color-text-primary);
      margin-bottom: 10px;
    }

    .submit-success-body {
      max-width: 480px;
      margin: 0 auto;
      font-size: 14px;
      color: var(--color-text-secondary);
      line-height: 1.75;
    }

    .submit-success-ref {
      display: inline-flex;
      margin-top: 18px;
      padding: 10px 18px;
      border-radius: 0;
      border: 0.5px solid var(--color-border-secondary);
      background: var(--color-background-secondary);
      color: var(--color-text-primary);
      font-size: 13px;
      font-weight: 500;
    }

    .submit-card.submit-wizard input[type="file"] {
      display: none;
    }

    .status-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 4px 10px;
      border-radius: 0;
      font-size: 11px;
      font-weight: 500;
      white-space: nowrap;
    }

    .status-accepted { background: #e8f6ee; color: #16794c; border-color: #9fd5b7; }
    .status-rejected { background: #fbeaec; color: #b42318; border-color: #f1a7ac; }
    .status-needs_edits { background: #fff4db; color: #9a5b00; border-color: #e1c15a; }
    .status-submitted { background: #fff4db; color: #8f5a00; }

    .profile-mini {
      font-size: 12px;
      color: var(--color-text-secondary);
      margin-top: 4px;
    }

    .modal-veil {
      position: fixed; inset: 0; background: rgba(17,17,17,0.6); z-index: 9999;
      display: flex; align-items: center; justify-content: center;
      opacity: 0; pointer-events: none; transition: opacity 0.2s ease;
    }
    .modal-veil.open { opacity: 1; pointer-events: auto; }
    .modal-box {
      background: #ffffff; border: 2px solid #000; padding: 28px;
      max-width: 440px; width: 90%; transform: scale(0.97); transition: transform 0.2s ease;
    }
    .modal-veil.open .modal-box { transform: scale(1); }
    .modal-box h3 { font-family: var(--font-sans); font-size: 20px; margin: 0 0 10px; }
    .modal-box p { font-size: 13px; color: #666; line-height: 1.65; margin: 0 0 20px; }
    .modal-btns { display: flex; gap: 10px; justify-content: flex-end; }

    @media (max-width: 991px) {
      .dash {
        grid-template-columns: 1fr;
      }

      .profile-card {
        position: static;
      }
    }

    @media (max-width: 575px) {
      .dashboard-shell {
        padding-top: 16px;
      }

      .page-header {
        flex-direction: column;
      }

      .page-title {
        font-size: 20px;
      }

      .tab {
        padding-inline: 12px;
      }

      .submit-hero,
      .submit-body,
      .submit-nav {
        padding-left: 16px;
        padding-right: 16px;
      }

      .submit-title,
      .submit-panel-title,
      .submit-success-title {
        font-size: 20px;
      }

      .submit-grid,
      .submit-grid.three,
      .submit-type-grid {
        grid-template-columns: 1fr;
      }

      .submit-upload-layout {
        grid-template-columns: 1fr;
      }

      .submit-upload-selected-row {
        grid-template-columns: 1fr;
      }

      .submit-phone-row {
        flex-direction: column;
      }

      .submit-phone-row .submit-select {
        width: 100%;
      }

      .submission-card,
      .profile-header,
      .profile-section,
      .profile-actions,
      .submit-card {
        padding-left: 16px;
        padding-right: 16px;
        background: #ffffff;
      }
    }

    :root {
      --color-background-primary: #ffffff;
      --color-background-secondary: #f8f8f5;
      --color-background-tertiary: #fff3ce;
      --color-border-secondary: #e3e3da;
      --color-border-tertiary: #ece8d9;
      --color-accent: #f0b429;
      --color-accent-dark: #c58c00;
      --color-accent-soft: #fff6d8;
    }

    body {
      background: #f0f0f0;
      color: var(--color-text-primary);
    }

    .empty-state,
    .submission-card,
    .profile-card,
    .submit-card,
    .profile-header,
    .submit-hero {
      background-color: rgba(255, 255, 255, 0.95);
    }

    .profile-header {
      background: #ffffff;
    }

    .avatar {
      background: #f0f0f0;
      color: #6b7280;
    }

    .submit-kicker {
      background: #f0f0f0;
      color: var(--color-accent-dark);
    }

    .submit-step.active .submit-step-num,
    .submit-step.done .submit-step-num {
      background: var(--color-accent);
      border-color: var(--color-accent-dark);
      color: var(--color-text-primary);
    }

    .submit-step.active .submit-step-label,
    .submit-step.done .submit-step-label {
      color: var(--color-accent-dark);
    }

    .submit-progress-fill {
      background: var(--color-accent);
    }

    .submit-type-card:hover,
    .submit-choice:hover {
      background: #fffaf0;
    }

    .submit-type-card.sel,
    .submit-choice.sel,
    .submit-drop-zone:hover,
    .submit-drop-zone.over {
      background: rgba(240, 180, 41, 0.09);
    }

    .submit-copy-link {
      background: var(--color-accent);
      color: var(--color-text-primary);
    }

    .submit-copy-link:hover {
      background: #e6a800;
      color: var(--color-text-primary);
    }

    .submit-btn.next,
    .submit-btn.submit {
      background: var(--color-accent);
      color: var(--color-text-primary);
    }

    .submit-btn.submit:hover,
    .submit-btn.next:hover {
      background: #e6a800;
    }

    .gysj-navbar .btn-primary {
      background: #eef0f3;
      border-color: #d7dee8;
      color: var(--color-text-primary) !important;
      box-shadow: none !important;
    }

    .gysj-navbar .btn-primary:hover,
    .gysj-navbar .btn-primary:focus,
    .gysj-navbar .btn-primary:active {
      background: #e5e7eb;
      border-color: #cfd6de;
      box-shadow: none !important;
      color: var(--color-text-primary) !important;
    }

    .status-submitted {
      background: #fff4db;
      color: #8f5a00;
    }

    :root {
      --border-radius-md: 0;
      --border-radius-lg: 0;
    }

    body {
      background: #f0f0f0;
    }

    .empty-state,
    .submission-card,
    .profile-card,
    .submit-card,
    .profile-header,
    .submit-hero {
      background: #ffffff;
      background-image: none;
      border-radius: 0;
    }

    .dash,
    .page-header,
    .tabs,
    .submit-nav,
    .profile-actions,
    .submission-actions,
    .submit-progress,
    .submit-progress-fill,
    .submit-step-num,
    .tab-count,
    .btn-outline,
    .empty-action,
    .dashboard-btn,
    .dashboard-link-btn,
    .submit-btn,
    .submit-kicker,
    .submit-copy-link,
    .status-badge,
    .submit-choice,
    .submit-type-card,
    .submit-drop-zone,
    .submit-success-circle {
      border-radius: 0;
    }

    .profile-header,
    .submit-hero {
      background: #ffffff;
    }

    .profile-header {
      background: #ffffff;
    }

    .submit-hero {
      background: #ffffff;
    }

    .submit-progress-fill,
    .submit-step.active .submit-step-num,
    .submit-step.done .submit-step-num,
    .submit-kicker,
    .submit-browse,
    .submit-copy-link,
    .submit-btn.next,
    .submit-btn.submit {
      background-image: none;
    }

    .submit-select {
      background-image: none;
      padding-right: 14px;
    }
  
      .em-tabs { display: flex; flex-wrap: wrap; gap: 4px; overflow: visible; padding-bottom: 10px; }
      .em-tabs .em-tab {
        background: transparent; border: none; font-size: 14px; color: #555; padding: 8px 12px; cursor: pointer;
        display: flex; align-items: center; gap: 8px; border-bottom: 3px solid transparent;
      }
      .em-tabs .em-tab.active {
        font-weight: bold; color: #003366; border-bottom: 3px solid #003366;
      }
      .em-tabs .em-tab:hover { background: #f0f4f8; }
      .em-tab-count { background: #fff; border: 1px solid #ccc; font-size: 11px; padding: 2px 6px; border-radius: 0; color: #666; font-weight: normal; }
      .em-tabs .em-tab.active .em-tab-count { border-color: #003366; color: #003366; }
      .dash { max-width: 100% !important; padding: 0 30px; display: block !important; }
      .dashboard-main { max-width: 100% !important; margin-bottom: 40px; }




      .em-table { width: 100%; border-collapse: collapse; font-family: Arial, sans-serif; font-size: 13px; background: #fff; border: 1px solid #ccc; }
      .em-table th { background: #003366; color: #fff; padding: 14px 10px; text-align: left; font-weight: bold; border-right: 1px solid #002244; }
      .em-table th i { margin-left: 4px; color: #7cb5ec; cursor: pointer; }
      .em-table th i.fa-filter { font-size: 11px; float: right; margin-top: 2px; }
      .em-table td { padding: 12px 10px; border-bottom: 1px solid #ebebeb; border-right: 1px solid #ebebeb; vertical-align: top; color: #333; }
      .em-table tr:hover td { background-color: #f5f8fc; }
      .em-table td:first-child { background: #f9fbff; }
      .em-table a.action-link { display: block; color: #3766b3; text-decoration: none; margin-bottom: 5px; font-size: 12.5px; }
      .em-table a.action-link:hover { text-decoration: underline; color: #1a4a9c; }

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
      gap: 24px;
    }

    .review-summary {
      display: grid;
      gap: 32px;
    }

    .version-list {
      display: grid;
      gap: 12px;
    }

    .version-accordion {
      background: #fafafa;
      border: 1px solid #e4e4e4;
      border-radius: 0;
      overflow: hidden;
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
      background: #111;
      color: #fff;
      border-color: #111;
    }

    .status-rejected {
      background: #fff;
      color: #111;
      border-color: #8f8f8f;
    }

    .status-needs_edits {
      background: #e5c84a;
      color: #111;
      border-color: #111;
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

    @media (max-width: 575px) {
      .info-grid {
        grid-template-columns: 1fr;
      }
    }

    /* Admin-like content block headings and boxes (scoped) */
    .version-accordion .meta-label,
    .gysj-modal .meta-label {
      margin: 0;
      color: #666;
      font-size: 13px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    .version-accordion .meta-value,
    .gysj-modal .meta-value,
    .version-accordion .info-value,
    .gysj-modal .info-value {
      margin-top: 8px;
      font-size: 15px;
      color: #111;
      font-weight: 500;
      word-break: break-word;
    }

    .version-accordion .content-block,
    .gysj-modal .content-block {
      margin-top: 48px;
    }

    .version-accordion .content-block:first-child,
    .gysj-modal .content-block:first-child {
      margin-top: 0;
    }

    .version-accordion .content-block h3,
    .gysj-modal .content-block h3 {
      margin: 0 0 16px;
      font-size: 18px;
      font-weight: 600;
      text-transform: none;
      color: #222;
      border-bottom: 2px solid #eaeaea;
      padding-bottom: 12px;
    }

    .version-accordion .file-line,
    .gysj-modal .file-line {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
    }

    .version-accordion .file-name,
    .gysj-modal .file-name {
      color: #111;
      font-weight: 700;
      word-break: break-word;
    }

    .version-accordion .content-box,
    .gysj-modal .content-box {
      background: #fff;
      border: 1px solid #e2e2e2;
      border-left: 4px solid var(--color-accent);
      border-radius: 0;
      padding: 24px;
      white-space: pre-wrap;
      color: #333;
      line-height: 1.8;
      font-size: 15px;
      box-shadow: 0 2px 5px rgba(0,0,0,0.02);
    }

    /* Dashboard button styles (scoped to modal/version to avoid global overrides) */
    .version-accordion .dashboard-btn,
    .gysj-modal .dashboard-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      border-radius: 0;
      padding: 10px 16px;
      font-size: 13px;
      font-weight: 700;
      text-decoration: none;
      transition: transform 0.2s ease, background-color 0.2s ease, border-color 0.2s ease, color 0.2s ease;
      background: #fff;
      color: #111;
      border: 1px solid #d6d6d6;
    }

    .version-accordion .dashboard-btn:hover,
    .gysj-modal .dashboard-btn:hover {
      transform: translateY(-1px);
      border-color: #111;
      background: #f6f6f6;
      color: #111;
    }


    /* Modal-specific rules (kept small) */
    .gysj-modal { display: none; }
    .gysj-modal.open { display: block; position: fixed; inset: 0; z-index: 2200; }
    .gysj-modal-backdrop { position: fixed; inset: 0; background: rgba(8,10,15,0.45); }
    .gysj-modal-content { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 94%; max-width: 980px; max-height: 92vh; overflow: auto; background: #fff; border-radius: 0; box-shadow: 0 20px 60px rgba(12,23,42,0.3); padding: 0; display: flex; flex-direction: column; }
    .gysj-modal-header { display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; border-bottom: 1px solid #e2e8f0; position: sticky; top: 0; background: #fff; z-index: 10; }
    .gysj-modal-title { margin: 0; font-size: 20px; font-weight: 700; color: #0f172a; }
    .gysj-modal-close { position: static; width: 32px; height: 32px; border-radius: 0; border: none; background: #f1f5f9; cursor: pointer; font-size: 20px; font-weight: bold; color: #64748b; display: flex; align-items: center; justify-content: center; transition: all 0.2s; padding: 0; line-height: 1; }
    .gysj-modal-close:hover { background: #e2e8f0; color: #0f172a; }
    .gysj-modal-body { padding: 24px; }
    .gysj-modal .modal-body h2 { margin-top: 0; font-size: 20px; }
    .gysj-modal .modal-meta { display: flex; gap: 14px; flex-wrap: wrap; margin-bottom: 12px; color: #444; }
    .gysj-modal .modal-actions { display: flex; gap: 10px; margin-top: 14px; }
    .dashboard-btn-disabled { opacity: 0.6; pointer-events: none; }
    body.modal-open { overflow: hidden; }
  
      .em-tabs { display: flex; flex-wrap: wrap; gap: 4px; overflow: visible; padding-bottom: 10px; }
      .em-tabs .em-tab {
        background: transparent; border: none; font-size: 14px; color: #555; padding: 8px 12px; cursor: pointer;
        display: flex; align-items: center; gap: 8px; border-bottom: 3px solid transparent;
      }
      .em-tabs .em-tab.active {
        font-weight: bold; color: #003366; border-bottom: 3px solid #003366;
      }
      .em-tabs .em-tab:hover { background: #f0f4f8; }
      .em-tab-count { background: #fff; border: 1px solid #ccc; font-size: 11px; padding: 2px 6px; border-radius: 0; color: #666; font-weight: normal; }
      .em-tabs .em-tab.active .em-tab-count { border-color: #003366; color: #003366; }
      .dash { max-width: 100% !important; padding: 0 30px; display: block !important; }
      .dashboard-main { max-width: 100% !important; margin-bottom: 40px; }
      
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
    .sub-author-card.collapsed .sub-author-body { display: none; }
    .sub-author-card.collapsed .toggle-icon { transform: rotate(-90deg); }
    
    .sub-kv { display: flex; flex-direction: column; gap: 4px; }
      .sub-kv-label { color: #64748b; font-size: 11px; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px; }
      .sub-kv-value { color: #0f172a; font-weight: 500; }
      .sub-kv.full-width { grid-column: 1 / -1; }
</style>

        <div class="edit-submission-shell">
          <div class="edit-submission-wrap">
            <div class="edit-submission-header">
              <div>
                <div class="edit-submission-kicker">Submission review</div>
                <h1 class="edit-submission-title">Edit Submission</h1>
                <p class="edit-submission-subtitle">Update the manuscript details and replace the file only when revisions are requested.</p>
              </div>
              <div class="edit-submission-meta">
              <div><strong>ID</strong> <?php echo (int) ($submission['id'] ?? 0); ?></div>
              <div><strong>Version</strong> <?php echo (int) $version; ?></div>
              <div><strong>Status</strong> <?php echo e($statusLabel); ?></div>
            </div>
            </div>

            <?php if ($success !== ''): ?>
              <div class="alert alert-success edit-submission-alert edit-submission-alert-success" role="alert"><?php echo e($success); ?></div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
              <div class="alert alert-danger edit-submission-alert edit-submission-alert-danger" role="alert"><?php echo e($error); ?></div>
            <?php endif; ?>

            <?php if (!$isEditable): ?>
              <div class="edit-submission-note">
                <?php if ($status === 'accepted' || $status === 'rejected'): ?>
                  This submission already has a final decision and cannot be edited.
                <?php else: ?>
                  This submission is under review and cannot be edited right now.
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <form class="edit-submission-form" method="post" action="edit-submission.php?id=<?php echo (int) $submissionId; ?>" enctype="multipart/form-data">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="paper_type" value="<?php echo e($paperTypeVal); ?>">

              <section class="edit-card">
                <div class="edit-card-head">
                  <h2 class="edit-card-title">Paper details</h2>
                </div>
                <div class="edit-card-body">
                  <div class="edit-grid edit-grid-2">
                    <div class="edit-field">
                      <label class="edit-required" for="journal">Research journal</label>
                      <select id="journal" name="journal" <?php echo $isEditable ? '' : 'disabled'; ?> <?php echo $isEditable ? 'required' : ''; ?>>
                        <option value="">Select journal</option>
                        <?php foreach ($submitJournals as $journalOption): ?>
                          <option value="<?php echo e($journalOption); ?>" <?php echo $journalVal === $journalOption ? 'selected' : ''; ?>><?php echo e($journalOption); ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>

                    <div class="edit-field">
                      <label for="keywords">Keywords</label>
                      <input type="text" id="keywords" name="keywords" value="<?php echo e($keywordsVal); ?>" <?php echo $isEditable ? '' : 'disabled'; ?>>
                    </div>
                  </div>

                  <div class="edit-field">
                    <label class="edit-required" for="title">Paper title</label>
                    <input type="text" id="title" name="title" value="<?php echo e($titleVal); ?>" <?php echo $isEditable ? '' : 'disabled'; ?> <?php echo $isEditable ? 'required' : ''; ?>>
                  </div>

                                    <div class="edit-field" style="grid-column: 1 / -1;">
                    <label class="edit-required">Author Profile</label>
                    <div class="author-section">
                      <div class="author-empty-box" id="authorEmptyState">
                        <button type="button" class="dashboard-btn author-add-btn" onclick="addAuthorCard()"><i class="ti ti-plus"></i> Add Author</button>
                        <div style="margin-top:12px; font-size:13px; color: #555;">No authors added yet. Click Add Author to begin.</div>
                      </div>
                      <div class="author-list" id="authorList"></div>
                      <div id="authorAddAnotherContainer" style="display:none; margin-top:15px;">
                        <button type="button" class="dashboard-btn author-add-btn" onclick="addAuthorCard()"><i class="ti ti-plus"></i> Add Another Author</button>
                      </div>
                    </div>
                  </div>
                  <input type="hidden" name="authors" id="submitAuthorsHidden" value="<?php echo e($authorsVal); ?>">
                  <input type="hidden" name="age" id="submitAuthorAgeHidden" value="<?php echo e($ageVal); ?>">
                  <input type="hidden" name="email" id="submitAuthorEmailHidden" value="<?php echo e($emailVal); ?>">
                  <input type="hidden" name="phone_code" id="submitAuthorPhoneCodeHidden" value="<?php echo e($phoneCodeVal); ?>">
                  <input type="hidden" name="phone_number" id="submitAuthorPhoneNumberHidden" value="<?php echo e($phoneNumberVal); ?>">
                  <textarea name="author_bio" id="submitAuthorBioHidden" style="display:none;"><?php echo e($authorBioVal); ?></textarea>
                  <input type="hidden" name="authors_payload" id="submitAuthorsPayload" value="<?php echo htmlspecialchars($authorsPayloadVal, ENT_QUOTES, 'UTF-8'); ?>">

                  <div class="edit-field">
                    <label class="edit-required" for="abstract">Abstract</label>
                    <textarea id="abstract" name="abstract" <?php echo $isEditable ? '' : 'disabled'; ?> <?php echo $isEditable ? 'required' : ''; ?>><?php echo e($abstractVal); ?></textarea>
                  </div>
                </div>
              </section>

              <section class="edit-card">
                <div class="edit-card-head">
                  <h2 class="edit-card-title">Submission details</h2>
                </div>
                <div class="edit-card-body">
                  <div class="edit-field">
                    <label class="edit-required">Guidelines confirmation</label>
                    <label class="edit-check-row">
                      <input type="checkbox" name="guidelines_confirm" value="1" <?php echo $guidelinesConfirmedVal ? 'checked' : ''; ?> <?php echo $isEditable ? '' : 'disabled'; ?> <?php echo $isEditable ? 'required' : ''; ?>>
                      <span>I have reviewed and understand the GYSJ author guidelines.</span>
                    </label>
                  </div>

                                    <div class="edit-grid edit-grid-2">
                    <div class="edit-field" style="grid-column: 1 / -1;">
                      <label class="edit-required" for="country">Country</label>
                      <input type="text" id="country" name="country" value="<?php echo e($countryVal); ?>" <?php echo $isEditable ? '' : 'disabled'; ?> <?php echo $isEditable ? 'required' : ''; ?>>
                    </div>
                  </div>

                  

                  

                  

                  

                  <div class="edit-field">
                    <label class="edit-required" for="project_story">Project story</label>
                    <textarea id="project_story" name="project_story" <?php echo $isEditable ? '' : 'disabled'; ?> <?php echo $isEditable ? 'required' : ''; ?>><?php echo e($projectStoryVal); ?></textarea>
                    <div class="edit-inline-help">Share how you became interested in the project and any useful context about the research journey.</div>
                  </div>

                  <div class="edit-field">
                    <label class="edit-required">Author and submission confirmations</label>
                    <div class="edit-toggle-grid">
                      <label class="edit-check-row">
                        <input type="checkbox" name="author_consent" value="1" <?php echo $authorConsentVal ? 'checked' : ''; ?> <?php echo $isEditable ? '' : 'disabled'; ?> <?php echo $isEditable ? 'required' : ''; ?>>
                        <span><strong>1. Author consent</strong><br>All authors have reviewed and approved this submission.</span>
                      </label>
                      <label class="edit-check-row">
                        <input type="checkbox" name="corresp_author_resp" value="1" <?php echo $correspAuthorRespVal ? 'checked' : ''; ?> <?php echo $isEditable ? '' : 'disabled'; ?> <?php echo $isEditable ? 'required' : ''; ?>>
                        <span><strong>2. Corresponding author responsibility</strong><br>The submitting author will coordinate editorial communication and revisions.</span>
                      </label>
                      <label class="edit-check-row">
                        <input type="checkbox" name="age_eligibility" value="1" <?php echo $ageEligibilityVal ? 'checked' : ''; ?> <?php echo $isEditable ? '' : 'disabled'; ?> <?php echo $isEditable ? 'required' : ''; ?>>
                        <span><strong>3. Age eligibility</strong><br>All student authors are eligible to submit.</span>
                      </label>
                      <label class="edit-check-row">
                        <input type="checkbox" name="permission_supervision" value="1" <?php echo $permissionSupervisionVal ? 'checked' : ''; ?> <?php echo $isEditable ? '' : 'disabled'; ?> <?php echo $isEditable ? 'required' : ''; ?>>
                        <span><strong>4. Permission and supervision</strong><br>Appropriate permission or supervision was obtained where applicable.</span>
                      </label>
                      <label class="edit-check-row">
                        <input type="checkbox" name="originality" value="1" <?php echo $originalityVal ? 'checked' : ''; ?> <?php echo $isEditable ? '' : 'disabled'; ?> <?php echo $isEditable ? 'required' : ''; ?>>
                        <span><strong>5. Originality of work</strong><br>The manuscript is original and not knowingly plagiarised or copied.</span>
                      </label>
                      <label class="edit-check-row">
                        <input type="checkbox" name="concurrent_submission" value="1" <?php echo $concurrentSubmissionVal ? 'checked' : ''; ?> <?php echo $isEditable ? '' : 'disabled'; ?> <?php echo $isEditable ? 'required' : ''; ?>>
                        <span><strong>6. Concurrent submission</strong><br>This manuscript is not under active review at another journal.</span>
                      </label>
                      <label class="edit-check-row">
                        <input type="checkbox" name="ethical_compliance" value="1" <?php echo $ethicalComplianceVal ? 'checked' : ''; ?> <?php echo $isEditable ? '' : 'disabled'; ?>>
                        <span><strong>7. Ethical compliance</strong><br>Appropriate ethical approval or consent was obtained where required.</span>
                      </label>
                      <label class="edit-check-row">
                        <input type="checkbox" name="ai_policy" value="1" <?php echo $aiPolicyVal ? 'checked' : ''; ?> <?php echo $isEditable ? '' : 'disabled'; ?> <?php echo $isEditable ? 'required' : ''; ?>>
                        <span><strong>8. AI usage policy</strong><br>Any AI use was limited to permitted support and not scientific fabrication.</span>
                      </label>
                      <label class="edit-check-row">
                        <input type="checkbox" name="formatting_guidelines" value="1" <?php echo $formattingGuidelinesVal ? 'checked' : ''; ?> <?php echo $isEditable ? '' : 'disabled'; ?> <?php echo $isEditable ? 'required' : ''; ?>>
                        <span><strong>9. Formatting and guidelines</strong><br>The manuscript follows the journal guidelines and is prepared clearly.</span>
                      </label>
                      <label class="edit-check-row">
                        <input type="checkbox" name="publication_agreement" value="1" <?php echo $publicationAgreementVal ? 'checked' : ''; ?> <?php echo $isEditable ? '' : 'disabled'; ?> <?php echo $isEditable ? 'required' : ''; ?>>
                        <span><strong>10. Publication agreement</strong><br>If accepted, the authors grant GYSJ permission to publish the manuscript.</span>
                      </label>
                    </div>
                  </div>

                  <div class="edit-field">
                    <label class="edit-required">Manuscript information</label>
                    <div class="edit-toggle-grid">
                      <label class="edit-radio-row">
                        <input type="radio" name="preprint_server" value="no" <?php echo $preprintServerVal !== 'yes' ? 'checked' : ''; ?> <?php echo $isEditable ? '' : 'disabled'; ?> <?php echo $isEditable ? 'required' : ''; ?>>
                        <span>No - This manuscript has not been posted on a preprint server</span>
                      </label>
                      <label class="edit-radio-row">
                        <input type="radio" name="preprint_server" value="yes" <?php echo $preprintServerVal === 'yes' ? 'checked' : ''; ?> <?php echo $isEditable ? '' : 'disabled'; ?>>
                        <span>Yes - This manuscript has been posted on a preprint server</span>
                      </label>
                    </div>
                    <input type="text" id="preprint_link" name="preprint_link" value="<?php echo e($preprintLinkVal); ?>" placeholder="If yes, please provide the link" <?php echo $isEditable ? '' : 'disabled'; ?>>
                  </div>
                </div>
              </section>

              <section class="edit-card">
                <div class="edit-card-head">
                  <h2 class="edit-card-title">Manuscript</h2>
                </div>
                <div class="edit-card-body">
                  <div class="edit-field">
                    <label>Current manuscript</label>
                    <div class="file-card">
                      <div class="file-card-main">
                        <div class="file-label">Submitted file</div>
                        <div class="file-name"><?php echo e($originalName !== '' ? $originalName : 'PDF manuscript'); ?></div>
                        <div class="edit-footer-note" style="margin-top:8px;">Existing submission and letter can be viewed without changing the file.</div>
                        <div class="file-actions">
                          <a class="edit-link" href="paper-file.php?id=<?php echo (int) $submissionId; ?>" target="_blank" rel="noopener">View submission</a>
                          <a class="edit-button-secondary" href="submission-letter.php?id=<?php echo (int) $submissionId; ?>" target="_blank" rel="noopener">View letter</a>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="upload-card">
                    <div>
                      <strong>Upload revised manuscript</strong>
                      <div style="margin-top:6px;"><small>PDF only. Maximum file size: 25 MB. A new version is created only when a file is attached.</small></div>
                    </div>
                    <div class="edit-field">
                      <input type="file" id="manuscript" name="manuscript" accept="application/pdf" <?php echo $isEditable ? '' : 'disabled'; ?>>
                      <?php if (!$isEditable): ?>
                        <small>Revised uploads are disabled until edits are requested.</small>
                      <?php endif; ?>
                    </div>
                  </div>

                  <div class="edit-field">
                    <label class="edit-required">Copyright agreement</label>
                    <div class="edit-inline-help" style="margin-bottom:8px;">You must complete the online copyright form before submitting. By signing, you allow the journal to hold copyright solely for publication, distribution, and archiving.</div>
                    <a class="edit-link" href="https://forms.gle/n86EvNhfp8RnhUzW8" target="_blank" rel="noopener" style="margin-bottom:10px; width: fit-content;">Complete copyright form ↗</a>
                    <label class="edit-check-row">
                      <input type="checkbox" name="copyright_confirm" value="1" <?php echo $copyrightConfirmedVal ? 'checked' : ''; ?> <?php echo $isEditable ? '' : 'disabled'; ?> <?php echo $isEditable ? 'required' : ''; ?>>
                      <span>I have completed the copyright form and agree to the above terms. I confirm this paper is my original work and I have the authority to make this agreement.</span>
                    </label>
                  </div>
                </div>
              </section>

              <div class="edit-footer">
                <div class="edit-footer-note">Review your edits carefully before saving. Changes apply only to this submission.</div>
                <div class="edit-footer-actions">
                  <a class="edit-link" href="user-dashboard.php">Cancel</a>
                  <button type="submit" class="edit-button" <?php echo $isEditable ? '' : 'disabled'; ?>>Save changes</button>
                </div>
              </div>
            </form>
          </div>
        </div>

  <script src="js/jquery.min.js"></script>
  <script src="js/tether.min.js" crossorigin="anonymous"></script>
  <script src="js/bootstrap.min.js" crossorigin="anonymous"></script>




<script>
function getAuthorListElement() {
      return document.getElementById('authorList');
    }

    function getAuthorEmptyElement() {
      return document.getElementById('authorEmptyState');
    }

    function createAuthorCard(prefill) {
      var data = prefill || {};
      var card = document.createElement('div');
      card.className = 'author-card';
      card.innerHTML = [
        '<div class="author-card-head">',
        '  <div class="author-card-title">Author <span class="author-card-index"></span><span class="author-card-preview" style="font-weight:normal; font-size:14px; margin-left:10px; color:#666;"></span></div>',
        '  <button type="button" class="author-card-remove">Remove</button>',
        '</div>',
        '<div class="submit-field">',
        '  <label class="submit-label">Name <span class="submit-req">*</span></label>',
        '  <input type="text" class="submit-input" data-author-field="name" placeholder="Author name" required style="width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 0; box-sizing: border-box;">',
        '</div>',
        '<div class="submit-field" style="display:flex; gap:10px; flex-wrap:wrap;">',
        '  <div style="flex:1; min-width: 140px;">',
        '    <label class="submit-label">Age <span class="submit-req">*</span></label>',
        '    <input type="number" class="submit-input" data-author-field="age" min="5" max="19" required style="width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 0; box-sizing: border-box;">',
        '  </div>',
        '  <div style="flex:2; min-width: 220px;">',
        '    <label class="submit-label">Personal Email <span class="submit-req">*</span></label>',
        '    <input type="email" class="submit-input" data-author-field="email" placeholder="author@example.com" required style="width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 0; box-sizing: border-box;">',
        '  </div>',
        '</div>',
        '<div class="submit-field" style="display:flex; gap:10px; flex-wrap:wrap;">',
        '  <div style="flex:1; min-width: 120px; position: relative;">',
        '    <label class="submit-label">Phone Code <span class="submit-req">*</span></label>',
        '    <input type="text" class="submit-input" data-author-field="phone_code" placeholder="+1 (Search)" autocomplete="off" required style="width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 0; box-sizing: border-box;">',
        '    <div class="phone-code-dropdown" style="display:none; position:absolute; top:100%; left:0; right:0; z-index:100; background:#fff; border:1px solid #ccc; border-top:none; max-height:200px; overflow-y:auto; border-radius: 0 0 4px 4px; box-shadow:0 4px 6px rgba(0,0,0,0.1);"></div>',
        '  </div>',
        '  <div style="flex:3; min-width: 200px;">',
        '    <label class="submit-label">Phone Number <span class="submit-req">*</span></label>',
        '    <input type="text" class="submit-input" data-author-field="phone_number" placeholder="Phone number" required style="width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 0; box-sizing: border-box;">',
        '  </div>',
        '</div>',
        '<div class="submit-field">',
        '  <label class="submit-label">Short Author Biography</label>',
        '  <textarea class="submit-input" data-author-field="bio" rows="3" placeholder="Short biography" style="width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 0; box-sizing: border-box;">',
        '</textarea>',
        '</div>',
        '<div class="submit-field">',
        '  <label class="submit-label">School Name <span class="submit-req">*</span></label>',
        '  <input type="text" class="submit-input" data-author-field="school_name" placeholder="School or institution name" required style="width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 0; box-sizing: border-box;">',
        '</div>',
        '<div class="submit-field">',
        '  <label class="submit-label">Grade Level <span class="submit-req">*</span></label>',
        '  <input type="text" class="submit-input" data-author-field="grade_level" placeholder="Grade or year" required style="width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 0; box-sizing: border-box;">',
        '</div>',
        '<div class="submit-field">',
        '  <label class="submit-label">School Email <span class="submit-req">*</span></label>',
        '  <input type="email" class="submit-input" data-author-field="school_email" placeholder="school@example.edu" required style="width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 0; box-sizing: border-box;">',
        '  <label style="display:block; margin-top:5px; font-size:13px; color:#555;">',
        '    <input type="checkbox" class="author-no-school-email"> I don\'t have a school email',
        '  </label>',
        '</div>',
        '<div class="submit-field">',
        '  <label class="submit-label">Admission Number <span class="submit-req">*</span></label>',
        '  <input type="text" class="submit-input" data-author-field="admission_number" placeholder="Admission number" required style="width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 0; box-sizing: border-box;">',
        '</div>',
        '<div class="submit-field" style="display:flex; gap:10px; flex-wrap:wrap;">',
        '  <div style="flex:1; min-width: 140px;">',
        '    <label class="submit-label">ORCID ID (optional)</label>',
        '    <input type="text" class="submit-input" data-author-field="orcid" placeholder="0000-0000-0000-0000" maxlength="19" oninput="let v=this.value.replace(/\\D/g,\'\').substring(0,16);this.value=v.replace(/(\\d{4})(?=\\d)/g,\'$1-\');" style="width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 0; box-sizing: border-box;">',
        '  </div>',
        '  <div style="flex:1; min-width: 140px;">',
        '    <label class="submit-label">Google Scholar Profile (optional)</label>',
        '    <input type="url" class="submit-input" data-author-field="scholar" placeholder="https://scholar.google.com/..." style="width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 0; box-sizing: border-box;">',
        '  </div>',
        '</div>',
        '<div class="submit-field" style="margin-top: 15px; text-align: right;">',
        '  <button type="button" class="dashboard-btn author-card-save">Save Author</button>',
        '  <button type="button" class="dashboard-btn author-card-edit" style="display:none; background: #ffc107; border-color: #ffc107; color: #000;">Edit Author</button>',
        '</div>'
      ].join('');

      var fieldNames = ['name', 'age', 'email', 'phone_code', 'phone_number', 'bio', 'school_name', 'grade_level', 'school_email', 'admission_number', 'orcid', 'scholar'];
      fieldNames.forEach(function(fieldName) {
        var field = card.querySelector('[data-author-field="' + fieldName + '"]');
        if (field) {
          field.value = typeof data[fieldName] === 'string' ? data[fieldName] : '';
          
          var errorSpan = document.createElement('div');
          errorSpan.style.color = '#dc3545';
          errorSpan.style.fontSize = '12px';
          errorSpan.style.marginTop = '4px';
          errorSpan.style.display = 'none';
          field.parentNode.insertBefore(errorSpan, field.nextSibling);

          field.addEventListener('input', function() {
            syncAuthorPayload();
            var val = field.value.trim();
            var isValid = true;
            var errorMsg = '';
            
            if (val) {
              if (fieldName === 'email') {
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) {
                  isValid = false; errorMsg = 'Invalid email address format.';
                }
              } else if (fieldName === 'school_email' && val !== 'N/A') {
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) {
                  isValid = false; errorMsg = 'Invalid email address format.';
                } else {
                  var domain = val.split('@')[1].toLowerCase();
                  var freeDomains = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'aol.com', 'icloud.com', 'mail.com'];
                  if (!domain.endsWith('.edu') && freeDomains.indexOf(domain) > -1) {
                    isValid = false; errorMsg = 'Please use a valid school email (not a generic personal email like Gmail).';
                  }
                }
              } else if (fieldName === 'phone_number') {
                if (!/^[0-9][0-9\s\-()]{5,24}$/.test(val)) {
                  isValid = false; errorMsg = 'Please enter a valid phone number format.';
                }
              } else if (fieldName === 'age') {
                var ageInt = parseInt(val, 10);
                if (isNaN(ageInt) || ageInt < 12 || ageInt > 20) {
                  isValid = false; errorMsg = 'Age must be between 12 and 20.';
                }
              } else if (fieldName === 'school_name') {
                if (!/[a-zA-Z]/.test(val)) {
                  isValid = false; errorMsg = 'School name must contain letters and cannot be purely numbers.';
                }
              }
            } else if (field.hasAttribute('required')) {
              isValid = false;
              errorMsg = 'This field is required.';
            }
            
            if (!isValid) {
              field.style.borderColor = '#dc3545';
              errorSpan.textContent = errorMsg;
              errorSpan.style.display = 'block';
            } else {
              field.style.borderColor = '#ccc';
              errorSpan.style.display = 'none';
            }
          });
          
          field.addEventListener('change', syncAuthorPayload);
        }
      });

      var phoneCodeInput = card.querySelector('[data-author-field="phone_code"]');
      var phoneCodeDropdown = card.querySelector('.phone-code-dropdown');
      if (phoneCodeInput && phoneCodeDropdown) {
        var codes = {
          "United States": "+1", "Canada": "+1", "United Kingdom": "+44", "India": "+91", "Australia": "+61",
          "China": "+86", "Japan": "+81", "Germany": "+49", "France": "+33", "Italy": "+39", "Brazil": "+55",
          "South Africa": "+27", "Mexico": "+52", "Spain": "+34", "Russia": "+7", "South Korea": "+82",
          "Netherlands": "+31", "Turkey": "+90", "Switzerland": "+41", "Sweden": "+46", "Saudi Arabia": "+966",
          "Nigeria": "+234", "Argentina": "+54", "Colombia": "+57", "Indonesia": "+62", "Pakistan": "+92",
          "Bangladesh": "+880", "Egypt": "+20", "Vietnam": "+84", "Philippines": "+63", "Thailand": "+66",
          "Malaysia": "+60", "Singapore": "+65", "New Zealand": "+64", "Ireland": "+353", "UAE": "+971"
        };
        var countryList = Object.keys(codes).sort();
        
        function renderPhoneDropdown(filterText) {
          phoneCodeDropdown.innerHTML = '';
          var count = 0;
          countryList.forEach(function(country) {
            var searchStr = (country + ' ' + codes[country]).toLowerCase();
            if (searchStr.indexOf(filterText.toLowerCase()) > -1) {
              count++;
              var item = document.createElement('div');
              item.style.padding = '8px 12px';
              item.style.cursor = 'pointer';
              item.style.borderBottom = '1px solid #eee';
              item.style.fontSize = '14px';
              item.textContent = country + ' (' + codes[country] + ')';
              item.onmouseover = function() { item.style.backgroundColor = '#f0f0f0'; };
              item.onmouseout = function() { item.style.backgroundColor = '#fff'; };
              item.onmousedown = function(e) {
                e.preventDefault(); 
                phoneCodeInput.value = codes[country];
                phoneCodeDropdown.style.display = 'none';
                syncAuthorPayload();
                var event = new Event('input', { bubbles: true });
                phoneCodeInput.dispatchEvent(event);
              };
              phoneCodeDropdown.appendChild(item);
            }
          });
          phoneCodeDropdown.style.display = count > 0 ? 'block' : 'none';
        }
        
        phoneCodeInput.addEventListener('focus', function() {
          renderPhoneDropdown(this.value);
        });
        
        phoneCodeInput.addEventListener('blur', function() {
          phoneCodeDropdown.style.display = 'none';
        });
        
        phoneCodeInput.addEventListener('input', function() {
          renderPhoneDropdown(this.value);
        });
      }

      var noSchoolEmailCheckbox = card.querySelector('.author-no-school-email');
      var schoolEmailInput = card.querySelector('[data-author-field="school_email"]');
      if (noSchoolEmailCheckbox && schoolEmailInput) {
        if (data['school_email'] === 'N/A') {
          noSchoolEmailCheckbox.checked = true;
          schoolEmailInput.value = 'N/A';
          schoolEmailInput.disabled = true;
          schoolEmailInput.removeAttribute('required');
        }
        noSchoolEmailCheckbox.addEventListener('change', function() {
          if (this.checked) {
            schoolEmailInput.dataset.originalValue = schoolEmailInput.value;
            schoolEmailInput.value = 'N/A';
            schoolEmailInput.disabled = true;
            schoolEmailInput.removeAttribute('required');
            schoolEmailInput.style.backgroundColor = '#f0f0f0';
          } else {
            schoolEmailInput.value = schoolEmailInput.dataset.originalValue || '';
            schoolEmailInput.disabled = false;
            schoolEmailInput.setAttribute('required', 'required');
            schoolEmailInput.style.backgroundColor = '';
          }
          syncAuthorPayload();
        });
      }

      var removeBtn = card.querySelector('.author-card-remove');
      if (removeBtn) {
        removeBtn.addEventListener('click', function() {
          removeAuthorCard(card);
        });
      }

      var saveBtn = card.querySelector('.author-card-save');
      var editBtn = card.querySelector('.author-card-edit');
      
      saveBtn.addEventListener('click', function() {
        var isValid = true;
        var errorMsg = 'Please fill out all required fields for this author before saving.';
        var requiredFields = card.querySelectorAll('input[required], textarea[required]');
        
        requiredFields.forEach(function(field) {
          var val = field.value.trim();
          field.style.borderColor = '#ccc';
          
          if (!val) {
            isValid = false;
            field.style.borderColor = 'red';
          } else {
            var fieldType = field.dataset.authorField;
            if (fieldType === 'email' || fieldType === 'school_email') {
              if (val !== 'N/A' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) {
                isValid = false; field.style.borderColor = 'red';
                errorMsg = 'Please enter a valid email address.';
              }
            } else if (fieldType === 'phone_number') {
              if (!/^[0-9][0-9\s\-()]{5,24}$/.test(val)) {
                isValid = false; field.style.borderColor = 'red';
                errorMsg = 'Please enter a valid phone number format.';
              }
            } else if (fieldType === 'age') {
              var ageInt = parseInt(val, 10);
              if (isNaN(ageInt) || ageInt < 12 || ageInt > 20) {
                isValid = false; field.style.borderColor = 'red';
                errorMsg = 'Age must be between 12 and 20.';
              }
            } else if (fieldType === 'school_name') {
              if (!/[a-zA-Z]/.test(val)) {
                isValid = false; field.style.borderColor = 'red';
                errorMsg = 'School name must contain letters and cannot be purely numbers.';
              }
            }
          }
        });
        
        if (!isValid) {
          alertModal('Validation Error', errorMsg);
          return;
        }
        
        var inputs = card.querySelectorAll('input, textarea');
        inputs.forEach(function(input) { input.readOnly = true; });
        var checkbox = card.querySelector('.author-no-school-email');
        if (checkbox) checkbox.disabled = true;
        
        var nameField = card.querySelector('[data-author-field="name"]');
        var preview = card.querySelector('.author-card-preview');
        if (preview && nameField && nameField.value.trim()) {
          preview.textContent = ' - ' + nameField.value.trim();
        }
        var fields = card.querySelectorAll('.submit-field');
        fields.forEach(function(f) {
          if (!f.contains(saveBtn) && !f.contains(editBtn)) {
            if (!f.dataset.originalDisplay) {
              f.dataset.originalDisplay = f.style.display || 'block';
            }
            f.style.display = 'none';
          }
        });
        
        card.dataset.saved = "true";
        saveBtn.style.display = 'none';
        editBtn.style.display = 'inline-block';
        refreshAuthorEmptyState();
      });

      editBtn.addEventListener('click', function() {
        var inputs = card.querySelectorAll('input, textarea');
        inputs.forEach(function(input) { 
          if (!input.classList.contains('author-card-save') && !input.classList.contains('author-card-edit')) {
            input.readOnly = false; 
          }
        });
        var checkbox = card.querySelector('.author-no-school-email');
        if (checkbox) checkbox.disabled = false;
        
        if (checkbox && checkbox.checked) {
          schoolEmailInput.disabled = true;
        }

        var preview = card.querySelector('.author-card-preview');
        if (preview) preview.textContent = '';
        var fields = card.querySelectorAll('.submit-field');
        fields.forEach(function(f) {
          if (!f.contains(saveBtn) && !f.contains(editBtn)) {
            f.style.display = f.dataset.originalDisplay || '';
          }
        });

        card.dataset.saved = "false";
        saveBtn.style.display = 'inline-block';
        editBtn.style.display = 'none';
        refreshAuthorEmptyState();
      });

      var isPrefilled = !!(data && data.name);
      if (isPrefilled) {
        card.dataset.saved = "true";
        saveBtn.style.display = 'none';
        editBtn.style.display = 'inline-block';
        setTimeout(function() {
          var inputs = card.querySelectorAll('input, textarea');
          inputs.forEach(function(input) { input.readOnly = true; });
          var checkbox = card.querySelector('.author-no-school-email');
          if (checkbox) checkbox.disabled = true;
          
          var nameField = card.querySelector('[data-author-field="name"]');
          var preview = card.querySelector('.author-card-preview');
          if (preview && nameField && nameField.value.trim()) {
            preview.textContent = ' - ' + nameField.value.trim();
          }
          var fields = card.querySelectorAll('.submit-field');
          fields.forEach(function(f) {
            if (!f.contains(saveBtn) && !f.contains(editBtn)) {
              if (!f.dataset.originalDisplay) {
                f.dataset.originalDisplay = f.style.display || 'block';
              }
              f.style.display = 'none';
            }
          });
        }, 10);
      } else {
        card.dataset.saved = "false";
      }

      return card;
    }

    function getAuthorCards() {
      return Array.prototype.slice.call(document.querySelectorAll('.author-card'));
    }

    function refreshAuthorCardLabels() {
      getAuthorCards().forEach(function(card, index) {
        var label = card.querySelector('.author-card-index');
        if (label) {
          label.textContent = String(index + 1);
        }
      });
    }

    function refreshAuthorEmptyState() {
      var emptyState = getAuthorEmptyElement();
      var addAnotherContainer = document.getElementById('authorAddAnotherContainer');
      var numCards = getAuthorCards().length;
      if (emptyState) {
        emptyState.style.display = numCards === 0 ? 'block' : 'none';
      }
      if (addAnotherContainer) {
        var allSaved = true;
        getAuthorCards().forEach(function(c) {
          if (c.dataset.saved !== "true") allSaved = false;
        });
        addAnotherContainer.style.display = (numCards > 0 && allSaved) ? 'block' : 'none';
      }
    }

    function addAuthorCard(prefill) {
      var list = getAuthorListElement();
      if (!list) return;
      list.appendChild(createAuthorCard(prefill));
      refreshAuthorCardLabels();
      refreshAuthorEmptyState();
      syncAuthorPayload();
    }

    function removeAuthorCard(card) {
      if (!card) return;
      card.remove();
      refreshAuthorCardLabels();
      refreshAuthorEmptyState();
      syncAuthorPayload();
    }

    function syncAuthorPayload() {
      var cards = getAuthorCards();
      var payload = cards.map(function(card) {
        return {
          name: (card.querySelector('[data-author-field="name"]') || {}).value || '',
          age: (card.querySelector('[data-author-field="age"]') || {}).value || '',
          email: (card.querySelector('[data-author-field="email"]') || {}).value || '',
          phone_code: (card.querySelector('[data-author-field="phone_code"]') || {}).value || '',
          phone_number: (card.querySelector('[data-author-field="phone_number"]') || {}).value || '',
          bio: (card.querySelector('[data-author-field="bio"]') || {}).value || '',
          school_name: (card.querySelector('[data-author-field="school_name"]') || {}).value || '',
          grade_level: (card.querySelector('[data-author-field="grade_level"]') || {}).value || '',
          school_email: (card.querySelector('[data-author-field="school_email"]') || {}).value || '',
          admission_number: (card.querySelector('[data-author-field="admission_number"]') || {}).value || '',
          orcid: (card.querySelector('[data-author-field="orcid"]') || {}).value || '',
          scholar: (card.querySelector('[data-author-field="scholar"]') || {}).value || ''
        };
      });

      var hiddenPayload = document.getElementById('submitAuthorsPayload');
      if (hiddenPayload) {
        hiddenPayload.value = JSON.stringify(payload);
      }

      var hiddenAuthors = document.getElementById('submitAuthorsHidden');
      var hiddenAge = document.getElementById('submitAuthorAgeHidden');
      var hiddenEmail = document.getElementById('submitAuthorEmailHidden');
      var hiddenPhoneCode = document.getElementById('submitAuthorPhoneCodeHidden');
      var hiddenPhoneNumber = document.getElementById('submitAuthorPhoneNumberHidden');
      var hiddenBio = document.getElementById('submitAuthorBioHidden');

      if (hiddenAuthors) {
        hiddenAuthors.value = payload.map(function(author) {
          return author.name.trim();
        }).filter(function(value) {
          return value !== '';
        }).join(', ');
      }

      if (payload.length > 0) {
        hiddenAge.value = payload[0].age || '';
        hiddenEmail.value = payload[0].email || '';
        hiddenPhoneCode.value = payload[0].phone_code || '';
        hiddenPhoneNumber.value = payload[0].phone_number || '';

        var bioText = payload.map(function(author, index) {
          var parts = [
            'Author ' + (index + 1) + ': ' + (author.name || 'Unnamed author'),
            'Age: ' + (author.age || ''),
            'Personal Email: ' + (author.email || ''),
            'Phone Code: ' + (author.phone_code || ''),
            'Phone Number: ' + (author.phone_number || ''),
            'Short Author Biography: ' + (author.bio || ''),
            'School Name: ' + (author.school_name || ''),
            'Grade Level: ' + (author.grade_level || ''),
            'School Email: ' + (author.school_email || ''),
            'Admission Number: ' + (author.admission_number || ''),
            'ORCID ID: ' + (author.orcid || 'N/A'),
            'Google Scholar: ' + (author.scholar || 'N/A')
          ];
          return parts.join('
');
        }).join('

');
        hiddenBio.value = bioText;
      } else {
        hiddenAge.value = '';
        hiddenEmail.value = '';
        hiddenPhoneCode.value = '';
        hiddenPhoneNumber.value = '';
        hiddenBio.value = '';
      }
    }

    function hydrateAuthorCards() {
      var list = getAuthorListElement();
      var payloadField = document.getElementById('submitAuthorsPayload');
      if (!list || !payloadField || !payloadField.value) {
        refreshAuthorEmptyState();
        syncAuthorPayload();
        return;
      }

      try {
        var payload = JSON.parse(payloadField.value);
        if (Array.isArray(payload) && payload.length > 0) {
          list.innerHTML = '';
          payload.forEach(function(author) {
            addAuthorCard({
              name: author && typeof author.name === 'string' ? author.name : '',
              age: author && typeof author.age === 'string' ? author.age : '',
              email: author && typeof author.email === 'string' ? author.email : '',
              phone_code: author && typeof author.phone_code === 'string' ? author.phone_code : '',
              phone_number: author && typeof author.phone_number === 'string' ? author.phone_number : '',
              bio: author && typeof author.bio === 'string' ? author.bio : '',
              school_name: author && typeof author.school_name === 'string' ? author.school_name : '',
              grade_level: author && typeof author.grade_level === 'string' ? author.grade_level : '',
              school_email: author && typeof author.school_email === 'string' ? author.school_email : '',
              admission_number: author && typeof author.admission_number === 'string' ? author.admission_number : '',
              orcid: author && typeof author.orcid === 'string' ? author.orcid : '',
              scholar: author && typeof author.scholar === 'string' ? author.scholar : ''
            });
          });
          return;
        }
      } catch (err) {
        // Ignore malformed payloads and fall back to an empty state.
      }

      refreshAuthorEmptyState();
      syncAuthorPayload();
    }

    

document.addEventListener('DOMContentLoaded', function() {
  hydrateAuthorCards();
});
</script>

</body>

</html>
