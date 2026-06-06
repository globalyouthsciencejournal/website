<?php
$files = glob("*.php");

foreach ($files as $file) {
    if (is_file($file)) {
        $content = file_get_contents($file);
        
        // Check if main.js is in the file
        if (strpos($content, '') !== false) {
            
            // Remove it from its current location
            $content = str_replace('', '', $content);
            $content = str_replace("<!-- Main -->\n    \n", '', $content);
            $content = str_replace("<!-- Main -->\r\n    \r\n", '', $content);
            
            // Add it right before     <script src="/js/main.js" defer></script>
</head>
            if (strpos($content, '    <script src="/js/main.js" defer></script>
</head>') !== false) {
                // To avoid breaking dependencies like jQuery which are loaded at the bottom,
                // we should add 'defer' so it executes after HTML parsing is complete.
                $content = str_replace('    <script src="/js/main.js" defer></script>
</head>', "    <script src=\"js/main.js\" defer></script>\n    <script src="/js/main.js" defer></script>
</head>", $content);
                file_put_contents($file, $content);
                echo "Updated $file\n";
            }
        }
    }
}
echo "Done.";
?>
