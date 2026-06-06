<?php
require_once __DIR__ . '/includes/bootstrap.php';

$slug = trim((string) ($_GET['slug'] ?? ''));

if ($slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) {
    http_response_code(400);
    die('Invalid slug.');
}

try {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM paper_submissions WHERE slug = ? AND status = 'accepted' LIMIT 1");
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
$authorBio = $paper['author_bio'] ?? '';
$publishedAt = $paper['published_at'] ?? '';

$parsedBioLinks = [];
if (preg_match('/Author \d+:/i', $authorBio)) {
    $chunks = preg_split('/Author \d+:/i', $authorBio);
    foreach ($chunks as $chunk) {
        $chunk = trim($chunk);
        if (empty($chunk)) continue;
        $lines = explode("\n", $chunk);
        $bName = trim($lines[0]);
        $bOrcid = '';
        $bSchool = '';
        for ($i = 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            $colonIdx = strpos($line, ':');
            if ($colonIdx !== false) {
                $k = strtolower(trim(substr($line, 0, $colonIdx)));
                $v = trim(substr($line, $colonIdx + 1));
                if (strpos($k, 'orcid') !== false) $bOrcid = $v;
                if (strpos($k, 'school name') !== false || strpos($k, 'institution') !== false) $bSchool = $v;
            }
        }
        $cName = trim(preg_replace('/[\*\(\d\)]/', '', $bName));
        $parsedBioLinks[$cName] = ['orcid' => $bOrcid, 'school' => $bSchool];
    }
}
$volume = $paper['volume_year'] ?? '';
$issue = $paper['issue_number'] ?? '';
$doi = $paper['doi'] ?? '';
$license = $paper['license_url'] ?? 'https://creativecommons.org/licenses/by/4.0/';
$year = $publishedAt ? date('Y', strtotime($publishedAt)) : date('Y');
$month = $publishedAt ? date('m', strtotime($publishedAt)) : date('m');
$day = $publishedAt ? date('d', strtotime($publishedAt)) : date('d');
$journalTitle = 'Global Youth Science Journal';

header('Content-Type: text/xml; charset=utf-8');
header('Content-Disposition: attachment; filename="jats-' . htmlspecialchars($slug) . '.xml"');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<!DOCTYPE article PUBLIC "-//NLM//DTD JATS (Z39.96) Journal Publishing DTD v1.2 20190208//EN" "JATS-journalpublishing1.dtd">
<article xmlns:mml="http://www.w3.org/1998/Math/MathML" xmlns:xlink="http://www.w3.org/1999/xlink" dtd-version="1.2" article-type="research-article">
  <front>
    <journal-meta>
      <journal-id journal-id-type="publisher-id">GYSJ</journal-id>
      <journal-title-group>
        <journal-title><?php echo htmlspecialchars($journalTitle); ?></journal-title>
      </journal-title-group>
      <publisher>
        <publisher-name>Global Youth Science Journal</publisher-name>
      </publisher>
    </journal-meta>
    <article-meta>
      <?php if ($doi): ?>
      <article-id pub-id-type="doi"><?php echo htmlspecialchars($doi); ?></article-id>
      <?php endif; ?>
      <title-group>
        <article-title><?php echo htmlspecialchars($title); ?></article-title>
      </title-group>
      <contrib-group>
      <?php
      $authorsJsonStr = (string)($paper['authors_json'] ?? '');
      $parsedAuthors = [];
      if ($authorsJsonStr !== '') {
          $parsedAuthors = json_decode($authorsJsonStr, true) ?: [];
      }
      
      $affiliations = [];
      $affilIndex = 1;
      
      if (!empty($parsedAuthors)) {
          foreach ($parsedAuthors as $pa) {
              echo "        <contrib contrib-type=\"author\">\n";
              echo "          <name>\n";
              
              $surname = !empty($pa['surname']) ? $pa['surname'] : $pa['name'];
              echo "            <surname>" . htmlspecialchars($surname) . "</surname>\n";
              
              if (!empty($pa['given_names']) && $pa['given_names'] !== $pa['name']) {
                  echo "            <given-names>" . htmlspecialchars($pa['given_names']) . "</given-names>\n";
              }
              echo "          </name>\n";
              
              if (!empty($pa['orcid'])) {
                  $orcid = $pa['orcid'];
                  if (strpos($orcid, 'http') !== 0) $orcid = 'https://orcid.org/' . $orcid;
                  echo "          <contrib-id contrib-id-type=\"orcid\">" . htmlspecialchars($orcid) . "</contrib-id>\n";
              }
              
              if (!empty($pa['affiliation'])) {
                  $affId = 'aff' . $affilIndex;
                  $affiliations[$affId] = $pa['affiliation'];
                  echo "          <xref ref-type=\"aff\" rid=\"" . $affId . "\">" . $affilIndex . "</xref>\n";
                  $affilIndex++;
              }
              echo "        </contrib>\n";
          }
      } else if ($authorsStr !== '') {
          $parts = explode(',', $authorsStr);
          foreach ($parts as $idx => $a) {
              $clean = trim(preg_replace('/[\*\(\d\)]/', '', $a));
              if ($clean !== '') {
                  echo "        <contrib contrib-type=\"author\">\n";
                  
                  $nameParts = explode(' ', $clean);
                  $surname = array_pop($nameParts);
                  $givenNames = implode(' ', $nameParts);
                  
                  echo "          <name>\n";
                  echo "            <surname>" . htmlspecialchars($surname) . "</surname>\n";
                  if ($givenNames) {
                      echo "            <given-names>" . htmlspecialchars($givenNames) . "</given-names>\n";
                  }
                  echo "          </name>\n";
                  
                  $orcid = $parsedBioLinks[$clean]['orcid'] ?? '';
                  if ($orcid) {
                      if (strpos($orcid, 'http') !== 0) $orcid = 'https://orcid.org/' . $orcid;
                      echo "          <contrib-id contrib-id-type=\"orcid\">" . htmlspecialchars($orcid) . "</contrib-id>\n";
                  }
                  
                  $school = $parsedBioLinks[$clean]['school'] ?? '';
                  if ($school) {
                      $affId = 'aff' . $affilIndex;
                      $affiliations[$affId] = $school;
                      echo "          <xref ref-type=\"aff\" rid=\"" . $affId . "\">" . $affilIndex . "</xref>\n";
                      $affilIndex++;
                  }
                  
                  echo "        </contrib>\n";
              }
          }
      }
      foreach ($affiliations as $id => $school) {
          echo "        <aff id=\"" . $id . "\"><label>" . str_replace('aff', '', $id) . "</label><institution>" . htmlspecialchars($school) . "</institution></aff>\n";
      }
      ?>
      </contrib-group>
      <pub-date publication-format="electronic" date-type="pub" iso-8601-date="<?php echo $publishedAt ? date('Y-m-d', strtotime($publishedAt)) : ''; ?>">
        <day><?php echo $day; ?></day>
        <month><?php echo $month; ?></month>
        <year><?php echo $year; ?></year>
      </pub-date>
      <?php if ($volume): ?>
      <volume><?php echo htmlspecialchars((string)$volume); ?></volume>
      <?php endif; ?>
      <?php if ($issue): ?>
      <issue><?php echo htmlspecialchars((string)$issue); ?></issue>
      <?php endif; ?>
      <permissions>
        <license xlink:href="<?php echo htmlspecialchars($license); ?>">
          <license-p>This is an open-access article distributed under the terms of the Creative Commons Attribution License.</license-p>
        </license>
      </permissions>
      <?php if (!empty($paper['funding_info'])): ?>
      <funding-group>
        <award-group>
          <funding-source>
            <institution-wrap>
              <institution><?php echo htmlspecialchars($paper['funding_info']); ?></institution>
            </institution-wrap>
          </funding-source>
        </award-group>
      </funding-group>
      <?php endif; ?>
      <abstract>
        <p><?php echo htmlspecialchars($abstract); ?></p>
      </abstract>
      <?php if (!empty($paper['keywords'])): ?>
      <kwd-group>
        <?php 
        $kw = explode(',', $paper['keywords']);
        foreach ($kw as $k) {
            $kt = trim($k);
            if ($kt !== '') {
                echo "<kwd>" . htmlspecialchars($kt) . "</kwd>\n";
            }
        }
        ?>
      </kwd-group>
      <?php endif; ?>
    </article-meta>
  </front>
  <body>
    <p>Please download the full PDF to view the manuscript body.</p>
  </body>
</article>
