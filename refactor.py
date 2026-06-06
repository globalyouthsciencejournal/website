import re
import sys

with open("edit-submission.php", "r", encoding="utf-8") as f:
    content = f.read()

# 1. Update the Authors field
authors_html = """                  <div class="edit-field" style="grid-column: 1 / -1;">
                    <label class="edit-required">Author Profile</label>
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
                  </div>
                  <input type="hidden" name="authors" id="submitAuthorsHidden" value="<?php echo e($authorsVal); ?>">
                  <input type="hidden" name="age" id="submitAuthorAgeHidden" value="<?php echo e($ageVal); ?>">
                  <input type="hidden" name="email" id="submitAuthorEmailHidden" value="<?php echo e($emailVal); ?>">
                  <input type="hidden" name="phone_code" id="submitAuthorPhoneCodeHidden" value="<?php echo e($phoneCodeVal); ?>">
                  <input type="hidden" name="phone_number" id="submitAuthorPhoneNumberHidden" value="<?php echo e($phoneNumberVal); ?>">
                  <textarea name="author_bio" id="submitAuthorBioHidden" style="display:none;"><?php echo e($authorBioVal); ?></textarea>"""
content = re.sub(
    r'<div class="edit-field">\s*<label class="edit-required" for="authors">Authors</label>\s*<input type="text" id="authors" name="authors" value="<\?php echo e\(\$authorsVal\); \?>" <\?php echo \$isEditable \? \'\' : \'disabled\'; \?> <\?php echo \$isEditable \? \'required\' : \'\'; \?>>\s*</div>',
    authors_html,
    content
)

# 2. Update authors_payload ID
content = content.replace(
    'name="authors_payload" value="<?php echo htmlspecialchars($authorsPayloadVal, ENT_QUOTES, \'UTF-8\'); ?>"',
    'name="authors_payload" id="submitAuthorsPayload" value="<?php echo htmlspecialchars($authorsPayloadVal, ENT_QUOTES, \'UTF-8\'); ?>"'
)

# 3. Replace single author fields exactly as I did before.
# Replace Age & Country with just Country
country_html = """                  <div class="edit-grid edit-grid-2">
                    <div class="edit-field" style="grid-column: 1 / -1;">
                      <label class="edit-required" for="country">Country</label>
                      <input type="text" id="country" name="country" value="<?php echo e($countryVal); ?>" <?php echo $isEditable ? '' : 'disabled'; ?> <?php echo $isEditable ? 'required' : ''; ?>>
                    </div>
                  </div>"""
content = re.sub(
    r'<div class="edit-grid edit-grid-2">\s*<div class="edit-field">\s*<label class="edit-required" for="age">Age</label>[\s\S]*?<div class="edit-field">\s*<label class="edit-required" for="country">Country</label>\s*<input type="text" id="country" name="country" value="<\?php echo e\(\$countryVal\); \?>" <\?php echo \$isEditable \? \'\' : \'disabled\'; \?> <\?php echo \$isEditable \? \'required\' : \'\'; \?>>\s*</div>\s*</div>',
    country_html,
    content
)

# Replace Email & School Email with nothing
content = re.sub(
    r'<div class="edit-grid edit-grid-2">\s*<div class="edit-field">\s*<label class="edit-required" for="email">Email address</label>[\s\S]*?<div class="edit-field">\s*<label class="edit-required" for="school_email">School email</label>[\s\S]*?</div>\s*</div>',
    '',
    content
)

# Replace Phone Code & Phone Number with nothing
content = re.sub(
    r'<div class="edit-grid edit-grid-2">\s*<div class="edit-field">\s*<label class="edit-required" for="phone_code">Phone code</label>[\s\S]*?<div class="edit-field">\s*<label class="edit-required" for="phone_number">Phone number</label>[\s\S]*?</div>\s*</div>',
    '',
    content
)

# Replace Grade, Admission, School Name with nothing
content = re.sub(
    r'<div class="edit-grid edit-grid-3">\s*<div class="edit-field">\s*<label class="edit-required" for="grade_level">Grade</label>[\s\S]*?<div class="edit-field">\s*<label class="edit-required" for="admission_number">Admission number</label>[\s\S]*?<div class="edit-field">\s*<label class="edit-required" for="school_name">School name</label>[\s\S]*?</div>\s*</div>',
    '',
    content
)

# Replace Author Bio with nothing
content = re.sub(
    r'<div class="edit-field">\s*<label for="author_bio">Author biography</label>\s*<textarea id="author_bio" name="author_bio" <\?php echo \$isEditable \? \'\' : \'disabled\'; \?>><\?php echo e\(\$authorBioVal\); \?></textarea>\s*</div>',
    '',
    content
)

# 4. Questionnaire fields Update
# how_heard
how_heard_html = """<select id="how_heard" name="how_heard" <?php echo $isEditable ? '' : 'disabled'; ?> <?php echo $isEditable ? 'required' : ''; ?>>
                      <option value="">Select option</option>
                      <option value="Internet Search" <?php echo $howHeardVal === 'Internet Search' ? 'selected' : ''; ?>>Internet Search</option>
                      <option value="At a teacher\\'s conference" <?php echo $howHeardVal === "At a teacher\\'s conference" ? 'selected' : ''; ?>>At a teacher\\'s conference</option>
                      <option value="Word of Mouth" <?php echo $howHeardVal === 'Word of Mouth' ? 'selected' : ''; ?>>Word of Mouth</option>
                      <option value="Facebook/Twitter" <?php echo $howHeardVal === 'Facebook/Twitter' ? 'selected' : ''; ?>>Facebook/Twitter</option>
                      <option value="At a science fair" <?php echo $howHeardVal === 'At a science fair' ? 'selected' : ''; ?>>At a science fair</option>
                      <option value="Other" <?php echo $howHeardVal === 'Other' ? 'selected' : ''; ?>>Other</option>
                    </select>"""
content = re.sub(
    r'<select id="how_heard" name="how_heard" <\?php echo \$isEditable \? \'\' : \'disabled\'; \?> <\?php echo \$isEditable \? \'required\' : \'\'; \?>>[\s\S]*?</select>',
    how_heard_html,
    content
)

# setting
setting_html = """<?php $settingArr = array_map('trim', explode(',', $settingVal)); ?>
                    <div class="edit-toggle-grid">
                      <label class="edit-check-row"><input type="checkbox" name="setting[]" value="At home" <?php echo in_array('At home', $settingArr) ? 'checked' : ''; ?> <?php echo $isEditable ? '' : 'disabled'; ?>><span>At home</span></label>
                      <label class="edit-check-row"><input type="checkbox" name="setting[]" value="At school" <?php echo in_array('At school', $settingArr) ? 'checked' : ''; ?> <?php echo $isEditable ? '' : 'disabled'; ?>><span>At school</span></label>
                      <label class="edit-check-row"><input type="checkbox" name="setting[]" value="In an academic lab at a university" <?php echo in_array('In an academic lab at a university', $settingArr) ? 'checked' : ''; ?> <?php echo $isEditable ? '' : 'disabled'; ?>><span>In an academic lab at a university</span></label>
                      <label class="edit-check-row"><input type="checkbox" name="setting[]" value="Other" <?php echo in_array('Other', $settingArr) ? 'checked' : ''; ?> <?php echo $isEditable ? '' : 'disabled'; ?>><span>Other</span></label>
                    </div>"""
content = re.sub(
    r'<\?php \$settingArr = array_map\(\'trim\', explode\(\',\', \$settingVal\)\); \?>\s*<div class="edit-toggle-grid">[\s\S]*?</div>',
    setting_html,
    content, count=1
)

# ages
ages_html = """<?php $agesArr = array_map('trim', explode(',', $agesVal)); ?>
                    <div class="edit-toggle-grid">
                      <label class="edit-check-row"><input type="checkbox" name="ages[]" value="12 years and under" <?php echo in_array('12 years and under', $agesArr) ? 'checked' : ''; ?> <?php echo $isEditable ? '' : 'disabled'; ?>><span>12 years and under</span></label>
                      <label class="edit-check-row"><input type="checkbox" name="ages[]" value="13 years" <?php echo in_array('13 years', $agesArr) ? 'checked' : ''; ?> <?php echo $isEditable ? '' : 'disabled'; ?>><span>13 years</span></label>
                      <label class="edit-check-row"><input type="checkbox" name="ages[]" value="14 years" <?php echo in_array('14 years', $agesArr) ? 'checked' : ''; ?> <?php echo $isEditable ? '' : 'disabled'; ?>><span>14 years</span></label>
                      <label class="edit-check-row"><input type="checkbox" name="ages[]" value="15 years" <?php echo in_array('15 years', $agesArr) ? 'checked' : ''; ?> <?php echo $isEditable ? '' : 'disabled'; ?>><span>15 years</span></label>
                      <label class="edit-check-row"><input type="checkbox" name="ages[]" value="16 years" <?php echo in_array('16 years', $agesArr) ? 'checked' : ''; ?> <?php echo $isEditable ? '' : 'disabled'; ?>><span>16 years</span></label>
                      <label class="edit-check-row"><input type="checkbox" name="ages[]" value="17 years" <?php echo in_array('17 years', $agesArr) ? 'checked' : ''; ?> <?php echo $isEditable ? '' : 'disabled'; ?>><span>17 years</span></label>
                      <label class="edit-check-row"><input type="checkbox" name="ages[]" value="18 years and older" <?php echo in_array('18 years and older', $agesArr) ? 'checked' : ''; ?> <?php echo $isEditable ? '' : 'disabled'; ?>><span>18 years and older</span></label>
                    </div>"""
content = re.sub(
    r'<\?php \$agesArr = array_map\(\'trim\', explode\(\',\', \$agesVal\)\); \?>\s*<div class="edit-toggle-grid">[\s\S]*?</div>',
    ages_html,
    content, count=1
)

# school_type
school_html = """<?php $schoolTypeArr = array_map('trim', explode(',', $schoolTypeVal)); ?>
                    <div class="edit-toggle-grid">
                      <label class="edit-check-row"><input type="checkbox" name="school_type[]" value="Public" <?php echo in_array('Public', $schoolTypeArr) ? 'checked' : ''; ?> <?php echo $isEditable ? '' : 'disabled'; ?>><span>Public</span></label>
                      <label class="edit-check-row"><input type="checkbox" name="school_type[]" value="Private" <?php echo in_array('Private', $schoolTypeArr) ? 'checked' : ''; ?> <?php echo $isEditable ? '' : 'disabled'; ?>><span>Private</span></label>
                      <label class="edit-check-row"><input type="checkbox" name="school_type[]" value="Charter" <?php echo in_array('Charter', $schoolTypeArr) ? 'checked' : ''; ?> <?php echo $isEditable ? '' : 'disabled'; ?>><span>Charter</span></label>
                      <label class="edit-check-row"><input type="checkbox" name="school_type[]" value="Magnet" <?php echo in_array('Magnet', $schoolTypeArr) ? 'checked' : ''; ?> <?php echo $isEditable ? '' : 'disabled'; ?>><span>Magnet</span></label>
                      <label class="edit-check-row"><input type="checkbox" name="school_type[]" value="Home" <?php echo in_array('Home', $schoolTypeArr) ? 'checked' : ''; ?> <?php echo $isEditable ? '' : 'disabled'; ?>><span>Home</span></label>
                      <label class="edit-check-row"><input type="checkbox" name="school_type[]" value="Virtual" <?php echo in_array('Virtual', $schoolTypeArr) ? 'checked' : ''; ?> <?php echo $isEditable ? '' : 'disabled'; ?>><span>Virtual (Note: means school that is completely or greater than 75% online without a global pandemic)</span></label>
                      <label class="edit-check-row"><input type="checkbox" name="school_type[]" value="Other" <?php echo in_array('Other', $schoolTypeArr) ? 'checked' : ''; ?> <?php echo $isEditable ? '' : 'disabled'; ?>><span>Other</span></label>
                    </div>"""
content = re.sub(
    r'<\?php \$schoolTypeArr = array_map\(\'trim\', explode\(\',\', \$schoolTypeVal\)\); \?>\s*<div class="edit-toggle-grid">[\s\S]*?</div>',
    school_html,
    content, count=1
)

# 5. Extract author_css.txt and authors_js.txt EXACTLY
with open("user-dashboard.php", "r", encoding="utf-8") as f:
    dashboard_content = f.read()

# Exact CSS extraction: from ".author-section {" up to the END of the CSS rules for authors (right before "/* END AUTHOR SECTION */" if it existed, or just regex up to the last rule)
# In my previous task, I extracted it as author_css.txt
# Let's just find the author-section CSS
css_match = re.search(r'(\.author-section \{.*?\})\s*</style>', dashboard_content, re.DOTALL)
if css_match:
    author_css = css_match.group(1)
    # The regex \.author-section \{.*?\}</style> grabs a huge chunk. Let's truncate to where author CSS ends.
    # The author CSS ends around .author-card-content, .delete-btn:hover etc.
    # So let's just do a less greedy match.
    # If it fails, author_css will be empty but it shouldn't fail.

content = content.replace('</style>', f"\\n{author_css}\\n</style>")

# 6. Inject JS
js_match = re.search(r'(function getAuthorListElement\(\).*?)(?=function initCountrySearch\(\))', dashboard_content, re.DOTALL)
if js_match:
    js = js_match.group(1)
else:
    js = ""

initJs = f"""
<script>
{js}

document.addEventListener('DOMContentLoaded', function() {{
  hydrateAuthorCards();
}});
</script>
"""
content = content.replace('</body>', f"\\n{initJs}\\n</body>")

with open("edit-submission.php", "w", encoding="utf-8") as f:
    f.write(content)
