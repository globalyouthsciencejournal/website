<?php
require_once __DIR__ . '/includes/bootstrap.php';

$slug = trim((string) ($_GET['slug'] ?? ''));
$format = strtolower(trim((string) ($_GET['format'] ?? 'ris')));

if ($slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) {
    http_response_code(400);
    die('Invalid slug.');
}

if (!in_array($format, ['ris', 'bibtex', 'enw'])) {
    http_response_code(400);
    die('Invalid format. Supported: ris, bibtex, enw.');
}

try {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT title, authors, abstract, published_at, volume_year, issue_number, doi, slug, authors_json FROM paper_submissions WHERE slug = ? AND status = 'accepted' LIMIT 1");
    $stmt->execute([$slug]);
    $paper = $stmt->fetch();
    
    if (!is_array($paper)) {
        http_response_code(404);
        die('Paper not found.');
    }
} catch (Throwable $e) {
    http_response_code(500);
    die('Database error.');
}

$title = $paper['title'] ?? '';
$abstract = $paper['abstract'] ?? '';
$authorsStr = $paper['authors'] ?? '';
$publishedAt = $paper['published_at'] ?? '';
$volume = $paper['volume_year'] ?? '';
$issue = $paper['issue_number'] ?? '';
$doi = $paper['doi'] ?? '';
$year = $publishedAt ? date('Y', strtotime($publishedAt)) : date('Y');
$url = 'https://' . ($_SERVER['HTTP_HOST']) . '/publication.php?slug=' . urlencode($slug);
$journalTitle = 'Global Youth Science Journal';

$authorList = [];
$authorsJsonStr = (string)($paper['authors_json'] ?? '');
if ($authorsJsonStr !== '') {
    $parsedAuthors = json_decode($authorsJsonStr, true) ?: [];
    foreach ($parsedAuthors as $pa) {
        if (!empty($pa['surname']) && !empty($pa['given_names']) && $pa['surname'] !== $pa['name']) {
            $authorList[] = $pa['surname'] . ', ' . $pa['given_names'];
        } else {
            $authorList[] = $pa['name'];
        }
    }
} else if ($authorsStr !== '') {
    $parts = explode(',', $authorsStr);
    foreach ($parts as $a) {
        $clean = trim(preg_replace('/[\*\(\d\)]/', '', $a));
        if ($clean !== '') $authorList[] = $clean;
    }
}

if ($format === 'ris') {
    header('Content-Type: application/x-research-info-systems; charset=utf-8');
    header('Content-Disposition: attachment; filename="citation-' . htmlspecialchars($slug) . '.ris"');
    
    echo "TY  - JOUR\n";
    echo "TI  - $title\n";
    echo "JO  - $journalTitle\n";
    foreach ($authorList as $a) {
        echo "AU  - $a\n";
    }
    echo "PY  - $year\n";
    if ($volume) echo "VL  - $volume\n";
    if ($issue) echo "IS  - $issue\n";
    if ($doi) echo "DO  - $doi\n";
    echo "UR  - $url\n";
    echo "AB  - " . str_replace(["\r\n", "\n", "\r"], " ", $abstract) . "\n";
    echo "ER  - \n";
} else if ($format === 'bibtex') {
    header('Content-Type: application/x-bibtex; charset=utf-8');
    header('Content-Disposition: attachment; filename="citation-' . htmlspecialchars($slug) . '.bib"');
    
    $bibAuthors = implode(' and ', $authorList);
    
    $firstAuthorSurname = 'Anon';
    if (!empty($authorList)) {
        $firstAuthorParts = explode(',', $authorList[0]);
        $firstAuthorSurname = trim($firstAuthorParts[0]);
    }
    $citationKey = 'GYSJ' . $year . ucfirst(strtolower(preg_replace('/[^a-zA-Z]/', '', $firstAuthorSurname)));
    if (empty(preg_replace('/[^a-zA-Z]/', '', $firstAuthorSurname))) {
         $citationKey = 'GYSJ' . $year . preg_replace('/[^a-zA-Z0-9]/', '', substr($authorList[0] ?? 'Anon', 0, 10));
    }
    $abstractClean = str_replace(["\r\n", "\n", "\r"], " ", $abstract);
    
    echo "@article{{$citationKey},\n";
    echo "  title = {{" . $title . "}},\n";
    echo "  author = {{" . $bibAuthors . "}},\n";
    echo "  journal = {{" . $journalTitle . "}},\n";
    echo "  year = {" . $year . "},\n";
    if ($volume) echo "  volume = {" . $volume . "},\n";
    if ($issue) echo "  number = {" . $issue . "},\n";
    if ($doi) echo "  doi = {" . $doi . "},\n";
    echo "  url = {" . $url . "},\n";
    echo "  abstract = {{" . $abstractClean . "}}\n";
    echo "}\n";
} else if ($format === 'enw') {
    header('Content-Type: application/x-endnote-refer; charset=utf-8');
    header('Content-Disposition: attachment; filename="citation-' . htmlspecialchars($slug) . '.enw"');
    
    echo "%0 Journal Article\n";
    echo "%T $title\n";
    echo "%J $journalTitle\n";
    foreach ($authorList as $a) {
        echo "%A $a\n";
    }
    echo "%D $year\n";
    if ($volume) echo "%V $volume\n";
    if ($issue) echo "%N $issue\n";
    if ($doi) echo "%R $doi\n";
    echo "%U $url\n";
    echo "%X " . str_replace(["\r\n", "\n", "\r"], " ", $abstract) . "\n";
}
