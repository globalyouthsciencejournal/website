<?php

$dir = new RecursiveDirectoryIterator(__DIR__);
$iterator = new RecursiveIteratorIterator($dir);

$replacements = [
    ''' => "'",
    '"' => '"',
    '"' => '"',
    '"' => '"', // Sometimes the last char gets truncated or is just "
    '—' => '—',
    '–' => '–',
    '©' => '©',
    '↗' => '↗',
    'é' => 'é',
    ''' => "'",
    '' => '', // often spurious  before non-breaking spaces or just junk
];

// Refine replacements to avoid overlapping issues (longer strings first)
$replacements = [
    ''' => "'",
    '"' => '"',
    '"' => '"',
    '—' => '—',
    '–' => '–',
    ''' => "'",
    '↗' => '↗',
    '©' => '©',
    'é' => 'é',
    '"' => '"', // Fallback for truncated right double quote
    '' => '',
];


foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php' && $file->getFilename() !== 'fix_mojibake.php') {
        $content = file_get_contents($file->getPathname());
        $original = $content;
        
        $content = str_replace(array_keys($replacements), array_values($replacements), $content);
        
        if ($content !== $original) {
            file_put_contents($file->getPathname(), $content);
            echo "Fixed: " . $file->getPathname() . "\n";
        }
    }
}
echo "Done.\n";
