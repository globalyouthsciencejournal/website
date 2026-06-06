$files = Get-ChildItem -Path . -Filter "*.php"
foreach ($file in $files) {
    $content = Get-Content -Path $file.FullName -Raw
    if ($content -match '<script src="js/main.js"></script>') {
        $content = $content -replace '<script src="js/main.js"></script>', ''
        $content = $content -replace "<!-- Main -->\r?\n\s*", ''
        $content = $content -replace '</head>', "    <script src=`"js/main.js`" defer></script>`r`n</head>"
        $utf8NoBom = New-Object System.Text.UTF8Encoding $False
        [System.IO.File]::WriteAllText($file.FullName, $content, $utf8NoBom)
        Write-Output $file.Name
    }
}
