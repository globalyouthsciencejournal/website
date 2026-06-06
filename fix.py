import re

with open("edit-submission.php", "r", encoding="utf-8") as f:
    content = f.read()

# Remove the one I injected
bad_inject = """function edit_submission_parse_details(string $details): array
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
content = content.replace(bad_inject, "")

# Find the original edit_submission_parse_details and replace it
orig_func = r"function edit_submission_parse_details\(string \$details\): array\s*\{\s*\$parsed = \[\];\s*foreach \(explode\(',', \$details\) as \$part\) \{\s*\$part = trim\(\$part\);\s*if \(\$part === ''\) \{\s*continue;\s*\}\s*\$segments = explode\(':', \$part, 2\);\s*if \(count\(\$segments\) !== 2\) \{\s*continue;\s*\}\s*\$label = strtolower\(trim\(\$segments\[0\]\)\);\s*\$value = trim\(\$segments\[1\]\);\s*if \(\$label !== '' && \$value !== ''\) \{\s*\$parsed\[\$label\] = \$value;\s*\}\s*\}\s*return \$parsed;\s*\}"

new_func = """function edit_submission_parse_details(string $details): array
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

content = re.sub(orig_func, new_func, content, flags=re.DOTALL)

with open("edit-submission.php", "w", encoding="utf-8") as f:
    f.write(content)
