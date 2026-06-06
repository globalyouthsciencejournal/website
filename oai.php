<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Ensure no output before XML
header('Content-Type: text/xml; charset=utf-8');

$verb = $_GET['verb'] ?? '';
$metadataPrefix = $_GET['metadataPrefix'] ?? '';
$identifier = $_GET['identifier'] ?? '';

$baseUrl = 'https://' . ($_SERVER['HTTP_HOST']) . '/oai.php';
$repositoryName = 'Global Youth Science Journal Repository';
$adminEmail = 'globalyouthsciencejournal@gmail.com';
$earliestDatestamp = '2020-01-01T00:00:00Z';
$currentDate = gmdate('Y-m-d\TH:i:s\Z');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<OAI-PMH xmlns="http://www.openarchives.org/OAI/2.0/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.openarchives.org/OAI/2.0/ http://www.openarchives.org/OAI/2.0/OAI-PMH.xsd">' . "\n";
echo '  <responseDate>' . $currentDate . '</responseDate>' . "\n";

$requestAttrs = '';
foreach ($_GET as $k => $v) {
    $requestAttrs .= ' ' . htmlspecialchars($k) . '="' . htmlspecialchars($v) . '"';
}
echo '  <request' . $requestAttrs . '>' . htmlspecialchars($baseUrl) . '</request>' . "\n";

function printError($code, $message) {
    echo '  <error code="' . htmlspecialchars($code) . '">' . htmlspecialchars($message) . '</error>' . "\n";
    echo '</OAI-PMH>';
    exit;
}

if ($verb === '') {
    printError('badVerb', 'Missing verb argument.');
}

try {
    $pdo = db();
} catch (Throwable $e) {
    printError('badArgument', 'Database error.');
}

function generateMetadata($row, $metadataPrefix) {
    $authorsJsonStr = (string)($row['authors_json'] ?? '');
    $parsedAuthors = [];
    if ($authorsJsonStr !== '') {
        $parsedAuthors = json_decode($authorsJsonStr, true) ?: [];
    }
    
    $articleUrl = 'https://' . ($_SERVER['HTTP_HOST']) . '/publication.php?slug=' . urlencode($row['slug']);
    $pubDate = $row['published_at'] ? date('Y-m-d', strtotime($row['published_at'])) : date('Y-m-d');
    
    if ($metadataPrefix === 'oai_dc') {
        echo "        <oai_dc:dc xmlns:oai_dc=\"http://www.openarchives.org/OAI/2.0/oai_dc/\" xmlns:dc=\"http://purl.org/dc/elements/1.1/\" xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" xsi:schemaLocation=\"http://www.openarchives.org/OAI/2.0/oai_dc/ http://www.openarchives.org/OAI/2.0/oai_dc.xsd\">\n";
        echo "          <dc:title>" . htmlspecialchars($row['title']) . "</dc:title>\n";
        if (!empty($parsedAuthors)) {
            foreach ($parsedAuthors as $pa) {
                echo "          <dc:creator>" . htmlspecialchars($pa['name']) . "</dc:creator>\n";
            }
        } elseif ($row['authors']) {
            $authors = explode(',', $row['authors']);
            foreach ($authors as $a) {
                $clean = trim(preg_replace('/[\*\(\d\)]/', '', $a));
                if ($clean !== '') {
                    echo "          <dc:creator>" . htmlspecialchars($clean) . "</dc:creator>\n";
                }
            }
        }
        if ($row['abstract']) {
            echo "          <dc:description>" . htmlspecialchars($row['abstract']) . "</dc:description>\n";
        }
        if ($row['category']) {
            echo "          <dc:subject>" . htmlspecialchars($row['category']) . "</dc:subject>\n";
        }
        if ($row['keywords']) {
            $kw = explode(',', $row['keywords']);
            foreach ($kw as $k) {
                if (trim($k) !== '') {
                    echo "          <dc:subject>" . htmlspecialchars(trim($k)) . "</dc:subject>\n";
                }
            }
        }
        echo "          <dc:publisher>Global Youth Science Journal</dc:publisher>\n";
        echo "          <dc:date>$pubDate</dc:date>\n";
        echo "          <dc:type>info:eu-repo/semantics/article</dc:type>\n";
        echo "          <dc:format>application/pdf</dc:format>\n";
        echo "          <dc:identifier>$articleUrl</dc:identifier>\n";
        if (!empty($row['doi'])) {
            echo "          <dc:identifier>doi:" . htmlspecialchars($row['doi']) . "</dc:identifier>\n";
        }
        echo "          <dc:language>eng</dc:language>\n";
        echo "        </oai_dc:dc>\n";
    } elseif ($metadataPrefix === 'jats') {
        echo "        <article xmlns:mml=\"http://www.w3.org/1998/Math/MathML\" xmlns:xlink=\"http://www.w3.org/1999/xlink\" dtd-version=\"1.2\" article-type=\"research-article\">\n";
        echo "          <front>\n";
        echo "            <journal-meta>\n";
        echo "              <journal-id journal-id-type=\"publisher-id\">GYSJ</journal-id>\n";
        echo "              <journal-title-group><journal-title>Global Youth Science Journal</journal-title></journal-title-group>\n";
        echo "              <publisher><publisher-name>Global Youth Science Journal</publisher-name></publisher>\n";
        echo "            </journal-meta>\n";
        echo "            <article-meta>\n";
        if (!empty($row['doi'])) {
            echo "              <article-id pub-id-type=\"doi\">" . htmlspecialchars($row['doi']) . "</article-id>\n";
        }
        echo "              <title-group>\n";
        echo "                <article-title>" . htmlspecialchars($row['title']) . "</article-title>\n";
        echo "              </title-group>\n";
        echo "              <contrib-group>\n";
        if (!empty($parsedAuthors)) {
            $affiliations = [];
            $affilIndex = 1;
            foreach ($parsedAuthors as $pa) {
                echo "                <contrib contrib-type=\"author\">\n";
                echo "                  <name>\n";
                $surname = !empty($pa['surname']) ? $pa['surname'] : $pa['name'];
                echo "                    <surname>" . htmlspecialchars($surname) . "</surname>\n";
                if (!empty($pa['given_names']) && $pa['given_names'] !== $pa['name']) {
                    echo "                    <given-names>" . htmlspecialchars($pa['given_names']) . "</given-names>\n";
                }
                echo "                  </name>\n";
                if (!empty($pa['affiliation'])) {
                    $affId = 'aff' . $affilIndex;
                    $affiliations[$affId] = $pa['affiliation'];
                    echo "                  <xref ref-type=\"aff\" rid=\"$affId\">$affilIndex</xref>\n";
                    $affilIndex++;
                }
                echo "                </contrib>\n";
            }
            foreach ($affiliations as $id => $school) {
                echo "                <aff id=\"$id\"><label>" . str_replace('aff', '', $id) . "</label><institution>" . htmlspecialchars($school) . "</institution></aff>\n";
            }
        }
        echo "              </contrib-group>\n";
        echo "              <pub-date publication-format=\"electronic\" date-type=\"pub\" iso-8601-date=\"$pubDate\">\n";
        echo "                <day>" . date('d', strtotime($pubDate)) . "</day>\n";
        echo "                <month>" . date('m', strtotime($pubDate)) . "</month>\n";
        echo "                <year>" . date('Y', strtotime($pubDate)) . "</year>\n";
        echo "              </pub-date>\n";
        echo "              <abstract>\n";
        echo "                <p>" . htmlspecialchars($row['abstract']) . "</p>\n";
        echo "              </abstract>\n";
        echo "            </article-meta>\n";
        echo "          </front>\n";
        echo "        </article>\n";
    }
}

if ($verb === 'Identify') {
    echo "  <Identify>\n";
    echo "    <repositoryName>$repositoryName</repositoryName>\n";
    echo "    <baseURL>$baseUrl</baseURL>\n";
    echo "    <protocolVersion>2.0</protocolVersion>\n";
    echo "    <adminEmail>$adminEmail</adminEmail>\n";
    echo "    <earliestDatestamp>$earliestDatestamp</earliestDatestamp>\n";
    echo "    <deletedRecord>no</deletedRecord>\n";
    echo "    <granularity>YYYY-MM-DDThh:mm:ssZ</granularity>\n";
    echo "  </Identify>\n";
} elseif ($verb === 'ListMetadataFormats') {
    echo "  <ListMetadataFormats>\n";
    echo "    <metadataFormat>\n";
    echo "      <metadataPrefix>oai_dc</metadataPrefix>\n";
    echo "      <schema>http://www.openarchives.org/OAI/2.0/oai_dc.xsd</schema>\n";
    echo "      <metadataNamespace>http://www.openarchives.org/OAI/2.0/oai_dc/</metadataNamespace>\n";
    echo "    </metadataFormat>\n";
    echo "    <metadataFormat>\n";
    echo "      <metadataPrefix>jats</metadataPrefix>\n";
    echo "      <schema>https://jats.nlm.nih.gov/publishing/1.2/JATS-journalpublishing1.xsd</schema>\n";
    echo "      <metadataNamespace>http://www.ncbi.nlm.nih.gov/JATS1</metadataNamespace>\n";
    echo "    </metadataFormat>\n";
    echo "  </ListMetadataFormats>\n";
} elseif ($verb === 'ListSets') {
    echo "  <ListSets>\n";
    echo "    <set>\n";
    echo "      <setSpec>articles</setSpec>\n";
    echo "      <setName>Published Articles</setName>\n";
    echo "    </set>\n";
    echo "  </ListSets>\n";
} elseif ($verb === 'ListIdentifiers' || $verb === 'ListRecords') {
    $resumptionToken = $_GET['resumptionToken'] ?? '';
    $limit = 50;
    $offset = 0;
    
    if ($resumptionToken !== '') {
        if (is_numeric($resumptionToken)) {
            $offset = (int) $resumptionToken;
        } else {
            printError('badResumptionToken', 'Invalid resumptionToken.');
        }
    } else {
        if ($metadataPrefix !== 'oai_dc' && $metadataPrefix !== 'jats') {
            printError('cannotDisseminateFormat', 'Only oai_dc and jats are supported.');
        }
    }
    
    $stmtCount = $pdo->query("SELECT COUNT(*) FROM paper_submissions WHERE status = 'accepted'");
    $total = (int) $stmtCount->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT slug, title, authors, abstract, keywords, published_at, category, doi, updated_at, authors_json FROM paper_submissions WHERE status = 'accepted' ORDER BY published_at DESC LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $records = $stmt->fetchAll();
    
    if (empty($records) && $offset == 0) {
        printError('noRecordsMatch', 'No records found.');
    }
    
    echo "  <$verb>\n";
    foreach ($records as $row) {
        $id = "oai:globalyouthsciencejournal:" . $row['slug'];
        $date = gmdate('Y-m-d\TH:i:s\Z', strtotime($row['updated_at'] ?: $row['published_at'] ?: '2026-05-30'));
        echo "    <header>\n";
        echo "      <identifier>$id</identifier>\n";
        echo "      <datestamp>$date</datestamp>\n";
        echo "      <setSpec>articles</setSpec>\n";
        echo "    </header>\n";
        
        if ($verb === 'ListRecords') {
            echo "    <metadata>\n";
            generateMetadata($row, $metadataPrefix);
            echo "    </metadata>\n";
        }
    }
    
    $nextOffset = $offset + $limit;
    if ($nextOffset < $total) {
        echo "    <resumptionToken completeListSize=\"$total\" cursor=\"$offset\">$nextOffset</resumptionToken>\n";
    } elseif ($resumptionToken !== '') {
        echo "    <resumptionToken completeListSize=\"$total\" cursor=\"$offset\"></resumptionToken>\n";
    }
    
    echo "  </$verb>\n";
} elseif ($verb === 'GetRecord') {
    if ($metadataPrefix !== 'oai_dc' && $metadataPrefix !== 'jats') {
        printError('cannotDisseminateFormat', 'Only oai_dc and jats are supported.');
    }
    if ($identifier === '') {
        printError('badArgument', 'Missing identifier.');
    }
    
    $prefix = "oai:globalyouthsciencejournal:";
    if (strpos($identifier, $prefix) !== 0) {
        printError('idDoesNotExist', 'Invalid identifier format.');
    }
    
    $slug = substr($identifier, strlen($prefix));
    
    $stmt = $pdo->prepare("SELECT slug, title, authors, abstract, keywords, published_at, category, doi, updated_at, authors_json FROM paper_submissions WHERE status = 'accepted' LIMIT 1");
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    
    if (!$row) {
        printError('idDoesNotExist', 'Record not found.');
    }
    
    $date = gmdate('Y-m-d\TH:i:s\Z', strtotime($row['updated_at'] ?: $row['published_at'] ?: '2026-05-30'));
    
    echo "  <GetRecord>\n";
    echo "    <record>\n";
    echo "      <header>\n";
    echo "        <identifier>$identifier</identifier>\n";
    echo "        <datestamp>$date</datestamp>\n";
    echo "        <setSpec>articles</setSpec>\n";
    echo "      </header>\n";
    echo "      <metadata>\n";
    generateMetadata($row, $metadataPrefix);
    echo "      </metadata>\n";
    echo "    </record>\n";
    echo "  </GetRecord>\n";
} else {
    printError('badVerb', 'Unsupported verb.');
}

echo '</OAI-PMH>';
