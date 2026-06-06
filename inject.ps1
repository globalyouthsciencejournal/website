$editFile = 'edit-submission.php'
$edit = Get-Content $editFile -Raw

$edit = $edit -replace 'name="authors_payload"', 'name="authors_payload" id="submitAuthorsPayload"'

$css = Get-Content 'author_css.txt' -Raw
$edit = $edit -replace '(?s)(</style>)', "
$css
$1"

$js = Get-Content 'authors_js.txt' -Raw
$initJs = "
<script>
$js

document.addEventListener('DOMContentLoaded', function() {
  hydrateAuthorCards();
});
</script>
"
$edit = $edit -replace '(?s)(</body>)', "$initJs
$1"

$edit | Out-File $editFile -Encoding UTF8
