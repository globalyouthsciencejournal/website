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
  $pdo->exec("CREATE TABLE IF NOT EXISTS submission_messages (
      id INT AUTO_INCREMENT PRIMARY KEY,
      submission_id INT NOT NULL,
      sender_type VARCHAR(50) NOT NULL,
      sender_name VARCHAR(100) NOT NULL,
      message TEXT NOT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
  )");
} catch (Throwable $e) {
  $pdo = null;
  $error = 'Database unavailable. Please try again later.';
}

function profile_defaults(array $user): array
{
  return [
    'id' => (int) ($user['id'] ?? 0),
    'name' => (string) ($user['name'] ?? ''),
    'email' => (string) ($user['email'] ?? ''),
    'username' => '',
    'phone' => '',
    'country' => '',

    'title' => '',
    'first_name' => '',
    'middle_name' => '',
    'last_name' => '',
    'position' => '',
    'institution' => '',
    'department' => '',
    'grade_level' => '',
    'school_name' => '',
    'school_email' => '',
    'admission_number' => '',
    'city' => '',
    'state' => '',
    'postal_code' => '',
  ];
}

function load_user_profile(PDO $pdo, int $userId, array $fallback): array
{
  $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
  $stmt->execute([$userId]);
  $row = $stmt->fetch();
  if (!is_array($row)) {
    return $fallback;
  }

  $fallback['id'] = (int) ($row['id'] ?? $userId);
  foreach ($fallback as $k => $_v) {
    if ($k === 'id') {
      continue;
    }
    if (!array_key_exists($k, $row)) {
      continue;
    }
    $fallback[$k] = $row[$k] === null ? '' : (string) $row[$k];
  }

  return $fallback;
}

function users_table_columns(PDO $pdo): array
{
  static $cached = null;
  if (is_array($cached)) {
    return $cached;
  }

  $driver = '';
  try {
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
  } catch (Throwable $e) {
    $driver = '';
  }

  $cols = [];
  try {
    if ($driver === 'sqlite') {
      $rows = $pdo->query('PRAGMA table_info(users)')->fetchAll();
      if (is_array($rows)) {
        foreach ($rows as $r) {
          $name = strtolower((string) ($r['name'] ?? ''));
          if ($name !== '') {
            $cols[$name] = true;
          }
        }
      }
    } else {
      $rows = $pdo->query('SHOW COLUMNS FROM users')->fetchAll();
      if (is_array($rows)) {
        foreach ($rows as $r) {
          $name = strtolower((string) ($r['Field'] ?? $r['field'] ?? ''));
          if ($name !== '') {
            $cols[$name] = true;
          }
        }
      }
    }
  } catch (Throwable $e) {
    $cols = [];
  }

  $cached = $cols;
  return $cols;
}

function users_has_columns(PDO $pdo, array $required): bool
{
  $cols = users_table_columns($pdo);
  foreach ($required as $c) {
    $c = strtolower((string) $c);
    if ($c === '' || !isset($cols[$c])) {
      return false;
    }
  }
  return true;
}

function paper_submissions_has_columns(PDO $pdo, array $required): bool
{
  static $cached = null;
  if (!is_array($cached)) {
    $cached = [];

    try {
      $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    } catch (Throwable $e) {
      $driver = '';
    }

    try {
      if ($driver === 'sqlite') {
        $rows = $pdo->query('PRAGMA table_info(paper_submissions)')->fetchAll();
        if (is_array($rows)) {
          foreach ($rows as $r) {
            $name = strtolower((string) ($r['name'] ?? ''));
            if ($name !== '') {
              $cached[$name] = true;
            }
          }
        }
      } else {
        $rows = $pdo->query('SHOW COLUMNS FROM paper_submissions')->fetchAll();
        if (is_array($rows)) {
          foreach ($rows as $r) {
            $name = strtolower((string) ($r['Field'] ?? $r['field'] ?? ''));
            if ($name !== '') {
              $cached[$name] = true;
            }
          }
        }
      }
    } catch (Throwable $e) {
      $cached = [];
    }
  }

  foreach ($required as $c) {
    $c = strtolower((string) $c);
    if ($c === '' || !isset($cached[$c])) {
      return false;
    }
  }

  return true;
}

$profile = profile_defaults($user);
if ($pdo instanceof PDO) {
  try {
    $profile = load_user_profile($pdo, (int) $user['id'], $profile);
  } catch (Throwable $e) {
    // Leave fallback profile values.
  }
}

function generate_unique_submission_slug(PDO $pdo, string $title): string
{
    $base = slugify($title);
    if ($base === '') {
        $base = 'paper';
    }

    $slug = $base;
    $i = 2;

    while (true) {
        $stmt = $pdo->prepare('SELECT 1 FROM paper_submissions WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        if (!$stmt->fetch()) {
            return $slug;
        }

        $slug = $base . '-' . $i;
        $i++;

        if ($i > 2000) {
            return $base . '-' . bin2hex(random_bytes(4));
        }
    }
}

function validate_pdf_upload(array $file, int $maxBytes): array
{
    if (!isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
        return [false, 'Please upload a manuscript file.'];
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

    $original = (string) ($file['name'] ?? 'manuscript.pdf');
    if ($original === '') {
        $original = 'manuscript.pdf';
    }

    $ext = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));
    if ($ext === '' && $mime === 'image/png') {
        $ext = 'png';
    }

    $allowedExts = ['pdf', 'doc', 'docx', 'png'];
    $allowedMimes = [
      'pdf' => ['application/pdf', 'application/x-pdf', 'application/acrobat'],
      'doc' => ['application/msword', 'application/vnd.ms-word', 'application/octet-stream'],
      'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
      'png' => ['image/png'],
    ];

    if (!in_array($ext, $allowedExts, true)) {
      return [false, 'Only PDF, DOCX, DOC, and PNG files are allowed.'];
    }

    if (!in_array($mime, $allowedMimes[$ext] ?? [], true) && $mime !== 'application/octet-stream') {
      return [false, 'Only PDF, DOCX, DOC, and PNG files are allowed.'];
    }

    return [true, '', $mime, $size, $tmp, $original, $ext];
}

function validate_image_upload(array $file, int $maxBytes): array
{
  if (!isset($file['error'])) {
    return [false, 'Please upload an image.'];
  }

  $err = (int) $file['error'];
  if ($err === UPLOAD_ERR_NO_FILE) {
    return [false, ''];
  }

  if ($err !== UPLOAD_ERR_OK) {
    return [false, 'Please upload an image.'];
  }

  $size = (int) ($file['size'] ?? 0);
  if ($size <= 0) {
    return [false, 'Uploaded image is empty.'];
  }

  if ($size > $maxBytes) {
    return [false, 'Image is too large.'];
  }

  $tmp = (string) ($file['tmp_name'] ?? '');
  if ($tmp === '' || !is_file($tmp)) {
    return [false, 'Upload failed.'];
  }

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = (string) $finfo->file($tmp);

  $ext = '';
  if ($mime === 'image/jpeg') {
    $ext = 'jpg';
  } elseif ($mime === 'image/png') {
    $ext = 'png';
  } elseif ($mime === 'image/gif') {
    $ext = 'gif';
  } elseif ($mime === 'image/webp') {
    $ext = 'webp';
  } else {
    return [false, 'Only JPG, PNG, GIF, and WebP images are allowed.'];
  }

  $original = (string) ($file['name'] ?? 'profile.' . $ext);
  if ($original === '') {
    $original = 'profile.' . $ext;
  }

  return [true, '', $mime, $size, $tmp, $original, $ext];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO) {
    $action = (string) ($_POST['action'] ?? '');

    
    if ($action === 'set_typing') {
        $subId = (int)($_POST['submission_id'] ?? 0);
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS chat_typing (submission_id INT, sender_type VARCHAR(50), last_typed DATETIME, PRIMARY KEY(submission_id, sender_type))");
            $stmt = $pdo->prepare("REPLACE INTO chat_typing (submission_id, sender_type, last_typed) VALUES (?, 'user', CURRENT_TIMESTAMP)");
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
        
        $pdo->prepare("UPDATE submission_messages SET is_read = 1 WHERE submission_id = ? AND sender_type = 'admin'")->execute([$subId]);
        
        $stmt = $pdo->prepare("SELECT sender_name, sender_type, message, created_at FROM submission_messages WHERE submission_id = ? ORDER BY created_at ASC, id ASC");
        $stmt->execute([$subId]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $isTyping = false;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS chat_typing (submission_id INT, sender_type VARCHAR(50), last_typed DATETIME, PRIMARY KEY(submission_id, sender_type))");
            $stmtTyping = $pdo->prepare("SELECT last_typed, CURRENT_TIMESTAMP as db_now FROM chat_typing WHERE submission_id = ? AND sender_type = 'admin'");
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
            $senderName = trim((string) ($user['name'] ?? 'Author'));
            $stmt = $pdo->prepare("INSERT INTO submission_messages (submission_id, sender_type, sender_name, message) VALUES (?, 'user', ?, ?)");
            $stmt->execute([$subId, $senderName, $msg]);
        }
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }

    csrf_validate();

  if ($action === 'update_profile') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $country = trim((string) ($_POST['country'] ?? ''));

    $title = trim((string) ($_POST['title'] ?? ''));
    $firstName = trim((string) ($_POST['first_name'] ?? ''));
    $middleName = trim((string) ($_POST['middle_name'] ?? ''));
    $lastName = trim((string) ($_POST['last_name'] ?? ''));

    $position = trim((string) ($_POST['position'] ?? ''));
    $institution = trim((string) ($_POST['institution'] ?? ''));
    $department = trim((string) ($_POST['department'] ?? ''));

    $gradeLevel = trim((string) ($_POST['grade_level'] ?? ''));
    $schoolName = trim((string) ($_POST['school_name'] ?? ''));
    $schoolEmail = trim((string) ($_POST['school_email'] ?? ''));
    $admissionNumber = trim((string) ($_POST['admission_number'] ?? ''));

    $city = trim((string) ($_POST['city'] ?? ''));
    $state = trim((string) ($_POST['state'] ?? ''));
    $postalCode = trim((string) ($_POST['postal_code'] ?? ''));

    $newPassword = (string) ($_POST['new_password'] ?? '');
    $newPassword2 = (string) ($_POST['new_password2'] ?? '');

    $nameParts = [$firstName];
    if ($middleName !== '') {
      $nameParts[] = $middleName;
    }
    $nameParts[] = $lastName;
    $name = trim(implode(' ', array_filter($nameParts, static function($v) { return $v !== ''; })));

    // Keep the form sticky on validation errors.
    $profile['username'] = $username;
    $profile['email'] = $email;
    $profile['phone'] = $phone;
    $profile['country'] = $country;
    $profile['title'] = $title;
    $profile['first_name'] = $firstName;
    $profile['middle_name'] = $middleName;
    $profile['last_name'] = $lastName;
    $profile['position'] = $position;
    $profile['institution'] = $institution;
    $profile['department'] = $department;
    $profile['grade_level'] = $gradeLevel;
    $profile['school_name'] = $schoolName;
    $profile['school_email'] = $schoolEmail;
    $profile['admission_number'] = $admissionNumber;
    $profile['city'] = $city;
    $profile['state'] = $state;
    $profile['postal_code'] = $postalCode;

    if ($username === '' || $email === '' || $phone === '' || $country === '' || $firstName === '' || $lastName === '' || $position === '' || $institution === '' || $gradeLevel === '' || $schoolName === '' || $city === '' || $state === '' || $postalCode === '') {
      $error = 'Please fill in all required fields.';
    } elseif (strlen($username) < 3 || strlen($username) > 64) {
      $error = 'Username must be between 3 and 64 characters.';
    } elseif (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{1,62}[a-zA-Z0-9]$/', $username)) {
      $error = 'Username may contain letters, numbers, dots, underscores, and hyphens (must start and end with a letter/number).';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $error = 'Please enter a valid email.';
    } elseif (!preg_match('/^\+?[0-9][0-9\s\-]{6,20}$/', $phone)) {
      $error = 'Please enter a valid phone number (e.g., +91XXXXXXXXXX).';
    } elseif ($schoolEmail !== '' && !filter_var($schoolEmail, FILTER_VALIDATE_EMAIL)) {
      $error = 'Please enter a valid school email (or leave it blank).';
    } elseif ($newPassword !== '' || $newPassword2 !== '') {
      if ($newPassword === '' || $newPassword2 === '') {
        $error = 'Please enter and confirm your new password.';
      } elseif (strlen($newPassword) < 8) {
        $error = 'Password must be at least 8 characters.';
      } elseif ($newPassword !== $newPassword2) {
        $error = 'Passwords do not match.';
      }
    }

    if ($error === '') {
      try {
        $userId = (int) $user['id'];

        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE email = ? AND id <> ? LIMIT 1');
        $stmt->execute([$email, $userId]);
        if ($stmt->fetch()) {
          $error = 'That email is already registered. Please use another.';
        }

        if ($error === '') {
          $stmt = $pdo->prepare('SELECT 1 FROM users WHERE username = ? AND id <> ? LIMIT 1');
          $stmt->execute([$username, $userId]);
          if ($stmt->fetch()) {
            $error = 'That username is already taken. Please choose another.';
          }
        }

        if ($error === '') {
          $params = [
            ':name' => $name,
            ':email' => $email,
            ':username' => $username,
            ':phone' => $phone,
            ':country' => $country,
            ':title' => $title !== '' ? $title : null,
            ':first_name' => $firstName,
            ':middle_name' => $middleName !== '' ? $middleName : null,
            ':last_name' => $lastName,
            ':position' => $position,
            ':institution' => $institution,
            ':department' => $department !== '' ? $department : null,
            ':grade_level' => $gradeLevel,
            ':school_name' => $schoolName,
            ':school_email' => $schoolEmail !== '' ? $schoolEmail : null,
            ':admission_number' => $admissionNumber !== '' ? $admissionNumber : null,
            ':city' => $city,
            ':state' => $state,
            ':postal_code' => $postalCode,
            ':id' => $userId,
          ];

          $sql = 'UPDATE users SET name = :name, email = :email, username = :username, phone = :phone, country = :country, title = :title, first_name = :first_name, middle_name = :middle_name, last_name = :last_name, position = :position, institution = :institution, department = :department, grade_level = :grade_level, school_name = :school_name, school_email = :school_email, admission_number = :admission_number, city = :city, state = :state, postal_code = :postal_code';

          if ($newPassword !== '') {
            $params[':password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
            $sql .= ', password_hash = :password_hash';
          }

          $sql .= ' WHERE id = :id';
          $stmt = $pdo->prepare($sql);
          $stmt->execute($params);

          $success = 'Account information updated successfully.';

          // Reload for display in the same request.
          $profile = load_user_profile($pdo, $userId, $profile);
        }
      } catch (Throwable $e) {
        $error = 'Could not update your account information. Please try again.';
      }
    }
  } elseif ($action === 'delete_submission') {
    $submissionId = (string) ($_POST['submission_id'] ?? '');
    if (!ctype_digit($submissionId)) {
      $error = 'Invalid submission.';
    } else {
      $id = (int) $submissionId;
      try {
        $stmt = $pdo->prepare('SELECT * FROM paper_submissions WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, (int)$user['id']]);
        $submission = $stmt->fetch();
        
        if (!is_array($submission)) {
          $error = 'Submission not found or permission denied.';
        } elseif (!in_array((string)($submission['status'] ?? ''), ['rejected', 'needs_edits', 'submitted', 'under_review'], true)) {
          $error = 'Only submissions in early stages, rejected, or needing edits can be deleted.';
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
          
          $stmt = $pdo->prepare('DELETE FROM paper_submission_attachments WHERE paper_submission_id = ?');
          $stmt->execute([$id]);
          
          $stmt = $pdo->prepare('DELETE FROM submission_messages WHERE submission_id = ?');
          $stmt->execute([$id]);
          
          $stmt = $pdo->prepare('DELETE FROM chat_typing WHERE submission_id = ?');
          $stmt->execute([$id]);
          
          $stmt = $pdo->prepare('DELETE FROM paper_submission_versions WHERE paper_submission_id = ?');
          $stmt->execute([$id]);
          
          $stmt = $pdo->prepare('DELETE FROM paper_submissions WHERE id = ? AND user_id = ?');
          $stmt->execute([$id, (int)$user['id']]);
          
          $pdo->commit();
          $success = 'Submission permanently deleted.';
        }
      } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
          $pdo->rollBack();
        }
        $error = 'Could not delete submission. Please try again. Error: ' . $e->getMessage();
      }
    }
  } elseif ($action === 'new_submission') {
        $allowedPaperTypes = ['Research Paper', 'Case Study', 'Survey Paper', 'Ex Version Paper'];
        $allowedJournals = [
          'Computer Science & Engineering',
          'Mathematics & Mathematical Sciences',
          'Applied Physics',
          'Applied Chemistry',
          'Civil Engineering',
          'Mechanical Engineering',
          'Business, Management & Accounting',
          'Electronics & Communication Engineering',
          'Humanities & Social Science',
          'Advance Research (General)',
          'Biology & Pharmacy',
          'Environmental Science',
        ];

        $guidelinesConfirmed = isset($_POST['guidelines_confirm']) && (string) $_POST['guidelines_confirm'] === '1';
        $guidelinesConfirmed = isset($_POST['guidelines_confirm']) && (string) $_POST['guidelines_confirm'] === '1';
        $journal = trim((string) ($_POST['journal'] ?? ''));
        $title = trim((string) ($_POST['title'] ?? ''));
        $abstract = trim((string) ($_POST['abstract'] ?? ''));
        $keywords = trim((string) ($_POST['keywords'] ?? ''));
        $projectStory = trim((string) ($_POST['project_story'] ?? ''));
        $authors = trim((string) ($_POST['authors'] ?? ''));
        $age = trim((string) ($_POST['author_age'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $phoneCode = trim((string) ($_POST['phone_code'] ?? ''));
        $phoneNumber = trim((string) ($_POST['phone_number'] ?? ''));
        $country = trim((string) ($_POST['country'] ?? ''));
        $gradeLevel = trim((string) ($_POST['grade_level'] ?? ''));
        $schoolName = trim((string) ($_POST['school_name'] ?? ''));
        $schoolEmail = trim((string) ($_POST['school_email'] ?? ''));
        $admissionNumber = trim((string) ($_POST['admission_number'] ?? ''));
        $authorBio = trim((string) ($_POST['author_bio'] ?? ''));
        $authorsPayloadRaw = trim((string) ($_POST['authors_payload'] ?? '[]'));
        $authorsData = json_decode($authorsPayloadRaw, true);
        if (!is_array($authorsData)) $authorsData = [];
        $howHeard = trim((string) ($_POST['how_heard'] ?? ''));
        $settingRaw = $_POST['setting'] ?? [];
        $setting = is_array($settingRaw) ? implode(', ', $settingRaw) : '';
        $agesRaw = $_POST['ages'] ?? [];
        $agesStr = is_array($agesRaw) ? implode(', ', $agesRaw) : '';
        $schoolTypeRaw = $_POST['school_type'] ?? [];
        $schoolTypeStr = is_array($schoolTypeRaw) ? implode(', ', $schoolTypeRaw) : '';
        $literatureTools = trim((string) ($_POST['literature_tools'] ?? ''));
        $softwareTools = trim((string) ($_POST['software_tools'] ?? ''));
        
        $preprintLink = trim((string) ($_POST['preprint_link'] ?? ''));
        $preprintServer = (isset($_POST['preprint_server']) && $_POST['preprint_server'] === 'No') ? 'No' : 'Yes';

        // Validate questionnaire checkboxes
        $authorConsent = isset($_POST['author_consent']) && (string)$_POST['author_consent'] === '1' ? 1 : 0;
        $correspAuthorResp = isset($_POST['corresp_author_resp']) && (string)$_POST['corresp_author_resp'] === '1' ? 1 : 0;
        $ageEligibility = isset($_POST['age_eligibility']) && (string)$_POST['age_eligibility'] === '1' ? 1 : 0;
        $permissionSupervision = isset($_POST['permission_supervision']) && (string)$_POST['permission_supervision'] === '1' ? 1 : 0;
        $originality = isset($_POST['originality']) && (string)$_POST['originality'] === '1' ? 1 : 0;
        $concurrentSubmission = isset($_POST['concurrent_submission']) && (string)$_POST['concurrent_submission'] === '1' ? 1 : 0;
        $ethicalCompliance = isset($_POST['ethical_compliance']) && (string)$_POST['ethical_compliance'] === '1' ? 1 : 0;
        $aiPolicy = isset($_POST['ai_policy']) && (string)$_POST['ai_policy'] === '1' ? 1 : 0;
        $formattingGuidelines = isset($_POST['formatting_guidelines']) && (string)$_POST['formatting_guidelines'] === '1' ? 1 : 0;
        $publicationAgreement = isset($_POST['publication_agreement']) && (string)$_POST['publication_agreement'] === '1' ? 1 : 0;

        if (
          !$guidelinesConfirmed || $journal === '' || $title === '' || $abstract === '' || $keywords === '' ||
          $authors === '' || $howHeard === '' ||
          $setting === '' || $agesStr === '' || $schoolTypeStr === '' || $literatureTools === '' || $softwareTools === ''
        ) {
          $error = 'Please complete all required submission fields and questionnaire sections.';
        } elseif (!$authorConsent || !$correspAuthorResp || !$ageEligibility || !$permissionSupervision ||
                  !$originality || !$concurrentSubmission || !$ethicalCompliance || !$aiPolicy ||
                  !$formattingGuidelines || !$publicationAgreement) {
          $error = 'You must confirm all required agreements to proceed.';
        } elseif (!in_array($journal, $allowedJournals, true)) {
          $error = 'Please choose a valid journal.';
        } elseif (!preg_match('/^(1[2-9]|20)$/', $age) || (int) $age > 20) {
          $error = 'You must be between 12 and 20 years old to submit to this journal.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
          $error = 'Please enter a valid personal email address.';
        } elseif (!preg_match('/^\+?[0-9]{1,4}$/', $phoneCode)) {
          $error = 'Please enter a valid phone country code.';
        } elseif (!preg_match('/^[0-9][0-9\s\-()]{5,24}$/', $phoneNumber)) {
          $error = 'Please enter a valid phone number.';
        } else {
          $upload = $_FILES['manuscripts'] ?? null;
          if (!is_array($upload) || !isset($upload['name']) || !is_array($upload['name']) || count($upload['name']) === 0 || $upload['name'][0] === '') {
            $error = 'Please upload at least one manuscript file.';
          } else {
            $fileCount = count($upload['name']);
            $validatedFiles = [];
            for ($i = 0; $i < $fileCount; $i++) {
              if ($upload['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
              $singleFile = [
                'name' => $upload['name'][$i],
                'type' => $upload['type'][$i],
                'tmp_name' => $upload['tmp_name'][$i],
                'error' => $upload['error'][$i],
                'size' => $upload['size'][$i],
              ];
              [$ok, $uploadError, $mime, $size, $tmp, $original, $uploadExt] = validate_pdf_upload($singleFile, 25 * 1024 * 1024);
              if (!$ok) {
                $error = 'File ' . ($i+1) . ': ' . $uploadError;
                break;
              }
              $itemType = trim((string) ($_POST['attachment_item_types'][$i] ?? 'Manuscript'));
              $itemDesc = trim((string) ($_POST['attachment_descriptions'][$i] ?? ''));
              if ($uploadExt === 'pdf' && strcasecmp($itemType, 'Supplementary File') !== 0) {
                $error = 'File ' . ($i+1) . ': PDF uploads can only be submitted as supplementary content, not as the manuscript file.';
                break;
              }
              $validatedFiles[] = [
                 'mime' => $mime, 'size' => $size, 'tmp' => $tmp, 'original' => $original, 'ext' => $uploadExt,
                 'type' => $itemType, 'desc' => $itemDesc
              ];
            }

            file_put_contents('gysj_debug.txt', 'Error: ' . $error . PHP_EOL, FILE_APPEND);
            if ($error === '') {
              if (count($validatedFiles) === 0) {
                 $error = 'No valid files were uploaded.';
              } else {
                try {
                  $slug = generate_unique_submission_slug($pdo, $title);
  
                  $uploadDir = __DIR__ . '/uploads/submissions';
                  if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                  }
  
                  $version = 1;
                  
                  // Move all files
                  foreach ($validatedFiles as &$vf) {
                    $filename = $slug . '-v' . $version . '-' . bin2hex(random_bytes(8)) . '.' . $vf['ext'];
                    $vf['relativePath'] = 'uploads/submissions/' . $filename;
                    $destination = $uploadDir . '/' . $filename;
                    if (!move_uploaded_file($vf['tmp'], $destination)) {
                      throw new RuntimeException('Failed to save file: ' . $vf['original']);
                    }
                  }
                  unset($vf);
                  
                  // First file becomes the backward-compatible "main" manuscript
                  $firstFile = $validatedFiles[0];
                  $relativePath = $firstFile['relativePath'];
                  $original = $firstFile['original'];
                  $mime = $firstFile['mime'];
                  $size = $firstFile['size'];

                $phone = trim($phoneCode . ' ' . $phoneNumber);
                $submissionDetails = [
                  'Journal' => $journal,
                  'Title' => $title,
                  'Abstract' => $abstract,
                  'Keywords' => $keywords,
                  'Project story' => $projectStory,
                  'Authors' => $authors,
                  'Author age' => $age,
                  'Personal email' => $email,
                  'Phone code' => $phoneCode,
                  'Phone number' => $phoneNumber,
                  'Phone' => $phone,
                  'Country' => $country,
                  'Grade level' => $gradeLevel,
                  'School name' => $schoolName,
                  'School email' => $schoolEmail,
                  'Admission number' => $admissionNumber,
                  'Author bio' => $authorBio,
                  'Guidelines confirmed' => $guidelinesConfirmed ? 'Yes' : 'No',
                  'Mentorship confirmation' => $permissionSupervision ? 'Yes' : 'No',
                  'Corresponding author responsibilities' => $correspAuthorResp ? 'Yes' : 'No',
                  'Age eligibility' => $ageEligibility ? 'Yes' : 'No',
                  'Permission to publish' => $authorConsent ? 'Yes' : 'No',
                  'Ethical approval' => $ethicalCompliance ? 'Yes' : 'No',
                  'No duplicate submission' => $concurrentSubmission ? 'Yes' : 'No',
                  'Original work' => $originality ? 'Yes' : 'No',
                  'AI policy' => $aiPolicy ? 'Yes' : 'No',
                  'Formatting guidelines' => $formattingGuidelines ? 'Yes' : 'No',
                  'Publication agreement' => $publicationAgreement ? 'Yes' : 'No',
                  'Preprint server' => $preprintServer,
                  'Preprint link' => $preprintLink,
                  'How heard about GYSJ' => $howHeard,
                  'Research setting' => $setting,
                  'Student ages' => $agesStr,
                  'School type' => $schoolTypeStr,
                  'Literature tools' => $literatureTools,
                  'Software tools' => $softwareTools,
                  'Authors JSON' => $authorsData,
                ];
                $metadata = json_encode($submissionDetails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!is_string($metadata) || $metadata === '') {
                  $metadata = trim(implode(', ', array_filter([
                    'Journal: ' . $journal,
                    'Title: ' . $title,
                    'Abstract: ' . $abstract,
                    'Keywords: ' . $keywords,
                    'Project story: ' . $projectStory,
                    'Authors: ' . $authors,
                    'Author age: ' . $age,
                    'Personal email: ' . $email,
                    'Phone code: ' . $phoneCode,
                    'Phone number: ' . $phoneNumber,
                    'Phone: ' . $phone,
                    'Country: ' . $country,
                    'Grade level: ' . $gradeLevel,
                    'School name: ' . $schoolName,
                    'School email: ' . $schoolEmail,
                    'Admission number: ' . $admissionNumber,
                    'Author bio: ' . $authorBio,
                    'Guidelines confirmed: ' . ($guidelinesConfirmed ? 'Yes' : 'No'),
                    'Preprint server: ' . $preprintServer,
                    'Preprint link: ' . $preprintLink,
                  ], static function (string $value): bool {
                    return trim($value) !== '';
                  })));
                }

                $hasSubmissionDetailsColumn = paper_submissions_has_columns($pdo, ['submission_details']);
                $hasCategoryColumn = paper_submissions_has_columns($pdo, ['category']);

                if ($hasSubmissionDetailsColumn) {
                  $cols = 'user_id, slug, title, authors, abstract, author_bio, submission_details, keywords, manuscript_path, manuscript_original_name, manuscript_mime, manuscript_size, version, status, author_consent, corresp_author_resp, age_eligibility, permission_supervision, originality, concurrent_submission, ethical_compliance, ai_policy, formatting_guidelines, publication_agreement';
                  $placeholders = '?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?';
                  $params = [
                    (int) $user['id'], $slug, $title, $authors, $abstract, $authorBio,
                    $metadata !== '' ? $metadata : null,
                    $keywords !== '' ? $keywords : null,
                    $relativePath, $original, $mime, (int) $size, $version, 'submitted',
                    $authorConsent, $correspAuthorResp, $ageEligibility, $permissionSupervision, $originality, $concurrentSubmission, $ethicalCompliance, $aiPolicy, $formattingGuidelines, $publicationAgreement
                  ];

                  if ($hasCategoryColumn) {
                    $cols .= ', category';
                    $placeholders .= ', ?';
                    $params[] = $journal;
                  }

                  $stmt = $pdo->prepare("INSERT INTO paper_submissions ($cols) VALUES ($placeholders)");
                  $stmt->execute($params);
                } else {
                  $legacyBio = $authorBio;
                  if ($metadata !== '') {
                    $legacyBio .= "\n\nSubmission details:\n" . $metadata;
                  }

                  $cols = 'user_id, slug, title, authors, abstract, author_bio, keywords, manuscript_path, manuscript_original_name, manuscript_mime, manuscript_size, version, status, author_consent, corresp_author_resp, age_eligibility, permission_supervision, originality, concurrent_submission, ethical_compliance, ai_policy, formatting_guidelines, publication_agreement';
                  $placeholders = '?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?';
                  $params = [
                    (int) $user['id'], $slug, $title, $authors, $abstract, $legacyBio,
                    $keywords !== '' ? $keywords : null,
                    $relativePath, $original, $mime, (int) $size, $version, 'submitted',
                    $authorConsent, $correspAuthorResp, $ageEligibility, $permissionSupervision, $originality, $concurrentSubmission, $ethicalCompliance, $aiPolicy, $formattingGuidelines, $publicationAgreement
                  ];

                  if ($hasCategoryColumn) {
                    $cols .= ', category';
                    $placeholders .= ', ?';
                    $params[] = $journal;
                  }

                  $stmt = $pdo->prepare("INSERT INTO paper_submissions ($cols) VALUES ($placeholders)");
                  $stmt->execute($params);
                }

                $insertedId = (int) $pdo->lastInsertId();
                
                if ($insertedId > 0) {
                  $pdo->exec("
                    CREATE TABLE IF NOT EXISTS paper_submission_attachments (
                      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                      paper_submission_id INT UNSIGNED NOT NULL,
                      version_number INT UNSIGNED NOT NULL DEFAULT 1,
                      category VARCHAR(100) NOT NULL DEFAULT 'Manuscript',
                      description TEXT,
                      file_path VARCHAR(1024) NOT NULL,
                      original_name VARCHAR(255) NOT NULL,
                      mime_type VARCHAR(100) NOT NULL,
                      file_size INT UNSIGNED NOT NULL,
                      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                      PRIMARY KEY (id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                  ");

                  $expectedCols = [
                      "version_number INT UNSIGNED NOT NULL DEFAULT 1",
                      "category VARCHAR(100) NOT NULL DEFAULT 'Manuscript'",
                      "description TEXT",
                      "original_name VARCHAR(255) NOT NULL DEFAULT 'unknown'",
                      "mime_type VARCHAR(100) NOT NULL DEFAULT 'application/octet-stream'",
                      "file_size INT UNSIGNED NOT NULL DEFAULT 0",
                      "file_path VARCHAR(1024) NOT NULL DEFAULT ''"
                  ];
                  foreach ($expectedCols as $colDef) {
                      try {
                          $pdo->exec("ALTER TABLE paper_submission_attachments ADD COLUMN $colDef");
                      } catch (Throwable $e) {}
                  }

                  $attachStmt = $pdo->prepare('INSERT INTO paper_submission_attachments (paper_submission_id, version_number, category, description, file_path, original_name, mime_type, file_size) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                  foreach ($validatedFiles as $vf) {
                    $attachStmt->execute([
                      $insertedId,
                      $version,
                      $vf['type'],
                      $vf['desc'],
                      $vf['relativePath'],
                      $vf['original'],
                      $vf['mime'],
                      $vf['size']
                    ]);
                  }
                }

                $hasTrackingColumns = gysj_table_has_columns($pdo, 'paper_submissions', ['tracking_id', 'tracking_country3', 'tracking_year', 'tracking_seq']);
                if ($hasTrackingColumns && $insertedId > 0) {
                  $trackingYear = (int) (new DateTimeImmutable('now'))->format('Y');
                  $trackingCountry3 = gysj_country_to_country3($country);
                  $trackingStmt = $pdo->prepare('UPDATE paper_submissions SET tracking_id = ?, tracking_country3 = ?, tracking_year = ?, tracking_seq = ? WHERE id = ?');

                  $trackingAssigned = false;
                  $trackingAttempts = 0;
                  while (!$trackingAssigned && $trackingAttempts < 5) {
                    $trackingAttempts++;
                    $trackingSeq = gysj_next_tracking_seq($pdo, $trackingYear);
                    $trackingId = gysj_format_tracking_id($trackingCountry3, $trackingYear, $trackingSeq);

                    try {
                      $trackingStmt->execute([$trackingId, $trackingCountry3, $trackingYear, $trackingSeq, $insertedId]);
                      $trackingAssigned = true;
                    } catch (Throwable $e) {
                      $message = strtolower((string) $e->getMessage());
                      $code = strtolower((string) $e->getCode());
                      $duplicate = (strpos($message, 'duplicate') !== false) || ($code === '23000');
                      if (!$duplicate) {
                        break;
                      }
                    }
                  }
                }

                $success = 'Submission uploaded successfully.';
              } catch (Throwable $e) {
                $error = 'Could not submit your paper. Please try again. Error: ' . $e->getMessage();
              }
            }
          }
        }
      }
    } elseif ($action === 'upload_revision') {
        $submissionId = (string) ($_POST['submission_id'] ?? '');
        if (!ctype_digit($submissionId)) {
            $error = 'Invalid submission.';
        } else {
            $upload = $_FILES['manuscripts'] ?? null;
            if (!is_array($upload) || !isset($upload['name']) || !is_array($upload['name']) || count($upload['name']) === 0 || $upload['name'][0] === '') {
              $error = 'Please upload at least one manuscript file.';
            } else {
                $fileCount = count($upload['name']);
                $validatedFiles = [];
                for ($i = 0; $i < $fileCount; $i++) {
                  if ($upload['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                  $singleFile = [
                    'name' => $upload['name'][$i],
                    'type' => $upload['type'][$i],
                    'tmp_name' => $upload['tmp_name'][$i],
                    'error' => $upload['error'][$i],
                    'size' => $upload['size'][$i],
                  ];
                  [$ok, $uploadError, $mime, $size, $tmp, $original, $uploadExt] = validate_pdf_upload($singleFile, 25 * 1024 * 1024);
                  if (!$ok) {
                    $error = 'File ' . ($i+1) . ': ' . $uploadError;
                    break;
                  }
                  $itemType = trim((string) ($_POST['attachment_item_types'][$i] ?? 'Manuscript'));
                  $itemDesc = trim((string) ($_POST['attachment_descriptions'][$i] ?? ''));
                  if ($uploadExt === 'pdf' && strcasecmp($itemType, 'Supplementary File') !== 0) {
                    $error = 'File ' . ($i+1) . ': PDF uploads can only be submitted as supplementary content, not as the manuscript file.';
                    break;
                  }
                  $validatedFiles[] = [
                     'mime' => $mime, 'size' => $size, 'tmp' => $tmp, 'original' => $original, 'ext' => $uploadExt,
                     'type' => $itemType, 'desc' => $itemDesc
                  ];
                }

                if ($error === '') {
                  if (count($validatedFiles) === 0) {
                     $error = 'No valid files were uploaded.';
                  } else {
                    try {
                        $stmt = $pdo->prepare('SELECT * FROM paper_submissions WHERE id = ? AND user_id = ? LIMIT 1');
                        $stmt->execute([(int) $submissionId, (int) $user['id']]);
                        $row = $stmt->fetch();
                        if (!is_array($row)) {
                            $error = 'Submission not found.';
                        } elseif ((string) ($row['status'] ?? '') !== 'needs_edits') {
                            $error = 'This submission is not requesting edits.';
                        } else {
                            $slug = (string) $row['slug'];
                            $version = (int) $row['version'];
                            $version = max(1, $version + 1);

                            $uploadDir = __DIR__ . '/uploads/submissions';
                            if (!is_dir($uploadDir)) {
                                mkdir($uploadDir, 0755, true);
                            }

                            // Move all files
                            foreach ($validatedFiles as &$vf) {
                              $filename = $slug . '-v' . $version . '-' . bin2hex(random_bytes(8)) . '.' . $vf['ext'];
                              $vf['relativePath'] = 'uploads/submissions/' . $filename;
                              $destination = $uploadDir . '/' . $filename;
                              if (!move_uploaded_file($vf['tmp'], $destination)) {
                                throw new RuntimeException('Failed to save file: ' . $vf['original']);
                              }
                            }
                            unset($vf);
                            
                            // First file becomes the backward-compatible "main" manuscript
                            $firstFile = $validatedFiles[0];
                            $relativePath = $firstFile['relativePath'];
                            $original = $firstFile['original'];
                            $mime = $firstFile['mime'];
                            $size = $firstFile['size'];

                            paper_archive_submission_version($pdo, $row);

                            $stmt = $pdo->prepare('UPDATE paper_submissions SET version = ?, manuscript_path = ?, manuscript_original_name = ?, manuscript_mime = ?, manuscript_size = ?, status = ?, reviewed_by = NULL, reviewed_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?');
                            $stmt->execute([
                                $version,
                                $relativePath,
                                $original,
                                $mime,
                                (int) $size,
                                'submitted',
                                (int) $submissionId,
                                (int) $user['id'],
                            ]);

                            $attachStmt = $pdo->prepare('INSERT INTO paper_submission_attachments (paper_submission_id, version_number, category, description, file_path, original_name, mime_type, file_size) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                            foreach ($validatedFiles as $vf) {
                                $attachStmt->execute([
                                (int) $submissionId,
                                $version,
                                $vf['type'],
                                $vf['desc'],
                                $vf['relativePath'],
                                $vf['original'],
                                $vf['mime'],
                                $vf['size']
                              ]);
                            }

                            $success = 'Revision uploaded successfully.';
                        }
                    } catch (Throwable $e) {
                        $error = 'Could not upload revision. Please try again.';
                    }
                  }
                }
            }
        }
    }
}

$submissions = [];
if ($pdo instanceof PDO) {
    try {
  $stmt = $pdo->prepare('SELECT * FROM paper_submissions WHERE user_id = ? ORDER BY created_at DESC');
    $stmt->execute([(int) $user['id']]);
        $rows = $stmt->fetchAll();
        if (is_array($rows)) {
            $submissions = $rows;
        }
    } catch (Throwable $e) {
        $submissions = [];
    }
}

$submissionVersionHistory = [];
if ($pdo instanceof PDO && !empty($submissions)) {
  $submissionIds = [];
  foreach ($submissions as $submissionRow) {
    $submissionId = (int) ($submissionRow['id'] ?? 0);
    if ($submissionId > 0) {
      $submissionIds[$submissionId] = $submissionId;
    }
  }

  if (!empty($submissionIds)) {
    try {
      $placeholders = implode(',', array_fill(0, count($submissionIds), '?'));
      $sql = 'SELECT id, paper_submission_id AS submission_id, version_number, title, authors, category, abstract, author_bio, submission_details, manuscript_path, manuscript_original_name, manuscript_mime, manuscript_size, status, admin_comment, reviewed_at, updated_at, created_at, archived_at FROM paper_submission_versions WHERE paper_submission_id IN (' . $placeholders . ') ORDER BY paper_submission_id ASC, version_number DESC, id DESC';
      $stmt = $pdo->prepare($sql);
      $stmt->execute(array_values($submissionIds));
      $rows = $stmt->fetchAll();
      if (is_array($rows)) {
        foreach ($rows as $row) {
          if (!is_array($row)) {
            continue;
          }

          $paperSubmissionId = (int) ($row['submission_id'] ?? 0);
          $versionNumber = (int) ($row['version_number'] ?? 0);
          if ($paperSubmissionId <= 0 || $versionNumber <= 0) {
            continue;
          }

          if (!isset($submissionVersionHistory[$paperSubmissionId]) || !is_array($submissionVersionHistory[$paperSubmissionId])) {
            $submissionVersionHistory[$paperSubmissionId] = [];
          }

          $submissionVersionHistory[$paperSubmissionId][] = user_normalize_submission_record($row);
        }
      }
    } catch (Throwable $e) {
      $submissionVersionHistory = [];
    }
  }
}

$unreadUserCounts = [];
if ($pdo instanceof PDO && !empty($submissionIds)) {
  try {
    $placeholders = implode(',', array_fill(0, count($submissionIds), '?'));
    $sql = "SELECT submission_id, COUNT(*) as count FROM submission_messages WHERE submission_id IN ($placeholders) AND sender_type = 'admin' AND is_read = 0 GROUP BY submission_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($submissionIds));
    $rows = $stmt->fetchAll();
    if (is_array($rows)) {
      foreach ($rows as $row) {
        $unreadUserCounts[(int)$row['submission_id']] = (int)$row['count'];
      }
    }
  } catch (Throwable $e) {}
}

$unreadUserCounts = [];
if ($pdo instanceof PDO && !empty($submissionIds)) {
  try {
    $placeholders = implode(',', array_fill(0, count($submissionIds), '?'));
    $sql = "SELECT submission_id, COUNT(*) as count FROM submission_messages WHERE submission_id IN ($placeholders) AND sender_type = 'admin' AND is_read = 0 GROUP BY submission_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($submissionIds));
    $rows = $stmt->fetchAll();
    if (is_array($rows)) {
      foreach ($rows as $row) {
        $unreadUserCounts[(int)$row['submission_id']] = (int)$row['count'];
      }
    }
  } catch (Throwable $e) {}
}

$submissionAttachments = [];
if ($pdo instanceof PDO && !empty($submissionIds)) {
  try {
    $placeholders = implode(',', array_fill(0, count($submissionIds), '?'));
    $sql = 'SELECT id, paper_submission_id, version_number, category, description, file_path, original_name, mime_type, file_size, created_at FROM paper_submission_attachments WHERE paper_submission_id IN (' . $placeholders . ') ORDER BY paper_submission_id ASC, version_number DESC, id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($submissionIds));
    $rows = $stmt->fetchAll();
    if (is_array($rows)) {
      foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $pid = (int) ($row['paper_submission_id'] ?? 0);
        $ver = (int) ($row['version_number'] ?? 0);
        if ($pid <= 0 || $ver <= 0) continue;
        if (!isset($submissionAttachments[$pid])) {
          $submissionAttachments[$pid] = [];
        }
        if (!isset($submissionAttachments[$pid][$ver])) {
          $submissionAttachments[$pid][$ver] = [];
        }
        $submissionAttachments[$pid][$ver][] = $row;
      }
    }
  } catch (Throwable $e) {
    $submissionAttachments = [];
  }
}

$viewParam = $_GET['view'] ?? 'all';
$viewRaw = is_string($viewParam) ? trim($viewParam) : 'all';
if ($viewRaw === '' || $viewRaw === 'overview') {
  $viewRaw = 'all';
}
if ($viewRaw === 'sent_back') {
  $viewRaw = 'needs_revision';
}
if ($viewRaw === 'revision_processing' || $viewRaw === 'revisions') {
  $viewRaw = 'processing';
}
$view = $viewRaw;
$allowedViews = ['all', 'submit', 'processing', 'needs_revision', 'decisions', 'accepted', 'submitted', 'needs_edits', 'rejected'];
if (!in_array($view, $allowedViews, true)) {
  $view = 'all';
}

$sortParam = $_GET['sort'] ?? '';
$sort = is_string($sortParam) ? trim($sortParam) : '';
$dirParam = $_GET['dir'] ?? 'asc';
$dir = is_string($dirParam) ? strtolower(trim($dirParam)) : 'asc';
$allowedSort = ['title', 'began', 'status_date', 'status'];
if (!in_array($sort, $allowedSort, true)) {
  $sort = '';
}
if ($dir !== 'asc' && $dir !== 'desc') {
  $dir = 'asc';
}

function submission_began_ts(array $s): int
{
  $raw = (string) ($s['created_at'] ?? '');
  $ts = $raw !== '' ? strtotime($raw) : false;
  return $ts === false ? 0 : (int) $ts;
}

function submission_status_date(array $s): string
{
  $status = (string) ($s['status'] ?? '');
  if ($status === 'submitted') {
    $updated = trim((string) ($s['updated_at'] ?? ''));
    if ($updated !== '') {
      return $updated;
    }
    return trim((string) ($s['created_at'] ?? ''));
  }

  $reviewed = trim((string) ($s['reviewed_at'] ?? ''));
  if ($reviewed !== '') {
    return $reviewed;
  }

  $updated = trim((string) ($s['updated_at'] ?? ''));
  if ($updated !== '') {
    return $updated;
  }

  return trim((string) ($s['created_at'] ?? ''));
}

function submission_status_ts(array $s): int
{
  $raw = submission_status_date($s);
  $ts = $raw !== '' ? strtotime($raw) : false;
  return $ts === false ? 0 : (int) $ts;
}

function sort_submissions(array $items, string $sort, string $dir): array
{
  if ($sort === '' || count($items) < 2) {
    return $items;
  }

  usort($items, function (array $a, array $b) use ($sort, $dir): int {
    $mult = ($dir === 'desc') ? -1 : 1;

    if ($sort === 'title') {
      $av = strtolower((string) ($a['title'] ?? ''));
      $bv = strtolower((string) ($b['title'] ?? ''));
      $cmp = $av <=> $bv;
      if ($cmp !== 0) {
        return $mult * $cmp;
      }
    } elseif ($sort === 'began') {
      $cmp = submission_began_ts($a) <=> submission_began_ts($b);
      if ($cmp !== 0) {
        return $mult * $cmp;
      }
    } elseif ($sort === 'status_date') {
      $cmp = submission_status_ts($a) <=> submission_status_ts($b);
      if ($cmp !== 0) {
        return $mult * $cmp;
      }
    } elseif ($sort === 'status') {
      $av = (string) ($a['status'] ?? '');
      $bv = (string) ($b['status'] ?? '');
      $cmp = $av <=> $bv;
      if ($cmp !== 0) {
        return $mult * $cmp;
      }
    }

    $cmp = submission_began_ts($b) <=> submission_began_ts($a);
    if ($cmp !== 0) {
      return $cmp;
    }

    return (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0);
  });

  return $items;
}

function user_dashboard_url(array $params): string
{
  $base = 'user-dashboard.php';
  $clean = [];

  if (isset($params['view']) && is_string($params['view']) && $params['view'] !== '' && $params['view'] !== 'all') {
    $clean['view'] = $params['view'];
  }
  if (isset($params['sort']) && is_string($params['sort']) && $params['sort'] !== '') {
    $clean['sort'] = $params['sort'];
  }
  if (isset($params['dir']) && is_string($params['dir']) && $params['dir'] !== '') {
    $clean['dir'] = $params['dir'];
  }

  return $clean ? ($base . '?' . http_build_query($clean)) : $base;
}

function dashboard_sort_link(string $label, string $key, string $view, string $currentSort, string $currentDir): string
{
  $nextDir = 'asc';
  if ($currentSort === $key && $currentDir === 'asc') {
    $nextDir = 'desc';
  }

  $icon = 'fa-sort';
  if ($currentSort === $key) {
    $icon = ($currentDir === 'asc') ? 'fa-sort-up' : 'fa-sort-down';
  }

  $href = user_dashboard_url(['view' => $view, 'sort' => $key, 'dir' => $nextDir]);
  return '<a class="text-warning" href="' . e($href) . '">' . e($label) . ' <i class="fa ' . e($icon) . '" aria-hidden="true"></i></a>';
}

$sentBackCount = 0; // needs_edits + version 1
$needsRevisionCount = 0; // needs_edits + version > 1
$processingCount = 0; // submitted + version 1
$revisionProcessingCount = 0; // submitted + version > 1
$revisionsCount = 0; // any version > 1
$decisionCount = 0; // accepted/rejected

foreach ($submissions as $s) {
  $status = (string) ($s['status'] ?? '');
  $version = (int) ($s['version'] ?? 1);

  if ($version > 1 && $status !== 'accepted' && $status !== 'rejected') {
    $revisionsCount++;
  }

  if ($status === 'needs_edits') {
    if ($version > 1) {
      $needsRevisionCount++;
    } else {
      $sentBackCount++;
    }
  } elseif ($status === 'submitted') {
    if ($version > 1) {
      $revisionProcessingCount++;
    } else {
      $processingCount++;
    }
  } elseif ($status === 'accepted' || $status === 'rejected') {
    $decisionCount++;
  }
}

$processingTotal = $processingCount + $revisionProcessingCount;
$needsRevisionTotal = $sentBackCount + $needsRevisionCount;
$totalSubmissions = count($submissions);

function status_badge_class(string $status): string
{
    switch ($status) {
        case 'accepted':
            return 'success';
        case 'rejected':
            return 'danger';
        case 'needs_edits':
            return 'warning';
        default:
            return 'primary';
    }
}

function status_label(string $status): string
{
    switch ($status) {
        case 'needs_edits':
            return 'Needs edits';
        case 'submitted':
        case 'escalated':
            return 'Submitted';
        case 'accepted':
            return 'Accepted';
        case 'rejected':
            return 'Rejected';
        default:
            return ucfirst($status);
    }
}

function user_status_accent_class(string $status): string
{
    switch ($status) {
        case 'accepted':
            return 'review-status-accepted';
        case 'rejected':
            return 'review-status-rejected';
        case 'needs_edits':
            return 'review-status-needs_edits';
        default:
            return 'review-status-submitted';
    }
}

function user_review_status_date(array $record): string
{
    $reviewedAt = trim((string) ($record['reviewed_at'] ?? ''));
    if ($reviewedAt !== '') {
        return $reviewedAt;
    }

    $updatedAt = trim((string) ($record['updated_at'] ?? ''));
    if ($updatedAt !== '') {
        return $updatedAt;
    }

    $archivedAt = trim((string) ($record['archived_at'] ?? ''));
    if ($archivedAt !== '') {
        return $archivedAt;
    }

    return trim((string) ($record['created_at'] ?? ''));
}

function user_parse_submission_details(string $submissionDetails): array
{
    $details = [];
    $decoded = json_decode($submissionDetails, true);
    if (is_array($decoded)) {
        foreach ($decoded as $k => $v) {
            $details[strtolower(trim((string) $k))] = trim((string) $v);
        }
        return $details;
    }

    foreach (explode(',', $submissionDetails) as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }

        $segments = explode(':', $part, 2);
        if (count($segments) !== 2) {
            continue;
        }

        $label = trim($segments[0]);
        $value = trim($segments[1]);
        if ($label === '' || $value === '') {
            continue;
        }

        $details[strtolower($label)] = $value;
    }

    return $details;
}

function user_submission_detail_value(array $details, array $labels): string
{
    foreach ($labels as $label) {
        $key = strtolower(trim((string) $label));
        if ($key !== '' && isset($details[$key])) {
            return trim((string) $details[$key]);
        }
    }

    return '';
}

function user_submission_phone_parts(array $details): array
{
  $phone = user_submission_detail_value($details, ['phone']);
  if ($phone === '') {
    return ['', ''];
  }

  if (preg_match('/^(\+\d{1,4})\s*(.*)$/', $phone, $match) === 1) {
    return [trim((string) $match[1]), trim((string) ($match[2] ?? ''))];
  }

  return ['', $phone];
}

function user_split_legacy_submission_bio(array $submission): array
{
    $authorBio = trim((string) ($submission['author_bio'] ?? ''));
    $submissionDetails = trim((string) ($submission['submission_details'] ?? ''));
    if ($submissionDetails === '' || $submissionDetails === '{}' || $submissionDetails === '[]') {
        $submissionDetails = trim((string) ($submission['metadata'] ?? ''));
    }

    if ($submissionDetails === '' && $authorBio !== '') {
        $parts = preg_split('/\R+\s*Submission details:\s*\R+/i', $authorBio, 2);
        if (is_array($parts) && count($parts) === 2) {
            $authorBio = trim((string) $parts[0]);
            $submissionDetails = trim((string) $parts[1]);
        }
    }

    return [$authorBio, $submissionDetails];
}

function user_normalize_submission_record(array $submission): array
{
    [$authorBio, $submissionDetailsRaw] = user_split_legacy_submission_bio($submission);
    $details = user_parse_submission_details($submissionDetailsRaw);
    $journal = user_submission_detail_value($details, ['journal']);

    if ($journal === '') {
        $category = trim((string) ($submission['category'] ?? ''));
        if ($category !== '') {
            $parts = preg_split('/\s*\|\s*/', $category, 2);
            if (is_array($parts) && count($parts) === 2) {
                $journal = trim((string) $parts[1]);
            }
        }
    }

    return [
        'id' => (int) ($submission['id'] ?? 0),
        'version_number' => (int) ($submission['version_number'] ?? ($submission['version'] ?? 1)),
        'title' => trim((string) ($submission['title'] ?? '')),
        'authors' => trim((string) ($submission['authors'] ?? '')),
        'journal' => $journal,
        'abstract' => trim((string) ($submission['abstract'] ?? '')),
        'author_bio' => $authorBio,
        'details' => $details,
        'manuscript_path' => trim((string) ($submission['manuscript_path'] ?? '')),
        'manuscript_original_name' => trim((string) ($submission['manuscript_original_name'] ?? '')),
        'manuscript_mime' => trim((string) ($submission['manuscript_mime'] ?? '')),
        'manuscript_size' => (int) ($submission['manuscript_size'] ?? 0),
        'status' => trim((string) ($submission['status'] ?? 'submitted')),
        'admin_comment' => trim((string) ($submission['admin_comment'] ?? '')),
        'reviewed_at' => trim((string) ($submission['reviewed_at'] ?? '')),
        'updated_at' => trim((string) ($submission['updated_at'] ?? '')),
        'created_at' => trim((string) ($submission['created_at'] ?? '')),
        'archived_at' => trim((string) ($submission['archived_at'] ?? '')),
        'tracking_id' => gysj_tracking_id_from_row((array) $submission),
    ];
}

function user_excerpt_text(string $text, int $limit = 240): string
{
    $clean = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    if ($clean === '') {
      return '';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
      if (mb_strlen($clean) <= $limit) {
        return $clean;
      }

      return rtrim(mb_substr($clean, 0, max(0, $limit - 1))) . '…';
    }

    if (strlen($clean) <= $limit) {
      return $clean;
    }

    return rtrim(substr($clean, 0, max(0, $limit - 1))) . '…';
}

function render_submission_card(array $submission, array $history, string $submitterName, string $submitterEmail, string $mailto): string
{
    $record = user_normalize_submission_record($submission);
    $sid = (int) ($record['id'] ?? 0);
    $status = (string) ($record['status'] ?? 'submitted');
    $paperTitle = (string) ($record['title'] ?? 'Untitled submission');
    $began = trim((string) ($record['created_at'] ?? ''));
    $statusDate = user_review_status_date($record);
    $abstract = user_excerpt_text((string) ($record['abstract'] ?? ''), 240);
    $trackingId = trim((string) ($record['tracking_id'] ?? ''));

    ob_start();
    ?>
    <article class="submission-card">
      <div class="submission-top">
        <div class="submission-summary-head">
          <div class="submission-title">Title: <?php echo e($paperTitle); ?></div>
          <div class="submission-meta submission-meta-inline">
            <span>Tracking ID <?php echo e($trackingId !== '' ? $trackingId : 'Pending'); ?></span>
            <span>When it began <?php echo e($began !== '' ? $began : 'Unknown'); ?></span>
            <span>Updated <?php echo e($statusDate !== '' ? $statusDate : 'Unknown'); ?></span>
          </div>
        </div>
        <span class="review-status-pill <?php echo e(user_status_accent_class($status)); ?>"><?php echo e(status_label($status)); ?></span>
      </div>

      <div class="submission-abstract-block">
        <div class="submission-abstract-label">Abstract:</div>
        <div class="submission-abstract-text"><?php echo e($abstract !== '' ? $abstract : 'No abstract available.'); ?></div>
      </div>

      <div class="submission-actions">
        <a class="action-link action-link-compact" href="#" onclick="openSubmissionModal(<?php echo $sid; ?>); return false;" rel="noopener"><i class="fa fa-file-pdf-o" aria-hidden="true"></i> View Submission</a>
        <?php if ($status === 'needs_edits'): ?>
          <a class="action-link action-link-compact" href="edit-submission.php?id=<?php echo $sid; ?>"><i class="fa fa-pencil" aria-hidden="true"></i> Edit Submission</a>
        <?php else: ?>
          <a class="action-link action-link-compact action-link-disabled" href="#" onclick="return false;"><i class="fa fa-pencil" aria-hidden="true"></i> Edit Submission</a>
        <?php endif; ?>
        <a class="action-link action-link-compact" href="submission-letter.php?id=<?php echo $sid; ?>" target="_blank" rel="noopener"><i class="fa fa-envelope-o" aria-hidden="true"></i> View Letter</a>
        <?php $chatTitle = trim((string) ($record['title'] ?? '')) !== '' ? trim((string) ($record['title'])) : 'Untitled'; ?>
        <?php $unreadCnt = $unreadUserCounts[$sid] ?? 0; ?>
        <?php $chatLabel = $unreadCnt > 0 ? "Chat ($unreadCnt)" : "Chat"; ?>
        <a class="action-link action-link-compact" href="#" data-chat-id="<?php echo $sid; ?>" data-chat-title="<?php echo htmlspecialchars($chatTitle, ENT_QUOTES, 'UTF-8'); ?>" onclick="event.preventDefault(); openChatModal(this.dataset.chatId, this.dataset.chatTitle);" rel="noopener" style="color: #0284c7;"><i class="fa fa-comments" aria-hidden="true"></i> <?php echo e($chatLabel); ?></a>
        <a class="action-link action-link-compact" href="<?php echo e($mailto); ?>" target="_blank" rel="noopener"><i class="fa fa-paper-plane-o" aria-hidden="true"></i> Send Email or Note</a>
      </div>

      </div>
    </article>
    <?php

    return (string) ob_get_clean();
}


function render_letter_modal(array $submission): string
{
    $sid = (int) ($submission['id'] ?? 0);
    $title = (string) ($submission['title'] ?? '');
    $status = (string) ($submission['status'] ?? 'submitted');
    $version = (int) ($submission['version'] ?? 1);
    $comment = (string) ($submission['admin_comment'] ?? '');
    $reviewedAt = trim((string) ($submission['reviewed_at'] ?? ''));
    $updatedAt = trim((string) ($submission['updated_at'] ?? ''));
    $createdAt = trim((string) ($submission['created_at'] ?? ''));

    $letterDate = $reviewedAt !== '' ? $reviewedAt : ($updatedAt !== '' ? $updatedAt : $createdAt);
    if ($letterDate !== '') {
        try {
            $letterDate = (new DateTimeImmutable($letterDate))->format('M j, Y g:i A');
        } catch (Throwable $e) {
            // fallback if date parsing fails
        }
    }

    $statusLabel = 'Submitted';
    $statusColor = '#666';
    if ($status === 'needs_edits') {
        $statusLabel = 'Edits Requested';
        $statusColor = '#b45309';
    } elseif ($status === 'accepted') {
        $statusLabel = 'Accepted';
        $statusColor = '#15803d';
    } elseif ($status === 'rejected') {
        $statusLabel = 'Rejected';
        $statusColor = '#b91c1c';
    }

    ob_start();
    ?>
    <div class="gysj-modal" id="letter-modal-<?php echo $sid; ?>" aria-hidden="true">
      <div class="gysj-modal-backdrop" onclick="closeLetterModal(<?php echo $sid; ?>)"></div>
      <div class="gysj-modal-dialog">
        <div class="gysj-modal-content">
          <div class="gysj-modal-header">
            <h2 class="gysj-modal-title">Submission Letter</h2>
            <button class="gysj-modal-close" onclick="closeLetterModal(<?php echo $sid; ?>)" aria-label="Close">&times;</button>
          </div>
          <div class="gysj-modal-body">
            <div style="margin-bottom: 20px;">
              <h3 style="margin-bottom: 5px; font-size: 18px; color: #333;"><?php echo e($title); ?></h3>
              <div style="font-size: 13px; color: #777;">
                ID <?php echo $sid; ?> &bull; Version <?php echo $version; ?> &bull; 
                <span style="background-color: <?php echo $statusColor; ?>; color: #fff; padding: 2px 6px; border-radius: 0; font-size: 11px; text-transform: uppercase; font-weight: bold;"><?php echo $statusLabel; ?></span>
              </div>
              <div style="font-size: 13px; color: #777; margin-top: 4px;">Letter Date: <?php echo e($letterDate); ?></div>
            </div>
            
            <div class="content-block" style="background: #f9f9f9; padding: 15px; border-radius: 0; border: 1px solid #eee;">
              <?php if (trim($comment) === ''): ?>
                <div style="color: #666; font-style: italic;">No letter is available yet for this submission.</div>
              <?php else: ?>
                <div style="white-space: pre-wrap; color: #333; line-height: 1.6; font-size: 14px;"><?php echo e($comment); ?></div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

function render_submission_modal(array $submission, array $history, array $attachments, string $submitterName, string $submitterEmail): string
{
    $record = user_normalize_submission_record($submission);
    $sid = (int) ($record['id'] ?? 0);
    $details = is_array($record['details']) ? $record['details'] : [];
    $fileName = trim((string) ($record['manuscript_original_name'] ?? ''));
    if ($fileName === '') {
      $fileName = trim((string) ($record['manuscript_path'] ?? 'Attached manuscript'));
    }
    $mime = strtolower(trim((string) ($record['manuscript_mime'] ?? '')));
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $isPdf = ($mime === 'application/pdf') || ($ext === 'pdf');
    $isDocx = ($mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') || ($ext === 'docx');
    $mailto = 'mailto:admin@globalyouthsciencejournal.org?subject=' . rawurlencode('Regarding Submission #' . $sid);

    $hasAuthorsJson = !empty($details['authors json']) && is_array($details['authors json']);

    if (!$hasAuthorsJson && !empty($record['author_bio'])) {
        $bio = $record['author_bio'];
        if (preg_match('/Author \d+:/i', $bio)) {
            $chunks = preg_split('/Author \d+:/i', $bio);
            $parsedAuthors = [];
            foreach ($chunks as $chunk) {
                $chunk = trim($chunk);
                if (empty($chunk)) continue;
                $lines = explode("\n", $chunk);
                $author = ['name' => trim($lines[0])];
                for ($i = 1; $i < count($lines); $i++) {
                    $line = trim($lines[$i]);
                    $colonIdx = strpos($line, ':');
                    if ($colonIdx !== false) {
                        $k = strtolower(trim(substr($line, 0, $colonIdx)));
                        $v = trim(substr($line, $colonIdx + 1));
                        if (strpos($k, 'age') !== false) $author['age'] = $v;
                        elseif (strpos($k, 'personal email') !== false) $author['email'] = $v;
                        elseif (strpos($k, 'phone code') !== false) $author['phone_code'] = $v;
                        elseif (strpos($k, 'phone number') !== false) $author['phone_number'] = $v;
                        elseif (strpos($k, 'short author biography') !== false || strpos($k, 'biography') !== false) $author['bio'] = $v;
                        elseif (strpos($k, 'school name') !== false) $author['school_name'] = $v;
                        elseif (strpos($k, 'grade level') !== false) $author['grade_level'] = $v;
                        elseif (strpos($k, 'school email') !== false) $author['school_email'] = $v;
                        elseif (strpos($k, 'admission number') !== false) $author['admission_number'] = $v;
                        elseif (strpos($k, 'orcid') !== false) $author['orcid'] = $v;
                        elseif (strpos($k, 'google scholar') !== false) $author['scholar'] = $v;
                    }
                }
                $parsedAuthors[] = $author;
            }
            if (count($parsedAuthors) > 0) {
                $details['authors json'] = $parsedAuthors;
                $hasAuthorsJson = true;
            }
        }
    }

    ob_start();
    ?>
    <div class="gysj-modal" id="submission-modal-<?php echo $sid; ?>" aria-hidden="true">
      <div class="gysj-modal-backdrop" onclick="closeSubmissionModal(<?php echo $sid; ?>)"></div>
      <div class="gysj-modal-dialog" style="max-width: 900px;">
        <div class="gysj-modal-content" style="background: #f8fafc;">
          <div class="gysj-modal-header">
            <h2 class="gysj-modal-title">Submission Details</h2>
            <button class="gysj-modal-close" onclick="closeSubmissionModal(<?php echo $sid; ?>)" aria-label="Close">&times;</button>
          </div>
          <div class="gysj-modal-body">

            <?php if (($record['status'] ?? '') === 'needs_edits'): ?>
              <div class="sub-modal-section" style="background: #fffbeb; border: 1px solid #fcd34d; padding: 16px; margin-bottom: 24px;">
                <div style="font-weight: 600; color: #b45309; font-size: 16px; margin-bottom: 8px;">
                  <i class="fa fa-exclamation-triangle"></i> You need to resubmit this article.
                </div>
                <div style="font-size: 14px; color: #92400e; margin-bottom: 12px;">
                  Please review the editor's letter and make the requested changes.
                </div>
                <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                  <a class="dashboard-btn dashboard-btn-secondary" href="#" onclick="event.preventDefault(); openLetterModal(<?php echo $sid; ?>)" style="background: #fef3c7; border-color: #fde68a; color: #b45309;"><i class="fa fa-envelope-o"></i> View Letter</a>
                  <a class="dashboard-btn" href="edit-submission.php?id=<?php echo $sid; ?>" style="background: #b45309; color: #fff; border-color: #b45309;"><i class="fa fa-pencil"></i> Edit Submission</a>
                  <a class="dashboard-btn dashboard-btn-secondary" href="#" data-chat-id="<?php echo $sid; ?>" data-chat-title="<?php echo htmlspecialchars((string)($record['title'] ?? 'Untitled'), ENT_QUOTES, 'UTF-8'); ?>" onclick="event.preventDefault(); openChatModal(this.dataset.chatId, this.dataset.chatTitle);" style="background: #fef3c7; border-color: #fde68a; color: #b45309;"><i class="fa fa-comments"></i> Chat</a>
                </div>
              </div>
            <?php endif; ?>

            <!-- SECTION A: Core Manuscript Details -->
            <div class="sub-modal-section" style="background: transparent; border: none; box-shadow: none; margin-bottom: 32px;">
              <div class="sub-core-title"><?php echo e((string) ($record['title'] ?? 'Untitled Manuscript')); ?></div>
              <div class="sub-core-meta">
                <?php if (!empty($record['journal'])): ?>
                  <?php
                    $dJournal = $record['journal'];
                    if (strpos($dJournal, 'Journal of') === false && $dJournal !== 'Advance Research (General)') { $dJournal = 'Journal of Advance Research in ' . $dJournal; }
                    if ($dJournal === 'Advance Research (General)') { $dJournal = 'Journal of Advance Research (General)'; }
                  ?>
                  <span class="sub-pill"><i class="fa fa-book"></i> <?php echo e($dJournal); ?></span>
                <?php endif; ?>
                <?php if (user_submission_detail_value($details, ['country']) !== ''): ?>
                  <span class="sub-pill"><i class="fa fa-globe"></i> <?php echo e(user_submission_detail_value($details, ['country'])); ?></span>
                <?php endif; ?>
              </div>

              <div class="sub-label">Abstract</div>
              <div class="sub-text-block"><?php echo e((string) ($record['abstract'] ?? 'No abstract provided.')); ?></div>

              <?php if (user_submission_detail_value($details, ['project story', 'project_story']) !== ''): ?>
                <div class="sub-label">Project Story</div>
                <div class="sub-text-block"><?php echo e(user_submission_detail_value($details, ['project story', 'project_story'])); ?></div>
              <?php endif; ?>

              <?php if (user_submission_detail_value($details, ['keywords']) !== ''): ?>
                <div class="sub-label">Keywords</div>
                <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px;">
                  <?php foreach(explode(',', user_submission_detail_value($details, ['keywords'])) as $kw): ?>
                    <span style="background: #e2e8f0; color: #334155; padding: 4px 10px; border-radius: 0; font-size: 13px; font-weight: 500;"><?php echo e(trim($kw)); ?></span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>

            <!-- SECTION B: Author Roster -->
            <div class="sub-modal-section">
              <div class="sub-modal-section-header"><i class="fa fa-users"></i> Author Information</div>
              <div class="sub-modal-section-body">
                <?php if ($hasAuthorsJson): ?>
                  <div class="sub-author-grid">
                    <?php foreach ($details['authors json'] as $index => $author): ?>
                    <div class="sub-author-card <?php echo $index > 0 ? 'collapsed' : ''; ?>">
                      <div class="sub-author-header" style="cursor: pointer;" onclick="this.parentElement.classList.toggle('collapsed')">
                        <div><?php echo e($author['name'] ?? 'Unnamed Author'); ?></div>
                        <div>
                          <?php if ($index === 0): ?><span style="background: #dbeafe; color: #1e40af; font-size: 11px; padding: 2px 8px; border-radius: 0; margin-right: 8px;">Primary Author</span><?php endif; ?>
                          <i class="fa fa-chevron-down toggle-icon" style="transition: transform 0.2s; color: #64748b;"></i>
                        </div>
                      </div>
                      <div class="sub-author-body">
                        <?php if (!empty($author['email'])): ?><div class="sub-kv"><div class="sub-kv-label">Email</div><div class="sub-kv-value"><?php echo e($author['email']); ?></div></div><?php endif; ?>
                        <?php if (!empty($author['age'])): ?><div class="sub-kv"><div class="sub-kv-label">Age</div><div class="sub-kv-value"><?php echo e($author['age']); ?></div></div><?php endif; ?>
                        <?php if (!empty($author['grade_level'])): ?><div class="sub-kv"><div class="sub-kv-label">Grade</div><div class="sub-kv-value"><?php echo e($author['grade_level']); ?></div></div><?php endif; ?>
                        <?php if (!empty($author['school_name'])): ?><div class="sub-kv full-width"><div class="sub-kv-label">School</div><div class="sub-kv-value"><?php echo e($author['school_name']); ?></div></div><?php endif; ?>
                        <?php if (!empty($author['school_email'])): ?><div class="sub-kv"><div class="sub-kv-label">School Email</div><div class="sub-kv-value"><?php echo e($author['school_email']); ?></div></div><?php endif; ?>
                        <?php if (!empty($author['admission_number'])): ?><div class="sub-kv"><div class="sub-kv-label">Admission No</div><div class="sub-kv-value"><?php echo e($author['admission_number']); ?></div></div><?php endif; ?>
                        <?php if (!empty($author['phone_code']) || !empty($author['phone_number'])): ?><div class="sub-kv"><div class="sub-kv-label">Phone</div><div class="sub-kv-value"><?php echo e($author['phone_code'] ?? ''); ?> <?php echo e($author['phone_number'] ?? ''); ?></div></div><?php endif; ?>
                        <?php if (!empty($author['orcid']) && is_string($author['orcid'])): ?>
                            <?php 
                            $orcidVal = trim($author['orcid']); 
                            $orcidUrl = strpos($orcidVal, 'http') === 0 ? $orcidVal : 'https://orcid.org/' . ltrim($orcidVal, '/');
                            ?>
                            <div class="sub-kv"><div class="sub-kv-label">ORCID</div><div class="sub-kv-value"><a href="<?php echo e($orcidUrl); ?>" target="_blank" rel="noopener" style="color:#2563eb; text-decoration:underline;"><?php echo e($orcidVal); ?></a></div></div>
                        <?php endif; ?>
                        <?php if (!empty($author['scholar']) && is_string($author['scholar'])): ?>
                            <?php 
                            $scholarVal = trim($author['scholar']); 
                            $scholarUrl = strpos($scholarVal, 'http') === 0 ? $scholarVal : 'https://' . ltrim($scholarVal, '/');
                            ?>
                            <div class="sub-kv"><div class="sub-kv-label">Google Scholar</div><div class="sub-kv-value"><a href="<?php echo e($scholarUrl); ?>" target="_blank" rel="noopener" style="color:#2563eb; text-decoration:underline;"><?php echo e($scholarVal); ?></a></div></div>
                        <?php endif; ?>
                        <?php if (!empty($author['bio'])): ?>
                          <div class="sub-kv full-width">
                            <div class="sub-kv-label">Biography</div>
                            <div class="sub-kv-value" style="white-space: pre-wrap; font-size: 13px; color: #475569; margin-top: 4px; line-height: 1.5;"><?php echo e($author['bio']); ?></div>
                          </div>
                        <?php endif; ?>
                      </div>
                    </div>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <!-- Fallback for legacy papers without authors JSON -->
                  <div class="sub-kv" style="margin-bottom: 16px;">
                    <div class="sub-kv-label">Submitter</div>
                    <div class="sub-kv-value"><?php echo e($submitterName); ?> (<?php echo e($submitterEmail); ?>)</div>
                  </div>
                  <?php if (!empty($record['author_bio'])): ?>
                    <div class="sub-kv full-width">
                      <div class="sub-kv-label">Author Bibliography / Biography</div>
                      <div class="sub-kv-value" style="white-space: pre-wrap; font-size: 13px; color: #475569; margin-top: 4px; line-height: 1.5;"><?php echo e($record['author_bio']); ?></div>
                    </div>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>

            <!-- SECTION C: Attached Files -->
            <div class="sub-modal-section">
              <div class="sub-modal-section-header"><i class="fa fa-folder-open"></i> Attached Files</div>
              <div class="sub-modal-section-body" style="background: #f8fafc; display: flex; flex-direction: column; gap: 8px;">
                <?php if (empty($attachments)): ?>
                  <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 0; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                      <div style="font-weight: 600; color: #0f172a; font-size: 14px; margin-bottom: 2px;"><i class="fa fa-file-text-o" style="color: #64748b; margin-right: 6px;"></i> <?php echo e($fileName); ?></div>
                      <div style="font-size: 12px; color: #64748b;">Manuscript</div>
                    </div>
                    <div>
                      <?php if ($isPdf): ?>
                        <a class="dashboard-btn dashboard-btn-secondary" href="paper-file.php?id=<?php echo $sid; ?>">Read PDF</a>
                      <?php elseif ($isDocx): ?>
                        <a class="dashboard-btn dashboard-btn-secondary" href="paper-file.php?id=<?php echo $sid; ?>">Download DOCX</a>
                      <?php else: ?>
                        <a class="dashboard-btn dashboard-btn-secondary" href="paper-file.php?id=<?php echo $sid; ?>">Open File</a>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php else: ?>
                  <?php foreach ($attachments as $att): ?>
                    <?php
                      $attId = (int) ($att['id'] ?? 0);
                      $attName = trim((string) ($att['original_name'] ?? 'Attached file'));
                      $attCat = trim((string) ($att['category'] ?? 'Manuscript'));
                      $attExt = strtolower(pathinfo($attName, PATHINFO_EXTENSION));
                      $attMime = strtolower(trim((string) ($att['mime_type'] ?? '')));
                      $attIsPdf = ($attMime === 'application/pdf') || ($attExt === 'pdf');
                      $attIsDocx = ($attMime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') || ($attExt === 'docx');
                      $attSizeKb = max(1, (int) (($att['file_size'] ?? 0) / 1024));
                    ?>
                    <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 0; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center;">
                      <div>
                        <div style="font-weight: 600; color: #0f172a; font-size: 14px; margin-bottom: 2px;"><i class="fa fa-file-text-o" style="color: #64748b; margin-right: 6px;"></i> <?php echo e($attName); ?></div>
                        <div style="font-size: 12px; color: #64748b;"><?php echo e($attCat); ?> &bull; <?php echo e($attSizeKb); ?> KB</div>
                      </div>
                      <div>
                        <?php if ($attIsPdf): ?>
                          <a class="dashboard-btn dashboard-btn-secondary" href="paper-file.php?id=<?php echo $sid; ?>&attachment_id=<?php echo $attId; ?>">Read PDF</a>
                        <?php elseif ($attIsDocx): ?>
                          <a class="dashboard-btn dashboard-btn-secondary" href="paper-file.php?id=<?php echo $sid; ?>&attachment_id=<?php echo $attId; ?>">Download DOCX</a>
                        <?php else: ?>
                          <a class="dashboard-btn dashboard-btn-secondary" href="paper-file.php?id=<?php echo $sid; ?>&attachment_id=<?php echo $attId; ?>">Open File</a>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>

            <!-- SECTION D: Methods & Tools -->
            <?php 
              $litTools = user_submission_detail_value($details, ['literature tools']);
              $swTools = user_submission_detail_value($details, ['software tools']);
            ?>
            <?php if ($litTools !== '' || $swTools !== ''): ?>
            <div class="sub-modal-section">
              <div class="sub-modal-section-header"><i class="fa fa-wrench"></i> Methods & Tools</div>
              <div class="sub-modal-section-body">
                <?php if ($litTools !== ''): ?>
                  <div class="sub-label">Literature Tools Used</div>
                  <div class="sub-text-block"><?php echo e($litTools); ?></div>
                <?php endif; ?>
                <?php if ($swTools !== ''): ?>
                  <div class="sub-label">Software Tools Used</div>
                  <div class="sub-text-block" style="margin-bottom: 0;"><?php echo e($swTools); ?></div>
                <?php endif; ?>
              </div>
            </div>
            <?php endif; ?>

            <!-- SECTION E: Compliance & Settings -->
            <div class="sub-modal-section">
              <div class="sub-modal-section-header"><i class="fa fa-check-square-o"></i> Compliance & Settings</div>
              <div class="sub-modal-section-body">
                
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
                  <?php if (user_submission_detail_value($details, ['how heard about gysj', 'how_heard']) !== ''): ?>
                    <div class="sub-kv"><div class="sub-kv-label">How Heard</div><div class="sub-kv-value"><?php echo e(user_submission_detail_value($details, ['how heard about gysj', 'how_heard'])); ?></div></div>
                  <?php endif; ?>
                  <?php if (user_submission_detail_value($details, ['research setting', 'setting']) !== ''): ?>
                    <div class="sub-kv"><div class="sub-kv-label">Research Setting</div><div class="sub-kv-value"><?php echo e(user_submission_detail_value($details, ['research setting', 'setting'])); ?></div></div>
                  <?php endif; ?>
                  <?php if (user_submission_detail_value($details, ['student ages', 'ages']) !== ''): ?>
                    <div class="sub-kv"><div class="sub-kv-label">Student Ages</div><div class="sub-kv-value"><?php echo e(user_submission_detail_value($details, ['student ages', 'ages'])); ?></div></div>
                  <?php endif; ?>
                  <?php if (user_submission_detail_value($details, ['school type', 'school_type']) !== ''): ?>
                    <div class="sub-kv"><div class="sub-kv-label">School Type</div><div class="sub-kv-value"><?php echo e(user_submission_detail_value($details, ['school type', 'school_type'])); ?></div></div>
                  <?php endif; ?>
                </div>

                <div class="sub-label">Questionnaire Checklist</div>
                <div class="sub-checklist-grid">
                <?php
                  $questionnaireKeys = [
                    'Guidelines confirmed', 'Mentorship confirmation', 'Editorial Manager access', 'Corresponding author responsibilities',
                    'Not enrolled in university', 'Age requirement', 'Permission to publish', 'Publication timeline',
                    'Ethical approval', 'No duplicate submission', 'Original work', 'AI policy', 'Template reviewed', 'Breach of contract'
                  ];
                  foreach ($questionnaireKeys as $qKey) {
                    $qVal = strtolower(trim(user_submission_detail_value($details, [$qKey, str_replace(' ', '_', strtolower($qKey))])));
                    if (in_array($qVal, ['yes', 'i agree', 'true', '1'], true)) {
                      echo '<div class="sub-checklist-item"><i class="fa fa-check-circle"></i> ' . e($qKey) . '</div>';
                    } else {
                      echo '<div class="sub-checklist-item" style="color: #991b1b; background: #fef2f2; border-color: #fecaca;"><i class="fa fa-times-circle" style="color: #ef4444;"></i> ' . e($qKey) . '</div>';
                    }
                  }
                ?>
                </div>
              </div>
            </div>

            <!-- SECTION F: Preprint Server -->
            <div class="sub-modal-section" style="background: #fff; border-color: #cbd5e1;">
              <div class="sub-modal-section-header" style="background: #f1f5f9; border-bottom: 1px solid #cbd5e1;"><i class="fa fa-link"></i> Preprint Server</div>
              <div class="sub-modal-section-body">
                <?php $preprintServer = strtolower(trim(user_submission_detail_value($details, ['preprint server', 'preprint_server']))); ?>
                <?php $preprintLink = trim(user_submission_detail_value($details, ['preprint link', 'preprint_link'])); ?>
                <?php if ($preprintServer === 'yes' && $preprintLink !== ''): ?>
                  <div class="sub-kv full-width">
                    <div class="sub-kv-label">Preprint Link</div>
                    <div class="sub-kv-value">
                      <a href="<?php echo e($preprintLink); ?>" target="_blank" style="color: #2563eb; text-decoration: underline; word-break: break-all;"><?php echo e($preprintLink); ?></a>
                    </div>
                  </div>
                <?php else: ?>
                  <div class="sub-kv full-width">
                    <div class="sub-kv-label">Preprint Link</div>
                    <div class="sub-kv-value">
                      <input type="text" value="N/A" disabled style="width: 100%; padding: 12px; border: 1px solid #e2e8f0; background-color: #f8fafc; color: #94a3b8; cursor: not-allowed; box-sizing: border-box; border-radius: 4px; font-weight: 500;">
                    </div>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <!-- SECTION G: Review History (Bottom) -->
            <div class="sub-modal-section" style="background: #fff; border-color: #cbd5e1;">
              <div class="sub-modal-section-header" style="background: #f1f5f9; border-bottom: 1px solid #cbd5e1;"><i class="fa fa-history"></i> Review History</div>
              <div class="sub-modal-section-body">
                <?php
                  $allVersions = [];
                  $allVersions[] = $record;
                  foreach ($history as $h) {
                    if (is_array($h)) {
                      $allVersions[] = user_normalize_submission_record($h);
                    }
                  }
                  
                  // Deduplicate and sort by updated_at / reviewed_at descending
                  $seenVersions = [];
                  $sortedVersions = [];
                  foreach ($allVersions as $v) {
                    $vId = (int) ($v['id'] ?? 0);
                    $vStatus = (string) ($v['status'] ?? 'submitted');
                    $vComment = (string) ($v['admin_comment'] ?? '');
                    
                    // Use a unique signature for each review event if IDs overlap
                    $sig = $vId . '_' . $vStatus . '_' . md5($vComment);
                    if (!isset($seenVersions[$sig])) {
                      $seenVersions[$sig] = true;
                      $sortedVersions[] = $v;
                    }
                  }
                  
                  usort($sortedVersions, function($a, $b) {
                    $dateA = strtotime($a['updated_at'] ?? $a['reviewed_at'] ?? $a['created_at'] ?? '');
                    $dateB = strtotime($b['updated_at'] ?? $b['reviewed_at'] ?? $b['created_at'] ?? '');
                    return $dateB <=> $dateA;
                  });
                ?>
                
                <?php if (empty($sortedVersions)): ?>
                  <div style="color: #64748b; font-style: italic;">No history available.</div>
                <?php else: ?>
                  <?php foreach ($sortedVersions as $idx => $vRec): ?>
                    <div style="border-bottom: 1px solid #e2e8f0; padding-bottom: 16px; margin-bottom: 16px; <?php echo $idx === count($sortedVersions) - 1 ? 'border-bottom: none; margin-bottom: 0; padding-bottom: 0;' : ''; ?>">
                      <div style="display: flex; gap: 24px; margin-bottom: 12px; flex-wrap: wrap;">
                        <div class="sub-kv">
                          <div class="sub-kv-label">Version <?php echo (int)($vRec['version_number'] ?? 1); ?> Status</div>
                          <div class="sub-kv-value">
                            <?php $vRevStatus = (string) ($vRec['status'] ?? 'submitted'); ?>
                            <span class="review-status-pill <?php echo e(user_status_accent_class($vRevStatus)); ?>"><?php echo e(status_label($vRevStatus)); ?></span>
                          </div>
                        </div>
                        <div class="sub-kv">
                          <div class="sub-kv-label">Updated On</div>
                          <div class="sub-kv-value"><?php echo e(user_review_status_date($vRec)); ?></div>
                        </div>
                      </div>
                      <div class="sub-kv full-width">
                        <div class="sub-kv-label">Admin Comment</div>
                        <div class="sub-text-block" style="margin-bottom: 0; background: #f8fafc; border-color: #e2e8f0; font-size: 13px;">
                          <?php echo e((string) ($vRec['admin_comment'] ?? '') !== '' ? (string) ($vRec['admin_comment'] ?? '') : 'No comment recorded.'); ?>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>

          </div>
          
          <div class="modal-actions" style="background: #fff; border-top: 1px solid #e2e8f0; position: sticky; bottom: 0; z-index: 10; padding: 16px 24px;">
            <?php if (($record['status'] ?? '') === 'needs_edits'): ?>
              <a class="dashboard-btn" href="edit-submission.php?id=<?php echo $sid; ?>"><i class="fa fa-pencil"></i> Edit Submission</a>
            <?php else: ?>
              <a class="dashboard-btn dashboard-btn-disabled" href="#" onclick="return false;"><i class="fa fa-pencil"></i> Edit Submission</a>
            <?php endif; ?>
            <a class="dashboard-btn dashboard-btn-secondary" href="#" onclick="event.preventDefault(); openLetterModal(<?php echo $sid; ?>)"><i class="fa fa-envelope-o"></i> View Letter</a>
            <a class="dashboard-btn" href="#" data-chat-id="<?php echo $sid; ?>" data-chat-title="<?php echo htmlspecialchars((string)($record['title'] ?? 'Untitled'), ENT_QUOTES, 'UTF-8'); ?>" onclick="event.preventDefault(); openChatModal(this.dataset.chatId, this.dataset.chatTitle);"><i class="fa fa-comments"></i> Chat with Editorial Team</a>
            
            <?php if (in_array((string)($record['status'] ?? ''), ['rejected', 'needs_edits'], true)): ?>
              <div style="flex-grow: 1;"></div>
              <form method="post" action="user-dashboard.php" style="margin: 0;" id="deleteSubmissionForm-<?php echo $sid; ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="delete_submission">
                <input type="hidden" name="submission_id" value="<?php echo $sid; ?>">
                <button type="button" class="dashboard-btn dashboard-btn-secondary" style="color: #ef4444; border-color: #fca5a5; background: #fef2f2;" onclick="openDeleteModal(<?php echo $sid; ?>)"><i class="fa fa-trash"></i> Delete</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
}


$filtered = [];
if ($view === 'all') {
  $filtered = $submissions;
} elseif ($view === 'processing') {
  $filtered = array_values(array_filter($submissions, static function (array $s): bool {
    return (string) ($s['status'] ?? '') === 'submitted';
  }));
} elseif ($view === 'needs_revision') {
  $filtered = array_values(array_filter($submissions, static function (array $s): bool {
    return (string) ($s['status'] ?? '') === 'needs_edits';
  }));
} elseif ($view === 'decisions') {
  $filtered = array_values(array_filter($submissions, static function (array $s): bool {
    $st = (string) ($s['status'] ?? '');
    return $st === 'accepted' || $st === 'rejected';
  }));
}

if ($sort !== '' && count($filtered) > 1) {
  usort($filtered, function (array $a, array $b) use ($sort, $dir): int {
    $mult = ($dir === 'desc') ? -1 : 1;

    if ($sort === 'title') {
      $av = strtolower((string) ($a['title'] ?? ''));
      $bv = strtolower((string) ($b['title'] ?? ''));
      $cmp = $av <=> $bv;
      if ($cmp !== 0) {
        return $mult * $cmp;
      }
    } elseif ($sort === 'began') {
      $cmp = submission_began_ts($a) <=> submission_began_ts($b);
      if ($cmp !== 0) {
        return $mult * $cmp;
      }
    } elseif ($sort === 'status_date') {
      $cmp = submission_status_ts($a) <=> submission_status_ts($b);
      if ($cmp !== 0) {
        return $mult * $cmp;
      }
    } elseif ($sort === 'status') {
      $av = (string) ($a['status'] ?? '');
      $bv = (string) ($b['status'] ?? '');
      $cmp = $av <=> $bv;
      if ($cmp !== 0) {
        return $mult * $cmp;
      }
    }

    // Stable tie-breaker: newest first.
    $cmp = submission_began_ts($b) <=> submission_began_ts($a);
    if ($cmp !== 0) {
      return $cmp;
    }
    return (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0);
  });
}
?>
<!DOCTYPE html>
<html lang="en" class="no-js">

<head>
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <link rel="shortcut icon" type="image/jpg" href="images/iysjournal.png">
  <title>User Dashboard | Global Youth Science Journal</title>
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
  <style>

      .em-table { width: 100%; border-collapse: collapse; font-family: Arial, sans-serif; font-size: 13px; background: #fff; border: 1px solid #ccc; }
      .em-table th { background: #003366; color: #fff; padding: 14px 10px; text-align: left; font-weight: bold; border-right: 1px solid #002244; }
      .em-table th i { margin-left: 4px; color: #7cb5ec; cursor: pointer; }
      .em-table th i.fa-filter { font-size: 11px; float: right; margin-top: 2px; }
      .em-table td { padding: 12px 10px; border-bottom: 1px solid #ebebeb; border-right: 1px solid #ebebeb; vertical-align: top; color: #333; }
      .em-table tr:hover td { background-color: #f5f8fc; }
      .em-table td:first-child { background: #f9fbff; }
      .em-table a.action-link { display: block; color: #3766b3; text-decoration: none; margin-bottom: 5px; font-size: 12.5px; }
      .em-table a.action-link:hover { text-decoration: underline; color: #1a4a9c; }

    :root {
      --font-sans: 'Poppins', sans-serif;
      --color-text-primary: #1f2937;
      --color-text-secondary: #4b5563;
      --color-text-tertiary: #6b7280;
      --color-background-primary: #ffffff;
      --color-background-secondary: #f4f7fb;
      --color-background-tertiary: #e8eef6;
      --color-border-secondary: #d7dee8;
      --color-border-tertiary: #e4e9f0;
      --color-accent: #f0b429;
      --border-radius-md: 0;
      --border-radius-lg: 0;
    }

    body {
      font-family: var(--font-sans);
        background: #f0f0f0;
      color: var(--color-text-primary);
    }

    .dashboard-shell {
      padding: 24px 0 40px;
    }

    .dash {
      display: grid;
      grid-template-columns: minmax(0, 1fr) 300px;
      gap: 24px;
      min-height: 600px;
    }

    .dashboard-main,
    .dashboard-sidebar {
      min-width: 0;
    }

    .dashboard-controls {
      display: flex;
      gap: 16px;
      margin-bottom: 24px;
      flex-wrap: wrap;
    }

    .gysj-search {
      flex: 1;
      min-width: 260px;
      padding: 12px 16px;
      border: 1px solid var(--color-border-primary);
      border-radius: 0;
      font-size: 14px;
      outline: none;
      background: var(--color-background-primary) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%239CA3AF' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='11' cy='11' r='8'%3E%3C/circle%3E%3Cline x1='21' y1='21' x2='16.65' y2='16.65'%3E%3C/line%3E%3C/svg%3E") no-repeat right 16px center;
      transition: all 0.2s;
    }

    .gysj-search:focus {
      border-color: var(--color-primary);
      box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
    }

    .workspace-table-container {
      background: var(--color-background-primary);
      border: 1px solid var(--color-border-primary);
      border-radius: 0;
      overflow-x: auto;
      margin-bottom: 30px;
    }

    .workspace-table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
      font-size: 14px;
    }

    .workspace-table th {
      background: var(--color-background-secondary);
      padding: 16px 20px;
      font-weight: 600;
      color: var(--color-text-secondary);
      border-bottom: 1px solid var(--color-border-primary);
      white-space: nowrap;
    }

    .workspace-table td {
      padding: 16px 20px;
      border-bottom: 1px solid var(--color-border-tertiary);
      vertical-align: middle;
      color: var(--color-text-primary);
    }

    .workspace-row {
      transition: background 0.15s ease;
      cursor: pointer;
    }

    .workspace-row:hover {
      background: var(--color-background-tertiary);
    }

    .workspace-row:last-child td {
      border-bottom: none;
    }

    .submitted-date {
      font-weight: 500;
      color: var(--color-text-primary);
    }

    .submitted-mini {
      font-size: 12px;
      color: var(--color-text-secondary);
      margin-top: 4px;
    }

    .fw-500 {
      font-weight: 500;
    }

    .db-pill {
      display: inline-flex;
      align-items: center;
      padding: 4px 10px;
      border-radius: 0;
      font-size: 12px;
      font-weight: 500;
      white-space: nowrap;
    }

    .db-pill-tag {
      background: var(--color-background-tertiary);
      color: var(--color-text-secondary);
      border: 1px solid var(--color-border-tertiary);
    }

    .db-status {
      display: inline-flex;
      align-items: center;
      padding: 6px 12px;
      border-radius: 0;
      font-size: 12px;
      font-weight: 600;
      white-space: nowrap;
    }

    .db-status::before {
      content: '';
      display: inline-block;
      width: 6px;
      height: 6px;
      border-radius: 50%;
      margin-right: 6px;
    }

    .db-status-submitted { background: #F3F4F6; color: #4B5563; }
    .db-status-submitted::before { background: #9CA3AF; }

    .db-status-under_review { background: #DBEAFE; color: #1E40AF; }
    .db-status-under_review::before { background: #3B82F6; }

    .db-status-needs_edits { background: #FEF3C7; color: #92400E; }
    .db-status-needs_edits::before { background: #F59E0B; }

    .db-status-accepted { background: #D1FAE5; color: #065F46; }
    .db-status-accepted::before { background: #10B981; }

    .db-status-rejected { background: #FEE2E2; color: #991B1B; }
    .db-status-rejected::before { background: #EF4444; }

    .page-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 24px;
    }

    .page-title {
      font-size: 22px;
      font-weight: 500;
      color: var(--color-text-primary);
      line-height: 1.2;
    }

    .page-sub {
      font-size: 13px;
      color: var(--color-text-secondary);
      margin-top: 3px;
    }

    .dashboard-btn,
    .dashboard-link-btn {
      background: var(--color-text-primary);
      color: var(--color-background-primary);
      border: none;
      border-radius: 0;
      padding: 9px 16px;
      font-size: 13px;
      font-weight: 500;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      white-space: nowrap;
      font-family: var(--font-sans);
      text-decoration: none;
    }

    .dashboard-btn:hover,
    .dashboard-link-btn:hover {
      opacity: 0.85;
      color: var(--color-background-primary);
      text-decoration: none;
    }

    .tabs {
      display: flex;
      gap: 0;
      border-bottom: 0.5px solid var(--color-border-tertiary);
      margin-bottom: 14px;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
    }

    .tab {
      padding: 8px 16px;
      font-size: 13px;
      color: var(--color-text-secondary);
      cursor: pointer;
      border-bottom: 2px solid transparent;
      margin-bottom: -1px;
      white-space: nowrap;
      user-select: none;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: transparent;
      border-left: 0;
      border-right: 0;
      border-top: 0;
      font-family: var(--font-sans);
    }

    .tab.active {
      color: var(--color-text-primary);
      border-bottom-color: var(--color-text-primary);
      font-weight: 500;
    }

    .tab:hover:not(.active) {
      color: var(--color-text-primary);
    }

    .tab-count {
      background: var(--color-background-secondary);
      border: 0.5px solid var(--color-border-tertiary);
      border-radius: 0;
      padding: 1px 7px;
      font-size: 11px;
      color: var(--color-text-tertiary);
    }

    .tab.active .tab-count {
      background: var(--color-background-tertiary);
      color: var(--color-text-secondary);
    }

    .sort-row {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      align-items: center;
      margin-bottom: 18px;
      font-size: 12px;
      color: var(--color-text-tertiary);
    }

    .sort-label {
      font-weight: 500;
    }

    .sort-link {
      color: var(--color-accent);
      text-decoration: none;
    }

    .sort-link:hover {
      text-decoration: underline;
    }

    .tab-panel {
      display: none;
    }

    .tab-panel.active {
      display: block;
    }

    .empty-state {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 64px 24px;
      text-align: center;
      border: 0.5px solid var(--color-border-tertiary);
      border-radius: 0;
      background: rgba(255, 255, 255, 0.72);
      box-shadow: 0 10px 30px rgba(12, 23, 42, 0.04);
    }

    .empty-icon {
      width: 52px;
      height: 52px;
      background: var(--color-background-secondary);
      border: 0.5px solid var(--color-border-tertiary);
      border-radius: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 16px;
      font-size: 22px;
      color: var(--color-text-tertiary);
    }

    .empty-title {
      font-size: 15px;
      font-weight: 500;
      color: var(--color-text-primary);
      margin-bottom: 6px;
    }

    .empty-desc {
      font-size: 13px;
      color: var(--color-text-secondary);
      line-height: 1.6;
      max-width: 320px;
    }

    .empty-action {
      margin-top: 18px;
      padding: 8px 16px;
      border: 0.5px solid var(--color-border-secondary);
      border-radius: 0;
      font-size: 13px;
      font-weight: 500;
      background: var(--color-background-primary);
      cursor: pointer;
      font-family: var(--font-sans);
      color: var(--color-text-primary);
      display: inline-flex;
      align-items: center;
      gap: 6px;
      text-decoration: none;
    }

    .empty-action:hover {
      background: var(--color-background-secondary);
      color: var(--color-text-primary);
      text-decoration: none;
    }

    .submission-stack {
      display: grid;
      gap: 14px;
    }

    .submission-card,
    .profile-card,
    .submit-card {
      background: var(--color-background-primary);
      border: 0.5px solid var(--color-border-tertiary);
      border-radius: 0;
      overflow: hidden;
      box-shadow: 0 10px 28px rgba(12, 23, 42, 0.05);
    }

    .submission-card {
      padding: 18px 18px 16px;
    }

    .submission-top {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 14px;
      margin-bottom: 12px;
    }

    .submission-title {
      font-size: 15px;
      font-weight: 600;
      line-height: 1.4;
      color: var(--color-text-primary);
      margin-bottom: 6px;
    }

    .submission-summary-head {
      min-width: 0;
    }

    .submission-meta {
      font-size: 12px;
      color: var(--color-text-secondary);
      line-height: 1.6;
      display: flex;
      flex-wrap: wrap;
      gap: 8px 14px;
    }

    .submission-meta-inline {
      margin-top: 2px;
    }

    .submission-meta span {
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }

    .submission-abstract-block {
      margin-top: 12px;
      padding-top: 12px;
      border-top: 0.5px solid var(--color-border-tertiary);
    }

    .submission-abstract-label {
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      color: var(--color-text-tertiary);
      margin-bottom: 8px;
    }

    .submission-abstract-text {
      font-size: 13px;
      line-height: 1.7;
      color: var(--color-text-primary);
      display: -webkit-box;
      -webkit-line-clamp: 4;
      -webkit-box-orient: vertical;
      overflow: hidden;
      word-break: break-word;
    }

    .submission-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-top: 14px;
      padding-top: 14px;
      border-top: 0.5px solid var(--color-border-tertiary);
    }

    .action-link {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 10px;
      border-radius: 0;
      border: 0.5px solid var(--color-border-secondary);
      color: var(--color-text-primary);
      background: var(--color-background-primary);
      font-size: 11px;
      text-decoration: none;
      transition: background-color 0.15s ease, border-color 0.15s ease;
    }

    .action-link-compact {
      white-space: nowrap;
    }

    .action-link-disabled {
      opacity: 0.45;
      pointer-events: none;
    }

    .action-link:hover {
      background: var(--color-background-secondary);
      border-color: var(--color-border-secondary);
      color: var(--color-text-primary);
      text-decoration: none;
    }

    .profile-header {
      padding: 20px;
      border-bottom: 0.5px solid var(--color-border-tertiary);
      display: flex;
      align-items: center;
      gap: 14px;
      background: #ffffff;
    }

    .avatar {
      width: 48px;
      height: 48px;
      border-radius: 0;
      background: #f0f0f0;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 16px;
      font-weight: 500;
      color: #6b7280;
      flex-shrink: 0;
      border: 0.5px solid var(--color-border-tertiary);
      overflow: hidden;
    }

    .avatar img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .profile-name {
      font-size: 15px;
      font-weight: 500;
      color: var(--color-text-primary);
    }

    .profile-role {
      font-size: 12px;
      color: var(--color-text-secondary);
      margin-top: 2px;
    }

    .profile-section {
      padding: 16px 20px;
      border-bottom: 0.5px solid var(--color-border-tertiary);
    }

    .profile-section:last-of-type {
      border-bottom: none;
    }

    .section-label {
      font-size: 11px;
      font-weight: 500;
      color: var(--color-text-tertiary);
      letter-spacing: 0.06em;
      text-transform: uppercase;
      margin-bottom: 12px;
    }

    .info-row {
      display: flex;
      align-items: flex-start;
      gap: 8px;
      margin-bottom: 10px;
    }

    .info-row:last-child {
      margin-bottom: 0;
    }

    .info-icon {
      font-size: 14px;
      color: var(--color-text-tertiary);
      margin-top: 1px;
      flex-shrink: 0;
    }

    .info-label {
      font-size: 11px;
      color: var(--color-text-tertiary);
    }

    .info-value {
      font-size: 13px;
      color: var(--color-text-primary);
      margin-top: 1px;
      word-break: break-word;
    }

    .profile-actions {
      padding: 14px 20px 20px;
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .btn-outline {
      width: 100%;
      padding: 8px 14px;
      border: 0.5px solid var(--color-border-secondary);
      border-radius: 0;
      background: var(--color-background-primary);
      font-size: 13px;
      color: var(--color-text-primary);
      cursor: pointer;
      font-family: var(--font-sans);
      display: flex;
      align-items: center;
      gap: 6px;
      text-align: left;
      text-decoration: none;
    }

    .btn-outline:hover {
      background: var(--color-background-secondary);
      color: var(--color-text-primary);
      text-decoration: none;
    }

    .upload-area {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 10px 12px;
      border: 0.5px dashed var(--color-border-secondary);
      border-radius: 0;
      cursor: default;
      font-size: 12px;
      color: var(--color-text-secondary);
      background: var(--color-background-primary);
    }

    .upload-area i {
      font-size: 16px;
      color: var(--color-text-tertiary);
    }

    .submit-card {
      padding: 20px;
    }

    .submit-card h2 {
      font-size: 18px;
      margin-bottom: 6px;
    }

    .submit-card .form-control,
    .submit-card .form-control-file,
    .profile-form .form-control,
    .profile-form .form-control-file {
      border-radius: 0;
    }

    .profile-form .form-group,
    .submit-card .form-group {
      margin-bottom: 12px;
    }

    .profile-form label,
    .submit-card label {
      font-size: 12px;
      font-weight: 500;
      color: var(--color-text-primary);
    }

    .profile-form .btn-primary,
    .submit-card .btn-primary {
      background: var(--color-text-primary);
      border-color: var(--color-text-primary);
      border-radius: 0;
      font-size: 13px;
      padding: 9px 16px;
    }

    .profile-form .btn-primary:hover,
    .submit-card .btn-primary:hover {
      background: #111827;
      border-color: #111827;
    }

    .submit-card.submit-wizard {
      padding: 0;
      overflow: hidden;
    }

    .submit-shell {
      background: var(--color-background-primary);
    }

    .submit-hero {
      padding: 18px 20px 16px;
      border-bottom: 0.5px solid var(--color-border-tertiary);
      background: #ffffff;
    }

    .submit-hero-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 14px;
      margin-bottom: 14px;
    }

    .submit-kicker {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 6px 10px;
      border-radius: 0;
      background: #f0f0f0;
      color: var(--color-accent);
      font-size: 12px;
      font-weight: 500;
    }

    .submit-title {
      font-size: 24px;
      font-weight: 600;
      color: var(--color-text-primary);
      line-height: 1.2;
      margin-top: 12px;
    }

    .submit-desc {
      margin-top: 6px;
      font-size: 13px;
      color: var(--color-text-secondary);
      line-height: 1.65;
      max-width: 780px;
    }

    .submit-stepper {
      display: flex;
      align-items: flex-start;
      justify-content: center;
      gap: 14px;
      width: 100%;
      margin: 0 auto 18px;
      overflow-x: auto;
      padding: 6px 0 8px;
    }

    .submit-step {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 8px;
      min-width: 88px;
      flex-shrink: 0;
    }

    .submit-step-num {
      width: 44px;
      height: 44px;
      border-radius: 0;
      border: 2px solid var(--color-border-secondary);
      display: flex;
      align-items: center;
      justify-content: center;
      background: var(--color-background-primary);
      color: var(--color-text-tertiary);
      font-size: 15px;
      font-weight: 600;
      transition: all 0.2s ease;
    }

    .submit-step-label {
      font-size: 12px;
      color: var(--color-text-tertiary);
      text-align: center;
      line-height: 1.35;
      white-space: normal;
      max-width: 108px;
    }

    .submit-step.active .submit-step-num,
    .submit-step.done .submit-step-num {
      border-color: var(--color-accent);
      background: var(--color-accent);
      color: var(--color-text-primary);
    }

    .submit-step.active .submit-step-label,
    .submit-step.done .submit-step-label {
      color: var(--color-accent-dark);
      font-weight: 500;
    }

    .submit-step-line {
      height: 4px;
      min-width: 42px;
      flex: 1;
      margin-top: 20px;
      background: #d8e0eb;
      border-radius: 0;
      position: relative;
      overflow: hidden;
    }

    .submit-step-line::after {
      content: '';
      position: absolute;
      inset: 0;
      background: transparent;
      transition: background 0.25s ease, box-shadow 0.25s ease;
    }

    .submit-step-line.done::after {
      background: linear-gradient(90deg, var(--color-accent) 0%, #f4c95a 100%);
      box-shadow: 0 0 0 1px rgba(240, 180, 41, 0.18), 0 0 10px rgba(240, 180, 41, 0.35);
    }

    .submit-progress-fill {
      border-bottom: 0.5px solid var(--color-border-tertiary);
    }

    .submit-body {
      padding: 24px 24px 18px;
    }

    .submit-panel {
      display: none;
      animation: submitFadeIn 0.25s ease;
    }

    .submit-panel.active {
      display: block;
    }

    @keyframes submitFadeIn {
      from { opacity: 0; transform: translateX(14px); }
      to { opacity: 1; transform: translateX(0); }
    }

    .submit-panel-title {
      font-size: 22px;
      font-weight: 600;
      color: var(--color-text-primary);
      margin-bottom: 6px;
      line-height: 1.25;
    }

    .submit-panel-desc {
      font-size: 13px;
      color: var(--color-text-secondary);
      line-height: 1.65;
      margin-bottom: 22px;
    }

    .submit-field {
      margin-bottom: 18px;
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
            <li class="nav-item"><a class="nav-link" href="index.php">Home</a></li>
            <li class="nav-item"><a class="nav-link" href="publication.php">Publication</a></li>
            <li class="nav-item"><a class="nav-link" href="editorial-board.php">Editorial Board</a></li>
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle" href="#" id="dropdownMenuButton2" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">About Us</a>
              <div class="dropdown-menu" aria-labelledby="dropdownMenuButton2">
                <a class="dropdown-item" href="our-founders.php">Our Founders</a>
                <a class="dropdown-item" href="our-mission.php">Our Mission</a>
                <a class="dropdown-item" href="our-funding.php">Our Funding</a>
              </div>
            </li>
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle" href="#" id="dropdownMenuButton3" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Paper Submissions</a>
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
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle" href="#" id="accountMenu" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><?php echo e((string) (($profile['name'] ?? '') !== '' ? $profile['name'] : ($profile['email'] ?? $user['email']))); ?></a>
              <div class="dropdown-menu" aria-labelledby="accountMenu">
                <a class="dropdown-item" href="user-dashboard.php">Dashboard</a>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item" href="account.php">Account Settings</a>
                <a class="dropdown-item" href="logout.php">Log Out</a>
              </div>
            </li>
          </ul>
        </div>
      </nav>
    </div>
  </div>

  <div class="container dashboard-shell">
    <?php if ($success !== ''): ?>
      <div class="alert alert-success" role="alert"><?php echo e($success); ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
      <div class="alert alert-danger" role="alert"><?php echo e($error); ?></div>
    <?php endif; ?>

    <?php if ($view === 'submit'): ?>
      <?php
        $submitPosted = ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'new_submission');
        $submitValue = static function (string $key, string $default = '') use ($submitPosted): string {
          if ($submitPosted && array_key_exists($key, $_POST)) {
            return trim((string) $_POST[$key]);
          }
          return $default;
        };

        $defaultName = trim((string) (($profile['name'] ?? '') !== '' ? $profile['name'] : ''));
        if ($defaultName === '') {
          $nameBits = array_filter([
            (string) ($profile['first_name'] ?? ''),
            (string) ($profile['middle_name'] ?? ''),
            (string) ($profile['last_name'] ?? ''),
          ], static function($value) { return $value !== ''; });
          $defaultName = trim(implode(' ', $nameBits));
        }

        $defaultEmail = trim((string) ($profile['email'] ?? $user['email']));
        $defaultPhone = trim((string) ($profile['phone'] ?? ''));
        $defaultPhoneCode = '';
        $defaultPhoneNumber = $defaultPhone;
        if ($defaultPhone !== '' && preg_match('/^(\+\d{1,4})\s*(.*)$/', $defaultPhone, $phoneMatch)) {
          $defaultPhoneCode = (string) $phoneMatch[1];
          $defaultPhoneNumber = trim((string) ($phoneMatch[2] ?? ''));
        }

        $submitGuidelinesConfirm = $submitPosted ? ((string) ($_POST['guidelines_confirm'] ?? '') === '1') : false;
        $submitJournal = $submitValue('journal');
        $submitTitle = $submitValue('title');
        $submitAbstract = $submitValue('abstract');
        $submitKeywords = $submitValue('keywords');
        $submitStory = $submitValue('project_story');
        $submitAuthors = $submitValue('authors');
        $submitAuthorAge = $submitValue('author_age');
        $submitEmail = $submitValue('email', $defaultEmail);
        $submitPhoneCode = $submitValue('phone_code', $defaultPhoneCode);
        $submitPhoneNumber = $submitValue('phone_number', $defaultPhoneNumber);
        $submitCountry = $submitValue('country', (string) ($profile['country'] ?? ''));
        $submitGrade = $submitValue('grade_level', (string) ($profile['grade_level'] ?? ''));
        $submitSchool = $submitValue('school_name', (string) ($profile['school_name'] ?? ''));
        $submitSchoolEmail = $submitValue('school_email', (string) ($profile['school_email'] ?? $defaultEmail));
        $submitAdmission = $submitValue('admission_number', (string) ($profile['admission_number'] ?? ''));
        $submitBio = $submitValue('author_bio');
        $submitAuthorsPayload = $submitValue('authors_payload');

        $submitJournals = [
          ['Computer Science & Engineering', 'Journal of Advance Research in Computer Science & Engineering'],
          ['Mathematics & Mathematical Sciences', 'Journal of Advance Research in Mathematics & Mathematical Sciences'],
          ['Applied Physics', 'Journal of Advance Research in Applied Physics'],
          ['Applied Chemistry', 'Journal of Advance Research in Applied Chemistry'],
          ['Civil Engineering', 'Journal of Advance Research in Civil Engineering'],
          ['Mechanical Engineering', 'Journal of Advance Research in Mechanical Engineering'],
          ['Business, Management & Accounting', 'Journal of Advance Research in Business, Management & Accounting'],
          ['Electronics & Communication Engineering', 'Journal of Advance Research in Electronics & Communication Engineering'],
          ['Humanities & Social Science', 'Journal of Advance Research in Humanities & Social Science'],
          ['Advance Research (General)', 'Journal of Advance Research (General)'],
          ['Biology & Pharmacy', 'Journal of Advance Research in Biology & Pharmacy'],
          ['Environmental Science', 'Journal of Advance Research in Environmental Science'],
        ];

        $submitCodes = [
          ['+91','India (+91)'],['+1','US/Canada (+1)'],['+44','UK (+44)'],['+61','Australia (+61)'],['+64','New Zealand (+64)'],['+27','South Africa (+27)'],
          ['+234','Nigeria (+234)'],['+254','Kenya (+254)'],['+971','UAE (+971)'],['+65','Singapore (+65)'],['+60','Malaysia (+60)'],['+63','Philippines (+63)'],
          ['+62','Indonesia (+62)'],['+92','Pakistan (+92)'],['+880','Bangladesh (+880)'],['+94','Sri Lanka (+94)'],['+977','Nepal (+977)'],['+20','Egypt (+20)'],
          ['+212','Morocco (+212)'],['+49','Germany (+49)'],['+33','France (+33)'],['+39','Italy (+39)'],['+34','Spain (+34)'],['+55','Brazil (+55)'],
          ['+52','Mexico (+52)'],['+86','China (+86)'],['+81','Japan (+81)'],['+82','South Korea (+82)'],['+66','Thailand (+66)'],['+84','Vietnam (+84)'],
        ];

        $submitCountries = ['Afghanistan','Albania','Algeria','Argentina','Armenia','Australia','Austria','Azerbaijan','Bahrain','Bangladesh','Belgium','Bolivia','Bosnia and Herzegovina','Botswana','Brazil','Bulgaria','Cambodia','Cameroon','Canada','Chile','China','Colombia','Costa Rica','Croatia','Cyprus','Czech Republic','Denmark','Ecuador','Egypt','El Salvador','Estonia','Ethiopia','Fiji','Finland','France','Georgia','Germany','Ghana','Greece','Guatemala','Hungary','Iceland','India','Indonesia','Iran','Iraq','Ireland','Israel','Italy','Jamaica','Japan','Jordan','Kazakhstan','Kenya','Kuwait','Kyrgyzstan','Laos','Latvia','Lebanon','Libya','Lithuania','Luxembourg','Madagascar','Malawi','Malaysia','Maldives','Mali','Malta','Mauritius','Mexico','Moldova','Monaco','Mongolia','Montenegro','Morocco','Mozambique','Myanmar','Namibia','Nepal','Netherlands','New Zealand','Nicaragua','Nigeria','Norway','Oman','Pakistan','Palestine','Panama','Paraguay','Peru','Philippines','Poland','Portugal','Qatar','Romania','Russia','Rwanda','Saudi Arabia','Senegal','Serbia','Singapore','Slovakia','Slovenia','Somalia','South Africa','South Korea','South Sudan','Spain','Sri Lanka','Sudan','Sweden','Switzerland','Syria','Taiwan','Tanzania','Thailand','Tunisia','Turkey','Uganda','Ukraine','United Arab Emirates','United Kingdom','United States','Uruguay','Uzbekistan','Venezuela','Vietnam','Yemen','Zambia','Zimbabwe'];
      ?>

      <div class="submit-card submit-wizard">
        <?php if ($success !== ''): ?>
          <style>
            .submit-success-circle-animated {
              width: 80px;
              height: 80px;
              border-radius: 50%;
              display: block;
              stroke-width: 2;
              stroke: #fff;
              stroke-miterlimit: 10;
              box-shadow: inset 0px 0px 0px #4bb71b;
              animation: fill 0.4s ease-in-out 0.4s forwards, scale 0.3s ease-in-out 0.9s both;
              margin: 0 auto 20px;
            }
            .submit-success-circle-animated .checkmark__circle {
              stroke-dasharray: 166;
              stroke-dashoffset: 166;
              stroke-width: 2;
              stroke-miterlimit: 10;
              stroke: #4bb71b;
              fill: none;
              animation: stroke 0.6s cubic-bezier(0.65, 0, 0.45, 1) forwards;
            }
            .submit-success-circle-animated .checkmark__check {
              transform-origin: 50% 50%;
              stroke-dasharray: 48;
              stroke-dashoffset: 48;
              animation: stroke 0.3s cubic-bezier(0.65, 0, 0.45, 1) 0.8s forwards;
            }
            @keyframes stroke { 100% { stroke-dashoffset: 0; } }
            @keyframes scale { 0%, 100% { transform: none; } 50% { transform: scale3d(1.1, 1.1, 1); } }
            @keyframes fill { 100% { box-shadow: inset 0px 0px 0px 40px #4bb71b; } }
          </style>
          <div class="submit-success">
            <svg class="submit-success-circle-animated" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 52 52">
              <circle class="checkmark__circle" cx="26" cy="26" r="25" fill="none" />
              <path class="checkmark__check" fill="none" d="M14.1 27.2l7.1 7.2 16.7-16.8" />
            </svg>
            <div class="submit-success-title">Submission Received</div>
            <p class="submit-success-body">Thank you for submitting to the Global Youth Science Journal. Our editors will review your manuscript and contact you at your registered email. Please allow 4–6 weeks for the review process.</p>
            <div class="submit-success-ref">Tracking ID: <?php echo e(isset($trackingId) && $trackingId !== '' ? $trackingId : 'GYSJ-' . strtoupper(substr(hash('sha256', (string) $user['id'] . '|' . date('YmdHis')), 0, 10))); ?></div>
            <div style="margin-top:22px;">
              <a class="dashboard-btn" href="<?php echo e(user_dashboard_url(['view' => 'all'])); ?>">Back to Dashboard</a>
            </div>
          </div>
        <?php else: ?>
          <div class="submit-hero">
            <div class="submit-hero-top">
              <div>
                <div class="submit-title">Submit your paper</div>
                <div class="submit-desc">Use the streamlined submission flow below to route your manuscript, author details, and compliance information to the editorial team.</div>
              </div>
              <a class="dashboard-link-btn" href="<?php echo e(user_dashboard_url(['view' => 'all', 'sort' => $sort, 'dir' => $dir])); ?>">
                <i class="ti ti-arrow-left" aria-hidden="true"></i> Back to Dashboard
              </a>
            </div>

            <div class="submit-stepper" id="submitStepper">
              <div class="submit-step active" data-step="1"><div class="submit-step-num">1</div><div class="submit-step-label">Journal Selection</div></div><div class="submit-step-line" data-line="1"></div>
              <div class="submit-step" data-step="2"><div class="submit-step-num">2</div><div class="submit-step-label">Upload Manuscript</div></div><div class="submit-step-line" data-line="2"></div>
              <div class="submit-step" data-step="3"><div class="submit-step-num">3</div><div class="submit-step-label">Paper Details</div></div><div class="submit-step-line" data-line="3"></div>
              <div class="submit-step" data-step="4"><div class="submit-step-num">4</div><div class="submit-step-label">Author Profile</div></div><div class="submit-step-line" data-line="4"></div>
              <div class="submit-step" data-step="5"><div class="submit-step-num">5</div><div class="submit-step-label">Submit</div></div>
            </div>
          </div>

          <form method="post" action="<?php echo e(user_dashboard_url(['view' => 'submit'])); ?>" enctype="multipart/form-data" id="submitWizardForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="new_submission">
            <input type="hidden" name="authors" id="submitAuthorsHidden" value="<?php echo e($submitAuthors); ?>">
            <input type="hidden" name="author_age" id="submitAuthorAgeHidden" value="<?php echo e($submitAuthorAge); ?>">
            <input type="hidden" name="email" id="submitAuthorEmailHidden" value="<?php echo e($submitEmail); ?>">
            <input type="hidden" name="phone_code" id="submitAuthorPhoneCodeHidden" value="<?php echo e($submitPhoneCode); ?>">
            <input type="hidden" name="phone_number" id="submitAuthorPhoneNumberHidden" value="<?php echo e($submitPhoneNumber); ?>">
            <textarea name="author_bio" id="submitAuthorBioHidden" style="display:none;"><?php echo e($submitBio); ?></textarea>
            <input type="hidden" name="authors_payload" id="submitAuthorsPayload" value="<?php echo e($submitAuthorsPayload); ?>">

            <div class="submit-body">
              <!-- Step 1: Selection (Guidelines, Country, Journal) -->
              <div class="submit-panel active" id="submitPanel1">
                <div class="submit-panel-title">Selection</div>
                <div class="submit-panel-desc">Before starting, please confirm you've reviewed our guidelines.</div>

                <div class="submit-field">
                  <div class="submit-guidelines-box">
                    <div class="submit-guidelines-header">
                      <span class="submit-guidelines-icon">📋</span>
                      <div>
                        <div class="submit-guidelines-title">GYSJ Author Guidelines</div>
                        <p class="submit-guidelines-text">Please review our comprehensive submission guidelines to ensure your manuscript meets all requirements.</p>
                      </div>
                    </div>
                    <a href="authorguidelines.php" target="_blank" rel="noopener" class="submit-guidelines-link">Read Full Guidelines ↗</a>
                  </div>
                  <label class="submit-check-row" for="submitGuidelines">
                    <input type="checkbox" id="submitGuidelines" name="guidelines_confirm" value="1" <?php echo $submitGuidelinesConfirm ? 'checked' : ''; ?>>
                    <span>I have reviewed and understand the GYSJ author guidelines</span>
                  </label>
                  <div class="submit-err" id="submitErrGuidelines">You must confirm you've reviewed the guidelines to continue.</div>
                </div>

                <div class="submit-field">
                  <label class="submit-label" for="submitCountry">Country of Origin <span class="submit-req">*</span></label>
                  <div class="submit-country-box">
                    <input
                      type="text"
                      id="submitCountrySearch"
                      class="submit-country-search"
                      placeholder="Search and select your country..."
                      autocomplete="off"
                    >
                    <div class="submit-country-list-wrapper" id="submitCountryListWrapper">
                      <!-- Countries will be populated here by JavaScript -->
                    </div>
                    <select class="submit-select" id="submitCountry" name="country" data-selected="<?php echo e($submitCountry); ?>" style="display:none;"></select>
                  </div>
                  <div class="submit-err" id="submitErrCountry">Please select your country.</div>
                </div>

                <div class="submit-field">
                  <label class="submit-label">Research Journal <span class="submit-req">*</span></label>
                  <div class="submit-list" id="submitJournalList">
                    <?php foreach ($submitJournals as $journalOption): ?>
                      <?php [$journalValue, $journalLabel] = $journalOption; ?>
                      <label class="submit-choice<?php echo $submitJournal === $journalValue ? ' sel' : ''; ?>">
                        <input type="radio" name="journal" value="<?php echo e($journalValue); ?>" <?php echo $submitJournal === $journalValue ? 'checked' : ''; ?> required>
                        <span class="submit-choice-dot"></span>
                        <span class="submit-choice-text"><?php echo e($journalLabel); ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </div> <!-- End submit-field -->
                <div style="margin-top:20px; display:flex; justify-content:flex-end;">
                  <button type="button" class="dashboard-btn" onclick="nextSubmitStep(2)">Next &#8594;</button>
                </div>
              </div> <!-- End submitPanel1 -->

              <!-- Step 2: Upload Manuscript -->
              <div class="submit-panel" id="submitPanel2" style="display:none;">
                <div class="submit-panel-title">Upload Manuscript</div>
                <div class="submit-panel-desc">Upload your manuscript as DOCX or PNG. PDF is allowed only for supplementary material, not as the manuscript.</div>

                <div class="submit-field">
                  <label class="submit-label" style="margin-top: 15px;">Upload Manuscript or Supplementary File <span class="submit-req">*</span></label>
                  <input type="file" name="manuscripts[]" multiple id="submitManuscriptInput" accept=".docx,.png,.pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,image/png,application/pdf" required>
                  <div class="submit-upload-layout">
                    <div class="submit-upload-copy">
                      <p>Please attach the manuscript as DOCX or PNG. You may also upload PDF files if they are supplementary content only.</p>
                      <ul>
                        <li>DOCX and PNG files can be used for manuscript content.</li>
                        <li>PDF files must be supplementary content only.</li>
                        <li>Use a shorter file name if upload fails.</li>
                        <li>You can drag and drop the file into the upload box.</li>
                      </ul>
                      <div class="submit-upload-note">Accepted formats: DOCX, PNG, and PDF.</div>
                    </div>

                    <div class="submit-upload-shell" id="submitManuscriptDropZone">
                      <div class="submit-upload-cta" id="submitManuscriptPrompt">
                        <button type="button" class="submit-upload-browse" id="submitManuscriptBrowse">Browse...</button>
                        <div class="submit-upload-or">OR</div>
                        <div class="submit-upload-drop">
                          <div class="submit-upload-drop-icon" aria-hidden="true"></div>
                          <div>Drag &amp; Drop<br>Files Here</div>
                        </div>
                      </div>
                    </div>
                  </div>
                  <div class="submit-upload-attachments" id="submitManuscriptAttachments">
                    <div class="submit-upload-attachments-head">
                      <div>
                        Attached Files
                        <div class="submit-upload-attachments-subtitle">Set the file type and description before continuing.</div>
                      </div>
                      <div class="submit-upload-attachments-subtitle" id="submitAttachmentsSubtitle">0 items selected</div>
                    </div>
                    <div class="submit-upload-attachments-grid" id="submitAttachmentsGrid">
                      <div class="submit-upload-attachments-headcell">Order</div>
                      <div class="submit-upload-attachments-headcell">Item</div>
                      <div class="submit-upload-attachments-headcell">Description</div>
                      <div class="submit-upload-attachments-headcell">File Name</div>
                      <div class="submit-upload-attachments-headcell">Size</div>
                      <div class="submit-upload-attachments-headcell">Actions</div>
                      <div class="submit-upload-attachments-headcell">Select</div>
                    </div>
                    <div class="submit-upload-attachment-warning" id="submitAttachmentWarning">PDF files are supplementary content only. Manuscript files cannot be PDF.</div>
                  </div>
                </div>

                <div style="margin-top:20px; display:flex; justify-content:space-between;">
                  <button type="button" class="dashboard-btn" style="background:#666;border-color:#666;" onclick="prevSubmitStep(1)">&#8592; Back</button>
                  <button type="button" class="dashboard-btn" onclick="nextSubmitStep(3)">Next &#8594;</button>
                </div>
              </div>

              <!-- Step 3: Paper Details -->
              <div class="submit-panel" id="submitPanel3" style="display:none;">
                <div class="submit-panel-title">Paper Details</div>
                <div class="submit-panel-desc">Enter the core details of your manuscript.</div>

                <div class="submit-field">
                  <label class="submit-label">Title <span class="submit-req">*</span></label>
                  <input type="text" class="submit-input" name="title" placeholder="Enter paper title" required style="width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 0; box-sizing: border-box;">
                </div>

               

                <div class="submit-field">
                  <label class="submit-label">Abstract <span class="submit-req">*</span></label>
                  <textarea class="submit-input" name="abstract" rows="4" placeholder="Enter abstract" required style="width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 0; box-sizing: border-box;"></textarea>
                </div>

                <div class="submit-field">
                  <label class="submit-label">Keywords <span class="submit-req">*</span></label>
                  <input type="text" class="submit-input" name="keywords" placeholder="Comma separated keywords" required style="width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 0; box-sizing: border-box;">
                </div>

                <div class="submit-field">
                  <label class="submit-label">Project Story</label>
                  <textarea class="submit-input" name="project_story" rows="3" placeholder="Brief story about this project (optional)" style="width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 0; box-sizing: border-box;"></textarea>
                </div>

                <div style="margin-top:20px; display:flex; justify-content:space-between;">
                  <button type="button" class="dashboard-btn" style="background:#666;border-color:#666;" onclick="prevSubmitStep(2)">&#8592; Back</button>
                  <button type="button" class="dashboard-btn" onclick="nextSubmitStep(4)">Next &#8594;</button>
                </div>
              </div>

              <!-- Step 4: Author Profile -->
              <div class="submit-panel" id="submitPanel4" style="display:none;">
                <div class="submit-panel-title">Author Profile</div>
                <div class="submit-panel-desc">Add one author at a time. The first author will be treated as the corresponding author for the submission.</div>

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

                <div style="margin-top:20px; display:flex; justify-content:space-between;">
                  <button type="button" class="dashboard-btn" style="background:#666;border-color:#666;" onclick="prevSubmitStep(3)">&#8592; Back</button>
                  <button type="button" class="dashboard-btn" onclick="nextSubmitStep(5)">Next &#8594;</button>
                </div>
              </div>

              <!-- Step 5: Submit -->
              <div class="submit-panel" id="submitPanel5" style="display:none;">
                <div class="submit-panel-title">Final Review & Submission</div>
                <div class="submit-panel-desc">Please review the agreements and fill out the final questionnaire.</div>

                <div class="submit-field">
                  <label class="submit-label" style="font-size:18px; margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:10px;">Questionnaire & Agreements <span class="submit-req">*</span></label>
                  
                  <label class="submit-check-row" style="margin-bottom:15px; display:flex; gap:10px; align-items:flex-start;">
                    <input type="checkbox" name="author_consent" value="1" required style="margin-top:5px;"> 
                    <span><strong>1. Author consent</strong><br>All authors have reviewed and approved this submission.</span>
                  </label>

                  <label class="submit-check-row" style="margin-bottom:15px; display:flex; gap:10px; align-items:flex-start;">
                    <input type="checkbox" name="corresp_author_resp" value="1" required style="margin-top:5px;"> 
                    <span><strong>2. Corresponding author responsibility</strong><br>The submitting author will coordinate editorial communication and revisions.</span>
                  </label>

                  <label class="submit-check-row" style="margin-bottom:15px; display:flex; gap:10px; align-items:flex-start;">
                    <input type="checkbox" name="age_eligibility" value="1" required style="margin-top:5px;"> 
                    <span><strong>3. Age eligibility</strong><br>All student authors are between the ages of 12 and 20 at submission.</span>
                  </label>

                  <label class="submit-check-row" style="margin-bottom:15px; display:flex; gap:10px; align-items:flex-start;">
                    <input type="checkbox" name="permission_supervision" value="1" required style="margin-top:5px;"> 
                    <span><strong>4. Permission and supervision</strong><br>Appropriate permission or supervision was obtained where applicable.</span>
                  </label>

                  <label class="submit-check-row" style="margin-bottom:15px; display:flex; gap:10px; align-items:flex-start;">
                    <input type="checkbox" name="originality" value="1" required style="margin-top:5px;"> 
                    <span><strong>5. Originality of work</strong><br>The manuscript is original and not knowingly plagiarised or copied.</span>
                  </label>

                  <label class="submit-check-row" style="margin-bottom:15px; display:flex; gap:10px; align-items:flex-start;">
                    <input type="checkbox" name="concurrent_submission" value="1" required style="margin-top:5px;"> 
                    <span><strong>6. Concurrent submission</strong><br>This manuscript is not under active review at another journal.</span>
                  </label>

                  <label class="submit-check-row" style="margin-bottom:15px; display:flex; gap:10px; align-items:flex-start;">
                    <input type="checkbox" name="ethical_compliance" value="1" required style="margin-top:5px;"> 
                    <span><strong>7. Ethical compliance</strong><br>Appropriate ethical approval or consent was obtained where required.</span>
                  </label>

                  <label class="submit-check-row" style="margin-bottom:15px; display:flex; gap:10px; align-items:flex-start;">
                    <input type="checkbox" name="ai_policy" value="1" required style="margin-top:5px;"> 
                    <span><strong>8. AI usage policy</strong><br>Any AI use was limited to permitted support and not scientific fabrication.</span>
                  </label>

                  <label class="submit-check-row" style="margin-bottom:15px; display:flex; gap:10px; align-items:flex-start;">
                    <input type="checkbox" name="formatting_guidelines" value="1" required style="margin-top:5px;"> 
                    <span><strong>9. Formatting and guidelines</strong><br>The manuscript follows the journal guidelines and is prepared clearly.</span>
                  </label>

                  <label class="submit-check-row" style="margin-bottom:25px; display:flex; gap:10px; align-items:flex-start;">
                    <input type="checkbox" name="publication_agreement" value="1" required style="margin-top:5px;"> 
                    <span><strong>10. Publication agreement</strong><br>If accepted, the authors grant GYSJ permission to publish the manuscript.</span>
                  </label>
                </div>

                <div class="submit-field" style="margin-top:30px; padding-top:20px; border-top:1px solid #ddd;">
                  <label class="submit-label" style="font-size:16px;">Preprint Server</label>
                  <div style="font-size:13px; color:#555; margin-bottom:15px; line-height:1.4;">
                    By clicking this box, I certify on behalf of all authors that the work presented in this manuscript is not under consideration for publication in another journal, and cannot be submitted to a preprint once it is accepted for scientific review at GYSJ. We understand that this work - if published in GYSJ - cannot be published elsewhere.
                  </div>
                  <div style="display:flex; gap:10px; align-items:center;">
                    <input type="text" class="submit-input" id="preprint_link_input" name="preprint_link" placeholder="Preprint URL" style="flex:1; padding: 12px; border: 1px solid #ccc; border-radius: 0; box-sizing: border-box; transition: all 0.2s;" required>
                    <label style="display:flex; align-items:center; gap:5px; background:#f5f5f5; padding:12px 15px; border:1px solid #ccc; cursor:pointer;">
                      <input type="checkbox" id="preprint_na_checkbox" name="preprint_server" value="No" onchange="var el=document.getElementById('preprint_link_input'); if(this.checked){ el.disabled=true; el.value=''; el.style.opacity='0.5'; el.style.filter='blur(2px)'; el.required=false; } else { el.disabled=false; el.style.opacity='1'; el.style.filter='none'; el.required=true; }"> 
                      N/A
                    </label>
                  </div>
                </div>

                <div class="submit-field">
                  <label class="submit-label">How did you hear about GYSJ? <span class="submit-req">*</span></label>
                  <select class="submit-select" name="how_heard" required style="width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 0; box-sizing: border-box;">
                    <option value="">Please select a response</option>
                    <option value="Internet Search">Internet Search</option>
                    <option value="At a teacher's conference">At a teacher's conference</option>
                    <option value="Word of Mouth">Word of Mouth</option>
                    <option value="Facebook/Twitter">Facebook/Twitter</option>
                    <option value="At a science fair">At a science fair</option>
                    <option value="Other">Other</option>
                  </select>
                </div>

                <div class="submit-field">
                  <label class="submit-label">In what setting was the research conducted? <span class="submit-req">*</span></label>
                  <div style="display:flex; flex-direction:column; gap:5px;">
                    <label><input type="checkbox" name="setting[]" value="At home"> At home</label>
                    <label><input type="checkbox" name="setting[]" value="At school"> At school</label>
                    <label><input type="checkbox" name="setting[]" value="In an academic lab at a university"> In an academic lab at a university</label>
                    <label><input type="checkbox" name="setting[]" value="Other"> Other</label>
                  </div>
                </div>

                <div class="submit-field">
                  <label class="submit-label">What ages are the student authors? Please select all that apply. <span class="submit-req">*</span></label>
                  <div style="display:flex; flex-direction:column; gap:5px;">
                    <label><input type="checkbox" name="ages[]" value="12 years and under"> 12 years and under</label>
                    <label><input type="checkbox" name="ages[]" value="13 years"> 13 years</label>
                    <label><input type="checkbox" name="ages[]" value="14 years"> 14 years</label>
                    <label><input type="checkbox" name="ages[]" value="15 years"> 15 years</label>
                    <label><input type="checkbox" name="ages[]" value="16 years"> 16 years</label>
                    <label><input type="checkbox" name="ages[]" value="17 years"> 17 years</label>
                    <label><input type="checkbox" name="ages[]" value="18 years and older"> 18 years and older</label>
                  </div>
                </div>

                <div class="submit-field">
                  <label class="submit-label">What best describes the type of school the student authors attend? <span class="submit-req">*</span></label>
                  <div style="display:flex; flex-direction:column; gap:5px;">
                    <label><input type="checkbox" name="school_type[]" value="Public"> Public</label>
                    <label><input type="checkbox" name="school_type[]" value="Private"> Private</label>
                    <label><input type="checkbox" name="school_type[]" value="Charter"> Charter</label>
                    <label><input type="checkbox" name="school_type[]" value="Magnet"> Magnet</label>
                    <label><input type="checkbox" name="school_type[]" value="Home"> Home</label>
                    <label><input type="checkbox" name="school_type[]" value="Virtual"> Virtual (Note: means school that is completely or greater than 75% online without a global pandemic)</label>
                    <label><input type="checkbox" name="school_type[]" value="Other"> Other</label>
                  </div>
                </div>

                <div class="submit-field">
                  <label class="submit-label">What websites and/or tools did you use to access and cite primary scientific literature related to your project? <span class="submit-req">*</span></label>
                  <textarea class="submit-input" name="literature_tools" maxlength="500" rows="3" required placeholder="Limit 500 characters" style="width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 0; box-sizing: border-box;"></textarea>
                </div>

                <div class="submit-field">
                  <label class="submit-label">What specialized software resources were used during experimentation, analysis, and writing of this manuscript? <span class="submit-req">*</span></label>
                  <textarea class="submit-input" name="software_tools" maxlength="20000" rows="5" required placeholder="Limit 20000 characters" style="width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 0; box-sizing: border-box;"></textarea>
                </div>

           

                <div style="margin-top:20px; display:flex; justify-content:space-between;">
                  <button type="button" class="dashboard-btn" style="background:#666;border-color:#666;" onclick="prevSubmitStep(4)">&#8592; Back</button>
                  <button type="submit" class="dashboard-btn" style="background:#000000;border-color:#000000;">Submit Manuscript</button>
                </div>
              </div>

            </div> <!-- End submit-body -->
          </form>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <?php
        $allTotal = count($submissions);
        $acceptedTotal = count(array_filter($submissions, function($s) { return ($s['status'] ?? '') === 'accepted'; }));
        $incompleteTotal = count(array_filter($submissions, function($s) { return ($s['status'] ?? '') === 'submitted'; }));
        $needsEditsTotal = count(array_filter($submissions, function($s) { return ($s['status'] ?? '') === 'needs_edits'; }));
        $rejectedTotal = count(array_filter($submissions, function($s) { return ($s['status'] ?? '') === 'rejected'; }));

        $panelMap = [
          'all' => ['label' => 'All', 'count' => $allTotal],
          'accepted' => ['label' => 'Accepted', 'count' => $acceptedTotal],
          'submitted' => ['label' => 'Submitted', 'count' => $incompleteTotal],
          'needs_edits' => ['label' => 'Needs Edits', 'count' => $needsEditsTotal],
          'rejected' => ['label' => 'Rejected', 'count' => $rejectedTotal],
        ];

        $panelData = [
          'all' => sort_submissions($submissions, $sort, $dir),
          'accepted' => sort_submissions(array_values(array_filter($submissions, static function (array $s): bool { return (string) ($s['status'] ?? '') === 'accepted'; })), $sort, $dir),
          'submitted' => sort_submissions(array_values(array_filter($submissions, static function (array $s): bool { return (string) ($s['status'] ?? '') === 'submitted'; })), $sort, $dir),
          'needs_edits' => sort_submissions(array_values(array_filter($submissions, static function (array $s): bool { return (string) ($s['status'] ?? '') === 'needs_edits'; })), $sort, $dir),
          'rejected' => sort_submissions(array_values(array_filter($submissions, static function (array $s): bool { return (string) ($s['status'] ?? '') === 'rejected'; })), $sort, $dir),
        ];

        $activeTab = array_key_exists($view, $panelMap) ? $view : 'all';
        $tabIndexMap = ['all' => 0, 'accepted' => 1, 'submitted' => 2, 'needs_edits' => 3, 'rejected' => 4];
        $activeIndex = $tabIndexMap[$activeTab];
      ?>

      <div class="dash">
        <div class="dashboard-main">
          <div class="page-header">
            <div>
              <div class="page-title">Your Dashboard</div>
              <div class="page-sub">Track and manage your manuscript submissions</div>
            </div>
            <a class="dashboard-btn" href="<?php echo e(user_dashboard_url(['view' => 'submit'])); ?>">
              <i class="ti ti-plus" aria-hidden="true"></i> New Manuscript
            </a>
          </div>

          
          <div class="dashboard-controls" style="margin-bottom: 20px;">
            <div class="tabs em-tabs" role="tablist">
              <?php foreach ($panelMap as $key => $data): ?>
                <button 
                  type="button"
                  class="tab em-tab <?php echo $activeTab === $key ? 'active' : ''; ?>" 
                  role="tab" 
                  aria-selected="<?php echo $activeTab === $key ? 'true' : 'false'; ?>"
                  onclick="filterSubmissions('<?php echo htmlspecialchars($key); ?>', this); return false;"
                >
                  <?php echo e($data['label']); ?> <span class="em-tab-count"><?php echo e((string) $data['count']); ?></span>
                </button>
              <?php endforeach; ?>
            </div>
          </div>

          <div style="overflow-x: auto; margin-bottom: 40px; box-shadow: 0 4px 12px rgba(0,0,0,0.05);">
            <table class="em-table">
              <thead>
                <tr>
                  <th style="width: 180px;">Action <i class="fa fa-minus-square"></i> <i class="fa fa-filter"></i></th>
                  <th style="width: 15%;">Tracking ID <i class="fa fa-caret-up"></i></th>
                  <th style="width: 25%;">Title <i class="fa fa-caret-up"></i></th>
                  <th style="width: 15%;">Date Submission Began <i class="fa fa-caret-down"></i></th>
                  <th style="width: 15%;">Status Date <i class="fa fa-caret-up"></i></th>
                  <th style="width: 12%;">Current Status <i class="fa fa-caret-up"></i></th>
                </tr>
              </thead>
              <tbody>
                <?php $currentPanelSubmissions = $panelData['all'] ?? []; ?>
                <?php foreach ($currentPanelSubmissions as $paper): ?>
                  <?php
                    $sid = (int) ($paper['id'] ?? 0);
                    $paperTitle = (string) ($paper['title'] ?? '(Title not yet Supplied)');
                    if (trim($paperTitle) === '') $paperTitle = '(Title not yet Supplied)';
                    
                    $paperAbstract = (string) ($paper['abstract'] ?? '');
                    if (trim($paperAbstract) === '') $paperAbstract = 'No abstract available.';
                    
                    $st = (string) ($paper['status'] ?? 'submitted');
                    $isDeleted = !empty($paper['is_deleted']) || $st === 'deleted';
                    
                    $statusDate = submission_status_date($paper);
                    $createdDate = (new DateTimeImmutable((string) ($paper['created_at'] ?? 'now')))->format('M j, Y');
                    $statusLabelMap = [
                      'submitted' => 'Submitted',
                      'under_review' => 'Under Review',
                      'needs_edits' => 'Needs Edits',
                      'accepted' => 'Accepted',
                      'rejected' => 'Rejected',
                      'deleted' => 'Deleted'
                    ];
                    $stLabel = $isDeleted ? 'Deleted' : ($statusLabelMap[$st] ?? 'Submitted');
                    
                    $stColorMap = [
                      'submitted' => '#0055aa',
                      'under_review' => '#d97706',
                      'needs_edits' => '#b45309',
                      'accepted' => '#15803d',
                      'rejected' => '#b91c1c',
                      'deleted' => '#666666'
                    ];
                    $stColor = $stColorMap[$isDeleted ? 'deleted' : $st] ?? '#333';
                    
                    if ($isDeleted) {
                        $rowStyle = 'opacity: 0.5; background-color: #f0f0f0; filter: grayscale(100%); pointer-events: none;';
                        $textOpacity = '';
                    } elseif ($st === 'rejected') {
                        $rowStyle = 'background-color: #fcfcfc;';
                        $textOpacity = 'opacity: 0.6;';
                    } else {
                        $rowStyle = '';
                        $textOpacity = '';
                    }
                  ?>
                  <tr class="submission-row" data-category="<?php echo htmlspecialchars($st); ?>" style="<?php echo $rowStyle; ?><?php echo ($activeTab !== 'all' && $st !== $activeTab) ? ' display: none;' : ''; ?>">
                    <td>
                      <?php if ($isDeleted): ?>
                        <a href="#" class="action-link action-link-disabled" onclick="return false;">View Submission</a>
                        <a href="#" class="action-link action-link-disabled" onclick="return false;">Edit Submission</a>
                        <a href="#" class="action-link action-link-disabled" onclick="return false;">View Letter</a>
                        <a href="#" class="action-link action-link-disabled" onclick="return false;">Chat</a>
                      <?php else: ?>
                        <!-- View Submission -->
                        <a href="#" class="action-link" onclick="event.preventDefault(); openSubmissionModal(<?php echo $sid; ?>)">View Submission</a>
                        
                        <!-- Edit Submission -->
                        <?php if ($st !== 'rejected'): ?>
                          <?php if ($st === 'needs_edits'): ?>
                            <a href="edit-submission.php?id=<?php echo $sid; ?>" class="action-link">Edit Submission</a>
                          <?php else: ?>
                            <a href="#" class="action-link action-link-disabled" onclick="return false;">Edit Submission</a>
                          <?php endif; ?>
                        <?php endif; ?>

                        <!-- View Letter -->
                        <?php if (in_array($st, ['rejected', 'accepted', 'needs_edits'], true)): ?>
                          <a href="#" class="action-link" onclick="event.preventDefault(); openLetterModal(<?php echo $sid; ?>)">View Letter</a>
                        <?php else: ?>
                          <a href="#" class="action-link action-link-disabled" onclick="return false;">View Letter</a>
                        <?php endif; ?>

                        <!-- Chat -->
                        <?php $chatTitle = trim((string) ($paper['title'] ?? '')) !== '' ? trim((string) $paper['title']) : 'Untitled'; ?>
                        <?php $unreadCnt = $unreadUserCounts[$sid] ?? 0; ?>
                        <?php $chatLabel = $unreadCnt > 0 ? "Chat ($unreadCnt)" : "Chat"; ?>
                        <a href="#" class="action-link" style="color: #0284c7;" data-chat-id="<?php echo $sid; ?>" data-chat-title="<?php echo htmlspecialchars($chatTitle, ENT_QUOTES, 'UTF-8'); ?>" onclick="event.preventDefault(); openChatModal(this.dataset.chatId, this.dataset.chatTitle);"><i class="fa fa-comments" aria-hidden="true"></i> <?php echo e($chatLabel); ?></a>

                        <!-- Remove Submission (Only for early stages) -->
                        <?php if (in_array($st, ['submitted', 'under_review'], true)): ?>
                          <form method="post" action="user-dashboard.php" style="display: none;" id="deleteSubmissionForm-<?php echo $sid; ?>">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="delete_submission">
                            <input type="hidden" name="submission_id" value="<?php echo $sid; ?>">
                          </form>
                          <a href="#" class="action-link" onclick="event.preventDefault(); openDeleteModal(<?php echo $sid; ?>)">Remove Submission</a>
                        <?php endif; ?>
                      <?php endif; ?>
                    </td>
                    <td style="<?php echo $textOpacity; ?>; font-weight: bold;"><?php echo e(trim((string) ($paper['tracking_id'] ?? '')) ?: 'Not Assigned'); ?></td>
                    <td style="<?php echo $textOpacity; ?>">
                      <div style="font-weight: bold; margin-bottom: 6px; font-size: 14px;"><?php echo e($paperTitle); ?></div>
                      <div style="font-size: 12px; color: #555; line-height: 1.4;"><?php echo e(mb_strlen($paperAbstract) > 240 ? mb_substr($paperAbstract, 0, 240) . '...' : $paperAbstract); ?></div>
                    </td>
                    <td style="<?php echo $textOpacity; ?>"><?php echo e($createdDate); ?></td>
                    <td style="<?php echo $textOpacity; ?>"><?php echo e((new DateTimeImmutable($statusDate))->format('M j, Y')); ?></td>
                    <td><span style="color: <?php echo $stColor; ?>; font-weight: bold; padding: 4px 8px; border-radius: 0; background: <?php echo $stColor; ?>15;"><?php echo e($stLabel); ?></span></td>
                  </tr>
                <?php endforeach; ?>
                <?php $isEmptyInitial = count($panelData[$activeTab] ?? []) === 0; ?>
                <tr id="empty-submissions-row" style="<?php echo $isEmptyInitial ? '' : 'display: none;'; ?>">
                  <td colspan="5" style="text-align: center; padding: 40px; color: #555;">
                    No submissions found in this category.
                  </td>
                </tr>
              </tbody>
            </table>
          </div>


        </div>

        <!-- Render Modals outside of the table structure -->
          <div class="modals-container">
          <?php foreach ($submissions as $paper): ?>
            <?php
              $sid = (int) ($paper['id'] ?? 0);
              $history = (is_array($submissionVersionHistory[$sid] ?? null)) ? $submissionVersionHistory[$sid] : [];
              $version = (int) ($paper['version'] ?? 1);
              $paperAttachments = $submissionAttachments[$sid][$version] ?? [];
              $submitterName = trim((string) ($profile['name'] ?? ($user['name'] ?? ($user['email'] ?? 'You'))));
              $submitterEmail = trim((string) ($profile['email'] ?? ($user['email'] ?? '')));
            ?>
            <?php echo render_submission_modal($paper, $history, $paperAttachments, $submitterName, $submitterEmail); ?>
            <?php echo render_letter_modal($paper); ?>
          <?php endforeach; ?>

        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="modal-veil" id="modalVeil">
    <div class="modal-box">
      <h3 id="mTitle"></h3>
      <p id="mBody"></p>
      <div class="modal-btns" id="mBtns">
      </div>
    </div>
  </div>

  <script src="js/jquery.min.js"></script>
  <script src="js/tether.min.js" crossorigin="anonymous"></script>
  <script src="js/bootstrap.min.js" crossorigin="anonymous"></script>
  <script>
    function closeModalVeil() {
      document.getElementById('modalVeil').classList.remove('open');
    }

    function alertModal(title, message) {
      document.getElementById('mTitle').textContent = title;
      document.getElementById('mBody').textContent = message;
      
      const btns = document.getElementById('mBtns');
      btns.innerHTML = `
        <button class="dashboard-btn" type="button" onclick="closeModalVeil()">OK</button>
      `;
      
      document.getElementById('modalVeil').classList.add('open');
    }

    function openDeleteModal(sid) {
      document.getElementById('mTitle').textContent = 'Delete Submission';
      document.getElementById('mBody').textContent = 'Are you sure you want to permanently delete this submission? This action cannot be undone.';
      
      const btns = document.getElementById('mBtns');
      btns.innerHTML = `
        <button class="dashboard-btn" type="button" onclick="closeModalVeil()">Cancel</button>
        <button class="dashboard-btn" style="background: #d32f2f; color: white; border-color: #d32f2f;" type="button" onclick="submitDeleteForm(${sid})">Confirm Delete</button>
      `;
      
      document.getElementById('modalVeil').classList.add('open');
    }

    function submitDeleteForm(sid) {
      var formId = 'deleteSubmissionForm-' + sid;
      var forms = document.querySelectorAll('#' + formId);
      if (forms.length > 0) {
          HTMLFormElement.prototype.submit.call(forms[0]);
      } else {
          alert("Delete form not found for ID: " + formId);
      }
    }

    function confirmDiscardDraft(el, autoSaveKey) {
      document.getElementById('mTitle').textContent = 'Discard Draft';
      document.getElementById('mBody').textContent = 'Are you sure you want to discard this draft?';
      
      const btns = document.getElementById('mBtns');
      btns.innerHTML = `
        <button class="dashboard-btn" type="button" onclick="closeModalVeil()">Cancel</button>
        <button class="dashboard-btn" style="background: #d32f2f; color: white; border-color: #d32f2f;" type="button" onclick="executeDiscardDraft('${autoSaveKey}')">Discard</button>
      `;
      
      window.currentDraftRow = el.closest('tr');
      document.getElementById('modalVeil').classList.add('open');
    }

    function executeDiscardDraft(autoSaveKey) {
      localStorage.removeItem(autoSaveKey);
      if (window.currentDraftRow) {
        window.currentDraftRow.remove();
        window.currentDraftRow = null;
      }
      closeModalVeil();
    }

    function switchTab(idx, el) {
      document.querySelectorAll('.tab').forEach(function (tab) {
        tab.classList.remove('active');
        tab.setAttribute('aria-selected', 'false');
      });
      document.querySelectorAll('.tab-panel').forEach(function (panel) {
        panel.classList.remove('active');
      });
      if (el) {
        el.classList.add('active');
        el.setAttribute('aria-selected', 'true');
      }
      var panel = document.getElementById('panel-' + idx);
      if (panel) {
        panel.classList.add('active');
      }
    }
  </script>
  
  <script>
    function filterSubmissions(category, btnElement) {
      document.querySelectorAll('.em-tabs .em-tab').forEach(tab => {
        tab.classList.remove('active');
        tab.setAttribute('aria-selected', 'false');
      });
      if (btnElement) {
        btnElement.classList.add('active');
        btnElement.setAttribute('aria-selected', 'true');
      }

      let visibleCount = 0;
      document.querySelectorAll('.submission-row').forEach(row => {
        if (category === 'all' || row.getAttribute('data-category') === category) {
          row.style.display = '';
          visibleCount++;
        } else {
          row.style.display = 'none';
        }
      });

      const emptyRow = document.getElementById('empty-submissions-row');
      if (visibleCount === 0) {
        if (emptyRow) emptyRow.style.display = '';
      } else {
        if (emptyRow) emptyRow.style.display = 'none';
      }
      
      const url = new URL(window.location);
      url.searchParams.set('view', category);
      window.history.pushState({}, '', url);
    }

    function applyFilters() {
      const query = document.getElementById('paperSearch') ? document.getElementById('paperSearch').value.toLowerCase() : '';
      document.querySelectorAll('.workspace-table tbody tr.workspace-row').forEach(row => {
        const searchData = row.getAttribute('data-search') || '';
        if (query === '' || searchData.includes(query)) {
          row.style.display = '';
        } else {
          row.style.display = 'none';
        }
      });
    }

    function openSubmissionModal(id) {
      var el = document.getElementById('submission-modal-' + id);
      if (!el) return;
      el.classList.add('open');
      document.body.classList.add('modal-open');
      el.setAttribute('aria-hidden', 'false');
    }
    function closeSubmissionModal(id) {
      var el = document.getElementById('submission-modal-' + id);
      if (!el) return;
      el.classList.remove('open');
      document.body.classList.remove('modal-open');
      el.setAttribute('aria-hidden', 'true');
    }

    function openLetterModal(id) {
      var el = document.getElementById('letter-modal-' + id);
      if (!el) return;
      el.classList.add('open');
      document.body.classList.add('modal-open');
      el.setAttribute('aria-hidden', 'false');
    }
    function closeLetterModal(id) {
      var el = document.getElementById('letter-modal-' + id);
      if (!el) return;
      el.classList.remove('open');
      document.body.classList.remove('modal-open');
      el.setAttribute('aria-hidden', 'true');
    }

    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        document.querySelectorAll('.gysj-modal.open').forEach(function(m) {
          m.classList.remove('open');
        });
        document.body.classList.remove('modal-open');
      }
    });

    function validateSubmitStep(step) {
      if (step === 1) {
        var guidelines = document.getElementById('submitGuidelines');
        var errGuidelines = document.getElementById('submitErrGuidelines');
        if (guidelines && !guidelines.checked) {
          errGuidelines.style.display = 'block';
          return false;
        } else if (errGuidelines) {
          errGuidelines.style.display = 'none';
        }

        var countrySearch = document.getElementById('submitCountrySearch');
        var countrySelect = document.getElementById('submitCountry');
        var errCountry = document.getElementById('submitErrCountry');
        if (countrySelect && !countrySelect.value) {
          errCountry.style.display = 'block';
          return false;
        } else if (errCountry) {
          errCountry.style.display = 'none';
        }

        var journalChecked = document.querySelector('input[name="journal"]:checked');
        if (!journalChecked) {
          alertModal('Validation Error', 'Please select a Research Journal.');
          return false;
        }
      } else if (step === 2) {
        var fileInput = document.getElementById('submitManuscriptInput');
        if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
          alertModal('Validation Error', 'Please upload at least one valid manuscript file.');
          return false;
        }
      } else if (step === 3) {
        var title = document.querySelector('input[name="title"]');
        var abstract = document.querySelector('textarea[name="abstract"]');
        if (!title.value.trim() || !abstract.value.trim()) {
          alertModal('Validation Error', 'Please fill out all mandatory paper details (Title, Abstract).');
          return false;
        }
      } else if (step === 4) {
        var authors = getAuthorCards();
        if (authors.length === 0) {
          alertModal('Validation Error', 'You must add at least one author.');
          return false;
        }
        for (var i = 0; i < authors.length; i++) {
          var card = authors[i];
          if (card.dataset.saved !== "true") {
            alertModal('Validation Error', 'Please save Author ' + (i + 1) + ' before proceeding.');
            return false;
          }
          var reqFields = card.querySelectorAll('input[required], textarea[required]');
          for (var j = 0; j < reqFields.length; j++) {
            if (!reqFields[j].value.trim()) {
              alertModal('Validation Error', 'Please fill out all starred (*) details for Author ' + (i + 1) + '.');
              return false;
            }
          }
        }
      }
      return true;
    }

    function nextSubmitStep(step) {
      if (step > 1 && !validateSubmitStep(step - 1)) {
        return;
      }
      document.querySelectorAll('.submit-panel').forEach(function(el) {
        el.style.display = 'none';
        el.classList.remove('active');
      });
      var nextPanel = document.getElementById('submitPanel' + step);
      if (nextPanel) {
        nextPanel.style.display = 'block';
        nextPanel.classList.add('active');
      }
      syncSubmitStepper(step);
      window.scrollTo(0, 0);
    }

    function prevSubmitStep(step) {
      document.querySelectorAll('.submit-panel').forEach(function(el) {
        el.style.display = 'none';
        el.classList.remove('active');
      });
      var prevPanel = document.getElementById('submitPanel' + step);
      if (prevPanel) {
        prevPanel.style.display = 'block';
        prevPanel.classList.add('active');
      }
      syncSubmitStepper(step);
      window.scrollTo(0, 0);
    }

    function syncSubmitStepper(step) {
      var activeStep = parseInt(step, 10) || 1;
      document.querySelectorAll('.submit-step').forEach(function(el) {
        var stepNumber = parseInt(el.getAttribute('data-step'), 10) || 0;
        el.classList.toggle('active', stepNumber <= activeStep);
        el.classList.toggle('done', stepNumber < activeStep);
      });
      document.querySelectorAll('.submit-step-line').forEach(function(line) {
        var lineNumber = parseInt(line.getAttribute('data-line'), 10) || 0;
        line.classList.toggle('done', lineNumber < activeStep);
      });
    }

    function initManuscriptUploadUI() {
      var input = document.getElementById('submitManuscriptInput');
      var browseBtn = document.getElementById('submitManuscriptBrowse');
      var dropZone = document.getElementById('submitManuscriptDropZone');
      var attachmentsPanel = document.getElementById('submitManuscriptAttachments');
      var attachmentWarning = document.getElementById('submitAttachmentWarning');
      var grid = document.getElementById('submitAttachmentsGrid');
      var subtitle = document.getElementById('submitAttachmentsSubtitle');
      
      if (!input || !browseBtn || !dropZone || !attachmentsPanel || !grid) return;

      var dt = typeof DataTransfer !== 'undefined' ? new DataTransfer() : null;

      function isSupportedFile(file) {
        if (!file) return false;
        var fileName = (file.name || '').toLowerCase();
        var fileType = (file.type || '').toLowerCase();
        return fileName.endsWith('.docx') || fileName.endsWith('.png') || fileName.endsWith('.pdf') ||
          fileType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' ||
          fileType === 'image/png' ||
          fileType === 'application/pdf' ||
          fileType === 'application/x-pdf' ||
          fileType === 'application/acrobat';
      }

      function renderGrid() {
        var files = input.files;
        var html = `
          <div class="submit-upload-attachments-headcell">Order</div>
          <div class="submit-upload-attachments-headcell">Item</div>
          <div class="submit-upload-attachments-headcell">Description</div>
          <div class="submit-upload-attachments-headcell">File Name</div>
          <div class="submit-upload-attachments-headcell">Size</div>
          <div class="submit-upload-attachments-headcell">Actions</div>
          <div class="submit-upload-attachments-headcell">Select</div>
        `;
        
        var hasPdf = false;
        if (files && files.length > 0) {
          dropZone.classList.add('has-file');
          attachmentsPanel.classList.add('open');
          if (subtitle) subtitle.textContent = files.length + (files.length === 1 ? ' item selected' : ' items selected');
          
          for (var i = 0; i < files.length; i++) {
            var file = files[i];
            var sizeKb = file.size ? Math.max(1, Math.round(file.size / 1024)) : 0;
            var fileName = (file.name || '').toLowerCase();
            var fileType = (file.type || '').toLowerCase();
            var isPdf = fileName.endsWith('.pdf') || fileType === 'application/pdf';
            if (isPdf) hasPdf = true;
            
            var defaultType = isPdf ? 'Supplementary File' : (i === 0 ? 'Manuscript' : 'Supplementary File');
            
            html += `
              <div class="submit-upload-attachment-order">${i + 1}</div>
              <div>
                <select class="submit-upload-attachment-type" name="attachment_item_types[]">
                  <option value="Manuscript" ${defaultType === 'Manuscript' ? 'selected' : ''}>Manuscript</option>
                  <option value="Figure" ${defaultType === 'Figure' ? 'selected' : ''}>Figure</option>
                  <option value="Supplementary File" ${defaultType === 'Supplementary File' ? 'selected' : ''}>Supplementary File</option>
                  <option value="Other" ${defaultType === 'Other' ? 'selected' : ''}>Other</option>
                </select>
              </div>
              <div>
                <input type="text" class="submit-upload-attachment-desc" name="attachment_descriptions[]" placeholder="Short description of this attachment">
              </div>
              <div class="submit-upload-attachment-file">${file.name}</div>
              <div class="submit-upload-attachment-size">${sizeKb} KB</div>
              <div class="submit-upload-attachment-actions">
                <button type="button" class="submit-upload-attachment-link" onclick="removeAttachment(${i})">Remove</button>
              </div>
              <div class="submit-upload-attachment-select">
                <input type="checkbox" checked disabled>
              </div>
            `;
          }
        } else {
          dropZone.classList.remove('has-file');
          attachmentsPanel.classList.remove('open');
          if (subtitle) subtitle.textContent = '0 items selected';
          input.value = '';
        }
        
        grid.innerHTML = html;
        if (attachmentWarning) {
          if (hasPdf) attachmentWarning.classList.add('on');
          else attachmentWarning.classList.remove('on');
        }
        
        if (typeof input.setCustomValidity === 'function') {
           input.setCustomValidity(files && files.length > 0 ? '' : 'No file selected.');
        }
      }

      window.removeAttachment = function(index) {
        if (!dt) return;
        var newDt = new DataTransfer();
        for (var i = 0; i < dt.files.length; i++) {
          if (i !== index) newDt.items.add(dt.files[i]);
        }
        dt = newDt;
        input.files = dt.files;
        renderGrid();
      };

      function handleFiles(files) {
        var invalid = false;
        if (dt) {
          for (var i = 0; i < files.length; i++) {
            if (isSupportedFile(files[i])) {
              dt.items.add(files[i]);
            } else {
              invalid = true;
            }
          }
          input.files = dt.files;
        } else {
          // Fallback if DataTransfer is not supported
          input.files = files;
        }
        if (invalid && typeof input.setCustomValidity === 'function') {
          input.setCustomValidity('Some files were ignored because they are not DOCX, PNG, or PDF.');
          input.reportValidity();
        }
        renderGrid();
      }

      browseBtn.addEventListener('click', function() { input.click(); });
      
      input.addEventListener('change', function() {
        if (dt) {
          // Input files are replaced on change by default. 
          // We must merge the new ones and clear the input's default reset behavior
          var newFiles = input.files;
          for (var i = 0; i < newFiles.length; i++) {
             if (isSupportedFile(newFiles[i])) dt.items.add(newFiles[i]);
          }
          input.files = dt.files;
        }
        renderGrid();
      });

      ['dragenter', 'dragover'].forEach(function(eventName) {
        dropZone.addEventListener(eventName, function(event) {
          event.preventDefault();
          event.stopPropagation();
          dropZone.classList.add('over');
        });
      });

      ['dragleave', 'dragend', 'drop'].forEach(function(eventName) {
        dropZone.addEventListener(eventName, function(event) {
          event.preventDefault();
          event.stopPropagation();
          dropZone.classList.remove('over');
        });
      });

      dropZone.addEventListener('drop', function(event) {
        var files = event.dataTransfer && event.dataTransfer.files ? event.dataTransfer.files : [];
        if (files.length > 0) {
          handleFiles(files);
        }
      });

      renderGrid();
    }

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
          return parts.join('\n');
        }).join('\n\n');
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

    function initCountrySearch() {
      var GYSJ_COUNTRIES = ["Afghanistan","Albania","Algeria","Andorra","Angola","Antigua and Barbuda","Argentina","Armenia","Australia","Austria","Azerbaijan","Bahamas","Bahrain","Bangladesh","Barbados","Belarus","Belgium","Belize","Benin","Bhutan","Bolivia","Bosnia and Herzegovina","Botswana","Brazil","Brunei","Bulgaria","Burkina Faso","Burundi","Cabo Verde","Cambodia","Cameroon","Canada","Central African Republic","Chad","Chile","China","Colombia","Comoros","Congo","Costa Rica","Croatia","Cuba","Cyprus","Czech Republic","Democratic Republic of the Congo","Denmark","Djibouti","Dominica","Dominican Republic","Ecuador","Egypt","El Salvador","Equatorial Guinea","Eritrea","Estonia","Eswatini","Ethiopia","Fiji","Finland","France","Gabon","Gambia","Georgia","Germany","Ghana","Greece","Grenada","Guatemala","Guinea","Guinea-Bissau","Guyana","Haiti","Honduras","Hungary","Iceland","India","Indonesia","Iran","Iraq","Ireland","Israel","Italy","Jamaica","Japan","Jordan","Kazakhstan","Kenya","Kiribati","Kuwait","Kyrgyzstan","Laos","Latvia","Lebanon","Lesotho","Liberia","Libya","Liechtenstein","Lithuania","Luxembourg","Madagascar","Malawi","Malaysia","Maldives","Mali","Malta","Marshall Islands","Mauritania","Mauritius","Mexico","Micronesia","Moldova","Monaco","Mongolia","Montenegro","Morocco","Mozambique","Myanmar","Namibia","Nauru","Nepal","Netherlands","New Zealand","Nicaragua","Niger","Nigeria","North Korea","North Macedonia","Norway","Oman","Pakistan","Palau","Palestine State","Panama","Papua New Guinea","Paraguay","Peru","Philippines","Poland","Portugal","Qatar","Romania","Russia","Rwanda","Saint Kitts and Nevis","Saint Lucia","Saint Vincent and the Grenadines","Samoa","San Marino","Sao Tome and Principe","Saudi Arabia","Senegal","Serbia","Seychelles","Sierra Leone","Singapore","Slovakia","Slovenia","Solomon Islands","Somalia","South Africa","South Korea","South Sudan","Spain","Sri Lanka","Sudan","Suriname","Sweden","Switzerland","Syria","Tajikistan","Tanzania","Thailand","Timor-Leste","Togo","Tonga","Trinidad and Tobago","Tunisia","Turkey","Turkmenistan","Tuvalu","Uganda","Ukraine","United Arab Emirates","United Kingdom","United States of America","Uruguay","Uzbekistan","Vanuatu","Venezuela","Vietnam","Yemen","Zambia","Zimbabwe"];
      var searchInput = document.getElementById('submitCountrySearch');
      var listWrapper = document.getElementById('submitCountryListWrapper');
      var hiddenSelect = document.getElementById('submitCountry');
      if (!searchInput || !listWrapper || !hiddenSelect) return;

      var preselected = hiddenSelect.getAttribute('data-selected') || '';

      function renderList(filterText) {
        listWrapper.innerHTML = '';
        var count = 0;
        GYSJ_COUNTRIES.forEach(function(c) {
          if (c.toLowerCase().indexOf(filterText.toLowerCase()) > -1) {
            count++;
            var item = document.createElement('div');
            item.className = 'submit-country-item';
            item.textContent = c;
            item.onclick = function() {
              searchInput.value = c;
              hiddenSelect.innerHTML = '<option value="' + c + '" selected>' + c + '</option>';
              hiddenSelect.value = c;
              listWrapper.style.display = 'none';
              var errCountry = document.getElementById('submitErrCountry');
              if (errCountry) errCountry.style.display = 'none';
            };
            listWrapper.appendChild(item);
          }
        });
        listWrapper.style.display = count > 0 ? 'block' : 'none';
      }

      searchInput.addEventListener('focus', function() {
        renderList(this.value);
      });

      searchInput.addEventListener('input', function() {
        hiddenSelect.value = '';
        hiddenSelect.innerHTML = '';
        renderList(this.value);
      });

      document.addEventListener('click', function(e) {
        if (e.target !== searchInput && !listWrapper.contains(e.target)) {
          listWrapper.style.display = 'none';
        }
      });

      if (preselected) {
        searchInput.value = preselected;
        hiddenSelect.innerHTML = '<option value="' + preselected + '" selected>' + preselected + '</option>';
        hiddenSelect.value = preselected;
      }
    }

    function initJournalSelection() {
      var radios = document.querySelectorAll('input[name="journal"]');
      radios.forEach(function(radio) {
        radio.addEventListener('change', function() {
          document.querySelectorAll('#submitJournalList .submit-choice').forEach(function(label) {
            label.classList.remove('sel');
          });
          if (this.checked) {
            var label = this.closest('.submit-choice');
            if (label) label.classList.add('sel');
          }
        });
      });
      

    }

    document.addEventListener('DOMContentLoaded', function() {
      var form = document.getElementById('submitWizardForm');
      var autoSaveKey = 'gysj_draft_submission';

      // 1. Dashboard Logic: Inject Draft Row if on main dashboard
      var tbody = document.querySelector('.em-table tbody');
      if (tbody && !form) {
        var draft = localStorage.getItem(autoSaveKey);
        if (draft) {
          try {
            var data = JSON.parse(draft);
            var title = data.title || '(Draft Submission)';
            var tr = document.createElement('tr');
            tr.className = 'submission-row';
            tr.innerHTML = `
              <td>
                <a href="user-dashboard.php?view=submit&resume_draft=1" class="action-link"><i class="fa fa-pencil"></i> Resume Submission</a>
                <a href="#" class="action-link" onclick="event.preventDefault(); confirmDiscardDraft(this, '${autoSaveKey}');"><i class="fa fa-trash-o"></i> Discard Draft</a>
              </td>
              <td style="font-weight: bold; color: #888;">Not Assigned</td>
              <td>
                <div style="font-weight: bold; margin-bottom: 6px; font-size: 14px;">${title}</div>
                <div style="font-size: 12px; color: #555; line-height: 1.4;">Unsaved local draft</div>
              </td>
              <td>-</td>
              <td>-</td>
              <td><span style="color: #666; font-weight: bold; padding: 4px 8px; border-radius: 0; background: #eee;">Draft (Unsaved)</span></td>
            `;
            tbody.insertBefore(tr, tbody.firstChild);
          } catch(e) {}
        }
      }

      // 2. Submit Form Logic: Auto-Save Engine
      if (form) {
        form.addEventListener('submit', function(e) {
          syncAuthorPayload();
          var settingChecked = document.querySelectorAll('input[name="setting[]"]:checked').length > 0;
          var agesChecked = document.querySelectorAll('input[name="ages[]"]:checked').length > 0;
          var schoolTypeChecked = document.querySelectorAll('input[name="school_type[]"]:checked').length > 0;
          
          if (!settingChecked || !agesChecked || !schoolTypeChecked) {
            e.preventDefault();
            alertModal('Validation Error', 'Please select at least one option for Setting, Student Ages, and School Type.');
          }
        });
      }

      syncSubmitStepper(1);
      initManuscriptUploadUI();
      
      // Auto-load draft before hydrating authors
      if (form) {
        var urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('resume_draft') === '1') {
          var draft = localStorage.getItem(autoSaveKey);
          if (draft) {
            try {
              var data = JSON.parse(draft);
              for (var key in data) {
                if (key === 'authors_payload') {
                  var payloadField = document.getElementById('submitAuthorsPayload');
                  if (payloadField) payloadField.value = data[key];
                } else if (Array.isArray(data[key])) {
                  var checkboxes = form.querySelectorAll('input[name="' + key + '"]');
                  checkboxes.forEach(function(cb) { cb.checked = data[key].includes(cb.value); });
                } else {
                  var fields = form.querySelectorAll('[name="' + key + '"]');
                  fields.forEach(function(field) {
                    if (field.type !== 'file') {
                      if (field.type === 'checkbox' || field.type === 'radio') {
                        field.checked = (field.value === data[key]);
                      } else {
                        field.value = data[key];
                      }
                    }
                  });
                }
              }
            } catch(e) {}
          }
        }
      }

      hydrateAuthorCards();
      initCountrySearch();
      initJournalSelection();

      if (form) {
        function saveDraft() {
          syncAuthorPayload();
          var formData = new FormData(form);
          var data = {};
          formData.forEach(function(value, key) {
            if (key === 'manuscripts[]' || key === 'manuscript') return;
            if (data[key]) {
              if (!Array.isArray(data[key])) data[key] = [data[key]];
              data[key].push(value);
            } else {
              data[key] = value;
            }
          });
          localStorage.setItem(autoSaveKey, JSON.stringify(data));
        }
        setInterval(saveDraft, 3000);
        form.addEventListener('change', saveDraft);
        form.addEventListener('keyup', saveDraft);
      }
    });

    <?php if (($success ?? '') === 'Submission uploaded successfully.'): ?>
      localStorage.removeItem('gysj_draft_submission');
    <?php endif; ?>
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
      const csrfToken = document.querySelector('#chatForm input[name="csrf_token"]') ? document.querySelector('#chatForm input[name="csrf_token"]').value : '';
      if (csrfToken) formData.append('csrf_token', csrfToken);
      
      fetch('user-dashboard.php', {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          window._currentIsTyping = data.is_typing || false;
          renderChatMessages(data.messages);
        }
      })
      .catch(err => console.error(err));
    }

    function renderChatMessages(messages) {
      const list = document.getElementById('chatMessageList');
      if (messages.length === 0) {
        list.innerHTML = '<div style="text-align:center; color:#94a3b8; padding:20px; font-style:italic;">No messages yet. Send a message to the editor!</div>';
        return;
      }
      
      let html = '';
      messages.forEach(msg => {
        const isUser = msg.sender_type === 'user';
        const justify = isUser ? 'flex-end' : 'flex-start';
        const bg = isUser ? '#e0f2fe' : '#f1f5f9';
        const align = isUser ? 'right' : 'left';
        
        html += '<div style="display:flex; justify-content:' + justify + '; margin-bottom: 12px;">';
        html += '<div style="max-width:80%; display:flex; flex-direction:column; align-items:' + (isUser ? 'flex-end' : 'flex-start') + ';">';
        html += '<div style="font-size:11px; color:#64748b; margin-bottom:4px; margin-left:2px; margin-right:2px;"><strong>' + escapeHtml(msg.sender_name) + '</strong> &bull; ' + escapeHtml(msg.created_at) + '</div>';
        html += '<div style="background:' + bg + '; padding:10px 14px; border-radius:0; font-size:14px; color:#334155; line-height:1.4; word-wrap:break-word;">' + escapeHtml(msg.message) + '</div>';
        html += '</div></div>';
      });
      if (window._currentIsTyping) {
        html += '<div style="display:flex; justify-content:flex-start; margin-bottom: 12px;">';
        html += '<div style="max-width:80%; display:flex; flex-direction:column; align-items:flex-start;">';
        html += '<div style="font-size:12px; color:#94a3b8; font-style:italic; padding:4px 0;">Editor is typing...</div>';
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
            fetch('user-dashboard.php', { method: 'POST', body: formData }).catch(err => console.error(err));
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
      const csrfToken = document.querySelector('#chatForm input[name="csrf_token"]') ? document.querySelector('#chatForm input[name="csrf_token"]').value : '';
      if (csrfToken) formData.append('csrf_token', csrfToken);
      formData.append('message', msg);
      
      fetch('user-dashboard.php', {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          input.value = '';
          loadChatMessages();
        }
      })
      .catch(err => console.error(err));
    }
    
    function escapeHtml(unsafe) {
      if (!unsafe) return '';
      return String(unsafe)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
    }
  </script>
</body>

</html>

