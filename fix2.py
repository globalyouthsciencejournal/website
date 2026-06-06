import re

with open("edit-submission.php", "r", encoding="utf-8") as f:
    content = f.read()

new_parse = '''
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
'''

# Put it right before function edit_submission_detail_value
content = content.replace("function edit_submission_detail_value", new_parse + "\nfunction edit_submission_detail_value")

with open("edit-submission.php", "w", encoding="utf-8") as f:
    f.write(content)
