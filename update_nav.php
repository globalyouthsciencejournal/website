<?php
$files = glob("*.php");
$target = '<li class="nav-item ">\s*<a class="nav-link" href="contact.php">Contact Us</a>\s*</li>';
$target2 = '<li class="nav-item">\s*<a class="nav-link" href="contact.php">Contact Us</a>\s*</li>';

$replacement = <<<HTML
<li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="dropdownSupport" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Support GYSJ</a>
                            <div class="dropdown-menu" aria-labelledby="dropdownSupport">
                                <a class="dropdown-item" href="contribute.php">Contribute</a>
                                <a class="dropdown-item" href="partners.php">Partners</a>
                            </div>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="contact.php">Contact Us</a>
                        </li>
HTML;

$count = 0;
foreach ($files as $f) {
    $content = file_get_contents($f);
    
    // Check if we already have Support GYSJ
    if (strpos($content, 'Support GYSJ') !== false) {
        continue;
    }

    // Try to replace
    $newContent = preg_replace('/<li class="nav-item\s*">\s*<a class="nav-link" href="contact\.php">Contact Us<\/a>\s*<\/li>/i', $replacement, $content);
    
    if ($newContent && $newContent !== $content) {
        file_put_contents($f, $newContent);
        echo "Updated $f\n";
        $count++;
    }
}
echo "Total files updated: $count\n";
?>
