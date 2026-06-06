$files = Get-ChildItem -Path . -Filter "*.php"
foreach ($file in $files) {
    $content = Get-Content -Path $file.FullName -Raw
    if ($content -match '<script src="js/main.js" defer></script>') {
        $content = $content -replace '<script src="js/main.js" defer></script>', '<script src="/js/main.js" defer></script>'
        $utf8NoBom = New-Object System.Text.UTF8Encoding $False
        [System.IO.File]::WriteAllText($file.FullName, $content, $utf8NoBom)
        Write-Output $file.Name
    }
}
