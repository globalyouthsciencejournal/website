<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (!function_exists('gysj_submission_journal_options')) {
  function gysj_submission_journal_options(): array
  {
    return [
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
  }
}

if (!function_exists('gysj_normalize_journal_selection')) {
  function gysj_normalize_journal_selection($value): array
  {
    $allowed = array_fill_keys(gysj_submission_journal_options(), true);

    if (is_string($value)) {
      $value = trim($value);
      if ($value === '') {
        $value = [];
      } else {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
          $value = $decoded;
        } else {
          $value = preg_split('/\s*,\s*/', $value) ?: [];
        }
      }
    }

    if (!is_array($value)) {
      return [];
    }

    $journals = [];
    foreach ($value as $item) {
      if (!is_string($item)) {
        continue;
      }

      $journal = trim($item);
      if ($journal === '' || !isset($allowed[$journal]) || in_array($journal, $journals, true)) {
        continue;
      }

      $journals[] = $journal;
    }

    return $journals;
  }
}

if (!function_exists('gysj_journal_selection_json')) {
  function gysj_journal_selection_json(array $journals): ?string
  {
    $journals = gysj_normalize_journal_selection($journals);
    if (empty($journals)) {
      return null;
    }

    $json = json_encode(array_values($journals), JSON_UNESCAPED_UNICODE);
    return is_string($json) ? $json : null;
  }
}

if (!function_exists('gysj_admin_reviewer_expertise_options')) {
  function gysj_admin_reviewer_expertise_options(): array
  {
    return array_merge(['All Fields'], gysj_submission_journal_options());
  }
}

if (!function_exists('gysj_admin_reviewer_availability_options')) {
  function gysj_admin_reviewer_availability_options(): array
  {
    return ['1–2 hours', '3–5 hours', '5–10 hours', '10+ hours'];
  }
}

if (!function_exists('gysj_admin_normalize_checkbox_selection')) {
  function gysj_admin_normalize_checkbox_selection($value, array $allowed, bool $allMeansEmpty = false): array
  {
    if (!is_array($value)) {
      return [];
    }

    if ($allMeansEmpty && in_array('All Fields', $value, true)) {
      return [];
    }

    $allowedMap = array_fill_keys($allowed, true);
    $selected = [];
    foreach ($value as $item) {
      if (!is_string($item)) {
        continue;
      }

      $item = trim($item);
      if ($item === '' || !isset($allowedMap[$item]) || in_array($item, $selected, true)) {
        continue;
      }

      $selected[] = $item;
    }

    return $selected;
  }
}

if (!function_exists('gysj_admin_normalize_files')) {
  function gysj_admin_normalize_files(array $fileInput): array
  {
    if (!isset($fileInput['name'])) {
      return [];
    }

    if (!is_array($fileInput['name'])) {
      return [$fileInput];
    }

    $normalized = [];
    $count = count($fileInput['name']);
    for ($i = 0; $i < $count; $i++) {
      $normalized[] = [
        'name' => $fileInput['name'][$i] ?? '',
        'type' => $fileInput['type'][$i] ?? '',
        'tmp_name' => $fileInput['tmp_name'][$i] ?? '',
        'error' => $fileInput['error'][$i] ?? UPLOAD_ERR_NO_FILE,
        'size' => $fileInput['size'][$i] ?? 0,
      ];
    }

    return $normalized;
  }
}

if (!function_exists('gysj_admin_validate_upload')) {
  function gysj_admin_validate_upload(array $file, bool $required, int $maxBytes, array $allowedMimes, string $missingMessage, string $invalidMessage): array
  {
    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode === UPLOAD_ERR_NO_FILE) {
      return $required ? [false, $missingMessage, null] : [true, '', null];
    }

    if ($errorCode !== UPLOAD_ERR_OK) {
      return [false, $invalidMessage, null];
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
      return [false, $invalidMessage, null];
    }

    if ($size > $maxBytes) {
      return [false, $invalidMessage, null];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
      return [false, $invalidMessage, null];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmp);
    if (!isset($allowedMimes[$mime])) {
      return [false, $invalidMessage, null];
    }

    $original = trim((string) ($file['name'] ?? ''));
    if ($original === '') {
      $original = 'upload.' . $allowedMimes[$mime];
    }

    return [true, '', [
      'mime' => $mime,
      'ext' => $allowedMimes[$mime],
      'size' => $size,
      'tmp' => $tmp,
      'original' => $original,
    ]];
  }
}

if (!function_exists('gysj_admin_store_upload')) {
  function gysj_admin_store_upload(array $upload, string $directory, string $prefix): array
  {
    if (!is_dir($directory)) {
      mkdir($directory, 0755, true);
    }

    $filename = $prefix . '-' . bin2hex(random_bytes(16)) . '.' . (string) ($upload['ext'] ?? 'bin');
    $destination = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file((string) ($upload['tmp'] ?? ''), $destination)) {
      throw new RuntimeException('Failed to save upload file.');
    }

    return [
      'path' => str_replace('\\', '/', basename($directory) === 'admin-applications' ? 'uploads/admin-applications/' . $filename : $destination),
      'original' => (string) ($upload['original'] ?? ''),
      'mime' => (string) ($upload['mime'] ?? ''),
      'size' => (int) ($upload['size'] ?? 0),
      'full_path' => $destination,
    ];
  }
}

if (!function_exists('gysj_table_has_columns')) {
  function gysj_table_has_columns(PDO $pdo, string $table, array $required): bool
  {
    try {
      $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    } catch (Throwable $e) {
      return false;
    }

    $cols = [];
    try {
      if ($driver === 'sqlite') {
        $rows = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
        foreach ($rows as $row) {
          $name = strtolower((string) ($row['name'] ?? ''));
          if ($name !== '') {
            $cols[$name] = true;
          }
        }
      } else {
        $rows = $pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll();
        foreach ($rows as $row) {
          $name = strtolower((string) ($row['Field'] ?? $row['field'] ?? ''));
          if ($name !== '') {
            $cols[$name] = true;
          }
        }
      }
    } catch (Throwable $e) {
      return false;
    }

    foreach ($required as $column) {
      $column = strtolower((string) $column);
      if ($column === '' || !isset($cols[$column])) {
        return false;
      }
    }

    return true;
  }
}

// If already an admin, there's no need to apply again.
// Logged-in regular users may still apply to become an admin.
$loggedInUser = auth_current_user();
if ($loggedInUser && (($loggedInUser['role'] ?? '') === 'admin')) {
  auth_redirect_dashboard($loggedInUser);
}

$success = '';
$error = '';

$pdo = null;
$adminExists = false;

try {
    $pdo = db();

    if (!function_exists('gysj_redesign_ensure_reviewer_columns')) {
      function gysj_redesign_ensure_reviewer_columns(PDO $pdo): void
      {
        $tables = ['admin_applications', 'users'];
        $columnsToAdd = [
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
    }
    gysj_redesign_ensure_reviewer_columns($pdo);

    $stmt = $pdo->query("SELECT 1 FROM users WHERE role = 'admin' LIMIT 1");
    $adminExists = (bool) $stmt->fetch();
} catch (Throwable $e) {
    $pdo = null;

    error_log('Admin signup init error: ' . $e);

    $msg = strtolower($e->getMessage());
    $code = (string) $e->getCode();

    if (strpos($msg, 'could not find driver') !== false) {
        $error = 'Admin sign up is unavailable because the server is missing the MySQL PDO driver (pdo_mysql).';
    } elseif (strpos($msg, '[2002]') !== false || strpos($msg, 'connection refused') !== false || strpos($msg, 'no connection could be made') !== false || $code === 'hy000') {
        $error = 'Admin sign up is unavailable because the site cannot connect to the database server.';
    } elseif ($code === '42s02' || strpos($msg, 'base table') !== false || strpos($msg, "doesn't exist") !== false) {
        $error = 'Admin sign up is unavailable because the database tables are not set up yet. Please run sql/schema.sql (or run: php scripts/init-db.php).';
    } elseif (strpos($msg, 'access denied') !== false || strpos($msg, 'sqlstate[28000]') !== false) {
        $error = 'Admin sign up is unavailable because the database credentials are invalid. Please check DB_USER/DB_PASS.';
    } elseif (strpos($msg, 'not configured') !== false || strpos($msg, 'configuration is missing') !== false) {
        $error = 'Admin sign up is unavailable because the database is not configured. Please set DB_* environment variables or update includes/config.local.php.';
    } else {
        $error = 'Admin sign up is temporarily unavailable. Please try again later.';
    }
}

    $journalOptions = gysj_submission_journal_options();
    $usersHaveAssignedJournalColumn = false;
    $adminApplicationsHaveAssignedJournalColumn = false;
    $postedAssignedJournals = [];

    if ($pdo instanceof PDO) {
      $usersHaveAssignedJournalColumn = gysj_table_has_columns($pdo, 'users', ['assigned_journals_json']);
      $adminApplicationsHaveAssignedJournalColumn = gysj_table_has_columns($pdo, 'admin_applications', ['assigned_journals_json']);
    }

    $usersHaveReviewerProfileColumns = false;
    $adminApplicationsHaveReviewerProfileColumns = false;
    if ($pdo instanceof PDO) {
      $usersHaveReviewerProfileColumns = gysj_table_has_columns($pdo, 'users', [
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
            ]);
      $adminApplicationsHaveReviewerProfileColumns = gysj_table_has_columns($pdo, 'admin_applications', [
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
            ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assigned_journals']) && is_array($_POST['assigned_journals'])) {
        $postedAssignedJournals = gysj_normalize_journal_selection($_POST['assigned_journals']);
    }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO) {
    csrf_validate();

  $name = trim((string) ($_POST['name'] ?? ''));
  $email = trim((string) ($_POST['email'] ?? ''));
  $password = (string) ($_POST['password'] ?? '');
  $password2 = (string) ($_POST['password2'] ?? '');
  $country = trim((string) ($_POST['country'] ?? ''));
  $institution = trim((string) ($_POST['institution'] ?? ''));
  $gradeLevel = trim((string) ($_POST['grade_level'] ?? ''));
  $experienceText = trim((string) ($_POST['reviewer_experience_text'] ?? ''));
  $reviewerReasonText = trim((string) ($_POST['reviewer_reason_text'] ?? ''));
  $profileLinks = trim((string) ($_POST['reviewer_profile_links'] ?? ''));
  $weeklyAvailability = trim((string) ($_POST['reviewer_weekly_availability'] ?? ''));
  $declarationConfirmed = isset($_POST['declaration_confirmed']) ? 1 : 0;

  
  $postedExpertise = $_POST['assigned_journals'] ?? [];


  $postedExpertiseAllFields = is_array($postedExpertise) && in_array('All Fields', $postedExpertise, true);
  $selectedJournals = gysj_admin_normalize_checkbox_selection($postedExpertise, gysj_submission_journal_options(), true);
  $postedAssignedJournals = $selectedJournals;
  $postedWeeklyAvailability = $weeklyAvailability;
  $postedCountry = $country;
  $postedInstitution = $institution;
  $postedGradeLevel = $gradeLevel;
  $postedExperienceText = $experienceText;
  $postedReviewerReasonText = $reviewerReasonText;
  $postedProfileLinks = $profileLinks;
  $postedDeclarationConfirmed = $declarationConfirmed === 1;

  $uploadError = '';
  $storedCv = null;
  $storedSupportingDocs = [];
  $createdUploadPaths = [];
  $shouldStoreFiles = true;

  if ($name === '' || $email === '' || $password === '' || $country === '' || $institution === '' || $gradeLevel === '' || $experienceText === '' || $reviewerReasonText === '' || $weeklyAvailability === '') {
    $error = 'Please fill in all required fields.';
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = 'Please enter a valid email.';
  } elseif (strlen($password) < 8) {
    $error = 'Password must be at least 8 characters.';
  } elseif ($password !== $password2) {
    $error = 'Passwords do not match.';
  } elseif (empty($selectedJournals) && !$postedExpertiseAllFields) {
    $error = 'Please choose at least one field of expertise or All Fields.';
  } elseif ($declarationConfirmed !== 1) {
    $error = 'Please confirm the declaration before submitting.';
  } else {
    try {
      // Re-check whether any admin exists at submit time.
      $stmt = $pdo->query("SELECT 1 FROM users WHERE role = 'admin' LIMIT 1");
      $adminExistsNow = (bool) $stmt->fetch();

      $stmt = $pdo->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
      $stmt->execute([$email]);
      if ($stmt->fetch()) {
        $error = 'That email is already registered. Please log in.';
      } else {
        $cvUpload = is_array($_FILES['cv'] ?? null) ? (array) $_FILES['cv'] : null;
        $supportingUploads = is_array($_FILES['supporting_documents'] ?? null)
          ? gysj_admin_normalize_files((array) $_FILES['supporting_documents'])
          : [];

        $cvAllowed = [
          'application/pdf' => 'pdf',
          'application/x-pdf' => 'pdf',
          'application/msword' => 'doc',
          'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        ];
        $supportAllowed = [
          'application/pdf' => 'pdf',
          'application/x-pdf' => 'pdf',
          'application/msword' => 'doc',
          'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
          'image/jpeg' => 'jpg',
          'image/png' => 'png',
        ];

        if ($cvUpload !== null) {
          [$ok, $uploadError, $cvMeta] = gysj_admin_validate_upload(
            $cvUpload,
            $adminExistsNow,
            10 * 1024 * 1024,
            $cvAllowed,
            'Please upload your CV (PDF, DOC, or DOCX).',
            'CV must be a PDF, DOC, or DOCX file.'
          );
          if (!$ok) {
            $error = $uploadError;
          } elseif (is_array($cvMeta)) {
            $storedCv = $cvMeta;
          }
        } elseif ($adminExistsNow) {
          $error = 'Please upload your CV (PDF, DOC, or DOCX).';
        }

        if ($error === '') {
          foreach ($supportingUploads as $supportingUpload) {
            [$ok, $supportError, $supportMeta] = gysj_admin_validate_upload(
              $supportingUpload,
              false,
              10 * 1024 * 1024,
              $supportAllowed,
              '',
              'Supporting documents must be PDF, DOC, DOCX, JPG, or PNG files.'
            );

            if (!$ok) {
              $error = $supportError !== '' ? $supportError : 'Supporting documents must be valid files.';
              break;
            }

            if (is_array($supportMeta)) {
              $storedSupportingDocs[] = $supportMeta;
            }
          }
        }

        if ($error === '') {
          $uploadDir = __DIR__ . '/uploads/admin-applications';
          $reviewerCvRecord = null;
          $supportingDocRecords = [];

          try {
            if (is_array($storedCv)) {
              $reviewerCvRecord = gysj_admin_store_upload($storedCv, $uploadDir, 'admin-cv');
              $createdUploadPaths[] = (string) ($reviewerCvRecord['full_path'] ?? '');
            }

            foreach ($storedSupportingDocs as $supportingDoc) {
              $storedDoc = gysj_admin_store_upload($supportingDoc, $uploadDir, 'admin-support');
              $supportingDocRecords[] = $storedDoc;
              $createdUploadPaths[] = (string) ($storedDoc['full_path'] ?? '');
            }
          } catch (Throwable $uploadException) {
            $error = 'Failed to save one or more uploaded files. Please try again.';
          }

          if ($error === '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $selectedJournalsJson = $usersHaveAssignedJournalColumn ? gysj_journal_selection_json($selectedJournals) : null;
            $supportingDocsJson = empty($supportingDocRecords)
              ? null
              : json_encode(array_values(array_map(static function (array $doc): array {
                return [
                  'path' => (string) ($doc['path'] ?? ''),
                  'original' => (string) ($doc['original'] ?? ''),
                  'mime' => (string) ($doc['mime'] ?? ''),
                  'size' => (int) ($doc['size'] ?? 0),
                ];
              }, $supportingDocRecords)), JSON_UNESCAPED_UNICODE);

            if (!$adminExistsNow) {
              if ($usersHaveReviewerProfileColumns && $usersHaveAssignedJournalColumn) {
                $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role, country, institution, grade_level, reviewer_experience_text, reviewer_reason_text, reviewer_weekly_availability, reviewer_profile_links, reviewer_cv_path, reviewer_cv_original_name, reviewer_cv_mime, reviewer_cv_size, reviewer_supporting_documents_json, reviewer_declaration_confirmed, assigned_journals_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                  $name,
                  $email,
                  $hash,
                  'admin',
                  $country,
                  $institution,
                  $gradeLevel,
                  $experienceText,
                  $reviewerReasonText,
                  $weeklyAvailability,
                  $profileLinks !== '' ? $profileLinks : null,
                  is_array($reviewerCvRecord) ? ($reviewerCvRecord['path'] ?? null) : null,
                  is_array($reviewerCvRecord) ? ($reviewerCvRecord['original'] ?? null) : null,
                  is_array($reviewerCvRecord) ? ($reviewerCvRecord['mime'] ?? null) : null,
                  is_array($reviewerCvRecord) ? (int) ($reviewerCvRecord['size'] ?? 0) : null,
                  $supportingDocsJson,
                  $declarationConfirmed,
                  $selectedJournalsJson,
                ]);
              } elseif ($usersHaveReviewerProfileColumns) {
                $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role, country, institution, grade_level, reviewer_experience_text, reviewer_reason_text, reviewer_weekly_availability, reviewer_profile_links, reviewer_cv_path, reviewer_cv_original_name, reviewer_cv_mime, reviewer_cv_size, reviewer_supporting_documents_json, reviewer_declaration_confirmed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                  $name,
                  $email,
                  $hash,
                  'admin',
                  $country,
                  $institution,
                  $gradeLevel,
                  $experienceText,
                  $reviewerReasonText,
                  $weeklyAvailability,
                  $profileLinks !== '' ? $profileLinks : null,
                  is_array($reviewerCvRecord) ? ($reviewerCvRecord['path'] ?? null) : null,
                  is_array($reviewerCvRecord) ? ($reviewerCvRecord['original'] ?? null) : null,
                  is_array($reviewerCvRecord) ? ($reviewerCvRecord['mime'] ?? null) : null,
                  is_array($reviewerCvRecord) ? (int) ($reviewerCvRecord['size'] ?? 0) : null,
                  $supportingDocsJson,
                  $declarationConfirmed,
                ]);
              } elseif (gysj_table_has_columns($pdo, 'users', ['country', 'institution', 'grade_level'])) {
                if ($usersHaveAssignedJournalColumn) {
                  $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role, country, institution, grade_level, assigned_journals_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                  $stmt->execute([$name, $email, $hash, 'admin', $country, $institution, $gradeLevel, $selectedJournalsJson]);
                } else {
                  $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role, country, institution, grade_level) VALUES (?, ?, ?, ?, ?, ?, ?)');
                  $stmt->execute([$name, $email, $hash, 'admin', $country, $institution, $gradeLevel]);
                }
              } elseif ($usersHaveAssignedJournalColumn) {
                $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role, assigned_journals_json) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$name, $email, $hash, 'admin', $selectedJournalsJson]);
              } else {
                $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
                $stmt->execute([$name, $email, $hash, 'admin']);
              }

              $userId = (int) $pdo->lastInsertId();
              auth_login_user($userId);
              redirect('admin-dashboard.php');
            }

            // From the 2nd admin onwards: require approval.
            $stmt = $pdo->prepare("SELECT 1 FROM admin_applications WHERE email = ? AND status = 'pending' LIMIT 1");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
              $error = 'An admin application for this email is already pending.';
            } else {
              if ($adminApplicationsHaveReviewerProfileColumns && $adminApplicationsHaveAssignedJournalColumn) {
                $stmt = $pdo->prepare('INSERT INTO admin_applications (name, email, password_hash, assigned_journals_json, country, institution, grade_level, reviewer_experience_text, reviewer_reason_text, reviewer_weekly_availability, reviewer_profile_links, reviewer_cv_path, reviewer_cv_original_name, reviewer_cv_mime, reviewer_cv_size, reviewer_supporting_documents_json, reviewer_declaration_confirmed, cv_path, cv_original_name, cv_mime, cv_size, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                  $name,
                  $email,
                  $hash,
                  $selectedJournalsJson,
                  $country,
                  $institution,
                  $gradeLevel,
                  $experienceText,
                  $reviewerReasonText,
                  $weeklyAvailability,
                  $profileLinks !== '' ? $profileLinks : null,
                  is_array($reviewerCvRecord) ? ($reviewerCvRecord['path'] ?? null) : null,
                  is_array($reviewerCvRecord) ? ($reviewerCvRecord['original'] ?? null) : null,
                  is_array($reviewerCvRecord) ? ($reviewerCvRecord['mime'] ?? null) : null,
                  is_array($reviewerCvRecord) ? (int) ($reviewerCvRecord['size'] ?? 0) : null,
                  $supportingDocsJson,
                  $declarationConfirmed,
                  is_array($reviewerCvRecord) ? ($reviewerCvRecord['path'] ?? null) : null,
                  is_array($reviewerCvRecord) ? ($reviewerCvRecord['original'] ?? null) : null,
                  is_array($reviewerCvRecord) ? ($reviewerCvRecord['mime'] ?? null) : null,
                  is_array($reviewerCvRecord) ? (int) ($reviewerCvRecord['size'] ?? 0) : null,
                  'pending',
                ]);
              } elseif ($adminApplicationsHaveReviewerProfileColumns) {
                $stmt = $pdo->prepare('INSERT INTO admin_applications (name, email, password_hash, country, institution, grade_level, reviewer_experience_text, reviewer_reason_text, reviewer_weekly_availability, reviewer_profile_links, reviewer_cv_path, reviewer_cv_original_name, reviewer_cv_mime, reviewer_cv_size, reviewer_supporting_documents_json, reviewer_declaration_confirmed, cv_path, cv_original_name, cv_mime, cv_size, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                  $name,
                  $email,
                  $hash,
                  $country,
                  $institution,
                  $gradeLevel,
                  $experienceText,
                  $reviewerReasonText,
                  $weeklyAvailability,
                  $profileLinks !== '' ? $profileLinks : null,
                  is_array($reviewerCvRecord) ? ($reviewerCvRecord['path'] ?? null) : null,
                  is_array($reviewerCvRecord) ? ($reviewerCvRecord['original'] ?? null) : null,
                  is_array($reviewerCvRecord) ? ($reviewerCvRecord['mime'] ?? null) : null,
                  is_array($reviewerCvRecord) ? (int) ($reviewerCvRecord['size'] ?? 0) : null,
                  $supportingDocsJson,
                  $declarationConfirmed,
                  is_array($reviewerCvRecord) ? ($reviewerCvRecord['path'] ?? null) : null,
                  is_array($reviewerCvRecord) ? ($reviewerCvRecord['original'] ?? null) : null,
                  is_array($reviewerCvRecord) ? ($reviewerCvRecord['mime'] ?? null) : null,
                  is_array($reviewerCvRecord) ? (int) ($reviewerCvRecord['size'] ?? 0) : null,
                  'pending',
                ]);
              } elseif (gysj_table_has_columns($pdo, 'admin_applications', ['country', 'institution', 'grade_level'])) {
                if ($adminApplicationsHaveAssignedJournalColumn) {
                  $stmt = $pdo->prepare('INSERT INTO admin_applications (name, email, password_hash, assigned_journals_json, country, institution, grade_level, cv_path, cv_original_name, cv_mime, cv_size, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                  $stmt->execute([
                    $name,
                    $email,
                    $hash,
                    $selectedJournalsJson,
                    $country,
                    $institution,
                    $gradeLevel,
                    is_array($reviewerCvRecord) ? ($reviewerCvRecord['path'] ?? '') : '',
                    is_array($reviewerCvRecord) ? ($reviewerCvRecord['original'] ?? '') : '',
                    is_array($reviewerCvRecord) ? ($reviewerCvRecord['mime'] ?? '') : '',
                    is_array($reviewerCvRecord) ? (int) ($reviewerCvRecord['size'] ?? 0) : 0,
                    'pending',
                  ]);
                } else {
                  $stmt = $pdo->prepare('INSERT INTO admin_applications (name, email, password_hash, country, institution, grade_level, cv_path, cv_original_name, cv_mime, cv_size, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                  $stmt->execute([
                    $name,
                    $email,
                    $hash,
                    $country,
                    $institution,
                    $gradeLevel,
                    is_array($reviewerCvRecord) ? ($reviewerCvRecord['path'] ?? '') : '',
                    is_array($reviewerCvRecord) ? ($reviewerCvRecord['original'] ?? '') : '',
                    is_array($reviewerCvRecord) ? ($reviewerCvRecord['mime'] ?? '') : '',
                    is_array($reviewerCvRecord) ? (int) ($reviewerCvRecord['size'] ?? 0) : 0,
                    'pending',
                  ]);
                }
              } elseif ($adminApplicationsHaveAssignedJournalColumn) {
                $stmt = $pdo->prepare('INSERT INTO admin_applications (name, email, password_hash, assigned_journals_json, cv_path, cv_original_name, cv_mime, cv_size, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                  $name,
                  $email,
                  $hash,
                  $selectedJournalsJson,
                  is_array($reviewerCvRecord) ? ($reviewerCvRecord['path'] ?? '') : '',
                  is_array($reviewerCvRecord) ? ($reviewerCvRecord['original'] ?? '') : '',
                  is_array($reviewerCvRecord) ? ($reviewerCvRecord['mime'] ?? '') : '',
                  is_array($reviewerCvRecord) ? (int) ($reviewerCvRecord['size'] ?? 0) : 0,
                  'pending',
                ]);
              } else {
                $stmt = $pdo->prepare('INSERT INTO admin_applications (name, email, password_hash, cv_path, cv_original_name, cv_mime, cv_size, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                  $name,
                  $email,
                  $hash,
                  is_array($reviewerCvRecord) ? ($reviewerCvRecord['path'] ?? '') : '',
                  is_array($reviewerCvRecord) ? ($reviewerCvRecord['original'] ?? '') : '',
                  is_array($reviewerCvRecord) ? ($reviewerCvRecord['mime'] ?? '') : '',
                  is_array($reviewerCvRecord) ? (int) ($reviewerCvRecord['size'] ?? 0) : 0,
                  'pending',
                ]);
              }

              $success = 'Your admin application has been submitted. An existing admin must approve it before you can log in.';
            }
          }
        }
      }
    } catch (Throwable $e) {
      if (!empty($createdUploadPaths)) {
        foreach ($createdUploadPaths as $createdUploadPath) {
          if ($createdUploadPath !== '' && is_file($createdUploadPath)) {
            @unlink($createdUploadPath);
          }
        }
      }

      error_log('Admin signup error: ' . $e);

      $msg = strtolower($e->getMessage());
      $code = (string) $e->getCode();

      if ($code === '42s02' || strpos($msg, 'base table') !== false || strpos($msg, "doesn't exist") !== false) {
        $error = 'Admin sign up is unavailable because the database tables are not set up yet. Please run sql/schema.sql (or run: php scripts/init-db.php).';
      } elseif (strpos($msg, 'duplicate') !== false || strpos($msg, 'uniq_users_email') !== false) {
        $error = 'That email is already registered. Please log in.';
      } else {
        $error = 'Could not submit your admin application. Please try again.';
      }
    }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <link rel="shortcut icon" type="image/jpg" href="images/iysjournal.png">
  <title>Become an Editor | Global Youth Science Journal</title>
  
  <link href="css/media_query.css" rel="stylesheet" type="text/css">
  <link href="css/style.css" rel="stylesheet" type="text/css">
  <link href="css/bootstrap.css" rel="stylesheet" type="text/css">
  <link href="css/font-awesome.min.css" rel="stylesheet" crossorigin="anonymous">
  <link href="css/animate.css" rel="stylesheet" type="text/css">
  <link href="https://fonts.googleapis.com/css?family=Poppins" rel="stylesheet">
  <link href="css/owl.carousel.css" rel="stylesheet" type="text/css">
  <link href="css/owl.theme.default.css" rel="stylesheet" type="text/css">
  <link href="css/style_1.css" rel="stylesheet" type="text/css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <script src="js/modernizr-3.5.0.min.js"></script>
  
  <style>
    :root {
      --bg: #dde3ea;
      --surface: #ffffff;
      --border: #eaeaea;
      --text-main: #111111;
      --text-muted: #6b7280;
      --primary: #000000;
      --primary-hover: #333333;
      --radius: 0px;
      --radius-sm: 0px;
      --input-bg: #ffffff;
      --input-border: #d1d5db;
      --input-focus: #000000;
      --error: #ef4444;
      --success: #10b981;
    }

    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
      background-color: var(--bg);
      color: var(--text-main);
      margin: 0;
      padding: 0;
      -webkit-font-smoothing: antialiased;
    }

    /* Minimal Navbar Override */
    .gysj-navbar-minimal {
      background: var(--surface);
      border-bottom: 1px solid var(--border);
      padding: 16px 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .gysj-navbar-minimal .brand {
      display: flex;
      align-items: center;
      gap: 12px;
      text-decoration: none;
      color: var(--text-main);
      font-weight: 600;
      font-size: 16px;
    }
    .gysj-navbar-minimal img {
      height: 32px;
    }

    /* Form Container */
    .app-container {
      max-width: 580px;
      margin: 48px auto;
      background: var(--surface);
      border-radius: var(--radius);
      border: 1px solid var(--border);
      box-shadow: 0 4px 24px rgba(0,0,0,0.04);
      padding: 40px;
      position: relative;
    }

    /* Header & Progress */
    .app-header {
      text-align: center;
      margin-bottom: 32px;
    }
    .app-header h1 {
      font-size: 24px;
      font-weight: 600;
      margin: 0 0 8px;
      letter-spacing: -0.02em;
    }
    .app-header p {
      color: var(--text-muted);
      font-size: 15px;
      margin: 0;
      line-height: 1.5;
    }
    .trust-indicators {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 16px;
      margin-top: 16px;
      font-size: 13px;
      color: var(--text-muted);
      flex-wrap: wrap;
    }
    .trust-indicators span {
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .progress-wrapper {
      margin-bottom: 32px;
    }
    .progress-text {
      font-size: 13px;
      color: var(--text-muted);
      font-weight: 500;
      margin-bottom: 8px;
      display: flex;
      justify-content: space-between;
    }
    .progress-bar-container {
      height: 6px;
      background: #f3f4f6;
      border-radius: 0px;
      overflow: hidden;
    }
    .progress-bar-fill {
      height: 100%;
      background: var(--primary);
      width: 20%;
      border-radius: 0px;
      transition: width 0.4s ease;
    }

    /* Steps */
    .step-container {
      display: none;
      animation: fadeIn 0.3s ease;
    }
    .step-container.active {
      display: block;
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(4px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .step-title {
      font-size: 18px;
      font-weight: 600;
      margin: 0 0 24px;
      padding-bottom: 12px;
      border-bottom: 1px solid var(--border);
    }

    /* Fields */
    .field-group {
      margin-bottom: 24px;
    }
    .field-group label {
      display: block;
      font-size: 14px;
      font-weight: 500;
      margin-bottom: 8px;
      color: var(--text-main);
    }
    .field-group label .required {
      color: var(--error);
    }
    .field-note {
      font-size: 13px;
      color: var(--text-muted);
      margin-bottom: 8px;
      display: block;
    }
    .input-control {
      width: 100%;
      padding: 12px 16px;
      font-size: 15px;
      border: 1px solid var(--input-border);
      border-radius: var(--radius-sm);
      background: var(--input-bg);
      color: var(--text-main);
      transition: border-color 0.2s, box-shadow 0.2s;
      font-family: inherit;
    }
    .input-control:focus {
      outline: none;
      border-color: var(--input-focus);
      box-shadow: 0 0 0 3px rgba(0,0,0,0.05);
    }
    textarea.input-control {
      min-height: 120px;
      resize: vertical;
    }
    
    .char-counter {
      text-align: right;
      font-size: 12px;
      color: var(--text-muted);
      margin-top: 6px;
    }
    .char-counter.near-limit {
      color: #f59e0b;
    }
    .char-counter.over-limit {
      color: var(--error);
    }

    /* Chips / Selection */
    .chip-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
      gap: 12px;
    }
    .chip-label {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      padding: 12px 14px;
      border: 1px solid var(--input-border);
      border-radius: var(--radius-sm);
      cursor: pointer;
      transition: all 0.2s;
      background: var(--surface);
      font-size: 14px;
      line-height: 1.4;
      user-select: none;
    }
    .chip-label:hover {
      border-color: #9ca3af;
    }
    .chip-label input {
      margin-top: 3px;
      cursor: pointer;
    }
    .chip-label:has(input:checked) {
      border-color: var(--primary);
      background: #fafafa;
    }

    /* Validation Messages */
    .error-text {
      color: var(--error);
      font-size: 13px;
      margin-top: 6px;
      display: none;
    }
    .field-group.has-error .input-control {
      border-color: var(--error);
    }
    .field-group.has-error .error-text {
      display: block;
    }

    /* Actions */
    .form-actions {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-top: 40px;
      padding-top: 24px;
      border-top: 1px solid var(--border);
    }
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 12px 24px;
      font-size: 15px;
      font-weight: 500;
      border-radius: var(--radius-sm);
      cursor: pointer;
      transition: all 0.2s;
      border: 1px solid transparent;
      font-family: inherit;
    }
    .btn-primary {
      background: var(--primary);
      color: #fff;
    }
    .btn-primary:hover {
      background: var(--primary-hover);
    }
    .btn-secondary {
      background: #fff;
      border-color: var(--input-border);
      color: var(--text-main);
    }
    .btn-secondary:hover {
      background: #f9fafb;
    }
    .btn:disabled {
      opacity: 0.6;
      cursor: not-allowed;
    }

    /* File Upload */
    .file-upload-wrapper {
      position: relative;
      border: 1px dashed var(--input-border);
      border-radius: var(--radius-sm);
      padding: 24px;
      text-align: center;
      background: #fafafa;
      transition: border-color 0.2s;
    }
    .file-upload-wrapper:hover {
      border-color: var(--text-muted);
    }
    .file-upload-wrapper input[type="file"] {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      opacity: 0;
      cursor: pointer;
    }
    .file-upload-label {
      pointer-events: none;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 8px;
    }
    .file-upload-label i {
      font-size: 24px;
      color: var(--text-muted);
    }
    .file-upload-label span {
      font-size: 14px;
      font-weight: 500;
    }
    .file-upload-label small {
      color: var(--text-muted);
      font-size: 12px;
    }

    /* Alerts */
    .alert {
      padding: 16px;
      border-radius: var(--radius-sm);
      margin-bottom: 24px;
      font-size: 14px;
    }
    .alert-danger {
      background: #fef2f2;
      color: #991b1b;
      border: 1px solid #fecaca;
    }
    .alert-success {
      background: #ecfdf5;
      color: #065f46;
      border: 1px solid #a7f3d0;
    }
    .alert-info {
      background: #eff6ff;
      color: #1e40af;
      border: 1px solid #bfdbfe;
    }

    /* Mobile Sticky Footer */
    @media (max-width: 640px) {
      .app-container {
        margin: 0;
        border-radius: 0;
        border: none;
        padding: 24px 20px 100px;
        min-height: 100vh;
      }
      .form-actions {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        background: var(--surface);
        padding: 16px 20px;
        margin: 0;
        border-top: 1px solid var(--border);
        box-shadow: 0 -4px 12px rgba(0,0,0,0.05);
        z-index: 10;
      }
      .btn {
        width: 100%;
        padding: 14px;
      }
      .form-actions {
        gap: 12px;
      }
    }
    
    .autosave-indicator {
      font-size: 12px;
      color: var(--text-muted);
      display: flex;
      align-items: center;
      gap: 6px;
      opacity: 0;
      transition: opacity 0.3s;
    }
    .autosave-indicator.visible {
      opacity: 1;
    }
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
                    <button class="navbar-toggler" type="button" data-toggle="collapse"
                        data-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false"
                        aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>
                </div>

                <div class="collapse navbar-collapse w-100 mt-3 gysj-nav-links" id="navbarSupportedContent">
                    <ul class="navbar-nav mx-auto">
                        <li class="nav-item">
                            <a class="nav-link" href="index.php">Home</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="publication.php">Publication</a>
                        </li>
                        <li class="nav-item ">
                            <a class="nav-link" href="editorial-board.php">Editorial Board</a>
                        </li>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="dropdownMenuButton2" data-toggle="dropdown"
                                aria-haspopup="true" aria-expanded="false">About Us</a>
                            <div class="dropdown-menu" aria-labelledby="dropdownMenuButton2">
                                <a class="dropdown-item" href="our-founders.php">Our Founders</a>
                                <a class="dropdown-item" href="our-mission.php">Our Mission</a>
                                <a class="dropdown-item" href="our-funding.php">Our Funding</a>
                            </div>
                        </li>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="dropdownMenuButton3" data-toggle="dropdown"
                                aria-haspopup="true" aria-expanded="false">Paper Submissions</a>
                            <div class="dropdown-menu" aria-labelledby="dropdownMenuButton3">
                                <a class="dropdown-item" href="user-dashboard.php?view=submit">Online Submission</a>
                                <a class="dropdown-item" href="call-for-paper.php">Call for Paper</a>
                                <a class="dropdown-item" href="authorguidelines.php">Guidelines for authors</a>
                                <a class="dropdown-item" href="copyright.php">Copyright</a>
                            </div>
                        </li>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="dropdownSupport" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Support GYSJ</a>
                            <div class="dropdown-menu" aria-labelledby="dropdownSupport">
                                <a class="dropdown-item" href="contribute.php">Contribute</a>
                                <a class="dropdown-item" href="partners.php">Partners</a>
                            </div>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="contact.php">Contact Us</a>
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

  <div class="app-container">
    <div class="app-header">
      <h1>Become an Editor at IYSJ</h1>
      <p>Help review, improve, and publish youth-led scientific research.</p>
      <div class="trust-indicators">
        <span><i class="fa fa-clock-o"></i> 5–8 minutes</span>
        <span><i class="fa fa-globe"></i> Open Internationally</span>
        <span><i class="fa fa-certificate"></i> Volunteer Position</span>
      </div>
    </div>

    <?php if (isset($error) && $error !== ''): ?>
      <div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> <?php echo e($error); ?></div>
    <?php endif; ?>
    <?php if (isset($success) && $success !== ''): ?>
      <div class="alert alert-success"><i class="fa fa-check-circle"></i> <?php echo e($success); ?></div>
    <?php endif; ?>
    <?php if (!$adminExists && empty($error) && empty($success)): ?>
      <div class="alert alert-info">No editors have been registered yet. Creating an account here will create the <b>first editor</b> immediately.</div>
    <?php elseif ($adminExists && empty($error) && empty($success)): ?>
      <div class="alert alert-info">Your application requires approval by an existing editor.</div>
    <?php endif; ?>

    <div class="progress-wrapper">
      <div class="progress-text">
        <span id="step-indicator-text">Step 1 of 5</span>
        <span id="step-percentage">20%</span>
      </div>
      <div class="progress-bar-container">
        <div class="progress-bar-fill" id="progress-fill"></div>
      </div>
    </div>

    <form method="post" action="admin-signup.php" enctype="multipart/form-data" id="application-form" novalidate>
      <?php echo csrf_field(); ?>

      <!-- STEP 1: Account Setup -->
      <div class="step-container active" data-step="1">
        <h2 class="step-title">1. Account Setup</h2>
        
        <div class="field-group">
          <label for="name">Full Name <span class="required">*</span></label>
          <input type="text" id="name" name="name" class="input-control save-draft" value="<?php echo e((string) ($name ?? '')); ?>" required>
          <div class="error-text">Please enter your full name.</div>
        </div>

        <div class="field-group">
          <label for="email">Email Address <span class="required">*</span></label>
          <input type="email" id="email" name="email" class="input-control save-draft" value="<?php echo e((string) ($email ?? '')); ?>" required>
          <div class="error-text">Please enter a valid email address.</div>
        </div>

        <div class="field-group">
          <label for="password">Password <span class="required">*</span></label>
          <input type="password" id="password" name="password" class="input-control" required minlength="8">
          <small class="field-note">✓ 8+ characters</small>
          <div class="error-text">Password must be at least 8 characters.</div>
        </div>

        <div class="field-group">
          <label for="password2">Confirm Password <span class="required">*</span></label>
          <input type="password" id="password2" name="password2" class="input-control" required minlength="8">
          <small class="field-note">✓ Passwords must match</small>
          <div class="error-text">Passwords do not match.</div>
        </div>
      </div>

      <!-- STEP 2: Academic Profile -->
      <div class="step-container" data-step="2">
        <h2 class="step-title">2. Academic Profile</h2>
        
        <div class="field-group">
          <label for="country">Country / Region <span class="required">*</span></label>
          <input type="text" id="country" name="country" class="input-control save-draft" value="<?php echo e((string) ($postedCountry ?? '')); ?>" required>
          <div class="error-text">Please specify your country or region.</div>
        </div>

        <div class="field-group">
          <label for="institution">Institution / School / Organization <span class="required">*</span></label>
          <input type="text" id="institution" name="institution" class="input-control save-draft" value="<?php echo e((string) ($postedInstitution ?? '')); ?>" required>
          <div class="error-text">Please provide your institution.</div>
        </div>

        <div class="field-group">
          <label for="grade_level">Current Grade / Year / Qualification <span class="required">*</span></label>
          <input type="text" id="grade_level" name="grade_level" class="input-control save-draft" value="<?php echo e((string) ($postedGradeLevel ?? '')); ?>" placeholder="e.g. Grade 10, Undergraduate Year 1, MSc Physics" required>
          <div class="error-text">Please provide your current academic level.</div>
        </div>
      </div>

      <!-- STEP 3: Editorial Interests -->
      <div class="step-container" data-step="3">
        <h2 class="step-title">3. Editorial Interests</h2>
        
        <div class="field-group">
          <label>Journal Specializations <span class="required">*</span></label>
          <span class="field-note">Select up to 5 areas that best match your expertise.</span>
          <div class="chip-grid" id="specializations-grid">
            <label class="chip-label">
              <input type="checkbox" name="assigned_journals[]" value="All Fields" class="save-draft-cb" <?php echo !empty($postedExpertiseAllFields) ? 'checked' : ''; ?>>
              <span>All Fields</span>
            </label>
            <?php foreach (gysj_submission_journal_options() as $journalOption): ?>
              <label class="chip-label">
                <input type="checkbox" name="assigned_journals[]" value="<?php echo e($journalOption); ?>" class="save-draft-cb" <?php echo in_array($journalOption, $postedAssignedJournals ?? [], true) ? 'checked' : ''; ?>>
                <span><?php echo e($journalOption); ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="error-text" id="error-specializations">Please select at least one specialization.</div>
        </div>

        

        <div class="field-group">
          <label>Weekly Availability <span class="required">*</span></label>
          <div class="chip-grid">
            <?php foreach (gysj_admin_reviewer_availability_options() as $availabilityOption): ?>
              <label class="chip-label">
                <input type="radio" name="reviewer_weekly_availability" value="<?php echo e($availabilityOption); ?>" class="save-draft-rb" required <?php echo ((string) ($postedWeeklyAvailability ?? '')) === $availabilityOption ? 'checked' : ''; ?>>
                <span><?php echo e($availabilityOption); ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="error-text" id="error-availability">Please select your availability.</div>
        </div>
      </div>

      <!-- STEP 4: Editorial Evaluation -->
      <div class="step-container" data-step="4">
        <h2 class="step-title">4. Editorial Evaluation</h2>
        
        <div class="field-group">
          <label for="reviewer_experience_text">Editorial Background & Experience <span class="required">*</span></label>
          <span class="field-note">Tell us about research projects, publications, editorial experience, teaching, or Olympiads.</span>
          <textarea id="reviewer_experience_text" name="reviewer_experience_text" class="input-control save-draft char-count" data-max="2500" required><?php echo e((string) ($postedExperienceText ?? '')); ?></textarea>
          <div class="char-counter"><span class="current-count">0</span> / 2500</div>
          <div class="error-text">Please provide your background experience.</div>
        </div>

        <div class="field-group">
          <label for="reviewer_reason_text">Why Are You a Good Editorial Fit? <span class="required">*</span></label>
          <span class="field-note">Describe your editorial vision and approach. (Recommended: 150–200 words)</span>
          <textarea id="reviewer_reason_text" name="reviewer_reason_text" class="input-control save-draft char-count" data-max="1500" required><?php echo e((string) ($postedReviewerReasonText ?? '')); ?></textarea>
          <div class="char-counter"><span class="current-count">0</span> / 1500</div>
          <div class="error-text">Please describe why you are a good fit.</div>
          
          <div class="alert alert-info" style="margin-top:12px; padding:12px;">
            <i class="fa fa-lightbulb-o"></i> <b>Tip:</b> Strong applications discuss fairness, quality standards, constructive feedback, and scientific integrity.
          </div>
        </div>
      </div>

      <!-- STEP 5: Documents & Submission -->
      <div class="step-container" data-step="5">
        <h2 class="step-title">5. Documents & Submission</h2>
        
        <div class="field-group">
          <label for="reviewer_profile_links">Professional Links (Optional)</label>
          <span class="field-note">LinkedIn, Portfolio, GitHub, or Research Profile</span>
          <input type="text" id="reviewer_profile_links" name="reviewer_profile_links" class="input-control save-draft" value="<?php echo e((string) ($postedProfileLinks ?? '')); ?>" placeholder="https://...">
        </div>

        <div class="field-group">
          <label>Upload Resume / CV</label>
          <div class="file-upload-wrapper">
            <input type="file" id="cv" name="cv" accept=".pdf,.doc,.docx" onchange="updateFileName(this, 'cv-name')">
            <div class="file-upload-label">
              <i class="fa fa-cloud-upload"></i>
              <span id="cv-name">Choose a file or drag & drop</span>
              <small>Accepted: PDF, DOCX (Max 10MB)</small>
            </div>
          </div>
        </div>

        <div class="field-group">
          <label>Supporting Documents (Optional)</label>
          <span class="field-note">Research papers, certificates, recommendation letters, etc.</span>
          <div class="file-upload-wrapper">
            <input type="file" id="supporting_documents" name="supporting_documents[]" multiple accept=".pdf,.doc,.docx,.jpg,.png" onchange="updateFileName(this, 'support-name', true)">
            <div class="file-upload-label">
              <i class="fa fa-files-o"></i>
              <span id="support-name">Choose files or drag & drop</span>
              <small>Accepted: PDF, DOCX, JPG, PNG</small>
            </div>
          </div>
        </div>

        <div class="field-group" style="margin-top: 32px; background: #fafafa; padding: 16px; border-radius: var(--radius-sm); border: 1px solid var(--border);">
          <label class="chip-label" style="border:none; background:transparent; padding:0; gap:12px;">
            <input type="checkbox" id="declaration_confirmed" name="declaration_confirmed" value="1" required <?php echo !empty($postedDeclarationConfirmed) ? 'checked' : ''; ?>>
            <span style="font-weight: 500; font-size: 14px;">I confirm that all information provided is accurate and I agree to uphold the editorial standards and ethical practices of IYSJ. <span class="required">*</span></span>
          </label>
          <div class="error-text" style="margin-left: 28px;">You must agree to the declaration.</div>
        </div>
      </div>

      <div class="form-actions">
        <div style="display:flex; align-items:center; gap:16px;">
          <button type="button" class="btn btn-secondary" id="btn-back" style="display: none;">← Back</button>
          <span class="autosave-indicator" id="autosave-msg"><i class="fa fa-check-circle"></i> Draft saved</span>
        </div>
        <button type="button" class="btn btn-primary" id="btn-next">Continue</button>
        <button type="submit" class="btn btn-primary" id="btn-submit" style="display: none;">Submit Application</button>
      </div>

    </form>
  </div>

  <script src="js/jquery.min.js"></script>
  <script src="js/tether.min.js" crossorigin="anonymous"></script>
  <script src="js/bootstrap.min.js" crossorigin="anonymous"></script>
  <script>
    document.addEventListener("DOMContentLoaded", function() {
      const totalSteps = 5;
      let currentStep = 1;
      
      const form = document.getElementById('application-form');
      const steps = document.querySelectorAll('.step-container');
      const btnNext = document.getElementById('btn-next');
      const btnBack = document.getElementById('btn-back');
      const btnSubmit = document.getElementById('btn-submit');
      const progressFill = document.getElementById('progress-fill');
      const stepText = document.getElementById('step-indicator-text');
      const stepPercent = document.getElementById('step-percentage');
      const autosaveMsg = document.getElementById('autosave-msg');

      // --- Autosave Logic ---
      const draftKey = 'gysj_admin_draft';
      
      function loadDraft() {
        try {
          const saved = localStorage.getItem(draftKey);
          if (saved) {
            const data = JSON.parse(saved);
            document.querySelectorAll('.save-draft').forEach(el => {
              if (data[el.id] !== undefined && !el.value) { // Don't override if PHP populated it
                el.value = data[el.id];
                updateCharCount(el);
              }
            });
            document.querySelectorAll('.save-draft-cb').forEach(el => {
              const key = el.name + '_' + el.value;
              if (data[key] !== undefined) el.checked = data[key];
            });
            document.querySelectorAll('.save-draft-rb').forEach(el => {
              if (data['rb_' + el.name] === el.value) el.checked = true;
            });
          }
        } catch(e) {}
      }

      function saveDraft() {
        const data = {};
        document.querySelectorAll('.save-draft').forEach(el => {
          data[el.id] = el.value;
        });
        document.querySelectorAll('.save-draft-cb').forEach(el => {
          data[el.name + '_' + el.value] = el.checked;
        });
        document.querySelectorAll('.save-draft-rb').forEach(el => {
          if(el.checked) data['rb_' + el.name] = el.value;
        });
        localStorage.setItem(draftKey, JSON.stringify(data));
        
        autosaveMsg.classList.add('visible');
        setTimeout(() => autosaveMsg.classList.remove('visible'), 2000);
      }

      // Autosave every 30s
      setInterval(saveDraft, 30000);
      // Also save on input blur
      document.querySelectorAll('.save-draft, .save-draft-cb, .save-draft-rb').forEach(el => {
        el.addEventListener('change', saveDraft);
      });
      
      loadDraft();

      // --- Character Counters ---
      function updateCharCount(el) {
        if (!el.classList.contains('char-count')) return;
        const max = parseInt(el.getAttribute('data-max'));
        const len = el.value.length;
        const counterDiv = el.nextElementSibling;
        const currentSpan = counterDiv.querySelector('.current-count');
        
        if (currentSpan) {
          currentSpan.textContent = len;
          counterDiv.classList.remove('near-limit', 'over-limit');
          if (len > max) {
            counterDiv.classList.add('over-limit');
          } else if (len > max * 0.9) {
            counterDiv.classList.add('near-limit');
          }
        }
      }
      document.querySelectorAll('.char-count').forEach(el => {
        el.addEventListener('input', () => updateCharCount(el));
        updateCharCount(el);
      });

      // --- Navigation & Validation ---
      function updateUI() {
        steps.forEach((el, index) => {
          el.classList.toggle('active', index + 1 === currentStep);
        });

        btnBack.style.display = currentStep > 1 ? 'inline-flex' : 'none';
        
        if (currentStep === totalSteps) {
          btnNext.style.display = 'none';
          btnSubmit.style.display = 'inline-flex';
        } else {
          btnNext.style.display = 'inline-flex';
          btnSubmit.style.display = 'none';
        }

        const pct = Math.round((currentStep / totalSteps) * 100);
        progressFill.style.width = pct + '%';
        stepText.textContent = `Step ${currentStep} of ${totalSteps}`;
        stepPercent.textContent = pct + '%';
        
        window.scrollTo({ top: 0, behavior: 'smooth' });
      }

      function validateStep(step) {
        let isValid = true;
        const container = document.querySelector(`.step-container[data-step="${step}"]`);
        
        // Clear previous errors
        container.querySelectorAll('.field-group').forEach(fg => fg.classList.remove('has-error'));

        // Standard inputs
        container.querySelectorAll('input[required], textarea[required]').forEach(el => {
          if (el.type === 'checkbox' || el.type === 'radio') return; // Handled below
          if (!el.value.trim()) {
            el.closest('.field-group').classList.add('has-error');
            isValid = false;
          }
        });

        // Step 1 Specific: Passwords
        if (step === 1) {
          const p1 = document.getElementById('password');
          const p2 = document.getElementById('password2');
          if (p1.value.length < 8 && p1.value.length > 0) {
            p1.closest('.field-group').classList.add('has-error');
            isValid = false;
          }
          if (p1.value !== p2.value) {
            p2.closest('.field-group').classList.add('has-error');
            isValid = false;
          }
          
          const email = document.getElementById('email');
          if (email.value && !email.value.includes('@')) {
            email.closest('.field-group').classList.add('has-error');
            isValid = false;
          }
        }

        // Step 3 Specific: Checkbox/Radio Groups
        if (step === 3) {
          const specChecked = container.querySelectorAll('input[name="assigned_journals[]"]:checked').length;
          if (specChecked === 0) {
            document.getElementById('error-specializations').closest('.field-group').classList.add('has-error');
            isValid = false;
          }

          

          const availChecked = container.querySelectorAll('input[name="reviewer_weekly_availability"]:checked').length;
          if (availChecked === 0) {
            document.getElementById('error-availability').closest('.field-group').classList.add('has-error');
            isValid = false;
          }
        }

        // Step 4 Specific: Char limits
        if (step === 4) {
          container.querySelectorAll('.char-count').forEach(el => {
            const max = parseInt(el.getAttribute('data-max'));
            if (el.value.length > max) {
              el.closest('.field-group').classList.add('has-error');
              const err = el.closest('.field-group').querySelector('.error-text');
              err.textContent = `Text exceeds maximum length of ${max} characters.`;
              isValid = false;
            }
          });
        }
        
        // Step 5 Specific: Declaration
        if (step === 5) {
          const decl = document.getElementById('declaration_confirmed');
          if (!decl.checked) {
            decl.closest('.field-group').classList.add('has-error');
            isValid = false;
          }
        }

        return isValid;
      }

      btnNext.addEventListener('click', () => {
        if (validateStep(currentStep)) {
          currentStep++;
          updateUI();
        }
      });

      btnBack.addEventListener('click', () => {
        if (currentStep > 1) {
          currentStep--;
          updateUI();
        }
      });
      
      form.addEventListener('submit', (e) => {
        if (!validateStep(currentStep)) {
          e.preventDefault();
        } else {
          // Clear draft on successful submit
          localStorage.removeItem(draftKey);
        }
      });

      updateUI();
      
      // All Fields Checkbox Logic
      
      const allFieldsCb = document.querySelector('input[value="All Fields"]');
      if (allFieldsCb) {
        allFieldsCb.addEventListener('change', function() {
          const otherCbs = document.querySelectorAll('input[name="assigned_journals[]"]:not([value="All Fields"])');
          otherCbs.forEach(cb => {
            if (this.checked) {
              cb.checked = false;
              cb.disabled = true;
              cb.closest('.chip-label').style.opacity = '0.5';
              cb.closest('.chip-label').style.pointerEvents = 'none';
              cb.closest('.chip-label').style.background = '#f9fafb';
            } else {
              cb.disabled = false;
              cb.closest('.chip-label').style.opacity = '1';
              cb.closest('.chip-label').style.pointerEvents = 'auto';
              cb.closest('.chip-label').style.background = '';
            }
          });
        });
        // trigger on load in case it was already checked (e.g. from draft or PHP)
        if(allFieldsCb.checked) {
          allFieldsCb.dispatchEvent(new Event('change'));
        }
      }
});
        });
      }
    });

    // File input UX
    window.updateFileName = function(input, labelId, isMultiple = false) {
      const label = document.getElementById(labelId);
      if (input.files && input.files.length > 0) {
        if (isMultiple && input.files.length > 1) {
          label.textContent = input.files.length + ' files selected';
        } else {
          label.textContent = input.files[0].name;
        }
        label.style.color = 'var(--primary)';
      } else {
        label.textContent = isMultiple ? 'Choose files or drag & drop' : 'Choose a file or drag & drop';
        label.style.color = '';
      }
    };
  </script>
</body>
</html>
