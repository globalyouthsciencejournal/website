import re

with open("edit-submission.php", "r", encoding="utf-8") as f:
    edit_content = f.read()

with open("user-dashboard.php", "r", encoding="utf-8") as f:
    dashboard_content = f.read()

# 1. Fix CSS
# Extract the author section CSS from user-dashboard.php
css_match = re.search(r'(\.author-section \{.*?\})\s*</style>', dashboard_content, re.DOTALL)
if css_match:
    author_css = css_match.group(1)
else:
    author_css = ""

# Remove any previously injected .author-section CSS from edit-submission.php
edit_content = re.sub(r'\\n\.author-section \{.*?\}\\n', '', edit_content, flags=re.DOTALL)
edit_content = re.sub(r'\n\.author-section \{.*?\n</style>', '\n</style>', edit_content, flags=re.DOTALL)

# Inject the new author_css with proper newlines
edit_content = edit_content.replace('</style>', f"\n{author_css}\n</style>")


# 2. Fix JS
# Extract JS from user-dashboard.php
js_match = re.search(r'(function getAuthorListElement\(\).*?)(?=function initCountrySearch\(\))', dashboard_content, re.DOTALL)
if js_match:
    author_js = js_match.group(1)
else:
    author_js = ""

# Remove previously injected JS from edit-submission.php
# The previously injected JS was right before </body>, and looked like:
# <script>
# function getAuthorListElement()...
# document.addEventListener('DOMContentLoaded', function() {
#   hydrateAuthorCards();
# });
# </script>
edit_content = re.sub(r'\\n<script>\\nfunction getAuthorListElement\(\).*?hydrateAuthorCards\(\);\n\}\);\\n</script>\\n', '', edit_content, flags=re.DOTALL)
edit_content = re.sub(r'\n<script>\nfunction getAuthorListElement\(\).*?hydrateAuthorCards\(\);\n\}\);\n</script>\n', '', edit_content, flags=re.DOTALL)
edit_content = re.sub(r'<script>\s*function getAuthorListElement\(\)[\s\S]*?hydrateAuthorCards\(\);\s*\}\);\s*</script>', '', edit_content, flags=re.DOTALL)

# Inject the new JS
new_js = f"""
<script>
{author_js}

document.addEventListener('DOMContentLoaded', function() {{
  hydrateAuthorCards();
}});
</script>
"""
edit_content = edit_content.replace('</body>', f"\n{new_js}\n</body>")


# 3. Fix literal \n in CSS from earlier mistakes
edit_content = edit_content.replace("\\n", "\n")

# 4. Fix purple accent color
# The author_css from user-dashboard.php might have --submit-focus: #111111; or something.
# The user wants "blue instead of purple". Maybe the button color in edit-submission has a purple hue?
# Let's replace any purple-ish hex codes in author_css if any exist, OR just set --submit-focus to a blue color like #0056b3
# edit-submission.php already uses:
# --edit-focus: #18181b;
# The user says "accent color is blue instead of purple". 
# The edit-submission.php style has:
# .edit-card-title { color: #5a189a; } -> that's purple!
# Let's replace #5a189a with #0d6efd (bootstrap blue) or #000000 (black) or just a nice blue #0056b3
edit_content = edit_content.replace('#5a189a', '#0056b3')
edit_content = edit_content.replace('#7a12d1', '#0056b3')

with open("edit-submission.php", "w", encoding="utf-8") as f:
    f.write(edit_content)
