import re

with open("edit-submission.php", "r", encoding="utf-8") as f:
    content = f.read()

# 1. Add the parse function at the top of the file before `auth_require_login();`
parse_fn = """require_once __DIR__ . '/includes/bootstrap.php';

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
"""
content = content.replace("require_once __DIR__ . '/includes/bootstrap.php';", parse_fn)

# 2. Update the POST extraction block
post_target = """    $title = trim((string) ($_POST['title'] ?? ''));
    $authors = trim((string) ($_POST['authors'] ?? ''));
    $abstract = trim((string) ($_POST['abstract'] ?? ''));
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
    
    // We get primary author's bio if needed
    $authorBio = trim((string) ($_POST['author_bio'] ?? ''));
    
    $keywords = trim((string) ($_POST['keywords'] ?? ''));
    $category = trim((string) ($_POST['category'] ?? ''));"""

post_replacement = """    $title = trim((string) ($_POST['title'] ?? ''));
    
    $authorsPayloadRaw = trim((string) ($_POST['authors_payload'] ?? '[]'));
    $authorsData = json_decode($authorsPayloadRaw, true);
    if (!is_array($authorsData)) $authorsData = [];
    
    $authorsList = [];
    foreach ($authorsData as $author) {
        if (!empty($author['name'])) {
            $authorsList[] = trim($author['name']);
        }
    }
    $authors = implode(', ', $authorsList);
    
    $abstract = trim((string) ($_POST['abstract'] ?? ''));
    $paperType = trim((string) ($_POST['paper_type'] ?? ''));
    $journal = trim((string) ($_POST['journal'] ?? ''));
    $country = trim((string) ($_POST['country'] ?? ''));
    
    // We get primary author's bio if needed
    $authorBio = trim((string) ($_POST['author_bio'] ?? ''));
    
    $keywords = trim((string) ($_POST['keywords'] ?? ''));
    $category = trim((string) ($_POST['category'] ?? ''));
    
    $howHeard = trim((string) ($_POST['how_heard'] ?? ''));
    $settingRaw = $_POST['setting'] ?? [];
    $setting = is_array($settingRaw) ? implode(', ', $settingRaw) : '';
    $agesRaw = $_POST['ages'] ?? [];
    $agesStr = is_array($agesRaw) ? implode(', ', $agesRaw) : '';
    $schoolTypeRaw = $_POST['school_type'] ?? [];
    $schoolTypeStr = is_array($schoolTypeRaw) ? implode(', ', $schoolTypeRaw) : '';
    $literatureTools = trim((string) ($_POST['literature_tools'] ?? ''));
    $softwareTools = trim((string) ($_POST['software_tools'] ?? ''));"""
content = content.replace(post_target, post_replacement)

# 3. Update the Validation Block
val_target = """    if (
      $paperType === '' || $journal === '' || $title === '' || $abstract === '' || $authors === '' ||
      $age === '' || $email === '' || $phoneCode === '' || $phoneNumber === '' || $country === '' ||
      $gradeLevel === '' || $schoolName === '' || $schoolEmail === '' || $admissionNumber === '' ||
      $authorBio === ''
    ) {
      $error = 'Please complete all submission fields before saving.';
    } elseif (!in_array($paperType, $submitPaperTypes, true)) {"""

val_replacement = """    if (
      $paperType === '' || $journal === '' || $title === '' || $abstract === '' || $authors === '' ||
      $country === '' || empty($authorsData)
    ) {
      $error = 'Please complete all submission fields before saving.';
    } elseif (!in_array($paperType, $submitPaperTypes, true)) {"""
content = content.replace(val_target, val_replacement)

# Remove the legacy specific validation rules
remove_legacy_rules = r"\} elseif \(!preg_match\('/^[1-9][0-9]\?\$/', \$age\).*?\} elseif \(\$schoolName === '' \|\| \$country === '' \|\| \$gradeLevel === '' \|\| \$admissionNumber === ''\) \{\s*\$error = 'Please complete all institution and contact fields\.';\s*"
content = re.sub(remove_legacy_rules, "", content, flags=re.DOTALL)


# 4. Update the DB submission builder
db_target = """          $phone = trim($phoneCode . ' ' . $phoneNumber);
          
          if ($hasSubmissionDetailsColumn) {
                  $submissionDetails = edit_submission_build_details([
                      'type' => $paperType,
                      'journal' => $journal,
                      'age' => $age,
                      'email' => $email,
                      'phone' => $phone,
                      'country' => $country,
                      'grade_level' => $gradeLevel,
                      'school_name' => $schoolName,
                      'school_email' => $schoolEmail,
                      'admission_number' => $admissionNumber,
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
                  ]);
          } else {
                  $submissionDetails = '';
          }
          
          $categoryValue = trim($paperType . ' | ' . $journal);"""

db_replacement = """          $submissionDetails = json_encode([
            'type' => $paperType,
            'journal' => $journal,
            'how_heard' => $howHeard,
            'setting' => $setting,
            'ages' => $agesStr,
            'school_type' => $schoolTypeStr,
            'literature_tools' => $literatureTools,
            'software_tools' => $softwareTools,
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
          
          $categoryValue = trim($paperType . ' | ' . $journal);"""
content = content.replace(db_target, db_replacement)


# 5. Extracting JSON / Legacy payload for the front end values
load_target = """$submissionDetailsRaw = (string) ($submission['submission_details'] ?? '');
[$authorBioVal, $submissionDetailsRaw] = edit_submission_split_legacy_bio((string) ($submission['author_bio'] ?? ''), $submissionDetailsRaw);
$submissionDetails = edit_submission_parse_details($submissionDetailsRaw);

$paperTypeVal = edit_submission_detail_value($submissionDetails, ['type'], '');
$journalVal = edit_submission_detail_value($submissionDetails, ['journal'], '');
$legacyCategory = trim((string) ($submission['category'] ?? ''));
if ($paperTypeVal === '' && $legacyCategory !== '') {
  $legacyParts = preg_split('/\\s*\\|\\s*/', $legacyCategory, 2);
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
$admissionNumberVal = edit_submission_detail_value($submissionDetails, ['admission number', 'admission_number'], (string) $profile['admission_number']);"""

load_replacement = """$submissionDetailsRaw = (string) ($submission['submission_details'] ?? '');
[$authorBioVal, $submissionDetailsRaw] = edit_submission_split_legacy_bio((string) ($submission['author_bio'] ?? ''), $submissionDetailsRaw);
$submissionDetails = edit_submission_parse_details($submissionDetailsRaw);
$advancedDetails = []; // fallback

if (isset($submissionDetails['authors_payload'])) {
    // New JSON format
    $paperTypeVal = $submissionDetails['type'] ?? '';
    $journalVal = $submissionDetails['journal'] ?? '';
    $howHeardVal = $submissionDetails['how_heard'] ?? '';
    $settingVal = $submissionDetails['setting'] ?? '';
    $agesVal = $submissionDetails['ages'] ?? '';
    $schoolTypeVal = $submissionDetails['school_type'] ?? '';
    $literatureToolsVal = $submissionDetails['literature_tools'] ?? '';
    $softwareToolsVal = $submissionDetails['software_tools'] ?? '';
    $authorsPayloadVal = $submissionDetails['authors_payload'] ?? '[]';
    
    // Default single author fields for hidden inputs just in case
    $emailVal = '';
    $phoneVal = '';
    $phoneCodeVal = '';
    $phoneNumberVal = '';
    $countryVal = '';
    $ageVal = '';
    $gradeLevelVal = '';
    
} else {
    // Legacy comma-separated format
    $advancedDetails = edit_submission_advanced_decode($submissionDetails);

    $paperTypeVal = edit_submission_detail_value($submissionDetails, ['type'], '');
    $journalVal = edit_submission_detail_value($submissionDetails, ['journal'], '');
    $legacyCategory = trim((string) ($submission['category'] ?? ''));
    if ($paperTypeVal === '' && $legacyCategory !== '') {
      $legacyParts = preg_split('/\\s*\\|\\s*/', $legacyCategory, 2);
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
    
    // Convert legacy author to authors_payload format so widget works
    $authorsArr = [];
    $legacyAuthors = trim((string) ($submission['authors'] ?? ''));
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
}"""
content = content.replace(load_target, load_replacement)

# 6. Add hidden payload field for JS usage
hidden_target = '<textarea name="author_bio" id="submitAuthorBioHidden" style="display:none;"><?php echo e($authorBioVal); ?></textarea>'
hidden_replace = '<textarea name="author_bio" id="submitAuthorBioHidden" style="display:none;"><?php echo e($authorBioVal); ?></textarea>\n                  <input type="hidden" name="authors_payload" id="submitAuthorsPayload" value="<?php echo htmlspecialchars($authorsPayloadVal, ENT_QUOTES, \'UTF-8\'); ?>">'
content = content.replace(hidden_target, hidden_replace)


with open("edit-submission.php", "w", encoding="utf-8") as f:
    f.write(content)
