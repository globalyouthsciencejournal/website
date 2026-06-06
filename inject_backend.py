import sys

with open("edit-submission.php", "r", encoding="utf-8") as f:
    content = f.read()

# 1. Inject JSON loading logic
load_target = """$submissionDetailsRaw = (string) ($submission['submission_details'] ?? '');
[$authorBioVal, $submissionDetailsRaw] = edit_submission_split_legacy_bio((string) ($submission['author_bio'] ?? ''), $submissionDetailsRaw);
$submissionDetails = edit_submission_parse_details($submissionDetailsRaw);
$advancedDetails = edit_submission_advanced_decode($submissionDetails);

$paperTypeVal = edit_submission_detail_value($submissionDetails, ['type'], '');"""

load_replace = """$submissionDetailsRaw = (string) ($submission['submission_details'] ?? '');
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
    $paperTypeVal = edit_submission_detail_value($submissionDetails, ['type'], '');"""

if load_target not in content:
    print("Error: load_target not found")
else:
    content = content.replace(load_target, load_replace)


# 2. Inject end of legacy logic
load_target_2 = """$admissionNumberVal = edit_submission_detail_value($submissionDetails, ['admission number', 'admission_number'], (string) $profile['admission_number']);"""

load_replace_2 = """$admissionNumberVal = edit_submission_detail_value($submissionDetails, ['admission number', 'admission_number'], (string) $profile['admission_number']);
    
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
} // END LOGIC BRANCH"""

if load_target_2 not in content:
    print("Error: load_target_2 not found")
else:
    content = content.replace(load_target_2, load_replace_2)

# 3. Inject POST saving
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

if save_target not in content:
    # Check if we already injected it somehow?
    if "$authorsPayloadRaw =" not in content:
        print("Error: save_target not found")
else:
    content = content.replace(save_target, save_replace)


with open("edit-submission.php", "w", encoding="utf-8") as f:
    f.write(content)
print("Done!")
