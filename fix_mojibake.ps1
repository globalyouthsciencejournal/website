$path = "d:\global-youth-science-journal-main\global-youth-science-journal-main\GYSJwebsitenew"
$files = Get-ChildItem -Path $path -Filter *.php -Recurse

$replacements = [ordered]@{
    'â€™' = "'"
    'â€œ' = '"'
    'â€ ' = '"'
    'â€”' = '—'
    'â€“' = '–'
    'â€˜' = "'"
    'â†—' = '↗'
    'Â©' = '©'
    'Ã©' = 'é'
    'â€' = '"'
    'Â' = ''
}

foreach ($file in $files) {
    $content = [System.IO.File]::ReadAllText($file.FullName, [System.Text.Encoding]::UTF8)
    $original = $content
    
    foreach ($key in $replacements.Keys) {
        $content = $content.Replace($key, $replacements[$key])
    }
    
    if ($content -cne $original) {
        [System.IO.File]::WriteAllText($file.FullName, $content, [System.Text.Encoding]::UTF8)
        Write-Output "Fixed: $($file.FullName)"
    }
}
Write-Output "Done."
