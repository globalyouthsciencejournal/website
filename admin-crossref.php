<?php
require_once __DIR__ . '/includes/bootstrap.php';
auth_require_admin();

$slug = trim((string) ($_GET['slug'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $slug !== '') {
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM paper_submissions WHERE slug = ? AND status = 'accepted' LIMIT 1");
        $stmt->execute([$slug]);
        $paper = $stmt->fetch();
        
        if (!is_array($paper)) {
            die('Paper not found.');
        }
    } catch (Throwable $e) {
        die('Database error.');
    }
    
    // Fallbacks
    $doi = $paper['doi'] ?: '10.59720/'.date('y').'-'.str_pad((string)$paper['id'], 3, '0', STR_PAD_LEFT);
    $url = 'https://' . ($_SERVER['HTTP_HOST']) . '/publication.php?slug=' . urlencode($slug);
    $title = $paper['title'] ?? '';
    $abstract = $paper['abstract'] ?? '';
    $authorsStr = $paper['authors'] ?? '';
    $publishedAt = $paper['published_at'] ?: date('Y-m-d H:i:s');
    $year = date('Y', strtotime($publishedAt));
    $month = date('m', strtotime($publishedAt));
    $day = date('d', strtotime($publishedAt));
    
    $timestamp = time();
    $batchId = 'gysj_crossref_' . $timestamp;

    header('Content-Type: text/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="crossref-' . htmlspecialchars($slug) . '.xml"');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<doi_batch xmlns="http://www.crossref.org/schema/4.4.2" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" version="4.4.2" xsi:schemaLocation="http://www.crossref.org/schema/4.4.2 http://www.crossref.org/schema/deposit/crossref4.4.2.xsd">
  <head>
    <doi_batch_id><?php echo htmlspecialchars($batchId); ?></doi_batch_id>
    <timestamp><?php echo $timestamp; ?></timestamp>
    <depositor>
      <depositor_name>Global Youth Science Journal</depositor_name>
      <email_address>globalyouthsciencejournal@gmail.com</email_address>
    </depositor>
    <registrant>Global Youth Science Journal</registrant>
  </head>
  <body>
    <journal>
      <journal_metadata>
        <full_title>Global Youth Science Journal</full_title>
        <abbrev_title>GYSJ</abbrev_title>
      </journal_metadata>
      <journal_issue>
        <publication_date media_type="online">
          <month><?php echo $month; ?></month>
          <day><?php echo $day; ?></day>
          <year><?php echo $year; ?></year>
        </publication_date>
      </journal_issue>
      <journal_article publication_type="full_text">
        <titles>
          <title><?php echo htmlspecialchars($title); ?></title>
        </titles>
        <contributors>
        <?php
        $authorsJsonStr = (string)($paper['authors_json'] ?? '');
        $parsedAuthors = [];
        if ($authorsJsonStr !== '') {
            $parsedAuthors = json_decode($authorsJsonStr, true) ?: [];
        }
        
        if (!empty($parsedAuthors)) {
            $seq = 'first';
            foreach ($parsedAuthors as $pa) {
                echo "          <person_name sequence=\"$seq\" contributor_role=\"author\">\n";
                if (!empty($pa['given_names']) && $pa['given_names'] !== $pa['name']) {
                    echo "            <given_name>" . htmlspecialchars($pa['given_names']) . "</given_name>\n";
                }
                $surname = !empty($pa['surname']) ? $pa['surname'] : $pa['name'];
                echo "            <surname>" . htmlspecialchars($surname) . "</surname>\n";
                
                if (!empty($pa['affiliation'])) {
                    echo "            <affiliation>" . htmlspecialchars($pa['affiliation']) . "</affiliation>\n";
                }
                if (!empty($pa['orcid'])) {
                    $orcidUrl = strpos($pa['orcid'], 'http') === 0 ? $pa['orcid'] : 'https://orcid.org/' . $pa['orcid'];
                    echo "            <ORCID>" . htmlspecialchars($orcidUrl) . "</ORCID>\n";
                }
                echo "          </person_name>\n";
                $seq = 'additional';
            }
        } elseif ($authorsStr !== '') {
            $parts = explode(',', $authorsStr);
            $seq = 'first';
            foreach ($parts as $a) {
                $clean = trim(preg_replace('/[\*\(\d\)]/', '', $a));
                if ($clean !== '') {
                    $nameParts = explode(' ', $clean);
                    $surname = count($nameParts) > 1 ? array_pop($nameParts) : $clean;
                    $givenNames = count($nameParts) > 0 ? implode(' ', $nameParts) : '';
                    
                    echo "          <person_name sequence=\"$seq\" contributor_role=\"author\">\n";
                    if ($givenNames !== '') {
                        echo "            <given_name>" . htmlspecialchars($givenNames) . "</given_name>\n";
                    }
                    echo "            <surname>" . htmlspecialchars($surname) . "</surname>\n";
                    echo "          </person_name>\n";
                    $seq = 'additional';
                }
            }
        }
        ?>
        </contributors>
        <publication_date media_type="online">
          <month><?php echo $month; ?></month>
          <day><?php echo $day; ?></day>
          <year><?php echo $year; ?></year>
        </publication_date>
        <doi_data>
          <doi><?php echo htmlspecialchars($doi); ?></doi>
          <resource><?php echo htmlspecialchars($url); ?></resource>
        </doi_data>
      </journal_article>
    </journal>
  </body>
</doi_batch>
<?php
    exit;
}
?>
<!DOCTYPE html>
<html>
<head><title>Admin - Crossref XML Generator</title></head>
<body>
    <h1>Generate Crossref Deposit XML</h1>
    <p>Select an article to generate its Crossref XML deposit file.</p>
    <form method="POST">
        <label>Article Slug:</label>
        <input type="text" name="slug" required>
        <button type="submit">Generate XML</button>
    </form>
</body>
</html>
