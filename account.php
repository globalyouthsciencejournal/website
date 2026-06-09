<?php
require_once __DIR__ . '/includes/bootstrap.php';

auth_require_login();
$user = auth_current_user();
if (!$user) {
    redirect('login.php');
}

error_log('PAGE LOAD: REQUEST_METHOD=' . $_SERVER['REQUEST_METHOD']);

$success = '';
$error = '';

try {
    $pdo = db();
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
        'assigned_journals_json' => '',

        'reviewer_experience_text' => '',
        'reviewer_reason_text' => '',
        'reviewer_weekly_availability' => '',
        'reviewer_profile_links' => '',
        'reviewer_cv_path' => '',
        'reviewer_cv_original_name' => '',
        'reviewer_cv_mime' => '',
        'reviewer_cv_size' => '',
        'reviewer_supporting_documents_json' => '',
        'reviewer_declaration_confirmed' => '',
    ];
}

    function account_decode_list($value): array
    {
      if (is_array($value)) {
        $items = [];
        foreach ($value as $item) {
          if (is_array($item)) {
            $label = trim((string) ($item['original'] ?? $item['name'] ?? $item['path'] ?? ''));
            if ($label === '' && isset($item['path'])) {
              $label = basename(str_replace('\\', '/', (string) $item['path']));
            }
            if ($label !== '') {
              $items[] = $label;
            }
            continue;
          }

          $item = trim((string) $item);
          if ($item !== '') {
            $items[] = $item;
          }
        }

        return $items;
      }

      $value = trim((string) $value);
      if ($value === '') {
        return [];
      }

      $decoded = json_decode($value, true);
      if (is_array($decoded)) {
          $items = [];
          foreach ($decoded as $item) {
            if (is_array($item)) {
              $label = trim((string) ($item['original'] ?? $item['name'] ?? $item['path'] ?? ''));
              if ($label === '' && isset($item['path'])) {
                $label = basename(str_replace('\\', '/', (string) $item['path']));
              }
              if ($label !== '') {
                $items[] = $label;
              }
              continue;
            }

            $item = trim((string) $item);
            if ($item !== '') {
              $items[] = $item;
            }
          }

          return $items;
      }

      return array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/', $value) ?: []), static function($item) { return $item !== ''; }));
    }

    function account_join_list($value, string $emptyLabel = 'Not set'): string
    {
      $items = account_decode_list($value);
      return empty($items) ? $emptyLabel : implode(', ', $items);
    }

    function account_render_file_label(string $path, string $originalName, string $emptyLabel = 'Not uploaded'): string
    {
      $path = trim($path);
      $originalName = trim($originalName);

      if ($path === '' && $originalName === '') {
        return $emptyLabel;
      }

      if ($originalName !== '') {
        return $originalName;
      }

      return basename(str_replace('\\', '/', $path));
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

$profile = profile_defaults($user);
if ($pdo instanceof PDO) {
    try {
        $profile = load_user_profile($pdo, (int) $user['id'], $profile);
    } catch (Throwable $e) {
        // Leave defaults.
    }
}

$isAdmin = strtolower(trim((string) ($user['role'] ?? ''))) === 'admin';

$activeSection = 'account';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $postedSection = trim((string) ($_POST['ui_section'] ?? ''));
  if (in_array($postedSection, ['account', 'personal', 'institution', 'address', 'security', 'admin'], true)) {
    $activeSection = $postedSection;
  }
} elseif (isset($_GET['section'])) {
  $querySection = trim((string) ($_GET['section'] ?? ''));
  if (in_array($querySection, ['account', 'personal', 'institution', 'address', 'security', 'admin'], true)) {
    $activeSection = $querySection;
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO) {
    csrf_validate();

    error_log('POST REQUEST RECEIVED: action=' . ($_POST['action'] ?? 'none') . ', username=' . ($_POST['username'] ?? 'none'));

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'update_admin_profile' || ($action === 'update_profile' && isset($_POST['admin_assigned_journals']))) {
        // Admin profile update - handle admin-specific fields only
        error_log('ADMIN PROFILE UPDATE TRIGGERED');
        if ((string) ($user['role'] ?? '') !== 'admin') {
            $error = 'You do not have permission to update admin profile.';
            error_log('ADMIN UPDATE ERROR: User is not admin');
        } else {
            $selectedJournals = [];
            if (is_array($_POST['admin_assigned_journals'] ?? null)) {
                $allOptions = ['Journal of Advance Research in Computer Science & Engineering', 'Journal of Advance Research in Mathematics & Mathematical Sciences', 'Journal of Advance Research in Applied Physics', 'Journal of Advance Research in Applied Chemistry', 'Journal of Advance Research in Civil Engineering', 'Journal of Advance Research in Mechanical Engineering', 'Journal of Advance Research in Business, Management & Accounting', 'Journal of Advance Research in Electronics & Communication Engineering', 'Journal of Advance Research in Humanities & Social Science', 'Journal of Advance Research (General)', 'Journal of Advance Research in Biology & Pharmacy', 'Journal of Advance Research in Environmental Science'];
                $allowedMap = array_fill_keys(array_merge(['All Fields'], $allOptions), true);
                foreach ($_POST['admin_assigned_journals'] as $journal) {
                    $journal = trim((string) $journal);
                    if ($journal !== '' && isset($allowedMap[$journal]) && !in_array($journal, $selectedJournals, true)) {
                        $selectedJournals[] = $journal;
                    }
                }
            }


            $experienceText = trim((string) ($_POST['admin_reviewer_experience_text'] ?? ''));
            $reasonText = trim((string) ($_POST['admin_reviewer_reason_text'] ?? ''));
            $profileLinks = trim((string) ($_POST['admin_reviewer_profile_links'] ?? ''));
            $weeklyAvailability = trim((string) ($_POST['admin_reviewer_weekly_availability'] ?? ''));
            $declarationConfirmed = isset($_POST['admin_declaration_confirmed']) ? 1 : 0;

            $journalsJson = empty($selectedJournals) ? null : json_encode($selectedJournals, JSON_UNESCAPED_UNICODE);
            

            try {
                $userId = (int) $user['id'];
                $params = [
                    ':reviewer_experience_text' => $experienceText !== '' ? $experienceText : null,
                    ':reviewer_reason_text' => $reasonText !== '' ? $reasonText : null,
                    ':reviewer_weekly_availability' => $weeklyAvailability !== '' ? $weeklyAvailability : null,
                    ':reviewer_profile_links' => $profileLinks !== '' ? $profileLinks : null,
                    ':reviewer_declaration_confirmed' => $declarationConfirmed,
                    ':assigned_journals_json' => $journalsJson,
                    ':id' => $userId,
                ];

                $sql = 'UPDATE users SET reviewer_experience_text = :reviewer_experience_text, reviewer_reason_text = :reviewer_reason_text,  reviewer_weekly_availability = :reviewer_weekly_availability, reviewer_profile_links = :reviewer_profile_links, reviewer_declaration_confirmed = :reviewer_declaration_confirmed, assigned_journals_json = :assigned_journals_json';

                // Handle CV upload
                if (is_array($_FILES['admin_cv'] ?? null) && (int) ($_FILES['admin_cv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $cvFile = $_FILES['admin_cv'];
                    $cvSize = (int) ($cvFile['size'] ?? 0);
                    $cvTmp = (string) ($cvFile['tmp_name'] ?? '');
                    $cvErr = (int) ($cvFile['error'] ?? UPLOAD_ERR_NO_FILE);

                    if ($cvErr === UPLOAD_ERR_OK && $cvSize > 0 && $cvSize <= 10 * 1024 * 1024 && is_file($cvTmp)) {
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $cvMime = (string) $finfo->file($cvTmp);

                        $cvAllowed = ['application/pdf' => 'pdf', 'application/x-pdf' => 'pdf', 'application/msword' => 'doc', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'];

                        if (isset($cvAllowed[$cvMime])) {
                            $uploadDir = __DIR__ . '/uploads/admin-cv';
                            if (!is_dir($uploadDir)) {
                                mkdir($uploadDir, 0755, true);
                            }

                            $ext = $cvAllowed[$cvMime];
                            $filename = 'admin-cv-' . $userId . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
                            $destination = $uploadDir . '/' . $filename;

                            if (move_uploaded_file($cvTmp, $destination)) {
                                $cvOriginal = (string) ($cvFile['name'] ?? 'cv.' . $ext);
                                $params[':reviewer_cv_path'] = 'uploads/admin-cv/' . $filename;
                                $params[':reviewer_cv_original_name'] = $cvOriginal;
                                $params[':reviewer_cv_mime'] = $cvMime;
                                $params[':reviewer_cv_size'] = $cvSize;
                                $sql .= ', reviewer_cv_path = :reviewer_cv_path, reviewer_cv_original_name = :reviewer_cv_original_name, reviewer_cv_mime = :reviewer_cv_mime, reviewer_cv_size = :reviewer_cv_size';
                            } else {
                                $error = 'Failed to save CV file.';
                            }
                        } else {
                            $error = 'CV must be PDF, DOC, or DOCX format.';
                        }
                    } elseif ($cvErr !== UPLOAD_ERR_NO_FILE) {
                        $error = 'CV file is invalid or too large.';
                    }
                }

                // Handle supporting documents
                if ($error === '' && is_array($_FILES['admin_supporting_documents'] ?? null)) {
                    $docs = $_FILES['admin_supporting_documents'];
                    if (is_array($docs['name'] ?? null) && !empty($docs['name'][0])) {
                        $supportDocs = [];
                        $supportAllowed = ['application/pdf' => 'pdf', 'application/x-pdf' => 'pdf', 'application/msword' => 'doc', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

                        $uploadDir = __DIR__ . '/uploads/admin-supporting';
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0755, true);
                        }

                        for ($i = 0; $i < count($docs['name']); $i++) {
                            $docErr = (int) ($docs['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                            if ($docErr === UPLOAD_ERR_NO_FILE) {
                                continue;
                            }
                            if ($docErr !== UPLOAD_ERR_OK) {
                                $error = 'Error uploading supporting document.';
                                break;
                            }

                            $docSize = (int) ($docs['size'][$i] ?? 0);
                            $docTmp = (string) ($docs['tmp_name'][$i] ?? '');
                            if ($docSize <= 0 || $docSize > 10 * 1024 * 1024 || !is_file($docTmp)) {
                                $error = 'Supporting document is invalid or too large.';
                                break;
                            }

                            $finfo = new finfo(FILEINFO_MIME_TYPE);
                            $docMime = (string) $finfo->file($docTmp);

                            if (!isset($supportAllowed[$docMime])) {
                                $error = 'Supporting documents must be PDF, DOC, DOCX, JPG, or PNG.';
                                break;
                            }

                            $ext = $supportAllowed[$docMime];
                            $filename = 'admin-support-' . $userId . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
                            $destination = $uploadDir . '/' . $filename;

                            if (move_uploaded_file($docTmp, $destination)) {
                                $supportDocs[] = [
                                    'path' => 'uploads/admin-supporting/' . $filename,
                                    'original' => (string) ($docs['name'][$i] ?? 'document.' . $ext),
                                    'mime' => $docMime,
                                    'size' => $docSize,
                                ];
                            } else {
                                $error = 'Failed to save supporting document.';
                                break;
                            }
                        }

                        if ($error === '' && !empty($supportDocs)) {
                            $params[':reviewer_supporting_documents_json'] = json_encode($supportDocs, JSON_UNESCAPED_UNICODE);
                            $sql .= ', reviewer_supporting_documents_json = :reviewer_supporting_documents_json';
                        }
                    }
                }

                if ($error === '') {
                    $sql .= ' WHERE id = :id';
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $success = 'Admin profile updated successfully.';
                    $profile = load_user_profile($pdo, $userId, $profile);
                    redirect('account.php?section=admin&updated=1');
                }
            } catch (Throwable $e) {
                error_log('Admin profile update error: ' . $e);
                $error = 'Could not update admin profile. Please try again.';
            }
        }
    } elseif ($action === 'update_profile') {
      $uiSection = trim((string) ($_POST['ui_section'] ?? 'account'));
      $posted = static function (string $key, string $fallback = ''): string {
        $value = trim((string) ($_POST[$key] ?? ''));
        return $value !== '' ? $value : $fallback;
      };

      $username = $posted('username', (string) ($profile['username'] ?? ''));
      $email = $posted('email', (string) ($profile['email'] ?? ''));
      $phone = $posted('phone', (string) ($profile['phone'] ?? ''));
      $country = $posted('country', (string) ($profile['country'] ?? ''));

      $title = $posted('title', (string) ($profile['title'] ?? ''));
      $firstName = $posted('first_name', (string) ($profile['first_name'] ?? ''));
      $middleName = $posted('middle_name', (string) ($profile['middle_name'] ?? ''));
      $lastName = $posted('last_name', (string) ($profile['last_name'] ?? ''));

      $position = $posted('position', (string) ($profile['position'] ?? ''));
      $institution = $posted('institution', (string) ($profile['institution'] ?? ''));
      $department = $posted('department', (string) ($profile['department'] ?? ''));

      $gradeLevel = $posted('grade_level', (string) ($profile['grade_level'] ?? ''));
      $schoolName = $posted('school_name', (string) ($profile['school_name'] ?? ''));
      $schoolEmail = $posted('school_email', (string) ($profile['school_email'] ?? ''));
      $admissionNumber = $posted('admission_number', (string) ($profile['admission_number'] ?? ''));

      $city = $posted('city', (string) ($profile['city'] ?? ''));
      $state = $posted('state', (string) ($profile['state'] ?? ''));
      $postalCode = $posted('postal_code', (string) ($profile['postal_code'] ?? ''));

        $newPassword = (string) ($_POST['new_password'] ?? '');
        $newPassword2 = (string) ($_POST['new_password2'] ?? '');

        $nameParts = [$firstName];
        if ($middleName !== '') {
            $nameParts[] = $middleName;
        }
        $nameParts[] = $lastName;
        $name = trim(implode(' ', array_filter($nameParts, static function($v) { return $v !== ''; })));

        // Sticky values
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

        if ($uiSection === 'account') {
          if ($username === '' || $email === '' || $phone === '' || $country === '' || $firstName === '' || $lastName === '') {
            $error = 'Please fill in all required fields (Name, Email, Phone, Country, First Name, Last Name).';
          } elseif (strlen($username) < 3 || strlen($username) > 64) {
            $error = 'Username must be between 3 and 64 characters.';
          } elseif (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{1,62}[a-zA-Z0-9]$/', $username)) {
            $error = 'Username may contain letters, numbers, dots, underscores, and hyphens (must start and end with a letter/number).';
          } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email.';
          } elseif (!preg_match('/^\+?[0-9][0-9\s\-]{6,20}$/', $phone)) {
            $error = 'Please enter a valid phone number (e.g., +91XXXXXXXXXX).';
          }
        }

        if ($error === '' && $uiSection !== 'account' && $schoolEmail !== '' && !filter_var($schoolEmail, FILTER_VALIDATE_EMAIL)) {
          $error = 'Please enter a valid school email (or leave it blank).';
        } elseif ($error === '' && ($uiSection === 'account' || $uiSection === 'security') && ($newPassword !== '' || $newPassword2 !== '')) {
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
                        ':position' => $position !== '' ? $position : null,
                        ':institution' => $institution !== '' ? $institution : null,
                        ':department' => $department !== '' ? $department : null,
                        ':grade_level' => $gradeLevel !== '' ? $gradeLevel : null,
                        ':school_name' => $schoolName !== '' ? $schoolName : null,
                        ':school_email' => $schoolEmail !== '' ? $schoolEmail : null,
                        ':admission_number' => $admissionNumber !== '' ? $admissionNumber : null,
                        ':city' => $city !== '' ? $city : null,
                        ':state' => $state !== '' ? $state : null,
                        ':postal_code' => $postalCode !== '' ? $postalCode : null,
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
                    $profile = load_user_profile($pdo, $userId, $profile);
                }
            } catch (Throwable $e) {
                $error = 'Could not update your account information. Please try again.';
            }
        }

        if ($success !== '') {
            // Post/Redirect/Get
            redirect('account.php?updated=1');
        }
    }
}

if (($success === '') && is_string($_GET['updated'] ?? null) && ($_GET['updated'] ?? '') === '1') {
    $success = 'Account information updated successfully.';
}
?>
<!DOCTYPE html>
<html lang="en" class="no-js">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <link rel="shortcut icon" type="image/jpg" href="images/iysjournal.png">
  <title>Update My Information | Global Youth Science Journal</title>
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
    :root {
      --font-sans: 'Poppins', sans-serif;
      --color-text-primary: #1f2937;
      --color-text-secondary: #4b5563;
      --color-text-tertiary: #6b7280;
      --color-text-danger: #b42318;
      --color-background-primary: #ffffff;
      --color-background-secondary: #f4f7fb;
      --color-background-danger: #fef2f2;
      --color-border-secondary: #d7dee8;
      --color-border-tertiary: #e4e9f0;
      --color-border-primary: #d79b00;
      --border-radius-md: 12px;
      --border-radius-lg: 18px;
    }

    body {
      font-family: var(--font-sans);
      background: #dde3ea;
      color: var(--color-text-primary);
    }

    .ps-wrap {
      padding: 0 0 40px;
    }

    .ps-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      padding: 20px 24px 16px;
      border-bottom: 0.5px solid var(--color-border-tertiary);
      margin-bottom: 0;
      background: #ffffff;
      border-radius: var(--border-radius-lg) var(--border-radius-lg) 0 0;
    }

    .ps-header-left h1 {
      font-size: 20px;
      font-weight: 500;
      margin: 0 0 2px;
    }

    .ps-header-left p {
      font-size: 13px;
      color: var(--color-text-secondary);
      margin: 0;
    }

    .ps-header-actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      justify-content: flex-end;
    }

    .ps-btn-ghost,
    .ps-btn-danger,
    .ps-upload-btn,
    .ps-save-btn,
    .ps-cancel-btn,
    .ps-tag {
      font-family: var(--font-sans);
    }

    .ps-btn-ghost {
      background: transparent;
      border: 0.5px solid var(--color-border-secondary);
      border-radius: var(--border-radius-md);
      padding: 7px 14px;
      font-size: 13px;
      font-weight: 500;
      cursor: pointer;
      color: var(--color-text-primary);
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }

    .ps-btn-ghost:hover {
      background: var(--color-background-secondary);
      color: var(--color-text-primary);
      text-decoration: none;
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

    .ps-btn-danger {
      background: transparent;
      border: 0.5px solid var(--color-border-secondary);
      border-radius: var(--border-radius-md);
      padding: 7px 14px;
      font-size: 13px;
      font-weight: 500;
      cursor: pointer;
      color: var(--color-text-danger);
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }

    .ps-btn-danger:hover {
      background: var(--color-background-danger);
      text-decoration: none;
    }

    .ps-layout {
      display: grid;
      grid-template-columns: 220px minmax(0, 1fr);
      min-height: 500px;
      background: rgba(255, 255, 255, 0.64);
      border: 0.5px solid var(--color-border-tertiary);
      border-top: 0;
      border-radius: 0 0 var(--border-radius-lg) var(--border-radius-lg);
      overflow: hidden;
      box-shadow: 0 14px 36px rgba(12, 23, 42, 0.06);
    }

    .ps-nav {
      border-right: 0.5px solid var(--color-border-tertiary);
      padding: 20px 0;
      background: rgba(255, 255, 255, 0.68);
    }

    .ps-nav-item {
      display: flex;
      align-items: center;
      gap: 9px;
      padding: 10px 20px;
      font-size: 13px;
      cursor: pointer;
      color: var(--color-text-secondary);
      border-left: 2px solid transparent;
      transition: all 0.15s;
      text-decoration: none;
      background: transparent;
      width: 100%;
      text-align: left;
      border-top: none;
      border-right: none;
      border-bottom: none;
      font-family: var(--font-sans);
    }

    .ps-nav-item:hover {
      color: var(--color-text-primary);
      background: var(--color-background-secondary);
      text-decoration: none;
    }

    .ps-nav-item.active {
      color: var(--color-text-primary);
      border-left-color: var(--color-text-primary);
      font-weight: 500;
      background: var(--color-background-secondary);
    }

    .ps-nav-item i {
      font-size: 15px;
    }

    .ps-content {
      padding: 24px 28px;
      display: flex;
      flex-direction: column;
      gap: 20px;
      min-width: 0;
    }

    .ps-section {
      display: none;
      flex-direction: column;
      gap: 20px;
    }

    .ps-section.visible {
      display: flex;
    }

    .ps-section-title {
      font-size: 15px;
      font-weight: 500;
      margin: 0 0 4px;
    }

    .ps-section-desc {
      font-size: 13px;
      color: var(--color-text-secondary);
      margin: 0 0 16px;
      line-height: 1.55;
    }

    .ps-card {
      background: var(--color-background-primary);
      border: 0.5px solid var(--color-border-tertiary);
      border-radius: var(--border-radius-lg);
      padding: 20px 22px;
      display: flex;
      flex-direction: column;
      gap: 16px;
    }

    .ps-row {
      display: grid;
      gap: 14px;
    }

    .ps-row-2 {
      grid-template-columns: 1fr 1fr;
    }

    .ps-row-3 {
      grid-template-columns: 1fr 1fr 1fr;
    }

    .ps-field {
      display: flex;
      flex-direction: column;
      gap: 5px;
    }

    .ps-label {
      font-size: 12px;
      font-weight: 500;
      color: var(--color-text-secondary);
      letter-spacing: 0.02em;
    }

    .ps-label .opt {
      font-weight: 400;
      color: var(--color-text-tertiary);
      font-style: italic;
    }

    .ps-input {
      border: 0.5px solid var(--color-border-secondary);
      border-radius: var(--border-radius-md);
      padding: 8px 11px;
      font-size: 14px;
      background: var(--color-background-primary);
      color: var(--color-text-primary);
      font-family: var(--font-sans);
      outline: none;
      transition: border-color 0.15s, box-shadow 0.15s;
      width: 100%;
      box-sizing: border-box;
    }

    .ps-input:focus {
      border-color: var(--color-border-primary);
      box-shadow: 0 0 0 3px rgba(215, 155, 0, 0.14);
    }

    .ps-divider {
      height: 0.5px;
      background: var(--color-border-tertiary);
      margin: 4px 0;
    }

    .ps-avatar-area {
      display: flex;
      align-items: center;
      gap: 18px;
      padding: 16px 22px;
      background: var(--color-background-primary);
      border: 0.5px solid var(--color-border-tertiary);
      border-radius: var(--border-radius-lg);
    }

    .ps-avatar {
      width: 64px;
      height: 64px;
      border-radius: 50%;
      background: var(--color-background-secondary);
      border: 0.5px solid var(--color-border-secondary);
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      overflow: hidden;
    }

    .ps-avatar img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .ps-avatar i {
      font-size: 28px;
      color: var(--color-text-tertiary);
    }

    .ps-avatar-info {
      flex: 1;
    }

    .ps-avatar-info p {
      font-size: 14px;
      font-weight: 500;
      margin: 0 0 3px;
    }

    .ps-avatar-info span {
      font-size: 12px;
      color: var(--color-text-tertiary);
    }

    .ps-upload-btn {
      background: transparent;
      border: 0.5px solid var(--color-border-secondary);
      border-radius: var(--border-radius-md);
      padding: 7px 14px;
      font-size: 13px;
      font-weight: 500;
      cursor: pointer;
      color: var(--color-text-primary);
      display: inline-flex;
      align-items: center;
      gap: 6px;
      margin-top: 10px;
    }

    .ps-upload-btn:hover {
      background: var(--color-background-secondary);
    }

    .ps-save-row {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 10px;
      padding: 16px 0 0;
      border-top: 0.5px solid var(--color-border-tertiary);
    }

    .ps-save-btn {
      background: var(--color-text-primary);
      color: var(--color-background-primary);
      border: none;
      border-radius: var(--border-radius-md);
      padding: 9px 20px;
      font-size: 14px;
      font-weight: 500;
      cursor: pointer;
    }

    .ps-save-btn:hover {
      opacity: 0.85;
    }

    .ps-cancel-btn {
      background: transparent;
      border: 0.5px solid var(--color-border-secondary);
      border-radius: var(--border-radius-md);
      padding: 9px 16px;
      font-size: 14px;
      cursor: pointer;
      color: var(--color-text-secondary);
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }

    .ps-cancel-btn:hover {
      background: var(--color-background-secondary);
      text-decoration: none;
      color: var(--color-text-primary);
    }

    .ps-hint {
      font-size: 12px;
      color: var(--color-text-tertiary);
    }

    .ps-badge-group {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }

    .ps-tag {
      background: var(--color-background-secondary);
      border: 0.5px solid var(--color-border-tertiary);
      border-radius: 20px;
      padding: 4px 12px;
      font-size: 12px;
      color: var(--color-text-secondary);
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }

    .ps-tag.selected {
      background: var(--color-text-primary);
      color: var(--color-background-primary);
      border-color: var(--color-text-primary);
    }

    .ps-summary {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 12px;
    }

    .ps-summary-card {
      padding: 14px 16px;
      border: 0.5px solid var(--color-border-tertiary);
      border-radius: var(--border-radius-md);
      background: rgba(255, 255, 255, 0.9);
    }

    .ps-summary-label {
      font-size: 11px;
      color: var(--color-text-tertiary);
      text-transform: uppercase;
      letter-spacing: 0.04em;
      margin-bottom: 4px;
    }

    .ps-summary-value {
      font-size: 14px;
      color: var(--color-text-primary);
      font-weight: 500;
      word-break: break-word;
    }

    .ps-section-actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      margin-top: 8px;
    }

    .ps-mini-link {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 7px 12px;
      border-radius: 999px;
      border: 0.5px solid var(--color-border-secondary);
      color: var(--color-text-primary);
      background: var(--color-background-primary);
      font-size: 12px;
      text-decoration: none;
    }

    .ps-mini-link:hover {
      background: var(--color-background-secondary);
      text-decoration: none;
      color: var(--color-text-primary);
    }

    @media (max-width: 991px) {
      .ps-layout {
        grid-template-columns: 1fr;
      }

      .ps-nav {
        border-right: none;
        border-bottom: 0.5px solid var(--color-border-tertiary);
        display: flex;
        overflow-x: auto;
        padding: 12px 0;
      }

      .ps-nav-item {
        border-left: none;
        border-bottom: 2px solid transparent;
        white-space: nowrap;
      }

      .ps-nav-item.active {
        border-left-color: transparent;
        border-bottom-color: var(--color-text-primary);
      }

      .ps-summary {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (max-width: 575px) {
      .ps-header {
        flex-direction: column;
        align-items: flex-start;
      }

      .ps-content {
        padding: 18px 16px;
      }

      .ps-card,
      .ps-avatar-area {
        padding-left: 16px;
        padding-right: 16px;
      }

      .ps-row-2,
      .ps-row-3,
      .ps-summary {
        grid-template-columns: 1fr;
      }

      .ps-save-row {
        flex-direction: column-reverse;
        align-items: stretch;
      }

      .ps-save-btn,
      .ps-cancel-btn {
        width: 100%;
        justify-content: center;
      }
    }

    :root {
      --color-background-primary: #ffffff;
      --color-background-secondary: #f8f8f5;
      --color-background-danger: #fff4e8;
      --color-border-secondary: #e3e3da;
      --color-border-tertiary: #ece8d9;
      --color-border-primary: #d79b00;
      --color-accent: #f0b429;
      --color-accent-dark: #c58c00;
      --color-accent-soft: #fff6d8;
    }

    body {
      background: #dde3ea;
      color: var(--color-text-primary);
    }

    .ps-header,
    .ps-layout,
    .ps-nav {
      background: rgba(255, 255, 255, 0.92);
    }

    .ps-nav-item.active {
      border-left-color: var(--color-border-primary);
      background: var(--color-background-secondary);
    }

    .ps-avatar {
      background: var(--color-accent-soft);
    }

    .ps-avatar i,
    .ps-mini-link,
    .ps-btn-danger {
      color: var(--color-accent-dark);
    }

    .ps-input:focus {
      border-color: var(--color-border-primary);
      box-shadow: 0 0 0 3px rgba(215, 155, 0, 0.14);
    }

    .ps-save-btn {
      background: var(--color-accent);
      border-color: var(--color-accent-dark);
      color: var(--color-text-primary);
    }

    .ps-save-btn:hover {
      background: #e6a800;
      border-color: #c58c00;
    }

    .ps-mini-link:hover,
    .ps-btn-ghost:hover,
    .ps-btn-danger:hover,
    .ps-cancel-btn:hover {
      background: var(--color-accent-soft);
      color: var(--color-text-primary);
    }

    :root {
      --border-radius-md: 10px;
      --border-radius-lg: 12px;
    }

    body {
      background: #dde3ea;
    }

    .ps-header,
    .ps-layout,
    .ps-nav,
    .ps-card,
    .ps-avatar-area,
    .ps-summary-card {
      background: #ffffff;
      border-radius: 12px;
    }

    .ps-header {
      background: #ffffff;
    }

    .ps-layout {
      overflow: hidden;
    }

    .ps-nav-item,
    .ps-btn-ghost,
    .ps-btn-danger,
    .ps-mini-link,
    .ps-cancel-btn,
    .ps-save-btn,
    .ps-upload-btn,
    .ps-tag {
      border-radius: 10px;
    }

    .ps-summary-card,
    .ps-card,
    .ps-avatar-area {
      background: #ffffff;
    }

    .ps-header,
    .ps-layout,
    .ps-nav {
      box-shadow: none;
    }

    .ps-header,
    .ps-layout,
    .ps-nav,
    .ps-card,
    .ps-avatar-area,
    .ps-summary-card,
    .ps-tag,
    .ps-mini-link {
      background-image: none;
    }

    .ps-header,
    .ps-layout,
    .ps-nav,
    .ps-card,
    .ps-avatar-area,
    .ps-summary-card {
      border-color: #e5e7eb;
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

  <div class="container" style="padding: 24px 15px 40px; max-width: 1180px;">
    <?php if ($success !== ''): ?>
      <div class="alert alert-success alert-dismissible fade show" role="alert" style="margin-bottom: 20px; border-radius: 0;">
        <?php echo e($success); ?>
        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
      <div class="alert alert-danger" role="alert" style="margin-bottom: 20px;"><?php echo e($error); ?></div>
    <?php endif; ?>

    <div class="ps-wrap">
      <div class="ps-header">
        <div class="ps-header-left">
          <h1>Account settings</h1>
          <p>Manage your profile and preferences</p>
        </div>
        <div class="ps-header-actions">
          <a class="ps-btn-ghost" href="user-dashboard.php"><i class="ti ti-layout-dashboard" aria-hidden="true"></i> Dashboard</a>
          <a class="ps-btn-danger" href="logout.php"><i class="ti ti-logout" aria-hidden="true"></i> Logout</a>
        </div>
      </div>

      <div class="ps-layout">
        <div class="ps-nav" role="tablist" aria-label="Account settings sections">
          <button type="button" class="ps-nav-item<?php echo $activeSection === 'account' ? ' active' : ''; ?>" onclick="showSection('account', this)"><i class="ti ti-user-circle" aria-hidden="true"></i> Account</button>
          <button type="button" class="ps-nav-item<?php echo $activeSection === 'personal' ? ' active' : ''; ?>" onclick="showSection('personal', this)"><i class="ti ti-id-badge" aria-hidden="true"></i> Personal</button>
          <button type="button" class="ps-nav-item<?php echo $activeSection === 'institution' ? ' active' : ''; ?>" onclick="showSection('institution', this)"><i class="ti ti-building" aria-hidden="true"></i> Institution</button>
          <button type="button" class="ps-nav-item<?php echo $activeSection === 'address' ? ' active' : ''; ?>" onclick="showSection('address', this)"><i class="ti ti-map-pin" aria-hidden="true"></i> Address</button>
          <button type="button" class="ps-nav-item<?php echo $activeSection === 'security' ? ' active' : ''; ?>" onclick="showSection('security', this)"><i class="ti ti-lock" aria-hidden="true"></i> Security</button>
          <?php if ($isAdmin): ?>
            <button type="button" class="ps-nav-item<?php echo $activeSection === 'admin' ? ' active' : ''; ?>" onclick="showSection('admin', this)"><i class="ti ti-crown" aria-hidden="true"></i> Admin</button>
          <?php endif; ?>
        </div>

        <div class="ps-content">

          <form method="post" action="account.php" enctype="multipart/form-data" id="accountSettingsForm" novalidate>
            <?php echo csrf_field(); ?>
            <input type="hidden" name="ui_section" id="ui_section" value="<?php echo e($activeSection); ?>">
            <!-- Hidden fields to ensure regular profile update has required data -->
            <input type="hidden" name="username" value="<?php echo e((string) ($profile['username'] ?? '')); ?>">
            <input type="hidden" name="email" value="<?php echo e((string) ($profile['email'] ?? '')); ?>">
            <input type="hidden" name="phone" value="<?php echo e((string) ($profile['phone'] ?? '')); ?>">
            <input type="hidden" name="country" value="<?php echo e((string) ($profile['country'] ?? '')); ?>">
            <input type="hidden" name="first_name" value="<?php echo e((string) ($profile['first_name'] ?? '')); ?>">
            <input type="hidden" name="last_name" value="<?php echo e((string) ($profile['last_name'] ?? '')); ?>">

            <div class="ps-section<?php echo $activeSection === 'account' ? ' visible' : ''; ?>" id="sec-account">
              <div>
                <p class="ps-section-title">Account information</p>
                <p class="ps-section-desc">Your login credentials and profile photo.</p>
              </div>

              <div class="ps-card">
                <div class="ps-row ps-row-2">
                  <div class="ps-field">
                    <label class="ps-label" for="username">Username</label>
                    <input class="ps-input" type="text" id="username" name="username" value="<?php echo e((string) ($profile['username'] ?? '')); ?>" required>
                  </div>
                  <div class="ps-field">
                    <label class="ps-label" for="email">Email address</label>
                    <input class="ps-input" type="email" id="email" name="email" value="<?php echo e((string) ($profile['email'] ?? '')); ?>" required>
                  </div>
                </div>

                <div class="ps-row ps-row-2">
                  <div class="ps-field">
                    <label class="ps-label" for="phone">Phone number</label>
                    <input class="ps-input" type="tel" id="phone" name="phone" value="<?php echo e((string) ($profile['phone'] ?? '')); ?>" required>
                  </div>
                  <div class="ps-field">
                    <label class="ps-label" for="country">Country</label>
                    <input class="ps-input" type="text" id="country" name="country" value="<?php echo e((string) ($profile['country'] ?? '')); ?>" required>
                  </div>
                </div>

                <div class="ps-row ps-row-2">
                  <div class="ps-field">
                    <label class="ps-label" for="new_password">New Password <span class="opt">(optional)</span></label>
                    <input class="ps-input" type="password" id="new_password" name="new_password" placeholder="Leave blank to keep current">
                  </div>
                  <div class="ps-field">
                    <label class="ps-label" for="new_password2">Confirm New Password</label>
                    <input class="ps-input" type="password" id="new_password2" name="new_password2" placeholder="Confirm new password">
                  </div>
                </div>
              </div>

              <div class="ps-save-row">
                <a class="ps-cancel-btn" href="user-dashboard.php">Cancel</a>
                <button class="ps-save-btn" type="submit" name="action" value="update_profile">Save changes</button>
              </div>
            </div>

            <div class="ps-section<?php echo $activeSection === 'personal' ? ' visible' : ''; ?>" id="sec-personal">
              <div>
                <p class="ps-section-title">Personal information</p>
                <p class="ps-section-desc">Your name and personal details.</p>
              </div>

              <div class="ps-card">
                <div class="ps-row ps-row-3">
                  <div class="ps-field">
                    <label class="ps-label" for="title">Title <span class="opt">(optional)</span></label>
                    <input class="ps-input" type="text" id="title" name="title" value="<?php echo e((string) ($profile['title'] ?? '')); ?>">
                  </div>
                  <div class="ps-field">
                    <label class="ps-label" for="first_name">First name</label>
                    <input class="ps-input" type="text" id="first_name" name="first_name" value="<?php echo e((string) ($profile['first_name'] ?? '')); ?>" required>
                  </div>
                  <div class="ps-field">
                    <label class="ps-label" for="last_name">Last name</label>
                    <input class="ps-input" type="text" id="last_name" name="last_name" value="<?php echo e((string) ($profile['last_name'] ?? '')); ?>" required>
                  </div>
                </div>

                <div class="ps-field" style="max-width: 340px;">
                  <label class="ps-label" for="middle_name">Middle name <span class="opt">(optional)</span></label>
                  <input class="ps-input" type="text" id="middle_name" name="middle_name" value="<?php echo e((string) ($profile['middle_name'] ?? '')); ?>" placeholder="Not provided">
                </div>
              </div>

              <div class="ps-save-row">
                <a class="ps-cancel-btn" href="user-dashboard.php">Cancel</a>
                <button class="ps-save-btn" type="submit" name="action" value="update_profile">Save changes</button>
              </div>
            </div>

            <div class="ps-section<?php echo $activeSection === 'institution' ? ' visible' : ''; ?>" id="sec-institution">
              <div>
                <p class="ps-section-title">Institution & student details</p>
                <p class="ps-section-desc">Your school, position, and enrollment information.</p>
              </div>

              <div class="ps-card">
                <div>
                  <p style="font-size: 13px; font-weight: 500; color: var(--color-text-secondary); margin: 0 0 4px;">Institution</p>
                  <div class="ps-row ps-row-2">
                    <div class="ps-field">
                      <label class="ps-label" for="institution">Institution / School</label>
                      <input class="ps-input" type="text" id="institution" name="institution" value="<?php echo e((string) ($profile['institution'] ?? '')); ?>" required>
                    </div>
                    <div class="ps-field">
                      <label class="ps-label" for="grade_level">Grade / Year level</label>
                      <input class="ps-input" type="text" id="grade_level" name="grade_level" value="<?php echo e((string) ($profile['grade_level'] ?? '')); ?>" required>
                    </div>
                  </div>
                  <div class="ps-row ps-row-2">
                    <div class="ps-field">
                      <label class="ps-label" for="school_email">School email <span class="opt">(optional)</span></label>
                      <input class="ps-input" type="email" id="school_email" name="school_email" value="<?php echo e((string) ($profile['school_email'] ?? '')); ?>" placeholder="you@school.edu">
                      <div style="margin-top: 8px; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" id="no_school_email_acc" onchange="document.getElementById('school_email').disabled = this.checked; if(this.checked) document.getElementById('school_email').value='';" style="width: auto; margin: 0;">
                        <label for="no_school_email_acc" style="margin: 0; font-size: 13px; font-weight: normal; color: var(--color-text-secondary); cursor: pointer;">My school doesn't provide an email</label>
                      </div>
                    </div>
                    <div class="ps-field">
                      <label class="ps-label" for="admission_number">Admission / ERP number <span class="opt">(optional)</span></label>
                      <input class="ps-input" type="text" id="admission_number" name="admission_number" value="<?php echo e((string) ($profile['admission_number'] ?? '')); ?>" placeholder="e.g. 2024-001234">
                    </div>
                  </div>
                </div>
              </div>

              <div class="ps-save-row">
                <a class="ps-cancel-btn" href="user-dashboard.php">Cancel</a>
                <button class="ps-save-btn" type="submit" name="action" value="update_profile">Save changes</button>
              </div>
            </div>

            <div class="ps-section<?php echo $activeSection === 'address' ? ' visible' : ''; ?>" id="sec-address">
              <div>
                <p class="ps-section-title">Address information</p>
                <p class="ps-section-desc">Your current city and mailing address.</p>
              </div>

              <div class="ps-card">
                <div class="ps-row ps-row-3">
                  <div class="ps-field">
                    <label class="ps-label" for="city">City</label>
                    <input class="ps-input" type="text" id="city" name="city" value="<?php echo e((string) ($profile['city'] ?? '')); ?>" required>
                  </div>
                  <div class="ps-field">
                    <label class="ps-label" for="state">State / Province</label>
                    <input class="ps-input" type="text" id="state" name="state" value="<?php echo e((string) ($profile['state'] ?? '')); ?>" required>
                  </div>
                  <div class="ps-field">
                    <label class="ps-label" for="postal_code">Postal code</label>
                    <input class="ps-input" type="text" id="postal_code" name="postal_code" value="<?php echo e((string) ($profile['postal_code'] ?? '')); ?>" required>
                  </div>
                </div>
              </div>

              <div class="ps-save-row">
                <a class="ps-cancel-btn" href="user-dashboard.php">Cancel</a>
                <button class="ps-save-btn" type="submit" name="action" value="update_profile">Save changes</button>
              </div>
            </div>

            <div class="ps-section<?php echo $activeSection === 'security' ? ' visible' : ''; ?>" id="sec-security">
              <div>
                <p class="ps-section-title">Password & security</p>
                <p class="ps-section-desc">Update your password. Leave blank to keep your current one.</p>
              </div>

              <div class="ps-card">
                <div class="ps-field" style="max-width: 340px;">
                  <label class="ps-label" for="new_password_security">New password <span class="opt">(optional)</span></label>
                  <input class="ps-input" type="password" id="new_password_security" name="new_password" placeholder="Enter new password" value="">
                </div>
                <div class="ps-field" style="max-width: 340px;">
                  <label class="ps-label" for="new_password2_security">Confirm new password</label>
                  <input class="ps-input" type="password" id="new_password2_security" name="new_password2" placeholder="Re-enter new password" value="">
                </div>
                <p class="ps-hint">Use at least 8 characters with a mix of letters, numbers, and symbols.</p>
              </div>

              <div class="ps-save-row">
                <a class="ps-cancel-btn" href="user-dashboard.php">Cancel</a>
                <button class="ps-save-btn" type="submit" name="action" value="update_profile">Update password</button>
              </div>
            </div>

            <?php if ($isAdmin): ?>
            <div class="ps-section<?php echo $activeSection === 'admin' ? ' visible' : ''; ?>" id="sec-admin">
              <div>
                <p class="ps-section-title">Admin & Editorial Profile</p>
                <p class="ps-section-desc">Manage your editorial credentials and specializations.</p>
              </div>

              <div class="ps-card">
                <div>
                  <p style="font-size: 13px; font-weight: 500; color: var(--color-text-secondary); margin: 0 0 12px;">Journal Specializations</p>
                  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin-bottom: 16px;">
                    <?php
                      $journalOptions = ['Journal of Advance Research in Computer Science & Engineering', 'Journal of Advance Research in Mathematics & Mathematical Sciences', 'Journal of Advance Research in Applied Physics', 'Journal of Advance Research in Applied Chemistry', 'Journal of Advance Research in Civil Engineering', 'Journal of Advance Research in Mechanical Engineering', 'Journal of Advance Research in Business, Management & Accounting', 'Journal of Advance Research in Electronics & Communication Engineering', 'Journal of Advance Research in Humanities & Social Science', 'Journal of Advance Research (General)', 'Journal of Advance Research in Biology & Pharmacy', 'Journal of Advance Research in Environmental Science'];
                      $selectedJournals = account_decode_list($profile['assigned_journals_json'] ?? '');
                    ?>
                    <label style="display: flex; gap: 8px; padding: 8px; border: 0.5px solid var(--color-border-secondary); border-radius: 0; cursor: pointer;">
                      <input type="checkbox" id="admin_all_fields" name="admin_assigned_journals[]" value="All Fields" <?php echo in_array('All Fields', $selectedJournals, true) ? 'checked' : ''; ?>>
                      <span style="font-size: 14px;">All Fields</span>
                    </label>
                    <?php foreach ($journalOptions as $journal): ?>
                      <label style="display: flex; gap: 8px; padding: 8px; border: 0.5px solid var(--color-border-secondary); border-radius: 0; cursor: pointer;" class="admin-journal-label">
                        <input type="checkbox" class="admin-journal-cb" name="admin_assigned_journals[]" value="<?php echo e($journal); ?>" <?php echo in_array($journal, $selectedJournals, true) ? 'checked' : ''; ?>>
                        <span style="font-size: 14px;"><?php echo e($journal); ?></span>
                      </label>
                    <?php endforeach; ?>
                    <script>
                      document.addEventListener('DOMContentLoaded', function() {
                        const allFieldsCb = document.getElementById('admin_all_fields');
                        const otherCbs = document.querySelectorAll('.admin-journal-cb');
                        const otherLabels = document.querySelectorAll('.admin-journal-label');
                        
                        function updateJournals() {
                          const isAll = allFieldsCb.checked;
                          otherCbs.forEach((cb, idx) => {
                            if (isAll) {
                              cb.checked = false;
                              cb.disabled = true;
                              otherLabels[idx].style.opacity = '0.5';
                              otherLabels[idx].style.pointerEvents = 'none';
                            } else {
                              cb.disabled = false;
                              otherLabels[idx].style.opacity = '1';
                              otherLabels[idx].style.pointerEvents = 'auto';
                            }
                          });
                        }
                        
                        if (allFieldsCb) {
                          allFieldsCb.addEventListener('change', updateJournals);
                          updateJournals();
                        }
                      });
                    </script>
                  </div>
                </div>

                <div class="ps-divider"></div>

                <div class="ps-field" style="margin-top: 16px;">
                  <label class="ps-label" for="admin_reviewer_experience_text">Editorial Background & Experience</label>
                  <textarea class="ps-input" id="admin_reviewer_experience_text" name="admin_reviewer_experience_text" style="min-height: 120px; resize: vertical;"><?php echo e((string) ($profile['reviewer_experience_text'] ?? '')); ?></textarea>
                  <p class="ps-hint" style="margin-top: 6px;">Mention relevant research, publications, teaching, or editorial experience.</p>
                </div>

                <div class="ps-field">
                  <label class="ps-label" for="admin_reviewer_reason_text">Editorial Vision & Approach</label>
                  <textarea class="ps-input" id="admin_reviewer_reason_text" name="admin_reviewer_reason_text" style="min-height: 120px; resize: vertical;"><?php echo e((string) ($profile['reviewer_reason_text'] ?? '')); ?></textarea>
                  <p class="ps-hint" style="margin-top: 6px;">Describe your editorial philosophy and approach (150-200 words).</p>
                </div>

                <div class="ps-divider"></div>

                <div>
                  <p style="font-size: 13px; font-weight: 500; color: var(--color-text-secondary); margin: 0 0 12px;">Editorial Availability</p>
                  <div class="ps-row ps-row-2">
                    <div class="ps-field">
                      <label class="ps-label" for="admin_reviewer_weekly_availability">Weekly Editorial Time</label>
                      <select class="ps-input" id="admin_reviewer_weekly_availability" name="admin_reviewer_weekly_availability">
                        <option value="">Select availability</option>
                        <option value="1–2 hours" <?php echo ((string) ($profile['reviewer_weekly_availability'] ?? '')) === '1–2 hours' ? 'selected' : ''; ?>>1–2 hours</option>
                        <option value="3–5 hours" <?php echo ((string) ($profile['reviewer_weekly_availability'] ?? '')) === '3–5 hours' ? 'selected' : ''; ?>>3–5 hours</option>
                        <option value="5–10 hours" <?php echo ((string) ($profile['reviewer_weekly_availability'] ?? '')) === '5–10 hours' ? 'selected' : ''; ?>>5–10 hours</option>
                        <option value="10+ hours" <?php echo ((string) ($profile['reviewer_weekly_availability'] ?? '')) === '10+ hours' ? 'selected' : ''; ?>>10+ hours</option>
                      </select>
                    </div>
                  </div>
                </div>

                <div class="ps-divider"></div>

                <div class="ps-field">
                  <label class="ps-label" for="admin_reviewer_profile_links">LinkedIn / Portfolio / GitHub / Research Profile <span class="opt">(optional)</span></label>
                  <input class="ps-input" type="text" id="admin_reviewer_profile_links" name="admin_reviewer_profile_links" value="<?php echo e((string) ($profile['reviewer_profile_links'] ?? '')); ?>" placeholder="https://linkedin.com/in/...">
                </div>

                <div class="ps-divider"></div>

                <div>
                  <p style="font-size: 13px; font-weight: 500; color: var(--color-text-secondary); margin: 0 0 12px;">Credentials</p>
                  <div class="ps-row ps-row-2">
                    <div class="ps-field">
                      <label class="ps-label">Resume / CV <span class="opt">(optional)</span></label>
                      <div style="display: flex; flex-direction: column; gap: 8px;">
                        <div class="ps-input" style="min-height: 42px; background:#f8fafc; display:flex; align-items:center; font-size:13px;"><?php echo e(account_render_file_label((string) ($profile['reviewer_cv_path'] ?? ''), (string) ($profile['reviewer_cv_original_name'] ?? ''))); ?></div>
                        <input type="file" name="admin_cv" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" style="font-size: 12px;">
                        <p class="ps-hint">Accepted formats: PDF, DOC, DOCX (max 10 MB)</p>
                      </div>
                    </div>
                    <div class="ps-field">
                      <label class="ps-label">Supporting Documents <span class="opt">(optional)</span></label>
                      <div style="display: flex; flex-direction: column; gap: 8px;">
                        <div class="ps-input" style="min-height: 42px; background:#f8fafc; display:flex; align-items:center; font-size:13px;"><?php echo e(account_join_list($profile['reviewer_supporting_documents_json'] ?? null, 'Not uploaded')); ?></div>
                        <input type="file" name="admin_supporting_documents[]" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" style="font-size: 12px;">
                        <p class="ps-hint">PDF, DOC, DOCX, JPG, PNG (max 10 MB each)</p>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="ps-divider"></div>

                <div class="ps-field" style="margin-top: 16px;">
                  <label style="display: flex; gap: 8px; padding: 8px; border: 0.5px solid var(--color-border-secondary); border-radius: 0; cursor: pointer;">
                    <input type="checkbox" name="admin_declaration_confirmed" value="1" <?php echo !empty($profile['reviewer_declaration_confirmed']) ? 'checked' : ''; ?>>
                    <span style="font-size: 13px;">I confirm that my editorial information is accurate and current.</span>
                  </label>
                </div>
              </div>

              <div class="ps-save-row">
                <a class="ps-cancel-btn" href="user-dashboard.php">Cancel</a>
                <button class="ps-save-btn" type="submit" name="action" value="update_admin_profile" formnovalidate>Update admin profile</button>
              </div>
            </div>
            <?php endif; ?>

          </form>

        </div>
      </div>
    </div>
  </div>

  <script src="js/jquery.min.js"></script>
  <script src="js/tether.min.js" crossorigin="anonymous"></script>
  <script src="js/bootstrap.min.js" crossorigin="anonymous"></script>
  <script>
    (function () {
      var successAlert = document.querySelector('.alert.alert-success');
      if (successAlert) {
        window.setTimeout(function () {
          if (window.jQuery && typeof window.jQuery(successAlert).alert === 'function') {
            window.jQuery(successAlert).alert('close');
            return;
          }

          successAlert.remove();
        }, 4500);
      }
    })();

    function showSection(name, el) {
      document.querySelectorAll('.ps-section').forEach(function (section) {
        section.classList.remove('visible');
      });
      document.querySelectorAll('.ps-nav-item').forEach(function (item) {
        item.classList.remove('active');
      });

      var section = document.getElementById('sec-' + name);
      if (section) {
        section.classList.add('visible');
      }

      if (el) {
        el.classList.add('active');
      }

      var sectionInput = document.getElementById('ui_section');
      if (sectionInput) {
        sectionInput.value = name;
      }
    }

    document.addEventListener('DOMContentLoaded', function () {
      var initial = document.getElementById('ui_section');
      if (initial && initial.value) {
        var activeButton = document.querySelector('.ps-nav-item.active');
        if (!activeButton) {
          var target = document.querySelector('.ps-nav-item[onclick*="' + initial.value + '"]');
          if (target) {
            target.classList.add('active');
          }
        }
      }
    });

    document.documentElement.classList.add('account-settings-flat');
  </script>
  <style>
    html.account-settings-flat .ps-header,
    html.account-settings-flat .ps-layout,
    html.account-settings-flat .ps-nav,
    html.account-settings-flat .ps-card,
    html.account-settings-flat .ps-avatar-area,
    html.account-settings-flat .ps-summary-card,
    html.account-settings-flat .ps-input,
    html.account-settings-flat .ps-btn-ghost,
    html.account-settings-flat .ps-btn-danger,
    html.account-settings-flat .ps-upload-btn,
    html.account-settings-flat .ps-save-btn,
    html.account-settings-flat .ps-cancel-btn,
    html.account-settings-flat .ps-tag,
    html.account-settings-flat .ps-mini-link,
    html.account-settings-flat .ps-nav-item,
    html.account-settings-flat .alert {
      border-radius: 0 !important;
    }

    html.account-settings-flat .ps-avatar {
      border-radius: 0 !important;
    }
  </style>
</body>

</html>
