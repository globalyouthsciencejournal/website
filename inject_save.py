import sys

with open("edit-submission.php", "r", encoding="utf-8") as f:
    content = f.read()

# Target for replacement
save_target = """          $phone = trim($phoneCode . ' ' . $phoneNumber);
          $submissionDetails = edit_submission_build_details([
            'Type' => $paperType,
            'Journal' => $journal,
            'Age' => $age,
            'Email' => $email,
            'Phone' => $phone,
            'Country' => $country,
            'Grade' => $gradeLevel,
            'School name' => $schoolName,
            'School email' => $schoolEmail,
            'Admission number' => $admissionNumber,
          ]);
          $advancedDetails = edit_submission_advanced_encode([
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
          if ($advancedDetails !== '') {
            $submissionDetails = edit_submission_build_details([
              'Type' => $paperType,
              'Journal' => $journal,
              'Age' => $age,
              'Email' => $email,
              'Phone' => $phone,
              'Country' => $country,
              'Grade' => $gradeLevel,
              'School name' => $schoolName,
              'School email' => $schoolEmail,
              'Admission number' => $admissionNumber,
              'Advanced details' => $advancedDetails,
            ]);
          }"""


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
"""

if save_target not in content:
    print("Error: save_target not found!")
else:
    content = content.replace(save_target, save_replace)
    with open("edit-submission.php", "w", encoding="utf-8") as f:
        f.write(content)
    print("Success replacing POST logic.")
