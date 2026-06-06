import re

with open("edit-submission.php", "r", encoding="utf-8") as f:
    content = f.read()

# 1. Update the payload loading logic at the top

load_target = """$submissionDetailsRaw = (string) ($submission['submission_details'] ?? '');
[$authorBioVal, $submissionDetailsRaw] = edit_submission_split_legacy_bio((string) ($submission['author_bio'] ?? ''), $submissionDetailsRaw);
$submissionDetails = edit_submission_parse_details($submissionDetailsRaw);

$paperTypeVal = edit_submission_detail_value($submissionDetails, ['type'], '');"""

load_replace = """$submissionDetailsRaw = (string) ($submission['submission_details'] ?? '');
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

    $paperTypeVal = edit_submission_detail_value($submissionDetails, ['type'], '');"""

content = content.replace(load_target, load_replace)

# 2. Update the rest of the legacy loading logic to inject JSON fallback for old submissions

load_target_2 = """    $admissionNumberVal = edit_submission_detail_value($submissionDetails, ['admission number', 'admission_number'], (string) $profile['admission_number']);"""

load_replace_2 = """    $admissionNumberVal = edit_submission_detail_value($submissionDetails, ['admission number', 'admission_number'], (string) $profile['admission_number']);
    
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

content = content.replace(load_target_2, load_replace_2)

# 3. Handle POST payload saving

save_target = """          $advancedDetailsJson = json_encode([
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

          $submissionDetails = edit_submission_build_details([
            'Type' => $paperType,
            'Journal' => $journal,
            'Age' => trim((string) ($_POST['age'] ?? '')),
            'Email' => trim((string) ($_POST['email'] ?? '')),
            'Phone' => trim((string) ($_POST['phone_code'] ?? '')) . ' ' . trim((string) ($_POST['phone_number'] ?? '')),
            'Country' => trim((string) ($_POST['country'] ?? '')),
            'Grade Level' => trim((string) ($_POST['grade_level'] ?? '')),
            'School Name' => trim((string) ($_POST['school_name'] ?? '')),
            'School Email' => trim((string) ($_POST['school_email'] ?? '')),
            'Admission Number' => trim((string) ($_POST['admission_number'] ?? '')),
            'Advanced Details' => $advancedDetailsJson,
          ]);"""

save_replace = """          $authorsPayloadRaw = trim((string) ($_POST['authors_payload'] ?? '[]'));
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
          $authors = implode(', ', $authorsList);

          $howHeard = trim((string) ($_POST['how_heard'] ?? ''));
          $setting = isset($_POST['setting']) && is_array($_POST['setting']) ? implode(', ', array_map('trim', $_POST['setting'])) : '';
          $agesArr = isset($_POST['ages']) && is_array($_POST['ages']) ? array_map('trim', $_POST['ages']) : [];
          $agesStr = implode(', ', $agesArr);
          $schoolTypeArr = isset($_POST['school_type']) && is_array($_POST['school_type']) ? array_map('trim', $_POST['school_type']) : [];
          $schoolTypeStr = implode(', ', $schoolTypeArr);
          $literatureTools = trim((string) ($_POST['literature_tools'] ?? ''));
          $softwareTools = trim((string) ($_POST['software_tools'] ?? ''));

          $submissionDetails = json_encode([
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
"""

content = content.replace(save_target, save_replace)

# Add hidden field for authors_payload
hidden_target = '<textarea name="author_bio" id="submitAuthorBioHidden" style="display:none;"><?php echo e($authorBioVal); ?></textarea>'
hidden_replace = '<textarea name="author_bio" id="submitAuthorBioHidden" style="display:none;"><?php echo e($authorBioVal); ?></textarea>\n                  <input type="hidden" name="authors_payload" id="submitAuthorsPayload" value="<?php echo htmlspecialchars($authorsPayloadVal, ENT_QUOTES, \'UTF-8\'); ?>">'
content = content.replace(hidden_target, hidden_replace)


with open("edit-submission.php", "w", encoding="utf-8") as f:
    f.write(content)

