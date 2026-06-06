import re
import sys

with open("edit-submission.php", "r", encoding="utf-8") as f:
    content = f.read()

new_parse = """function edit_submission_parse_details(string $details): array
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
}"""
content = re.sub(
    r'function edit_submission_parse_details\(string \$details\): array\s*\{\s*\$parsed = \[\];.*?return \$parsed;\s*\}',
    lambda m: new_parse,
    content,
    flags=re.DOTALL
)

extract_code = """$submissionDetailsRaw = (string) ($submission['submission_details'] ?? '');
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
    $ageVal = '';
    $emailVal = '';
    $phoneVal = '';
    $phoneCodeVal = '';
    $phoneNumberVal = '';
    $countryVal = '';
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
}
"""
content = re.sub(
    r'\$submissionDetailsRaw = \(string\) \(\$submission\[\'submission_details\'\].*?\$admissionNumberVal = edit_submission_detail_value.*?;\s*',
    lambda m: extract_code + "\n",
    content,
    flags=re.DOTALL
)

# 3. Add hidden authors_payload input
content = content.replace(
    '<textarea name="author_bio" id="submitAuthorBioHidden" style="display:none;"><?php echo e($authorBioVal); ?></textarea>',
    '<textarea name="author_bio" id="submitAuthorBioHidden" style="display:none;"><?php echo e($authorBioVal); ?></textarea>\n                  <input type="hidden" name="authors_payload" id="submitAuthorsPayload" value="<?php echo htmlspecialchars($authorsPayloadVal, ENT_QUOTES, \'UTF-8\'); ?>">'
)

# 4. Fix POST handler data extraction
post_vars = """    $title = trim((string) ($_POST['title'] ?? ''));
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
    
    // We get primary author's bio if needed, or leave blank since authors_payload has it
    $authorBio = '';
    
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

content = re.sub(
    r'\$title = trim.*?\$category = trim.*?;',
    lambda m: post_vars,
    content,
    flags=re.DOTALL
)

new_validation = """    if (
      $paperType === '' || $journal === '' || $title === '' || $abstract === '' || $authors === '' ||
      $country === '' || empty($authorsData)
    ) {
      $error = 'Please complete all submission fields before saving.';
    } elseif (!in_array($paperType, $submitPaperTypes, true)) {"""
content = re.sub(
    r'if \([\s\S]*?\$authorBio === \'\'\s*\)\s*\{\s*\$error = \'Please complete all submission fields before saving.\';\s*\}\s*elseif \(!in_array\(\$paperType, \$submitPaperTypes, true\)\) \{',
    lambda m: new_validation,
    content
)
content = re.sub(
    r'\} elseif \(!preg_match\(\'/^[1-9][0-9]\?\$/\', \$age\).*?\} elseif \(\$schoolName === \'\' \|\| \$country === \'\' \|\| \$gradeLevel === \'\' \|\| \$admissionNumber === \'\'\) \{\s*\$error = \'Please complete all institution and contact fields.\';\s*',
    '',
    content,
    flags=re.DOTALL
)

saving_logic = """          $submissionDetails = json_encode([
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
content = re.sub(
    r'\$phone = trim.*?\$categoryValue = trim\(\$paperType \. \' \| \' \. \$journal\);',
    lambda m: saving_logic,
    content,
    flags=re.DOTALL
)

with open("edit-submission.php", "w", encoding="utf-8") as f:
    f.write(content)
