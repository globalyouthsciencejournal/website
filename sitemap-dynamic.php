<?php
require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: text/xml; charset=utf-8');

$host = 'https://' . ($_SERVER['HTTP_HOST']);
$today = date('Y-m-d');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

// Core pages
$pages = [
    '/',
    '/publication.php',
    '/editorial-board.php',
    '/call-for-paper.php',
    '/authorguidelines.php',
    '/our-mission.php',
    '/our-founders.php',
    '/our-funding.php',
    '/contact.php'
];

foreach ($pages as $p) {
    echo "  <url>\n";
    echo "    <loc>" . htmlspecialchars($host . $p) . "</loc>\n";
    echo "    <lastmod>" . date('c', strtotime($today)) . "</lastmod>\n";
    echo "    <changefreq>weekly</changefreq>\n";
    echo "    <priority>" . ($p === '/' ? '1.0' : '0.8') . "</priority>\n";
    echo "  </url>\n";
}

try {
    $pdo = db();
    try {
        $stmt = $pdo->query("SELECT slug, updated_at, published_at, volume_year, issue_number FROM paper_submissions WHERE status = 'accepted' ORDER BY published_at DESC");
    } catch (Throwable $e) {
        $stmt = $pdo->query("SELECT slug, updated_at, published_at FROM paper_submissions WHERE status = 'accepted' ORDER BY published_at DESC");
    }
    $articles = $stmt->fetchAll();
    
    foreach ($articles as $row) {
        $mod = !empty($row['updated_at']) ? date('c', strtotime($row['updated_at'])) : (!empty($row['published_at']) ? date('c', strtotime($row['published_at'])) : date('c', strtotime($today)));
        
        $vol = $row['volume_year'] ?? null;
        $iss = $row['issue_number'] ?? null;
        
        if (!empty($vol) && !empty($iss)) {
            $url = $host . '/publications/' . urlencode($vol) . '/' . urlencode($iss) . '/' . urlencode($row['slug']);
        } else {
            $url = $host . '/publications/' . urlencode($row['slug']);
        }
        
        echo "  <url>\n";
        echo "    <loc>" . htmlspecialchars($url) . "</loc>\n";
        echo "    <lastmod>$mod</lastmod>\n";
        echo "    <changefreq>monthly</changefreq>\n";
        echo "    <priority>0.9</priority>\n";
        echo "  </url>\n";
    }
} catch (Throwable $e) {}

echo '</urlset>';
