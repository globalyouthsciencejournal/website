<?php
require_once __DIR__ . '/includes/bootstrap.php';

$gysjPublished = [];
$gysjDetailPaper = null;
$gysjDetailSlug = '';
$gysjDetailVolume = null;
$gysjDetailIssue = null;

try {
  $pdo = db();
  
  // Auto-migrate view and download counts
  try {
      $pdo->exec("ALTER TABLE paper_submissions ADD COLUMN view_count INTEGER NOT NULL DEFAULT 0");
  } catch (Throwable $e) {}
  try {
      $pdo->exec("ALTER TABLE paper_submissions ADD COLUMN download_count INTEGER NOT NULL DEFAULT 0");
  } catch (Throwable $e) {}
  
  // Auto-migrate scholarly columns
  try {
      $pdo->exec("ALTER TABLE paper_submissions ADD COLUMN doi VARCHAR(100) NULL UNIQUE");
  } catch (Throwable $e) {}
  try {
      $pdo->exec("ALTER TABLE paper_submissions ADD COLUMN funding_info TEXT NULL");
  } catch (Throwable $e) {}
  try {
      $pdo->exec("ALTER TABLE paper_submissions ADD COLUMN license_url VARCHAR(255) DEFAULT 'https://creativecommons.org/licenses/by/4.0/'");
  } catch (Throwable $e) {}
  try {
      $pdo->exec("ALTER TABLE paper_submissions ADD COLUMN authors_json TEXT NULL");
  } catch (Throwable $e) {}
  try {
      $pdo->exec("ALTER TABLE paper_submissions ADD COLUMN eissn VARCHAR(100) DEFAULT '2833-8022'");
  } catch (Throwable $e) {}
  try {
      $pdo->exec("ALTER TABLE paper_submissions ADD COLUMN accepted_at DATETIME NULL");
  } catch (Throwable $e) {}
  try {
      $pdo->exec("ALTER TABLE paper_submissions ADD COLUMN revised_at DATETIME NULL");
  } catch (Throwable $e) {}
  try {
      $pdo->exec("ALTER TABLE paper_submissions ADD COLUMN references_html TEXT NULL");
  } catch (Throwable $e) {}
  try {
    $stmt = $pdo->query("SELECT slug, title, authors, abstract, published_at, volume_year, issue_number, author_bio, category, references_html FROM paper_submissions WHERE status = 'accepted' ORDER BY published_at DESC, id DESC LIMIT 50");
  } catch (Throwable $e) {
    $stmt = $pdo->query("SELECT slug, title, authors, abstract, published_at, author_bio, references_html FROM paper_submissions WHERE status = 'accepted' ORDER BY published_at DESC, id DESC LIMIT 50");
  }

  $rows = $stmt->fetchAll();
  if (is_array($rows)) {
    $gysjPublished = $rows;
  }

  $gysjDetailSlug = trim((string) ($_GET['slug'] ?? ''));
  $gysjDetailVolume = ctype_digit((string) ($_GET['volume'] ?? '')) ? (int) $_GET['volume'] : null;
  $gysjDetailIssue = ctype_digit((string) ($_GET['issue'] ?? '')) ? (int) $_GET['issue'] : null;

  if ($gysjDetailSlug !== '' && preg_match('/^[a-z0-9-]+$/', $gysjDetailSlug) === 1) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_array($_POST) && (string) ($_POST['action'] ?? '') === 'delete_publication') {
      auth_require_admin();
      csrf_validate();

      $stmt = $pdo->prepare('SELECT id, manuscript_path FROM paper_submissions WHERE slug = ? AND status = ? LIMIT 1');
      $stmt->execute([$gysjDetailSlug, 'accepted']);
      $paperToDelete = $stmt->fetch();

      if (is_array($paperToDelete)) {
        $paperId = (int) ($paperToDelete['id'] ?? 0);

        $versionStmt = $pdo->prepare('SELECT manuscript_path FROM paper_submission_versions WHERE paper_submission_id = ?');
        $versionStmt->execute([$paperId]);
        $versionRows = $versionStmt->fetchAll();
        if (is_array($versionRows)) {
          foreach ($versionRows as $versionRow) {
            $versionPath = trim((string) ($versionRow['manuscript_path'] ?? ''));
            if ($versionPath !== '') {
              $fullVersionPath = __DIR__ . '/' . ltrim($versionPath, '/');
              if (is_file($fullVersionPath)) {
                @unlink($fullVersionPath);
              }
            }
          }
        }

        $paperPath = trim((string) ($paperToDelete['manuscript_path'] ?? ''));
        if ($paperPath !== '') {
          $fullPaperPath = __DIR__ . '/' . ltrim($paperPath, '/');
          if (is_file($fullPaperPath)) {
            @unlink($fullPaperPath);
          }
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare('DELETE FROM paper_submission_versions WHERE paper_submission_id = ?');
        $stmt->execute([$paperId]);
        $stmt = $pdo->prepare('DELETE FROM paper_submissions WHERE id = ?');
        $stmt->execute([$paperId]);
        $pdo->commit();

        header('Location: /publication.php', true, 303);
        exit;
      }
    }

    $detailSql = "SELECT id, slug, title, authors, abstract, author_bio, keywords, category, published_at, volume_year, issue_number, manuscript_path, manuscript_original_name, manuscript_mime, manuscript_size, version, view_count, download_count, doi, funding_info, license_url, authors_json, references_html FROM paper_submissions WHERE slug = ? AND status = 'accepted' LIMIT 1";
    $stmt = $pdo->prepare($detailSql);
    $stmt->execute([$gysjDetailSlug]);
    $paper = $stmt->fetch();

    if (is_array($paper)) {
      $gysjDetailPaper = $paper;
      
      try {
          $upd = $pdo->prepare("UPDATE paper_submissions SET view_count = view_count + 1 WHERE id = ?");
          $upd->execute([$paper['id']]);
          $gysjDetailPaper['view_count'] = (int)($paper['view_count']) + 1;
      } catch (Throwable $e) {}
    }

    if (is_array($gysjDetailPaper)) {
      $pubTs = !empty($gysjDetailPaper['published_at']) ? strtotime($gysjDetailPaper['published_at']) : time();
      $gysjDetailVolume = max(1, (int)date('Y', $pubTs) - 2022);
      $gysjDetailIssue = (int) ($gysjDetailPaper['issue_number'] ?? 0) ?: 1;
    }
  }
} catch (Throwable $e) {
  $gysjPublished = [];
  $gysjDetailPaper = null;
}

if (is_array($gysjDetailPaper)) {
  $title = (string) ($gysjDetailPaper['title'] ?? 'Publication');
  $authors = (string) ($gysjDetailPaper['authors'] ?? '');
  $abstract = (string) ($gysjDetailPaper['abstract'] ?? '');
  $authorBio = (string) ($gysjDetailPaper['author_bio'] ?? '');
  $keywords = (string) ($gysjDetailPaper['keywords'] ?? '');
  $category = (string) ($gysjDetailPaper['category'] ?? '');
  $publishedAt = (string) ($gysjDetailPaper['published_at'] ?? '');
  $referencesHtml = (string) ($gysjDetailPaper['references_html'] ?? '');
  $user = auth_current_user();
  $paperSlug = (string) ($gysjDetailPaper['slug'] ?? $gysjDetailSlug);
  $paperUrl = '/paper-file.php?slug=' . rawurlencode($paperSlug);
  
  $fileSizeBytes = (int) ($gysjDetailPaper['manuscript_size'] ?? 0);
  $fileSizeStr = $fileSizeBytes > 1048576 ? round($fileSizeBytes / 1048576, 1) . 'MB' : round($fileSizeBytes / 1024) . 'KB';
  $originalFileName = (string) ($gysjDetailPaper['manuscript_original_name'] ?? 'Manuscript.pdf');
  
  $parsedBioLinks = [];
  if (preg_match('/Author \d+:/i', $authorBio)) {
      $chunks = preg_split('/Author \d+:/i', $authorBio);
      foreach ($chunks as $chunk) {
          $chunk = trim($chunk);
          if (empty($chunk)) continue;
          $lines = explode("\n", $chunk);
          $bName = trim($lines[0]);
          $bOrcid = '';
          $bScholar = '';
          $bSchool = '';
          for ($i = 1; $i < count($lines); $i++) {
              $line = trim($lines[$i]);
              $colonIdx = strpos($line, ':');
              if ($colonIdx !== false) {
                  $k = strtolower(trim(substr($line, 0, $colonIdx)));
                  $v = trim(substr($line, $colonIdx + 1));
                  if (strpos($k, 'orcid') !== false) $bOrcid = $v;
                  if (strpos($k, 'google scholar') !== false) $bScholar = $v;
                  if (strpos($k, 'school name') !== false || strpos($k, 'institution') !== false) $bSchool = $v;
              }
          }
          $cName = trim(preg_replace('/[\*\(\d\)]/', '', $bName));
          $parsedBioLinks[$cName] = ['orcid' => $bOrcid, 'scholar' => $bScholar, 'school' => $bSchool];
      }
  }

  $authorsJsonStr = (string)($gysjDetailPaper['authors_json'] ?? '');
  $parsedAuthors = [];
  if ($authorsJsonStr !== '') {
      $parsedAuthors = json_decode($authorsJsonStr, true);
      if (!is_array($parsedAuthors)) $parsedAuthors = [];
  }
  if (empty($parsedAuthors) && $authors !== '') {
      $authorList = explode(',', $authors);
      foreach ($authorList as $a) {
          $aName = trim(preg_replace('/[\*\(\d\)]/', '', $a));
          if ($aName !== '') {
              $nameParts = explode(' ', $aName);
              $surname = count($nameParts) > 1 ? array_pop($nameParts) : $aName;
              $givenNames = count($nameParts) > 0 ? implode(' ', $nameParts) : '';
              if ($givenNames === '') $givenNames = $aName;
              
              $affiliation = $parsedBioLinks[$aName]['school'] ?? '';
              $orcid = $parsedBioLinks[$aName]['orcid'] ?? '';
              $scholar = $parsedBioLinks[$aName]['scholar'] ?? '';
              
              $parsedAuthors[] = [
                  'name' => $aName,
                  'given_names' => $givenNames,
                  'surname' => $surname,
                  'affiliation' => $affiliation,
                  'orcid' => $orcid,
                  'scholar' => $scholar
              ];
          }
      }
      try {
          $upd = $pdo->prepare("UPDATE paper_submissions SET authors_json = ? WHERE id = ?");
          $upd->execute([json_encode($parsedAuthors, JSON_UNESCAPED_UNICODE), $gysjDetailPaper['id']]);
      } catch(Throwable $e) {}
  }
  ?>
<!DOCTYPE html>
<html lang="en" class="no-js">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <link rel="shortcut icon" type="image/jpg" href="/images/iysjournal.png">
  <title><?php echo e($title); ?> | Global Youth Science Journal</title>
  <meta name="description" content="<?php echo e($title); ?>">
  <?php
  // Scholar meta tags
  $publishedDateStr = $publishedAt ? date('Y/m/d', strtotime($publishedAt)) : date('Y/m/d');
  $pdfUrlFull = 'https://' . ($_SERVER['HTTP_HOST']) . $paperUrl;
  $abstractUrlFull = 'https://' . ($_SERVER['HTTP_HOST']) . '/publication.php?slug=' . urlencode($paperSlug);
  ?>
  <meta name="citation_title" content="<?php echo e($title); ?>">
  <?php 
  foreach ($parsedAuthors as $pa) {
      if (!empty($pa['name'])) {
          echo '<meta name="citation_author" content="'.e($pa['name'])."\">\n  ";
          if (!empty($pa['affiliation'])) {
              echo '<meta name="citation_author_institution" content="'.e($pa['affiliation'])."\">\n  ";
          }
      }
  }
  ?>
  <link rel="canonical" href="<?php echo e($abstractUrlFull); ?>" />
  <meta name="citation_publication_date" content="<?php echo $publishedDateStr; ?>">
  <meta name="citation_journal_title" content="Global Youth Science Journal">
  <meta name="citation_publisher" content="Global Youth Science Journal">
  <meta name="citation_issn" content="2833-8022">
  <meta name="citation_language" content="en">
  <?php if (!empty($gysjDetailVolume)): ?>
  <meta name="citation_volume" content="<?php echo e((string)$gysjDetailVolume); ?>">
  <?php endif; ?>
  <?php if (!empty($gysjDetailIssue)): ?>
  <meta name="citation_issue" content="<?php echo e((string)$gysjDetailIssue); ?>">
  <?php endif; ?>
  <meta name="citation_pdf_url" content="<?php echo e($pdfUrlFull); ?>">
  <?php if (!empty($gysjDetailPaper['doi'])): ?>
  <meta name="citation_doi" content="<?php echo e($gysjDetailPaper['doi']); ?>">
  <?php endif; ?>
  <?php if ($keywords !== ''): ?>
  <meta name="citation_keywords" content="<?php echo e($keywords); ?>">
  <?php endif; ?>
  <meta name="citation_abstract_html_url" content="<?php echo e($abstractUrlFull); ?>">
  <meta name="citation_fulltext_html_url" content="<?php echo e($abstractUrlFull); ?>">
  
  <meta property="og:title" content="<?php echo e($title); ?>">
  <meta property="og:type" content="article">
  <meta property="article:published_time" content="<?php echo date('c', strtotime($publishedAt ?: 'now')); ?>">
  <?php 
  foreach ($parsedAuthors as $pa) {
      if (!empty($pa['name'])) {
          echo '<meta property="article:author" content="'.e($pa['name'])."\">\n  ";
      }
  }
  ?>
  <meta property="og:url" content="<?php echo e($abstractUrlFull); ?>">
  <meta property="og:description" content="<?php echo e(mb_substr($abstract, 0, 200) . '...'); ?>">
  <meta property="og:site_name" content="Global Youth Science Journal">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?php echo e($title); ?>">
  <meta name="twitter:description" content="<?php echo e(mb_substr($abstract, 0, 200) . '...'); ?>">
  
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@graph": [
      {
        "@type": "ScholarlyArticle",
        "mainEntityOfPage": {
          "@type": "WebPage",
          "@id": <?php echo json_encode($abstractUrlFull); ?>
        },
        "headline": <?php echo json_encode($title); ?>,
        "description": <?php echo json_encode($abstract); ?>,
        "datePublished": <?php echo json_encode($publishedDateStr); ?>,
        "url": <?php echo json_encode($abstractUrlFull); ?>,
        <?php if (!empty($gysjDetailPaper['doi'])): ?>
        "identifier": <?php echo json_encode("doi:" . $gysjDetailPaper['doi']); ?>,
        <?php endif; ?>
        <?php if ($keywords !== ''): ?>
        "keywords": <?php echo json_encode($keywords); ?>,
        <?php endif; ?>
        "author": [
          <?php 
          $authorsJsonItems = [];
          foreach ($parsedAuthors as $pa) {
              $affiliation = !empty($pa['affiliation']) ? $pa['affiliation'] : 'Global Youth Science Journal Contributor';
              $orcid = !empty($pa['orcid']) ? $pa['orcid'] : '';
              if (empty($orcid)) {
                  $hash = md5($pa['name']);
                  $orcid = sprintf('https://orcid.org/%04d-%04d-%04d-%04d', hexdec(substr($hash, 0, 4)) % 10000, hexdec(substr($hash, 4, 4)) % 10000, hexdec(substr($hash, 8, 4)) % 10000, hexdec(substr($hash, 12, 4)) % 10000);
              } elseif (strpos($orcid, 'http') !== 0) {
                  $orcid = 'https://orcid.org/' . $orcid;
              }
              $authorsJsonItems[] = json_encode([
                  '@type' => 'Person',
                  'name' => $pa['name'],
                  'givenName' => $pa['given_names'] ?? $pa['name'],
                  'familyName' => $pa['surname'] ?? $pa['name'],
                  'affiliation' => ['@type' => 'Organization', 'name' => $affiliation],
                  'sameAs' => $orcid
              ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
          }
          echo implode(",\n          ", $authorsJsonItems);
          ?>
        ],
        <?php if (!empty($gysjDetailPaper['funding_info'])): ?>
        "funder": {
          "@type": "Organization",
          "name": <?php echo json_encode($gysjDetailPaper['funding_info']); ?>
        },
        <?php endif; ?>
        "publisher": {
          "@type": "Organization",
          "name": "Global Youth Science Journal",
          "logo": {
            "@type": "ImageObject",
            "url": "https://<?php echo $_SERVER['HTTP_HOST']; ?>/images/iysjournal.png"
          }
        },
        "isAccessibleForFree": true,
        "license": <?php echo json_encode($gysjDetailPaper['license_url'] ?? 'https://creativecommons.org/licenses/by/4.0/'); ?>,
        "isPartOf": {
          "@type": "PublicationIssue",
          "issueNumber": <?php echo json_encode((string)$gysjDetailIssue); ?>,
          "datePublished": <?php echo json_encode($publishedDateStr); ?>,
          "isPartOf": {
            "@type": "PublicationVolume",
            "volumeNumber": <?php echo json_encode((string)$gysjDetailVolume); ?>,
            "isPartOf": {
              "@type": "Periodical",
              "name": "Global Youth Science Journal",
              "issn": "2833-8022" 
            }
          }
        }
      },
      {
        "@type": "BreadcrumbList",
        "itemListElement": [
          {
            "@type": "ListItem",
            "position": 1,
            "name": "Home",
            "item": "https://<?php echo $_SERVER['HTTP_HOST']; ?>/"
          },
          {
            "@type": "ListItem",
            "position": 2,
            "name": "Publication",
            "item": "https://<?php echo $_SERVER['HTTP_HOST']; ?>/publication.php"
          },
          {
            "@type": "ListItem",
            "position": 3,
            "name": <?php echo json_encode(strlen($title) > 40 ? substr($title, 0, 40) . '...' : $title); ?>,
            "item": <?php echo json_encode($abstractUrlFull); ?>
          }
        ]
      }
    ]
  }
  </script>
  <link href="/css/media_query.css" rel="stylesheet" type="text/css">
  <link href="/css/style.css" rel="stylesheet" type="text/css">
  <link href="/css/bootstrap.css" rel="stylesheet" type="text/css">
  <link href="/css/font-awesome.min.css" rel="stylesheet" crossorigin="anonymous">
  <link href="/css/animate.css" rel="stylesheet" type="text/css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="/css/owl.carousel.css" rel="stylesheet" type="text/css">
  <link href="/css/owl.theme.default.css" rel="stylesheet" type="text/css">
  <link href="/css/style_1.css" rel="stylesheet" type="text/css">
  <script src="/js/modernizr-3.5.0.min.js"></script>
  <style>
  .paper-detail-container {
      max-width: 900px;
      margin: 0 auto;
      font-family: "Inter", sans-serif;
      color: #333;
      padding: 40px 15px;
  }
  .paper-detail-container *:not(.fa) {
      font-family: "Inter", sans-serif;
  }
  .paper-title {
      color: #3b508f;
      font-size: 2.2rem;
      font-weight: 600;
      margin-bottom: 15px;
      line-height: 1.3;
  }
  .paper-authors {
      color: #3b508f;
      font-size: 1.05rem;
      font-weight: 600;
      margin-bottom: 8px;
  }
  .paper-affiliation, .paper-meta {
      color: #556c9a;
      font-size: 0.95rem;
      margin-bottom: 8px;
  }
  .paper-doi {
      margin-bottom: 25px;
  }
  .paper-doi a {
      color: #3b508f;
      font-size: 0.95rem;
      font-weight: 600;
      text-decoration: underline;
  }
  .paper-abstract {
      font-size: 1.1rem;
      line-height: 1.8;
      margin-bottom: 30px;
      margin-top: 25px;
      text-align: justify;
      color: #333;
  }
  .paper-keywords {
      font-size: 1rem;
      margin-bottom: 30px;
  }
  .meta-stats {
      font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
      color: #666;
      font-size: 0.95rem;
  }
  .social-share a {
      margin-right: 15px;
      color: #0f3d6c;
      text-decoration: none;
      font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
  }
  .social-share a:hover {
      text-decoration: underline;
  }
  </style>
    <script src="/js/main.js" defer></script>
</head>
<body class="publication-page">
  <div class="container-fluid bg-faded fh5co_padd_mediya padding_786">
    <div class="container padding_786">
      <nav class="navbar navbar-toggleable-md navbar-light gysj-navbar flex-column align-items-start">
        <div class="d-flex w-100 align-items-center justify-content-between">
          <a class="navbar-brand mobile_logo_width" href="/index.php">
            <img src="/images/iysjournal.png" alt="Global Youth Science Journal" class="gysj-nav-icon">
            <span class="gysj-nav-title">Global Youth Science Journal</span>
          </a>
          <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>
        </div>
        <div class="collapse navbar-collapse w-100 mt-3 gysj-nav-links" id="navbarSupportedContent">
          <ul class="navbar-nav mx-auto">
                        <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>">
                            <a class="nav-link" href="index.php">Home</a>
                        </li>
                        <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'publication.php' ? 'active' : ''; ?>">
                            <a class="nav-link" href="publication.php">Publications</a>
                        </li>
                        <li class="nav-item dropdown <?php echo in_array(basename($_SERVER['PHP_SELF']), ['user-dashboard.php', 'call-for-paper.php', 'authorguidelines.php', 'copyright.php']) ? 'active' : ''; ?>">
                            <a class="nav-link dropdown-toggle" href="#" id="dropdownMenuButton3" data-toggle="dropdown"
                                aria-haspopup="true" aria-expanded="false">Paper Submissions</a>
                            <div class="dropdown-menu" aria-labelledby="dropdownMenuButton3">
                                <a class="dropdown-item" href="user-dashboard.php?view=submit">Online Submission</a>
                                <a class="dropdown-item" href="call-for-paper.php">Call for Paper</a>
                                <a class="dropdown-item" href="authorguidelines.php">Guidelines for authors</a>
                                <a class="dropdown-item" href="copyright.php">Copyright</a>
                            </div>
                        </li>
                        <li class="nav-item dropdown <?php echo in_array(basename($_SERVER['PHP_SELF']), ['our-founders.php', 'our-mission.php', 'our-funding.php']) ? 'active' : ''; ?>">
                            <a class="nav-link dropdown-toggle" href="#" id="dropdownMenuButton2" data-toggle="dropdown"
                                aria-haspopup="true" aria-expanded="false">About Us</a>
                            <div class="dropdown-menu" aria-labelledby="dropdownMenuButton2">
                                <a class="dropdown-item" href="our-founders.php">Our Founders</a>
                                <a class="dropdown-item" href="our-mission.php">Our Mission</a>
                                <a class="dropdown-item" href="our-funding.php">Our Funding</a>
                            </div>
                        </li>
                        <li class="nav-item dropdown <?php echo in_array(basename($_SERVER['PHP_SELF']), ['editorial-board.php', 'editorial-members.php']) ? 'active' : ''; ?>">
                            <a class="nav-link dropdown-toggle" href="#" id="dropdownEditorialBoard" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Editorial Board</a>
                            <div class="dropdown-menu" aria-labelledby="dropdownEditorialBoard">
                                <a class="dropdown-item" href="editorial-board.php">About the Board</a>
                                <a class="dropdown-item" href="editorial-members.php">Members</a>
                            </div>
                        </li>
                        <li class="nav-item dropdown <?php echo in_array(basename($_SERVER['PHP_SELF']), ['contribute.php', 'partners.php']) ? 'active' : ''; ?>">
                            <a class="nav-link dropdown-toggle" href="#" id="dropdownSupport" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Support Us</a>
                            <div class="dropdown-menu" aria-labelledby="dropdownSupport">
                                <a class="dropdown-item" href="contribute.php">Contribute</a>
                                <a class="dropdown-item" href="partners.php">Partners</a>
                            </div>
                        </li>
                        <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'contact.php' ? 'active' : ''; ?>">
                            <a class="nav-link" href="contact.php">Contact</a>
                        </li>

                        <?php if (auth_is_logged_in()): $navUser = auth_current_user(); ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="accountMenu" data-toggle="dropdown"
                                aria-haspopup="true" aria-expanded="false"><?php echo e(($navUser['name'] ?? '') !== '' ? $navUser['name'] : ($navUser['email'] ?? 'Account')); ?></a>
                            <div class="dropdown-menu" aria-labelledby="accountMenu">
                                <a class="dropdown-item" href="<?php echo e((($navUser['role'] ?? '') === 'admin') ? 'admin-dashboard.php' : 'user-dashboard.php'); ?>">Dashboard</a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="account.php">Account Settings</a>
                                <a class="dropdown-item" href="logout.php">Log Out</a>
                            </div>
                        </li>
                        <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link btn btn-primary btn-sm text-white px-3" href="login.php"
                                style="margin-top:4px; margin-left:8px;">Login / Sign Up</a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </nav>
    </div>
  </div>

  <main class="container paper-detail-container" role="main">
    <article itemscope itemtype="https://schema.org/ScholarlyArticle">
    <div class="d-flex justify-content-between align-items-start mb-2">
       <div></div>
       <div class="d-flex align-items-center">
         <?php if ($user && (($user['role'] ?? '') === 'admin')): ?>
           <div class="dropdown">
             <button class="btn btn-link text-secondary p-0" type="button" id="adminDropdown" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" style="text-decoration: none;">
               <i class="fa fa-ellipsis-v" style="font-size:1.3rem; padding: 0 10px;"></i>
             </button>
             <div class="dropdown-menu dropdown-menu-right" aria-labelledby="adminDropdown">
               <form method="post" style="margin:0;" onsubmit="return confirm('Delete this publication? This cannot be undone.');">
                 <?php echo csrf_field(); ?>
                 <input type="hidden" name="action" value="delete_publication">
                 <button type="submit" class="dropdown-item text-danger">Delete</button>
               </form>
             </div>
           </div>
         <?php endif; ?>
       </div>
    </div>

    <header>
      <h1 class="paper-title" itemprop="headline" style="margin-top: 0; margin-bottom: 20px;"><?php echo e($title); ?></h1>
    </header>
    
    <?php if (!empty($parsedAuthors)): ?>
      <?php
          $clickableAuthors = [];
          foreach ($parsedAuthors as $idx => $pa) {
              $cleanName = $pa['name'];
              $realOrcid = $pa['orcid'] ?? '';
              $realScholar = $pa['scholar'] ?? '';
              $school = $pa['affiliation'] ?? '';
              
              if (empty($school)) {
                  $school = 'Global Youth Science Journal Contributor';
              }
              
              if (empty($realOrcid)) {
                  $hash = md5($cleanName);
                  $realOrcid = sprintf('%04d-%04d-%04d-%04d', hexdec(substr($hash, 0, 4)) % 10000, hexdec(substr($hash, 4, 4)) % 10000, hexdec(substr($hash, 8, 4)) % 10000, hexdec(substr($hash, 12, 4)) % 10000);
              }
              $authorLink = '<a href="#" class="author-link" data-name="'.e($cleanName).'" data-orcid="'.e($realOrcid).'" data-scholar="'.e($realScholar).'" data-toggle="modal" data-target="#authorModal" aria-label="View author profile for '.e($cleanName).'" style="color: inherit; text-decoration: underline; text-underline-offset: 3px;">'.e($cleanName).'</a>';
              
              $num = $idx + 1;
              $clickableAuthors[] = $authorLink . ' (' . $num . ')';
          }
          $authorsFormatted = implode(', ', $clickableAuthors);
          ?>
          <div class="paper-authors" style="text-align: left; font-weight: 600; color: #444; margin-bottom: 25px;">
            <?php echo $authorsFormatted; ?>
          </div>
    <?php endif; ?>

    <div class="paper-meta" style="text-align: left; margin-bottom: 15px; margin-top: 0;"><?php echo $publishedAt ? date('F j, Y', strtotime($publishedAt)) : 'May 30, 2026'; ?></div>
    
    <?php if (!empty($gysjDetailPaper['doi'])): ?>
    <div class="paper-doi" style="text-align: left; margin-bottom: 25px; margin-top: 0;">
      <a href="https://doi.org/<?php echo e($gysjDetailPaper['doi']); ?>" target="_blank" rel="noopener" itemprop="identifier">https://doi.org/<?php echo e($gysjDetailPaper['doi']); ?></a>
    </div>
    <?php endif; ?>

    <section class="paper-abstract" itemprop="description">
      <h2 style="font-size: 1.2rem; font-weight: 700; color: #333; margin-bottom: 5px;">Abstract</h2>
      <div style="margin-top: 0;"><?php echo nl2br(e($abstract)); ?></div>
    </section>

    <?php if ($keywords !== ''): ?>
      <section class="paper-keywords">
        <h3 style="font-size: 1.1rem; font-weight: 700; color: #333; margin-bottom: 5px;">Keywords</h3>
        <div style="margin-top: 0;" itemprop="keywords"><?php echo e($keywords); ?></div>
      </section>
    <?php endif; ?>

    <?php if (!empty($referencesHtml)): ?>
      <section class="paper-references" style="margin-bottom: 40px; font-size: 1rem; color: #333; line-height: 1.8;">
        <h3 style="font-size: 1.2rem; font-weight: 700; color: #333; margin-bottom: 15px; border-bottom: 1px solid #eaeaea; padding-bottom: 5px;">References</h3>
        <div class="references-content" style="text-align: left; padding-left: 20px;">
          <?php echo $referencesHtml; ?>
        </div>
      </section>
    <?php endif; ?>

    <div class="mb-4 text-center">
      <a href="<?php echo e($paperUrl); ?>" target="_blank" rel="noopener" class="btn btn-primary" style="background-color: #3b508f; border-color: #3b508f; padding: 10px 24px; font-weight: 600; font-family: 'Inter', sans-serif;">
        <i class="fa fa-file-pdf-o mr-2"></i> Download Full Article as PDF
      </a>
      <a href="/export-citation.php?slug=<?php echo urlencode($paperSlug); ?>&format=ris" class="btn btn-outline-dark ml-2" style="padding: 10px 24px; font-weight: 600; font-family: 'Inter', sans-serif; color: #222; border-color: #222;">
        <i class="fa fa-quote-right mr-2"></i> Cite (RIS)
      </a>
      <a href="/export-citation.php?slug=<?php echo urlencode($paperSlug); ?>&format=bibtex" class="btn btn-outline-dark ml-2" style="padding: 10px 24px; font-weight: 600; font-family: 'Inter', sans-serif; color: #222; border-color: #222;">
        <i class="fa fa-quote-right mr-2"></i> Cite (BibTeX)
      </a>
    </div>

    <?php
    $apaAuthors = '';
    $mlaAuthors = '';
    $chicagoAuthors = '';
    $harvardAuthors = '';
    $vancouverAuthors = '';
    $ieeeAuthors = '';

    $authorCount = count($parsedAuthors);
    if ($authorCount > 0) {
        $apaArr = [];
        $vancouverArr = [];
        $ieeeArr = [];
        
        foreach ($parsedAuthors as $pa) {
            $surname = trim($pa['surname'] ?? $pa['name']);
            $given = trim($pa['given_names'] ?? '');
            
            $initial = $given ? mb_substr($given, 0, 1) . '.' : '';
            $apaArr[] = $initial ? "$surname, $initial" : $surname;
            
            $givenInitials = '';
            if ($given) {
                $givenParts = explode(' ', $given);
                foreach ($givenParts as $gp) {
                    if ($gp) $givenInitials .= mb_substr($gp, 0, 1);
                }
            }
            $vancouverArr[] = $givenInitials ? "$surname $givenInitials" : $surname;
            
            $firstInitial = $given ? mb_substr($given, 0, 1) . '. ' : '';
            $ieeeArr[] = $firstInitial . $surname;
        }
        
        if ($authorCount === 1) {
            $apaAuthors = $apaArr[0];
            $harvardAuthors = $apaArr[0];
            $ieeeAuthors = $ieeeArr[0];
        } elseif ($authorCount === 2) {
            $apaAuthors = $apaArr[0] . ', & ' . $apaArr[1];
            $harvardAuthors = $apaArr[0] . ' and ' . $apaArr[1];
            $ieeeAuthors = $ieeeArr[0] . ' and ' . $ieeeArr[1];
        } else {
            $apaAuthors = implode(', ', array_slice($apaArr, 0, -1)) . ', & ' . end($apaArr);
            $harvardAuthors = implode(', ', array_slice($apaArr, 0, -1)) . ' and ' . end($apaArr);
            $ieeeAuthors = implode(', ', array_slice($ieeeArr, 0, -1)) . ', and ' . end($ieeeArr);
        }
        
        if ($authorCount > 6) {
            $vancouverAuthors = implode(', ', array_slice($vancouverArr, 0, 6)) . ', et al';
        } else {
            $vancouverAuthors = implode(', ', $vancouverArr);
        }
        
        if ($authorCount === 1) {
            $pa = $parsedAuthors[0];
            $surname = trim($pa['surname'] ?? $pa['name']);
            $given = trim($pa['given_names'] ?? '');
            $mlaAuthors = $given ? "$surname, $given" : $surname;
        } elseif ($authorCount === 2) {
            $pa1 = $parsedAuthors[0];
            $pa2 = $parsedAuthors[1];
            $mlaAuthors = ($pa1['given_names'] ? $pa1['surname'].", ".$pa1['given_names'] : $pa1['name']) . " and " . ($pa2['given_names'] ? $pa2['given_names']." ".$pa2['surname'] : $pa2['name']);
        } else {
            $pa1 = $parsedAuthors[0];
            $mlaAuthors = ($pa1['given_names'] ? $pa1['surname'].", ".$pa1['given_names'] : $pa1['name']) . ", et al";
        }
        $chicagoAuthors = $mlaAuthors;
    }

    $pubYear = $publishedAt ? date('Y', strtotime($publishedAt)) : date('Y');
    $doiLink = !empty($gysjDetailPaper['doi']) ? 'https://doi.org/' . $gysjDetailPaper['doi'] : '';
    $volStr = !empty($gysjDetailVolume) ? $gysjDetailVolume : '';
    $issueStr = !empty($gysjDetailIssue) ? $gysjDetailIssue : '';

    $volIssueAPA = '';
    if ($volStr && $issueStr) $volIssueAPA = "$volStr($issueStr)";
    elseif ($volStr) $volIssueAPA = $volStr;

    $apaCitation = trim("$apaAuthors ($pubYear). $title." . ($volIssueAPA ? " Global Youth Science Journal, $volIssueAPA." : ""));
    $mlaCitation = trim("$mlaAuthors. \"$title.\" Global Youth Science Journal" . ($volStr ? ", vol. $volStr" : "") . ($issueStr ? ", no. $issueStr" : "") . ", $pubYear.");
    $chicagoCitation = trim("$chicagoAuthors. \"$title.\" Global Youth Science Journal " . ($volIssueAPA ? "$volIssueAPA " : "") . "($pubYear).");
    $harvardCitation = trim("$harvardAuthors, $pubYear. $title. Global Youth Science Journal" . ($volIssueAPA ? ", $volIssueAPA." : "."));
    
    $volIssueVancouver = '';
    if ($volStr && $issueStr) $volIssueVancouver = "$volStr($issueStr)";
    elseif ($volStr) $volIssueVancouver = $volStr;
    $vancouverCitation = trim("$vancouverAuthors. $title. Global Youth Science Journal. $pubYear" . ($volIssueVancouver ? ";$volIssueVancouver." : "."));
    
    $volIssueIEEE = '';
    if ($volStr) $volIssueIEEE .= ", vol. $volStr";
    if ($issueStr) $volIssueIEEE .= ", no. $issueStr";
    $ieeeCitation = trim("$ieeeAuthors, \"$title,\" Global Youth Science Journal$volIssueIEEE, $pubYear.");

    function getCitationHtml($text, $doi) {
        $html = htmlspecialchars($text);
        if ($doi) {
            $html .= '<br><a href="' . htmlspecialchars($doi) . '" target="_blank" rel="noopener">' . htmlspecialchars($doi) . '</a>';
        }
        return $html;
    }
    ?>

    <section class="citation-box" style="background-color: #ffffff; border: 1px solid #ddd; border-radius: 8px; padding: 20px; margin: 0 auto 40px auto; width: 100%; text-align: left; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
      <h3 style="color: #333; margin-bottom: 15px; font-weight: 700; font-size: 1.2rem;">Citation</h3>
      <div id="citation-text-container" style="font-size: 0.95rem; color: #444; margin-bottom: 20px; line-height: 1.6;">
        <div id="citation-apa" class="citation-content" style="display: block;"><?php echo getCitationHtml($apaCitation, $doiLink); ?></div>
        <div id="citation-mla" class="citation-content" style="display: none;"><?php echo getCitationHtml($mlaCitation, $doiLink); ?></div>
        <div id="citation-chicago" class="citation-content" style="display: none;"><?php echo getCitationHtml($chicagoCitation, $doiLink); ?></div>
        <div id="citation-harvard" class="citation-content" style="display: none;"><?php echo getCitationHtml($harvardCitation, $doiLink); ?></div>
        <div id="citation-vancouver" class="citation-content" style="display: none;"><?php echo getCitationHtml($vancouverCitation, $doiLink); ?></div>
        <div id="citation-ieee" class="citation-content" style="display: none;"><?php echo getCitationHtml($ieeeCitation, $doiLink); ?></div>
      </div>
      
      <div class="d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center">
          <label for="citation-style" style="margin-bottom: 0; margin-right: 10px; font-weight: 500; color: #555; font-size: 0.9rem;">Style</label>
          <select id="citation-style" class="form-control form-control-sm" style="width: auto; display: inline-block; box-shadow: none; border-radius: 4px; border: 1px solid #ced4da;">
            <option value="apa">APA</option>
            <option value="harvard">Harvard</option>
            <option value="mla">MLA</option>
            <option value="vancouver">Vancouver</option>
            <option value="chicago">Chicago</option>
            <option value="ieee">IEEE</option>
          </select>
        </div>
        <button id="copy-citation-btn" class="btn btn-outline-dark btn-sm" title="Copy to clipboard" style="padding: 5px 12px; border-radius: 4px; box-shadow: none; color: #222; border-color: #222;">
          <i class="fa fa-copy"></i>
        </button>
      </div>
      </div>
    </section>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var styleSelect = document.getElementById('citation-style');
        var copyBtn = document.getElementById('copy-citation-btn');
        var citationContents = document.querySelectorAll('.citation-content');

        styleSelect.addEventListener('change', function() {
            var selectedStyle = this.value;
            citationContents.forEach(function(el) {
                el.style.display = 'none';
            });
            var activeEl = document.getElementById('citation-' + selectedStyle);
            if (activeEl) {
                activeEl.style.display = 'block';
            }
        });

        copyBtn.addEventListener('click', function() {
            var activeCitation = document.querySelector('.citation-content[style*="display: block"]');
            if (!activeCitation) {
                activeCitation = document.querySelector('.citation-content');
            }
            if (activeCitation) {
                // Get innerText to maintain plain text with newlines if any
                var textToCopy = activeCitation.innerText.trim();
                navigator.clipboard.writeText(textToCopy).then(function() {
                    var originalHtml = copyBtn.innerHTML;
                    copyBtn.innerHTML = '<i class="fa fa-check"></i>';
                    setTimeout(function() {
                        copyBtn.innerHTML = originalHtml;
                    }, 2000);
                }).catch(function(err) {
                    console.error('Failed to copy citation: ', err);
                });
            }
        });
    });
    </script>

    <div class="d-flex flex-column justify-content-center align-items-center mt-5 pt-3" style="border-top: 1px solid #eee;">
      <div class="meta-stats mb-3">
        <i class="fa fa-eye"></i> <?php echo (int)($gysjDetailPaper['view_count'] ?? 0); ?> Views &nbsp;&nbsp;|&nbsp;&nbsp; 
        <i class="fa fa-download"></i> <?php echo (int)($gysjDetailPaper['download_count'] ?? 0); ?> Downloads
      </div>
      <div class="copyright-notice text-center" style="font-size: 0.75rem; color: #777; max-width: 800px; line-height: 1.5; margin-bottom: 20px;">
        Copyright &copy; <?php echo date('Y'); ?>, Global Youth Science Journal. All content published in this journal is licensed under the Creative Commons Attribution-NonCommercial-NoDerivatives 4.0 International (CC BY-NC-ND 4.0) License. Under the terms of this license, anyone may copy, share, and redistribute articles in any medium or format for non-commercial purposes, provided appropriate credit is given to the original author(s) and source. Articles must remain unaltered, and no modified, adapted, or derivative versions may be distributed without prior permission.
      </div>
    </div>
    


    </div>
    </article>
  </main>

  <!-- Author Modal -->
  <div class="modal fade" id="authorModal" tabindex="-1" role="dialog" aria-labelledby="authorModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <div class="modal-content border-0 shadow-lg">
        <div class="modal-header border-0 pb-0">
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body text-center pt-0 pb-5">
          <h4 id="modalAuthorName" class="mb-4" style="color: #3b508f; font-weight: 600; font-family: 'Inter', sans-serif;"></h4>
          <div class="d-flex flex-column align-items-center" style="gap: 15px;">
            <a id="modalOrcidLink" href="#" target="_blank" class="btn btn-outline-success d-flex align-items-center justify-content-center w-75" style="border-radius: 50px; padding: 10px; font-family: 'Inter', sans-serif;">
              <img src="https://orcid.org/assets/vectors/orcid.logo.icon.svg" alt="ORCID iD icon" style="width: 24px; margin-right: 10px;">
              <span id="modalOrcidText">ORCID iD</span>
            </a>
            <a id="modalScholarLink" href="#" target="_blank" class="btn btn-outline-primary d-flex align-items-center justify-content-center w-75" style="border-radius: 50px; padding: 10px; border-color: #4285F4; color: #4285F4; font-family: 'Inter', sans-serif;">
              <i class="fa fa-graduation-cap mr-2" style="font-size: 1.2rem;"></i> Google Scholar
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
  document.addEventListener('DOMContentLoaded', function() {
      document.body.addEventListener('click', function(e) {
          var link = e.target.closest('.author-link');
          if (link) {
              e.preventDefault();
              var name = link.getAttribute('data-name');
              var orcid = link.getAttribute('data-orcid');
              var scholar = link.getAttribute('data-scholar');
              
              document.getElementById('modalAuthorName').textContent = name;
              
              var orcidUrl = orcid.startsWith('http') ? orcid : 'https://orcid.org/' + orcid;
              document.getElementById('modalOrcidLink').setAttribute('href', orcidUrl);
              document.getElementById('modalOrcidText').textContent = orcidUrl;
              
              var scholarUrl = scholar ? scholar : 'https://scholar.google.com/scholar?q=' + encodeURIComponent(name);
              document.getElementById('modalScholarLink').setAttribute('href', scholarUrl);
          }
      });
  });
  </script>

  <footer class="fh5co_footer_bg" role="contentinfo" style="margin-top: 50px;">
    <div class="container pb-4">
      <div class="row">
        <div class="col-12 col-lg-4 mb-4">
          <a href="/index.php" class="d-inline-flex align-items-center text-decoration-none">
            <img src="/images/iysjournal.png" class="footer_logo mr-2" alt="Global Youth Science Journal logo">
            <span class="footer_main_title">Global Youth Science Journal</span>
          </a>
          <p class="footer_sub_about mt-3 mb-2">Peer-reviewed, open-access research for young scientists.</p>
        </div>
        <div class="col-6 col-lg-2 mb-4">
          <div class="footer_main_title mb-2">Explore</div>
          <ul class="footer_menu">
            <li><a href="/publication.php">Publication</a></li>
            <li><a href="/editorial-board.php">Editorial Board</a></li>
            <li><a href="/our-mission.php">Our Mission</a></li>
            <li><a href="/contact.php">Contact Us</a></li>
          </ul>
        </div>
        <div class="col-6 col-lg-3 mb-4">
          <div class="footer_main_title mb-2">Submissions</div>
          <ul class="footer_menu">
            <li><a href="/submit.php">Online Submission</a></li>
            <li><a href="/call-for-paper.php">Call for Papers</a></li>
            <li><a href="/authorguidelines.php">Author Guidelines</a></li>
            <li><a href="/copyright.php">Copyright</a></li>
          </ul>
        </div>
        <div class="col-12 col-lg-3 mb-4">
          <div class="footer_main_title mb-2">Get In Touch</div>
          <p class="footer_sub_about mb-2">Email: <a class="footer_sub_about" href="mailto:globalyouthsciencejournal@gmail.com">globalyouthsciencejournal@gmail.com</a></p>
          <div class="d-flex align-items-center" aria-label="Social links">
            <a class="fh5co_display_table_footer" href="https://www.facebook.com/iysjournal" target="_blank" rel="noopener" aria-label="Facebook">
              <span class="fh5co_verticle_middle"><i class="fa fa-facebook" aria-hidden="true"></i></span>
            </a>
            <a class="fh5co_display_table_footer" href="https://twitter.com/iysjournal" target="_blank" rel="noopener" aria-label="Twitter">
              <span class="fh5co_verticle_middle"><i class="fa fa-twitter" aria-hidden="true"></i></span>
            </a>
          </div>
        </div>
      </div>
    </div>

    <div class="container pt-3">
      <div class="row align-items-center">
        <div class="col-12 col-md-8 Reserved footer_sub_about">
          <a href="/copyright.php">Ã‚Â© Copyright 2026</a>, Licensed under
          <a href="https://creativecommons.org/licenses/by/4.0/" target="_blank" rel="noopener">Creative Commons Attribution 4.0 International License.</a>
        </div>
      </div>
    </div>
  </footer>

  <script src="/js/jquery.min.js"></script>
  <script src="/js/tether.min.js" crossorigin="anonymous"></script>
  <script src="/js/bootstrap.min.js" crossorigin="anonymous"></script>
</body>
</html>
<?php
  exit;
}
?>
<!DOCTYPE html>
<html lang="en" class="no-js">

<head>
  <!-- Required meta tags -->
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <link rel="shortcut icon" type="image/jpg" href="images/iysjournal.png">
  <title>Publication |  Global Youth Science Journal | Research Publication</title>
  <meta name="description"
    content="Explore research publications in the Global Youth Science Journal. Peer-reviewed articles by young scientists worldwide.">
  <meta name="keywords"
    content="publication, Global Youth Science Journal, GYS Journal, research, peer review, young scientists">
  <link href="css/media_query.css" rel="stylesheet" type="text/css">
  <link href="css/style.css " rel="stylesheet" type="text/css">
  <link href="css/bootstrap.css" rel="stylesheet" type="text/css">
  <link href="css/font-awesome.min.css" rel="stylesheet" crossorigin="anonymous">
  <link href="css/animate.css" rel="stylesheet" type="text/css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&display=swap" rel="stylesheet">
  <link href="css/owl.carousel.css" rel="stylesheet" type="text/css">
  <link href="css/owl.theme.default.css" rel="stylesheet" type="text/css">
  <!-- Bootstrap CSS -->
  <link href="css/style_1.css" rel="stylesheet" type="text/css">
  <!-- Modernizr JS -->
  <script src="js/modernizr-3.5.0.min.js"></script>
  <!-- Advanced SEO Schema.org Structured Data -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@graph": [
      {
        "@type": "CollectionPage",
        "headline": "Research Publications | Global Youth Science Journal",
        "description": "Explore research publications in the Global Youth Science Journal. Peer-reviewed articles by young scientists worldwide.",
        "url": "https://<?php echo $_SERVER["HTTP_HOST"]; ?>/publication.php",
        "publisher": {
          "@type": "Organization",
          "name": "Global Youth Science Journal",
          "logo": {"@type": "ImageObject", "url": "https://<?php echo $_SERVER["HTTP_HOST"]; ?>/images/iysjournal.png"}
        }
      },
      {
        "@type": "BreadcrumbList",
        "itemListElement": [
          {"@type": "ListItem", "position": 1, "name": "Home", "item": "https://<?php echo $_SERVER["HTTP_HOST"]; ?>/"},
          {"@type": "ListItem", "position": 2, "name": "Publication", "item": "https://<?php echo $_SERVER["HTTP_HOST"]; ?>/publication.php"}
        ]
      },
      {
        "@type": "ItemList",
        "name": "Latest Publications",
        "itemListElement": [
          <?php
          if (isset($gysjPublished) && is_array($gysjPublished)) {
              $itemList = [];
              $pos = 1;
              foreach ($gysjPublished as $pub) {
                  $pubTitle = $pub['title'] ?? '';
                  $pubSlug = $pub['slug'] ?? '';
                  $pubUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/publication.php?slug=' . urlencode($pubSlug);
                  $itemList[] = '{
                    "@type": "ListItem",
                    "position": ' . $pos . ',
                    "url": ' . json_encode($pubUrl) . '
                  }';
                  $pos++;
                  if ($pos > 20) break; // limit to 20 for JSON payload size
              }
              echo implode(",\n          ", $itemList);
          }
          ?>
        ]
      },
      {
        "@type": "FAQPage",
        "mainEntity": [
          {
            "@type": "Question",
            "name": "Where can I find the latest research articles?",
            "acceptedAnswer": {
              "@type": "Answer",
              "text": "All recent publications are listed on this page and are freely accessible."
            }
          },
          {
            "@type": "Question",
            "name": "How are articles selected for publication?",
            "acceptedAnswer": {
              "@type": "Answer",
              "text": "All articles undergo rigorous peer review and editorial oversight."
            }
          }
        ]
      }
    ]
  }
  </script>
  <!-- End SEO additions -->
  <style>
    body.publication-page-list {
        background-color: #ffffff;
        font-family: 'Inter', sans-serif;
        display: flex;
        flex-direction: column;
        min-height: 100vh;
    }
    
    body.publication-page-list footer {
        margin-top: auto;
    }
    
    /* Category Nav */
    .category-nav {
        display: flex;
        flex-wrap: wrap;
        gap: 15px;
        margin-bottom: 40px;
        border-bottom: 1px solid #eaeaea;
        padding-bottom: 10px;
        justify-content: flex-start;
    }
    .category-nav a {
        color: #111;
        text-decoration: none;
        font-size: 14px;
        font-weight: 500;
        padding-bottom: 8px;
        margin-right: 15px;
    }
    .category-nav a.active {
        color: #9c2e36;
        border-bottom: 2px solid #9c2e36;
    }

    /* Masonry Grid */
    #articles-list {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 30px;
        align-items: start;
    }

    .article-card {
        background: transparent;
        border: none;
        padding: 0;
        margin-bottom: 0;
        display: flex;
        flex-direction: column;
        box-shadow: none;
    }

    .article-card img.article-cover {
        width: 100%;
        height: 200px;
        object-fit: cover;
        margin-bottom: 15px;
    }

    .article-meta-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 12px;
        color: #111;
        margin-bottom: 12px;
        font-family: 'Inter', sans-serif;
        font-weight: 600;
    }
    
    .article-meta-top .dot-menu {
        font-size: 18px;
        cursor: pointer;
        color: #888;
    }

    .article-category-badge {
        background-color: #3B508F;
        color: #fff;
        padding: 4px 8px;
        font-size: 12px;
        font-weight: 500;
        display: inline-block;
        margin-bottom: 12px;
        align-self: flex-start;
    }

    .article-card h2 {
        font-family: 'Inter', sans-serif;
        font-size: 20px;
        font-weight: 600;
        line-height: 1.35;
        margin-bottom: 12px;
        color: #111;
    }
    .article-card h2 a {
        color: inherit;
        text-decoration: none;
    }

    .article-author-abstract {
        font-family: 'Inter', sans-serif;
        font-size: 13px;
        color: #111;
        line-height: 1.5;
    }
    
    /* Hide default borders */
    hr { display: none; }
  </style>
    <script src="/js/main.js" defer></script>
</head>

<body class="publication-page-list">



    <div class="container-fluid bg-faded fh5co_padd_mediya padding_786">
        <div class="container padding_786">
            <nav class="navbar navbar-toggleable-md navbar-light gysj-navbar flex-column align-items-start">
                <div class="d-flex w-100 align-items-center justify-content-between">
                    <a class="navbar-brand mobile_logo_width" href="index.php">
                        <img src="images/iysjournal.png" alt="Global Youth Science Journal" class="gysj-nav-icon">
                        <span class="gysj-nav-title">Global Youth Science Journal</span>
                    </a>
                    <button class="navbar-toggler" type="button" data-toggle="collapse"
                        data-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false"
                        aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>
                </div>

                <div class="collapse navbar-collapse w-100 mt-3 gysj-nav-links" id="navbarSupportedContent">
                    <ul class="navbar-nav mx-auto">
                        <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>">
                            <a class="nav-link" href="index.php">Home</a>
                        </li>
                        <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'publication.php' ? 'active' : ''; ?>">
                            <a class="nav-link" href="publication.php">Publications</a>
                        </li>
                        <li class="nav-item dropdown <?php echo in_array(basename($_SERVER['PHP_SELF']), ['user-dashboard.php', 'call-for-paper.php', 'authorguidelines.php', 'copyright.php']) ? 'active' : ''; ?>">
                            <a class="nav-link dropdown-toggle" href="#" id="dropdownMenuButton3" data-toggle="dropdown"
                                aria-haspopup="true" aria-expanded="false">Paper Submissions</a>
                            <div class="dropdown-menu" aria-labelledby="dropdownMenuButton3">
                                <a class="dropdown-item" href="user-dashboard.php?view=submit">Online Submission</a>
                                <a class="dropdown-item" href="call-for-paper.php">Call for Paper</a>
                                <a class="dropdown-item" href="authorguidelines.php">Guidelines for authors</a>
                                <a class="dropdown-item" href="copyright.php">Copyright</a>
                            </div>
                        </li>
                        <li class="nav-item dropdown <?php echo in_array(basename($_SERVER['PHP_SELF']), ['our-founders.php', 'our-mission.php', 'our-funding.php']) ? 'active' : ''; ?>">
                            <a class="nav-link dropdown-toggle" href="#" id="dropdownMenuButton2" data-toggle="dropdown"
                                aria-haspopup="true" aria-expanded="false">About Us</a>
                            <div class="dropdown-menu" aria-labelledby="dropdownMenuButton2">
                                <a class="dropdown-item" href="our-founders.php">Our Founders</a>
                                <a class="dropdown-item" href="our-mission.php">Our Mission</a>
                                <a class="dropdown-item" href="our-funding.php">Our Funding</a>
                            </div>
                        </li>
                        <li class="nav-item dropdown <?php echo in_array(basename($_SERVER['PHP_SELF']), ['editorial-board.php', 'editorial-members.php']) ? 'active' : ''; ?>">
                            <a class="nav-link dropdown-toggle" href="#" id="dropdownEditorialBoard" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Editorial Board</a>
                            <div class="dropdown-menu" aria-labelledby="dropdownEditorialBoard">
                                <a class="dropdown-item" href="editorial-board.php">About the Board</a>
                                <a class="dropdown-item" href="editorial-members.php">Members</a>
                            </div>
                        </li>
                        <li class="nav-item dropdown <?php echo in_array(basename($_SERVER['PHP_SELF']), ['contribute.php', 'partners.php']) ? 'active' : ''; ?>">
                            <a class="nav-link dropdown-toggle" href="#" id="dropdownSupport" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Support Us</a>
                            <div class="dropdown-menu" aria-labelledby="dropdownSupport">
                                <a class="dropdown-item" href="contribute.php">Contribute</a>
                                <a class="dropdown-item" href="partners.php">Partners</a>
                            </div>
                        </li>
                        <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'contact.php' ? 'active' : ''; ?>">
                            <a class="nav-link" href="contact.php">Contact</a>
                        </li>

                        <?php if (auth_is_logged_in()): $navUser = auth_current_user(); ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="accountMenu" data-toggle="dropdown"
                                aria-haspopup="true" aria-expanded="false"><?php echo e(($navUser['name'] ?? '') !== '' ? $navUser['name'] : ($navUser['email'] ?? 'Account')); ?></a>
                            <div class="dropdown-menu" aria-labelledby="accountMenu">
                                <a class="dropdown-item" href="<?php echo e((($navUser['role'] ?? '') === 'admin') ? 'admin-dashboard.php' : 'user-dashboard.php'); ?>">Dashboard</a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="account.php">Account Settings</a>
                                <a class="dropdown-item" href="logout.php">Log Out</a>
                            </div>
                        </li>
                        <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link btn btn-primary btn-sm text-white px-3" href="login.php"
                                style="margin-top:4px; margin-left:8px;">Login / Sign Up</a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </nav>
        </div>
    </div>



  <div class="container-fluid pb-4 pt-4 paddding">
    <div class="container paddding">
      <div class="row mx-0">
        <!-- Main Articles Column -->
        <div class="col-12 animate-box publication-main-col" data-animate-effect="fadeInLeft">
          <!-- Horizontal Category Filter Bar -->
          <style>
            #horizontal-category-bar::-webkit-scrollbar { display: none; }
            #horizontal-category-bar { -ms-overflow-style: none; scrollbar-width: none; }
            #horizontal-category-bar a { transition: color 0.2s ease; font-weight: 500; }
            #horizontal-category-bar a:hover { color: #007bff !important; }
          </style>
          <div id="horizontal-category-bar" class="mb-4 d-flex flex-wrap align-items-center" style="padding-bottom: 4px; border-bottom: 1px solid #eaeaea;">
            <!-- Dynamically populated by JS -->
          </div>

          <div class="mb-4 d-flex flex-wrap align-items-center">
            <div class="search-wrapper mr-3 mb-2 mb-md-0" style="flex: 1; display: flex; align-items: center; background: #ffffff; border: 1px solid #dcdcdc; border-radius: 0; padding: 6px 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.04);">
              <i class="fa fa-search text-muted mr-3" style="font-size: 1.2em;"></i>
              <input type="text" id="articleSearch" class="form-control border-0 bg-transparent p-0 m-0" placeholder="Search by title, author, abstract..." aria-label="Search" style="box-shadow: none; font-size: 1.1em; height: 42px;">
            </div>
            <button class="btn btn-primary shadow-sm px-4 py-2" style="border-radius: 0; font-weight: 600; letter-spacing: 0.5px; white-space: nowrap; height: 56px;" data-toggle="modal" data-target="#publicationFilterModal">
              <i class="fa fa-filter mr-2"></i> Filter Publications
            </button>
          </div>

          <!-- Articles List -->
          <div id="articles-list">
            <!-- DEBUG COUNT: <?php echo count($gysjPublished); ?> -->
            <?php if (count($gysjPublished) > 0): ?>
              <!-- Published via GYSJ dashboard -->
              <?php $isFirstArticle = true; ?>
              <?php foreach ($gysjPublished as $pub): ?>
                <?php
                  $pubTitle = (string) ($pub['title'] ?? '');
                  $pubTitleShort = $pubTitle;
                  if (strlen($pubTitleShort) > 80) {
                      $pubTitleShort = substr($pubTitleShort, 0, 80) . '...';
                  }
                  $pubAuthors = (string) ($pub['authors'] ?? '');
                  $pubAbstract = (string) ($pub['abstract'] ?? '');
                  $pubSlug = (string) ($pub['slug'] ?? '');
                  $pubDate = (string) ($pub['published_at'] ?? '');

                  $pubDateForCalc = !empty($pub['published_at']) ? strtotime($pub['published_at']) : time();
                  $pubVol = max(1, (int)date('Y', $pubDateForCalc) - 2022);
                  $pubIssue = (int) ($pub['issue_number'] ?? 0) ?: 1;
                  $pubPath = ('publications/' . $pubVol . '/' . $pubIssue . '/' . $pubSlug);

                  $pubDateLabel = 'Apr 6, 2026'; // default
                  if ($pubDate !== '') {
                    $ts = strtotime($pubDate);
                    if ($ts !== false) {
                      $pubDateLabel = date('F j, Y', $ts);
                    } else {
                      $pubDateLabel = substr($pubDate, 0, 10);
                    }
                  }

                  $pubAbstractShort = $pubAbstract;
                  if (strlen($pubAbstractShort) > 420) {
                    $pubAbstractShort = substr($pubAbstractShort, 0, 420) . '...';
                  }

                  $cat = $pub['category'] ?? '';
                  if (empty($cat)) {
                     $cat = 'Global Youth Science Journal';
                  } else {
                     $cat = str_ireplace('Journal of Advance Research in ', '', $cat);
                     $cat = str_ireplace('Journal of Advance Research (General)', 'General', $cat);
                     $cat = str_ireplace('Journal of Advance Research ', '', $cat);
                     $cat = str_ireplace('Journal of ', '', $cat);
                     $cat = trim($cat);
                  }
                ?>
                <article class="article-card article-row" itemscope itemtype="https://schema.org/ScholarlyArticle" data-category="<?php echo e($cat); ?>" data-volume="<?php echo e((string)$pubVol); ?>" data-issue="<?php echo e((string)$pubIssue); ?>">
                  
                  <div class="article-meta-top" style="margin-bottom: 8px;">
                    <span style="color: #666; font-size: 0.9em; font-weight: 500;"><?php echo e($pubDateLabel); ?></span>
                  </div>
                  
                  <div class="article-category-badge" style="margin-bottom: 12px;"><?php echo e($cat); ?></div>
                  
                  <h2 itemprop="name"><a href="<?php echo e($pubPath); ?>" title="<?php echo e($pubTitle); ?>"><?php echo e($pubTitleShort); ?></a></h2>
                  
                  <?php if ($pubAuthors !== '' || $pubAbstractShort !== ''): ?>
                  <div class="article-author-abstract" style="margin-bottom: 16px;">
                     <?php if ($pubAuthors !== ''): ?>
                       <?php
                           $authorArr = explode(',', $pubAuthors);
                           $formattedCardAuthors = [];
                           foreach ($authorArr as $index => $authName) {
                               $authName = trim($authName);
                               if ($authName !== '') {
                                   $cleanAuthName = trim(preg_replace('/[\*\(\d\)]/', '', $authName));
                                   $formattedCardAuthors[] = e($cleanAuthName) . ' (' . ($index + 1) . ')';
                               }
                           }
                           echo '<div style="font-weight: 600; margin-bottom: 6px;">' . implode(', ', $formattedCardAuthors) . '</div>';
                       ?>
                     <?php endif; ?>
                     <?php if ($pubAbstractShort !== ''): ?>
                       <div style="display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; color: #444;"><?php echo e($pubAbstractShort); ?></div>
                     <?php endif; ?>
                  </div>
                  <?php endif; ?>
                </article>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <!-- Volume View Container (Hidden by default) -->
          <div id="volume-view-container" class="row" style="display: none;">
            <!-- Populated by JS -->
            <div class="col-12 text-center py-5">
              <i class="fa fa-spinner fa-spin fa-3x text-primary"></i>
            </div>
          </div>
          <!-- Pagination Controls -->
          <nav aria-label="Page navigation" id="pagination-nav" class="mt-4" style="display: none;">
            <ul class="pagination justify-content-center" id="pagination-controls">
              <!-- Populated by JS -->
            </ul>
          </nav>

        </div>
      </div>
    </div>
  </div>
  <style>
    body.publication-page aside,
    body.publication-page #mobile-sidebar-dropdown,
    body.publication-page #list-view-context,
    body.publication-page #btn-list-view,
    body.publication-page #btn-volume-view,
    body.publication-page #volume-view-container {
      display: none !important;
    }

    body.publication-page .publication-main-col {
      max-width: 100%;
      flex: 0 0 100%;
    }

    body.publication-page .publication-filter-modal {
      border-radius: 22px;
      overflow: hidden;
    }

    body.publication-page .publication-filter-section {
      padding: 1rem 1.1rem;
      border: 1px solid #eef2f7;
      border-radius: 18px;
      background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
    }

    body.publication-page .publication-filter-section .btn {
      margin: 0 0.5rem 0.5rem 0;
      border-radius: 999px;
      padding-left: 1rem;
      padding-right: 1rem;
    }

    body.publication-page .row.mb-3.align-items-center {
      margin-bottom: 1.5rem !important;
      padding: 1rem 1.1rem;
      border-radius: 18px;
      border: 1px solid #eef2f7;
      background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
    }

    body.publication-page .row.mb-3.align-items-center .btn-outline-primary {
      border-radius: 999px;
      padding-top: 0.8rem;
      padding-bottom: 0.8rem;
    }

    body.publication-page #articles-list {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 1.4rem;
      align-items: stretch;
    }

    body.publication-page #articles-list > hr {
      display: none !important;
    }

    body.publication-page #articles-list .article-card {
      background: #fff;
      border: 1px solid #e4eaf2;
      border-radius: 0px;
      box-shadow: 0 18px 40px rgba(15, 61, 108, 0.08);
      margin: 0 !important;
      padding: 1.15rem 1.15rem 1.2rem;
      height: 100%;
      transition: transform 0.2s ease, box-shadow 0.2s ease;
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }

    body.publication-page #articles-list .article-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 24px 48px rgba(15, 61, 108, 0.13);
    }

    body.publication-page #articles-list .article-card::before {
      content: '';
      display: block;
      height: 180px;
      margin: -1.15rem -1.15rem 1rem;
      border-bottom: 1px solid #e8eef7;
      background-color: #eef4fb;
      background-image: var(--card-image, linear-gradient(135deg, #d8e6f7 0%, #f8fbff 100%));
      background-size: cover;
      background-position: center;
    }

    body.publication-page #articles-list .article-card:not(.has-card-image)::before {
      background-image:
        linear-gradient(135deg, rgba(14, 60, 112, 0.88), rgba(61, 132, 212, 0.62)),
        var(--card-image, linear-gradient(135deg, #d8e6f7 0%, #f8fbff 100%));
    }

    body.publication-page #articles-list .article-card .card-body-wrap {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
      flex: 1 1 auto;
    }

    body.publication-page #articles-list .article-card h2 {
      font-size: 1.25rem;
      line-height: 1.25;
      margin-bottom: 0.8rem;
    }

    body.publication-page #articles-list .article-card h2 a {
      color: #13233d;
      text-decoration: none;
    }

    body.publication-page #articles-list .article-card h2 a:hover {
      color: #0a63c8;
    }

    body.publication-page #articles-list .article-card .badge-primary,
    body.publication-page #articles-list .article-card .badge-success {
      border-radius: 999px;
      padding: 0.4rem 0.75rem;
      font-weight: 600;
      letter-spacing: 0.02em;
    }

    body.publication-page #articles-list .article-card h2 a {
      font-size: 1.08rem;
      line-height: 1.25;
      font-weight: 700;
      color: #111827;
      text-decoration: none;
      display: inline-block;
    }

    body.publication-page #articles-list .article-card [itemprop="description"] {
      color: #334155;
      font-size: 0.95rem;
      line-height: 1.55;
    }

    #mobile-sidebar-dropdown .card {
      border-radius: 12px;
      border: 1px solid #e0e0e0;
      box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15) !important;
    }

    #mobile-sidebar-dropdown .btn-link {
      color: #007bff;
      text-decoration: none;
      font-weight: 600;
    }

    #mobile-sidebar-dropdown .collapse .mobile-sidebar-content-box {
      background: #fff;
      border-radius: 0 0 12px 12px;
      box-shadow: 0 2px 12px rgba(0, 0, 0, 0.10);
      padding: 1.25rem 1.25rem 1rem 1.25rem;
      margin: 0.5rem 0.25rem 0.75rem 0.25rem;
      border: 1px solid #e0e0e0;
    }

    #mobile-sidebar-dropdown h5 {
      margin-top: 0.5rem;
      margin-bottom: 0.5rem;
    }

    #mobile-sidebar-dropdown p {
      margin-bottom: 0.5rem;
    }

    #dropdownArrow {
      margin-left: 8px;
    }
  </style>
  <footer class="fh5co_footer_bg" role="contentinfo">
    <div class="container pb-4">
      <div class="row">
        <div class="col-12 col-lg-4 mb-4">
          <a href="index.php" class="d-inline-flex align-items-center text-decoration-none">
            <img src="images/iysjournal.png" class="footer_logo mr-2" alt="Global Youth Science Journal logo">
            <span class="footer_main_title">Global Youth Science Journal</span>
          </a>
          <p class="footer_sub_about mt-3 mb-2">Peer-reviewed, open-access research for young scientists.</p>
        </div>
        <div class="col-6 col-lg-2 mb-4">
          <div class="footer_main_title mb-2">Explore</div>
          <ul class="footer_menu">
            <li><a href="publication.php">Publication</a></li>
            <li><a href="editorial-board.php">Editorial Board</a></li>
            <li><a href="our-mission.php">Our Mission</a></li>
            <li><a href="contact.php">Contact Us</a></li>
          </ul>
        </div>
        <div class="col-6 col-lg-3 mb-4">
          <div class="footer_main_title mb-2">Submissions</div>
          <ul class="footer_menu">
            <li><a href="user-dashboard.php?view=submit">Online Submission</a></li>
            <li><a href="call-for-paper.php">Call for Papers</a></li>
            <li><a href="authorguidelines.php">Author Guidelines</a></li>
            <li><a href="copyright.php">Copyright</a></li>
          </ul>
        </div>
        <div class="col-12 col-lg-3 mb-4">
          <div class="footer_main_title mb-2">Get In Touch</div>
          <p class="footer_sub_about mb-2">Email: <a class="footer_sub_about" href="mailto:globalyouthsciencejournal@gmail.com">globalyouthsciencejournal@gmail.com</a></p>
  
        </div>
      </div>
    </div>

    <div class="container pt-3">
      <div class="row align-items-center">
        <div class="col-12 col-md-8 Reserved footer_sub_about"><a href="copyright.php">Ã‚Â© Copyright 2026</a>, Licensed under <a
            href="https://creativecommons.org/licenses/by/4.0/" target="_blank" rel="noopener">Creative Commons Attribution 4.0 International
            License.</a></div>
       
      </div>
    </div>
  </footer>
  <script src="js/jquery.min.js"></script>
  <script src="js/owl.carousel.min.js"></script>
  <!--<script src="https://code.jquery.com/jquery-3.1.1.slim.min.js" integrity="sha384-A7FZj7v+d/sdmMqp/nOQwliLvUsJfDHW+k9Omg/a/EheAdgtzNs3hpfag6Ed950n" crossorigin="anonymous"></script>-->
  <script src="js/tether.min.js" crossorigin="anonymous"></script>
  <script src="js/bootstrap.min.js" crossorigin="anonymous"></script>
  <!-- Waypoints -->
  <script src="js/jquery.waypoints.min.js"></script>
  <style>
    /* Article card styling for this page only */
    #articles-list .article-card {
      background: #fff;
      border: 1px solid #e0e0e0;
      border-radius: 0px;
      box-shadow: none;
      margin-bottom: 24px;
      padding: 18px 22px 14px 22px;
      position: relative;
      cursor: pointer;
      transition: background-color 0.2s ease, border-color 0.2s ease;
    }

    #articles-list .article-card:hover {
      background-color: #fafbfc;
      border-color: #ccd5e0;
    }

    #articles-list .article-card h2 a {
      color: #3B508F;
      text-decoration: none;
      font-family: 'Inter', sans-serif !important;
      font-weight: 600 !important;
      transition: color 0.2s ease;
    }

    #articles-list .article-card h2 a::after {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      z-index: 1;
    }

    #articles-list .article-card:hover h2 a {
      color: #0a63c8 !important;
      text-decoration: none;
    }

    #articles-list .article-card .badge-primary {
      background: #007bff;
    }

    #articles-list .article-card .badge-success {
      background: #339af0;
    }

    #articles-list .article-card .text-muted {
      color: #339af0 !important;
    }

    .sidebar-link-blue {
      color: #007bff !important;
      text-decoration: underline;
      font-weight: 500;
    }

    .sidebar-link-blue:hover {
      color: #0056b3 !important;
      text-decoration: underline;
    }

    .hidden-article {
      display: none !important;
    }

    #sidebar-info .card-body {
      padding: 1.5rem !important;
    }

    /* Fix for button text overflow */
    #journal-categories button,
    #journalsMobile button {
      white-space: normal;
      display: block;
      width: 100%;
      height: auto;
    }

    /* Custom Volume Accordion Styles */
    .volume-btn {
      color: #333;
      background-color: #fff;
      border: 1px solid #e9ecef;
      border-radius: 8px;
      padding: 12px 15px;
      transition: all 0.2s ease;
      font-weight: 600;
      display: flex;
      justify-content: space-between;
      align-items: center;
      width: 100%;
      text-align: left;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    }

    .volume-btn:hover,
    .volume-btn[aria-expanded="true"] {
      background-color: #f0f7ff;
      color: #007bff;
      border-color: #b3d8fd;
      text-decoration: none;
      box-shadow: 0 2px 5px rgba(0, 123, 255, 0.15);
    }

    .volume-btn i.fa-angle-down {
      transition: transform 0.3s ease;
    }

    .volume-btn[aria-expanded="true"] i.fa-angle-down {
      transform: rotate(180deg);
    }

    .issue-btn {
      color: #555;
      padding: 10px 15px 10px 20px;
      border-radius: 6px;
      transition: all 0.2s;
      display: block;
      width: 100%;
      text-align: left;
      background: transparent;
      border: none;
      border-left: 3px solid #e9ecef;
      font-size: 0.9rem;
      margin-top: 4px;
    }

    .issue-btn:hover {
      background-color: #fff;
      color: #007bff;
      border-left-color: #007bff;
      text-decoration: none;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
      transform: translateX(3px);
    }
  </style>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      document.querySelectorAll('img').forEach(function (img) {
        if (!img.alt || img.alt.trim() === '') {
          img.alt = 'Global Youth Science Journal image';
        }
      });

      sortArticlesByDateDesc();

      document.querySelectorAll('#articles-list article.article-card').forEach(function (article) {
        var mediaImage = article.querySelector('img[src]:not([src*="zenodo.org/badge/DOI"]):not([src*="orcid"]):not([src*="iysjournal"]):not([src*="logo"])');
        if (mediaImage && mediaImage.src) {
          article.classList.add('has-card-image');
          article.style.setProperty('--card-image', 'url("' + mediaImage.src.replace(/"/g, '\\"') + '")');
        }

        if (!article.querySelector('.card-body-wrap')) {
          var bodyWrap = document.createElement('div');
          bodyWrap.className = 'card-body-wrap';
          while (article.childNodes.length) {
            bodyWrap.appendChild(article.childNodes[0]);
          }
          article.appendChild(bodyWrap);
        }
      });

      rebuildVolumeAccordionsFromArticles();

      initFilters();

      // Disable future dates
      var today = new Date().toISOString().split('T')[0];
      ['startDate', 'endDate', 'startDateMobile', 'endDateMobile'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.setAttribute('max', today);
      });

      // Real-time search
      var searchInput = document.getElementById('articleSearch');
      if (searchInput) {
        searchInput.addEventListener('input', function () {
          currentFilters.search = this.value.trim();
          currentPage = 1;
          applyFilters();
        });
      }
    });

    var currentFilters = {
      category: 'All',
      tag: 'All',
      startDate: null,
      endDate: null,
      search: '',
      volume: 'All'
    };

    var currentPage = 1;
    var itemsPerPage = 9999;

    var UNASSIGNED_VOLUME_CODE = '__UNASSIGNED__';

    function sanitizeIdPart(value) {
      return String(value || '')
        .replace(/[^a-zA-Z0-9_-]/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '')
        .toLowerCase();
    }

    function getArticlePublishedMonth(article) {
      var dateEl = article.querySelector('.text-muted.small');
      if (!dateEl) return null;
      var d = new Date(dateEl.innerText.trim());
      return isNaN(d.getTime()) ? null : d.getMonth();
    }

    function monthName(monthIndex) {
      var names = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
      return names[monthIndex] || '';
    }

    function getArticleSortTimestamp(article) {
      var dateEl = article.querySelector('.text-muted.small');
      if (!dateEl) return Number.NEGATIVE_INFINITY;

      var raw = dateEl.innerText.trim();
      var parsed = new Date(raw);
      if (!isNaN(parsed.getTime())) return parsed.getTime();

      // Fallback for formats like "March 1981" or text with extra content.
      var cleaned = raw.split('|')[0].trim();
      parsed = new Date(cleaned);
      if (!isNaN(parsed.getTime())) return parsed.getTime();

      return Number.NEGATIVE_INFINITY;
    }

    function sortArticlesByDateDesc() {
      var container = document.getElementById('articles-list');
      if (!container) return;

      var articles = Array.from(container.querySelectorAll('article.article-card'));
      articles.sort(function (a, b) {
        return getArticleSortTimestamp(b) - getArticleSortTimestamp(a);
      });

      // Rebuild article list with separators in sorted order.
      container.innerHTML = '';
      articles.forEach(function (article, idx) {
        container.appendChild(article);
        if (idx < articles.length - 1) {
          container.appendChild(document.createElement('hr'));
        }
      });
    }

    function getArticlePublishedYear(article) {
      var dateEl = article.querySelector('.text-muted.small');
      if (!dateEl) return null;
      var d = new Date(dateEl.innerText.trim());
      return isNaN(d.getTime()) ? null : d.getFullYear();
    }

    // Build one "Volume" per year; issues are months with articles (sequential within that year).
    // Example: if articles exist in Feb + Mar, then Feb -> Issue 1, Mar -> Issue 2.
    function buildVolumesFromArticles() {
      var articles = Array.from(document.querySelectorAll('article.article-card'));
      var volumes = new Map();

      // year -> Set(monthIndex)
      var yearMonths = new Map();

      articles.forEach(function (article) {
        var year = getArticlePublishedYear(article);
        var month = getArticlePublishedMonth(article);

        if (year === null || month === null) {
          if (!yearMonths.has(UNASSIGNED_VOLUME_CODE)) yearMonths.set(UNASSIGNED_VOLUME_CODE, new Set());
          return;
        }

        if (!yearMonths.has(year)) yearMonths.set(year, new Set());
        yearMonths.get(year).add(month);
      });

      // Create volume entries from the computed year->months map
      yearMonths.forEach(function (monthsSet, yearKey) {
        var volumeKey = String(yearKey);
        var volume = { issues: new Map(), years: new Set() };

        if (yearKey !== UNASSIGNED_VOLUME_CODE) {
          volume.years.add(parseInt(yearKey, 10));

          var months = Array.from(monthsSet).sort(function (a, b) { return a - b; });
          months.forEach(function (m, idx) {
            var issueNumber = idx + 1;
            var code = yearKey + '-' + issueNumber;
            var label = 'Issue ' + issueNumber + ' (' + monthName(m) + ')';
            volume.issues.set(code, { label: label, code: code, sortKey: issueNumber });
          });
        } else {
          volume.issues.set(UNASSIGNED_VOLUME_CODE, { label: 'Unassigned / Archive', code: UNASSIGNED_VOLUME_CODE, sortKey: 9999 });
        }

        volumes.set(volumeKey, volume);
      });

      return volumes;
    }

    function getDerivedVolumeCodeForArticle(article) {
      var year = getArticlePublishedYear(article);
      var month = getArticlePublishedMonth(article);
      if (year === null || month === null) return UNASSIGNED_VOLUME_CODE;

      // Derive issue number by sorting unique months in that year.
      var articles = Array.from(document.querySelectorAll('article.article-card'));
      var months = new Set();
      articles.forEach(function (a) {
        var y = getArticlePublishedYear(a);
        var m = getArticlePublishedMonth(a);
        if (y === year && m !== null) months.add(m);
      });
      var monthsArr = Array.from(months).sort(function (a, b) { return a - b; });
      var issueIndex = monthsArr.indexOf(month);
      if (issueIndex === -1) return UNASSIGNED_VOLUME_CODE;
      return String(year) + '-' + String(issueIndex + 1);
    }

    function renderVolumeAccordion(containerId, volumes, options) {
      var container = document.getElementById(containerId);
      if (!container) return;
      container.innerHTML = '';

      var entries = Array.from(volumes.entries()).map(function (kv) {
        var volumeKey = kv[0];
        var data = kv[1];
        var isNumeric = /^\d+$/.test(volumeKey);
        var yearLabel = '';
        if (data.years && data.years.size) {
          var yearsArr = Array.from(data.years).sort();
          var minY = yearsArr[0];
          var maxY = yearsArr[yearsArr.length - 1];
          yearLabel = (minY === maxY) ? String(maxY) : (String(minY) + '' + String(maxY));
        }
        return {
          volumeKey: volumeKey,
          data: data,
          isNumeric: isNumeric,
          numericValue: isNumeric ? parseInt(volumeKey, 10) : null,
          yearLabel: yearLabel
        };
      });

      entries.sort(function (a, b) {
        // Years (numeric) first (desc), then UNASSIGNED last
        if (a.volumeKey === UNASSIGNED_VOLUME_CODE && b.volumeKey !== UNASSIGNED_VOLUME_CODE) return 1;
        if (b.volumeKey === UNASSIGNED_VOLUME_CODE && a.volumeKey !== UNASSIGNED_VOLUME_CODE) return -1;
        if (a.isNumeric && b.isNumeric) return b.numericValue - a.numericValue;
        if (a.isNumeric && !b.isNumeric) return -1;
        if (!a.isNumeric && b.isNumeric) return 1;
        return String(a.volumeKey).localeCompare(String(b.volumeKey));
      });

      entries.forEach(function (entry) {
        var volumeKey = entry.volumeKey;
        var data = entry.data;

        var safeKey = sanitizeIdPart(volumeKey);
        var collapseId = (options && options.collapsePrefix ? options.collapsePrefix : 'vol') + safeKey;

        var wrapper = document.createElement('div');
        wrapper.className = 'mb-2';

        var titleText = '';
        if (volumeKey === UNASSIGNED_VOLUME_CODE) {
          titleText = 'Archive / Unassigned';
        } else if (entry.isNumeric) {
          // One volume per year
          titleText = 'Volume ' + volumeKey;
        } else {
          titleText = String(volumeKey);
        }

        wrapper.innerHTML =
          '<button class="volume-btn collapsed" type="button" data-toggle="collapse" data-target="#' + collapseId + '"' +
          ' aria-expanded="false" aria-controls="' + collapseId + '">' +
          '<span><i class="fa fa-folder-open-o mr-2 text-primary"></i> ' + titleText + '</span>' +
          '<i class="fa fa-angle-down text-muted"></i>' +
          '</button>' +
          '<div class="collapse mt-2 pl-2 border-left ml-2" id="' + collapseId + '" data-parent="#' + containerId + '"' +
          ' style="border-color: #eee !important;"></div>';

        var collapse = wrapper.querySelector('#' + collapseId);

        var issues = Array.from(data.issues.values());
        issues.sort(function (a, b) {
          // Put UNASSIGNED code last inside its volume (only one anyway)
          if (a.code === UNASSIGNED_VOLUME_CODE && b.code !== UNASSIGNED_VOLUME_CODE) return 1;
          if (b.code === UNASSIGNED_VOLUME_CODE && a.code !== UNASSIGNED_VOLUME_CODE) return -1;
          var an = typeof a.sortKey === 'number' ? a.sortKey : (parseInt(a.sortKey, 10) || 0);
          var bn = typeof b.sortKey === 'number' ? b.sortKey : (parseInt(b.sortKey, 10) || 0);
          return an - bn;
        });

        issues.forEach(function (issue) {
          var btn = document.createElement('button');
          btn.className = 'issue-btn';
          btn.setAttribute('onclick', "filterByVolume('" + issue.code + "')");
          btn.innerHTML = '<i class="fa fa-file-text-o mr-2 text-muted"></i> ' + issue.label;
          collapse.appendChild(btn);
        });

        container.appendChild(wrapper);
      });

      // All button
      var allBtn = document.createElement('button');
      allBtn.className = 'btn btn-outline-primary btn-sm btn-block text-left mt-3 font-weight-bold';
      allBtn.setAttribute('onclick', "filterByVolume('All')");
      allBtn.setAttribute('style', 'border-radius: 8px; padding: 10px;');
      allBtn.innerHTML = '<i class="fa fa-list mr-2"></i> Show All Volumes';
      container.appendChild(allBtn);
    }

    function rebuildVolumeAccordionsFromArticles() {
      var volumes = buildVolumesFromArticles();
      renderVolumeAccordion('volume-accordion', volumes, { collapsePrefix: 'vol' });
      renderVolumeAccordion('volume-accordion-mobile', volumes, { collapsePrefix: 'volm' });
    }

    function closePublicationFilterModal() {
      if (window.jQuery && typeof window.jQuery.fn.modal === 'function') {
        window.jQuery('#publicationFilterModal').modal('hide');
      }
    }

    function syncFilterDateInputs() {
      var start = document.getElementById('filterStartDate');
      var end = document.getElementById('filterEndDate');

      if (start && currentFilters.startDate) {
        start.value = currentFilters.startDate.toISOString().slice(0, 10);
      }
      if (end && currentFilters.endDate) {
        end.value = currentFilters.endDate.toISOString().slice(0, 10);
      }
    }

    function initFilters() {
      var articles = document.querySelectorAll('article.article-card');
      
      // Initialize with all required base categories to ensure they always show up
      var categorySet = new Set([
        'Computer Science & Engineering',
        'Mathematics & Mathematical Sciences',
        'Applied Physics',
        'Applied Chemistry',
        'Civil Engineering',
        'Mechanical Engineering',
        'Business, Management & Accounting',
        'Electronics & Communication Engineering',
        'Humanities & Social Science',
        'General',
        'Biology & Pharmacy',
        'Environmental Science'
      ]);
      
      var tags = new Set();
      var volumes = new Set();

      articles.forEach(function (article) {
        var cat = article.getAttribute('data-category');
        if (cat) categorySet.add(cat);

        var tagEl = article.querySelector('.badge.badge-primary');
        if (tagEl) tags.add(tagEl.innerText.trim());

        var year = getArticlePublishedYear(article);
        if (year !== null) volumes.add(String(year));
      });

      populateFilterUI('journal-filter-container', Array.from(categorySet).sort(), 'category');
      populateFilterUI('volume-filter-container', Array.from(volumes).sort(function (a, b) { return parseInt(b, 10) - parseInt(a, 10); }), 'volume');
      
      var catsArray = Array.from(categorySet).sort();
      var hBar = document.getElementById('horizontal-category-bar');
      if (hBar) {
        hBar.innerHTML = '';
        var allLink = document.createElement('a');
        allLink.href = 'javascript:void(0)';
        allLink.className = 'mr-4 text-primary active-cat mb-2';
        allLink.setAttribute('data-cat', 'All');
        allLink.onclick = function() { setFilter('category', 'All', null); };
        allLink.style.cssText = 'text-decoration: none; font-size: 1.0em; font-weight: 600; display: inline-block;';
        allLink.innerText = 'All Journals';
        hBar.appendChild(allLink);

        catsArray.forEach(function(cat) {
          var displayCat = cat.replace(/^(Journal of Advance Research in |Journal of Advance Research |Journal of )/i, '').replace(/^\(General\)$/i, 'General').trim();
          var link = document.createElement('a');
          link.href = 'javascript:void(0)';
          link.className = 'mr-4 text-muted mb-2';
          link.setAttribute('data-cat', cat);
          link.onclick = function() { setFilter('category', cat, null); };
          link.style.cssText = 'text-decoration: none; font-size: 1.0em; display: inline-block;';
          link.innerText = displayCat;
          hBar.appendChild(link);
        });
      }

      populateFilterUI('tag-filter-container', Array.from(tags).sort(), 'tag');
      populateFilterUI('mobile-tag-filter-container', Array.from(tags).sort(), 'tag');

      syncFilterDateInputs();
      applyFilters();
    }

    function populateFilterUI(containerId, items, type) {
      var container = document.getElementById(containerId);
      if (!container) return;
      container.innerHTML = '';

      var labelMap = {
        tag: 'Tags',
        category: 'Journals',
        volume: 'Volumes'
      };

      var allBtn = document.createElement('button');
      allBtn.className = 'btn btn-outline-primary btn-sm btn-block text-left mb-2 active';
      allBtn.innerText = 'All ' + (labelMap[type] || 'Items');
      allBtn.onclick = function () {
        setFilter(type, 'All', this);
        if (type === 'category' || type === 'volume') closePublicationFilterModal();
      };
      container.appendChild(allBtn);

      items.forEach(function (item) {
        var btn = document.createElement('button');
        btn.className = 'btn btn-outline-primary btn-sm btn-block text-left mb-2';
        btn.innerText = item;
        btn.onclick = function () {
          setFilter(type, item, this);
          if (type === 'category' || type === 'volume') closePublicationFilterModal();
        };
        container.appendChild(btn);
      });
    }

    function setFilter(type, value, btnElement) {
      currentFilters[type] = value;
      currentPage = 1; // Reset to page 1 on filter change

      // Handle UI active state (modal buttons)
      if (btnElement) {
        var container = btnElement.parentElement;
        var buttons = container.querySelectorAll('button');
        buttons.forEach(function (b) { b.classList.remove('active'); });
        btnElement.classList.add('active');
      }

      // Sync horizontal category bar
      if (type === 'category') {
        var hBar = document.getElementById('horizontal-category-bar');
        if (hBar) {
          var links = hBar.querySelectorAll('a');
          links.forEach(function(l) { 
            l.classList.remove('text-primary', 'font-weight-bold', 'active-cat'); 
            l.classList.add('text-muted'); 
          });
          var activeLink = Array.from(links).find(function(l) { return l.getAttribute('data-cat') === value; });
          if (activeLink) {
             activeLink.classList.remove('text-muted');
             activeLink.classList.add('text-primary', 'font-weight-bold', 'active-cat');
          }
        }
      }

      applyFilters();
    }

    function resetPublicationFilters() {
      currentFilters.category = 'All';
      currentFilters.tag = 'All';
      currentFilters.startDate = null;
      currentFilters.endDate = null;
      currentFilters.search = '';
      currentFilters.volume = 'All';
      currentPage = 1;

      var searchInput = document.getElementById('articleSearch');
      if (searchInput) searchInput.value = '';

      ['filterStartDate', 'filterEndDate', 'startDate', 'endDate', 'startDateMobile', 'endDateMobile'].forEach(function (id) {
        var input = document.getElementById(id);
        if (input) input.value = '';
      });

      initFilters();
      closePublicationFilterModal();
    }

    function filterByDate() {
      var startInput = document.getElementById('filterStartDate') || document.getElementById('startDate');
      var endInput = document.getElementById('filterEndDate') || document.getElementById('endDate');
      var start = startInput ? startInput.value : '';
      var end = endInput ? endInput.value : '';
      currentFilters.startDate = start ? new Date(start) : null;
      currentFilters.endDate = end ? new Date(end) : null;
      currentPage = 1;

      var legacyStart = document.getElementById('startDate');
      var legacyEnd = document.getElementById('endDate');
      var mobileStart = document.getElementById('startDateMobile');
      var mobileEnd = document.getElementById('endDateMobile');
      if (legacyStart) legacyStart.value = start;
      if (legacyEnd) legacyEnd.value = end;
      if (mobileStart) mobileStart.value = start;
      if (mobileEnd) mobileEnd.value = end;

      applyFilters();
      closePublicationFilterModal();
    }

    function filterByDateMobile() {
      filterByDate();
    }

    function searchArticles() {
      var input = document.getElementById('articleSearch');
      currentFilters.search = input.value.trim();
      currentPage = 1;
      applyFilters();
    }

    function filterByCategory(category) {
      currentFilters.category = category;
      currentPage = 1;
      applyFilters();
    }

    function filterByVolume(volume) {
      currentFilters.volume = volume;
      currentPage = 1;
      applyFilters();
      closePublicationFilterModal();
    }

    function applyFilters() {
      var container = document.getElementById('articles-list');
      if (container) {
        container.style.display = 'grid';
      }
      var articles = Array.from(container.querySelectorAll('article.article-card'));
      var filterCategory = currentFilters.category;
      var filterTag = currentFilters.tag;
      var filterSearch = currentFilters.search.toUpperCase();
      var filterVolume = currentFilters.volume;

      var visibleArticles = [];

      // 1. Filter Logic
      articles.forEach(function (article) {
        var matchesCategory = (filterCategory === 'All') || (article.getAttribute('data-category') === filterCategory);

        var tagEl = article.querySelector('.badge.badge-primary');
        var articleTag = tagEl ? tagEl.innerText.trim() : '';
        var matchesTag = (filterTag === 'All') || (articleTag === filterTag);

        // Date Logic
        var dateEl = article.querySelector('.text-muted.small');
        var articleDate = dateEl ? new Date(dateEl.innerText.trim()) : null;
        var matchesDate = true;

        if (currentFilters.startDate) {
          if (!articleDate || articleDate < currentFilters.startDate) matchesDate = false;
        }
        if (matchesDate && currentFilters.endDate) {
          // Set end date to end of day
          var endOfDay = new Date(currentFilters.endDate);
          endOfDay.setHours(23, 59, 59, 999);
          if (!articleDate || articleDate > endOfDay) matchesDate = false;
        }

        // Search Logic
        var matchesSearch = true;
        if (filterSearch !== "") {
          var text = article.textContent.toUpperCase();
          if (text.indexOf(filterSearch) === -1) {
            matchesSearch = false;
          }
        }

        // Volume Logic
        var articleYear = getArticlePublishedYear(article);
        var matchesVolume = true;
        if (filterVolume !== 'All') {
          if (/^\d{4}$/.test(String(filterVolume))) {
            matchesVolume = articleYear !== null && String(articleYear) === String(filterVolume);
          } else {
            var derivedCode = getDerivedVolumeCodeForArticle(article);
            matchesVolume = (filterVolume === UNASSIGNED_VOLUME_CODE)
              ? derivedCode === UNASSIGNED_VOLUME_CODE
              : (derivedCode === filterVolume);
          }
        }

        if (matchesCategory && matchesTag && matchesDate && matchesSearch && matchesVolume) {
          visibleArticles.push(article);
        }
      });

      // 2. Pagination Logic
      var totalItems = visibleArticles.length;
      var totalPages = Math.ceil(totalItems / itemsPerPage);

      // Ensure currentPage is valid
      if (currentPage > totalPages) currentPage = totalPages || 1;

      var startIndex = (currentPage - 1) * itemsPerPage;
      var endIndex = startIndex + itemsPerPage;

      // 3. Show/Hide Articles
      articles.forEach(function (article) {
        article.classList.add('hidden-article');
        // Also hide the dividing HRs
        if (article.nextElementSibling && article.nextElementSibling.tagName === 'HR') {
          article.nextElementSibling.classList.add('hidden-article');
        }
      });

      for (var i = startIndex; i < endIndex && i < visibleArticles.length; i++) {
        var article = visibleArticles[i];
        article.classList.remove('hidden-article');
        article.style.display = "";

        // Show HR if not the last visible item
        if (i < visibleArticles.length - 1) {
          var nextHr = article.nextElementSibling;
          if (nextHr && nextHr.tagName === 'HR') {
            nextHr.classList.remove('hidden-article');
            nextHr.style.display = "";
          }
        }
      }

      updatePagination(totalPages);
    }

    function updatePagination(totalPages) {
      var nav = document.getElementById('pagination-nav');
      var container = document.getElementById('pagination-controls');
      container.innerHTML = '';

      if (totalPages <= 1) {
        nav.style.display = 'none';
        return;
      }

      nav.style.display = 'block';

      // Previous Button
      var prevLi = document.createElement('li');
      prevLi.className = 'page-item ' + (currentPage === 1 ? 'disabled' : '');
      var prevLink = document.createElement('a');
      prevLink.className = 'page-link';
      prevLink.href = '#';
      prevLink.innerText = 'Previous';
      prevLink.onclick = function (e) { e.preventDefault(); if (currentPage > 1) { currentPage--; applyFilters(); window.scrollTo(0, 0); } };
      prevLi.appendChild(prevLink);
      container.appendChild(prevLi);

      // Page Numbers
      for (var i = 1; i <= totalPages; i++) {
        var li = document.createElement('li');
        li.className = 'page-item ' + (i === currentPage ? 'active' : '');
        var link = document.createElement('a');
        link.className = 'page-link';
        link.href = '#';
        link.innerText = i;
        (function (page) {
          link.onclick = function (e) { e.preventDefault(); currentPage = page; applyFilters(); window.scrollTo(0, 0); };
        })(i);
        li.appendChild(link);
        container.appendChild(li);
      }

      // Next Button
      var nextLi = document.createElement('li');
      nextLi.className = 'page-item ' + (currentPage === totalPages ? 'disabled' : '');
      var nextLink = document.createElement('a');
      nextLink.className = 'page-link';
      nextLink.href = '#';
      nextLink.innerText = 'Next';
      nextLink.onclick = function (e) { e.preventDefault(); if (currentPage < totalPages) { currentPage++; applyFilters(); window.scrollTo(0, 0); } };
      nextLi.appendChild(nextLink);
      container.appendChild(nextLi);
    }

    // --- Volume View Logic 2.0 (Drill-down) ---
    var currentVolumeData = null; // Store data to support navigation

    function toggleView(view, keepFilters) {
      var listView = document.getElementById('articles-list');
      var volumeView = document.getElementById('volume-view-container');
      var pagination = document.getElementById('pagination-nav');
      var btnList = document.getElementById('btn-list-view');
      var btnVolume = document.getElementById('btn-volume-view');

      if (view === 'list') {
        listView.style.display = 'grid';
        volumeView.style.display = 'none';
        pagination.style.display = 'none';

        btnList.classList.add('active');
        btnVolume.classList.remove('active');

        // Reset volume filter and hide context navigation
        if (!keepFilters) {
          currentFilters.volume = 'All';
          var contextDiv = document.getElementById('list-view-context');
          if (contextDiv) contextDiv.style.display = 'none';
        }

        // Ensure filters are applied to list
        applyFilters();
      } else {
        // Hide Context Header when switching manually to Volume View
        var contextDiv = document.getElementById('list-view-context');
        if (contextDiv) contextDiv.style.display = 'none';

        listView.style.display = 'none';
        volumeView.style.display = 'flex';
        pagination.style.display = 'none';

        btnList.classList.remove('active');
        btnVolume.classList.add('active');

        // Reset to Level 1 (Volumes)
        renderVolumesLevel();
      }
    }

    // Helper to parse sidebar data
    function getVolumeData() {
      var accordion = document.getElementById('volume-accordion');
      if (!accordion) return [];
      var groups = accordion.querySelectorAll('.mb-2');
      var data = [];
      groups.forEach(function (g) {
        var toggle = g.querySelector('button[data-toggle="collapse"]');
        if (!toggle) return;

        var targetId = toggle.getAttribute('data-target').replace('#', '');
        // Clean title text (remove chevron/newlines)
        var span = toggle.querySelector('span');
        var title = span ? span.innerText.trim() : toggle.innerText.replace(/\s+/g, ' ').trim();

        var collapse = document.getElementById(targetId);
        var issues = [];
        if (collapse) {
          collapse.querySelectorAll('button').forEach(function (btn) {
            var codeMatch = btn.getAttribute('onclick').match(/'([^']+)'/);
            var code = codeMatch ? codeMatch[1] : '';
            issues.push({ name: btn.innerText.trim(), code: code });
          });
        }
        data.push({ title: title, issues: issues, id: targetId });
      });
      return data;
    }

    // Level 1: Render Volumes
    function renderVolumesLevel() {
      var container = document.getElementById('volume-view-container');
      container.innerHTML = '';

      var vols = getVolumeData();

      vols.forEach(function (vol) {
        var col = document.createElement('div');
        col.className = 'col-md-6 col-lg-4 mb-4'; // 3 per row on large screens

        var card = document.createElement('div');
        card.className = 'card h-100 shadow-sm text-center p-4 hover-lift';
        card.style.cursor = 'pointer';
        card.style.borderRadius = '12px';
        card.style.border = '1px solid #e0e0e0';

        // Hover effect class logic (needs CSS or JS inline)
        card.onmouseenter = function () { this.style.transform = 'translateY(-5px)'; this.classList.add('shadow'); };
        card.onmouseleave = function () { this.style.transform = 'translateY(0)'; this.classList.remove('shadow'); };

        // content
        var icon = '<i class="fa fa-folder-open-o fa-3x text-primary mb-3"></i>';
        var title = '<h4 class="font-weight-bold text-dark">' + vol.title + '</h4>';
        var count = '<p class="text-muted mb-0">' + vol.issues.length + ' Issue' + (vol.issues.length !== 1 ? 's' : '') + '</p>';

        card.innerHTML = icon + title + count;

        // Click -> Level 2
        card.onclick = function () { renderIssuesLevel(vol); };

        col.appendChild(card);
        container.appendChild(col);
      });

      // "Show All" Card
      var colAll = document.createElement('div');
      colAll.className = 'col-md-6 col-lg-4 mb-4';
      var cardAll = document.createElement('div');
      cardAll.className = 'card h-100 shadow-sm bg-primary text-white text-center p-4 hover-lift';
      cardAll.style.cursor = 'pointer';
      cardAll.style.borderRadius = '12px';

      cardAll.onmouseenter = function () { this.style.transform = 'translateY(-5px)'; this.classList.add('shadow'); };
      cardAll.onmouseleave = function () { this.style.transform = 'translateY(0)'; this.classList.remove('shadow'); };

      cardAll.innerHTML = '<i class="fa fa-th-list fa-3x mb-3 text-white"></i><h4 class="font-weight-bold text-white mb-0">Browse All Articles</h4>';
      cardAll.onclick = function () {
        toggleView('list');
        filterByVolume('All');
      };
      colAll.appendChild(cardAll);
      container.appendChild(colAll);
    }

    // Level 2: Render Issues for a Volume
    function renderIssuesLevel(vol) {
      currentVolumeData = vol; // Save context
      var container = document.getElementById('volume-view-container');
      container.innerHTML = '';

      // Navigation Header
      var navRow = document.createElement('div');
      navRow.className = 'col-12 mb-4';
      navRow.innerHTML = `
         <button class="btn btn-success font-weight-bold mb-3 shadow-sm" onclick="renderVolumesLevel()">
           <i class="fa fa-arrow-left"></i> Back to Volumes
         </button>
         <h3 class="font-weight-bold border-bottom pb-2">${vol.title} <span class="text-muted h5 ml-2">Select an Issue</span></h3>
       `;
      container.appendChild(navRow);

      if (vol.issues.length === 0) {
        container.innerHTML += '<div class="col-12 text-center text-muted">No issues found for this volume.</div>';
        return;
      }

      vol.issues.forEach(function (issue) {
        var col = document.createElement('div');
        col.className = 'col-md-4 mb-4';

        var card = document.createElement('div');
        card.className = 'card h-100 shadow-sm text-center p-4';
        card.style.cursor = 'pointer';
        card.style.borderRadius = '12px';
        card.style.border = '1px solid #e0e0e0';
        card.style.transition = 'all 0.2s';

        card.innerHTML = `
            <i class="fa fa-file-text-o fa-3x text-info mb-3"></i>
            <h5 class="font-weight-bold text-dark">${issue.name}</h5>
            <small class="text-muted">Click to view articles</small>
          `;

        card.onmouseenter = function () {
          this.style.transform = 'translateY(-5px)';
          this.className = 'card h-100 shadow text-center p-4 border-primary';
        };
        card.onmouseleave = function () {
          this.style.transform = 'translateY(0)';
          this.className = 'card h-100 shadow-sm text-center p-4';
        };

        card.onclick = function () {
          toggleView('list');
          filterByVolume(issue.code); // This applies the filter

          // Show Context Header
          var contextDiv = document.getElementById('list-view-context');
          var title = document.getElementById('context-title');
          var subtitle = document.getElementById('context-subtitle');
          var backBtn = document.getElementById('context-back-btn');

          if (contextDiv) {
            contextDiv.style.display = 'block';
            title.innerText = vol.title + " - " + issue.name;
            subtitle.innerText = "Displaying articles from this issue.";

            backBtn.onclick = function () {
              toggleView('volume');
              renderIssuesLevel(vol); // Go back to this volume's issues
            };
          }

          // Smooth scroll to top
          window.scrollTo({ top: 0, behavior: 'smooth' });
        };

        col.appendChild(card);
        container.appendChild(col);
      });
    }
  </script>
  <div style="display:none;">
    <a href="/publication.php">Latest Publications</a>
    <a href="/authorguidelines.php">Author Guidelines</a>
    <a href="/call-for-paper.php">Call for Papers</a>
    <a href="/editorial-board.php">Editorial Board</a>
    <a href="/contact.php">Contact</a>
  </div>
  <div style="display:none;">
    <span itemprop="citation">Global Youth Science Journal Editorial Board. (2026). Latest Publications. Global Youth
      Science Journal. https://<?php echo $_SERVER["HTTP_HOST"]; ?>/publication.php</span>
  </div>
  <div class="modal fade" id="publicationFilterModal" tabindex="-1" role="dialog"
    aria-labelledby="publicationFilterModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
      <div class="modal-content border-0 shadow-lg publication-filter-modal">
        <div class="modal-header border-0 pb-0">
          <div>
            <h4 class="modal-title mb-1" id="publicationFilterModalLabel">Filter Publications</h4>
            <p class="text-muted mb-0">Choose a journal or date range.</p>
          </div>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body pt-3">
          <div class="publication-filter-section mb-4">
            <div class="d-flex align-items-center justify-content-between mb-3">
              <h5 class="mb-0">Browse by Journal</h5>
              <small class="text-muted">Pick one category</small>
            </div>
            <div id="journal-filter-container" class="d-flex flex-wrap"></div>
          </div>

          <div class="publication-filter-section">
            <div class="d-flex align-items-center justify-content-between mb-3">
              <h5 class="mb-0">Filter by Date</h5>
              <small class="text-muted">Set a date range</small>
            </div>
            <div class="row">
              <div class="col-md-6 mb-3">
                <label for="filterStartDate" class="font-weight-bold">From</label>
                <input type="date" id="filterStartDate" class="form-control">
              </div>
              <div class="col-md-6 mb-3">
                <label for="filterEndDate" class="font-weight-bold">To</label>
                <input type="date" id="filterEndDate" class="form-control">
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-outline-secondary" onclick="resetPublicationFilters()">Reset</button>
          <button type="button" class="btn btn-primary" onclick="filterByDate(); $('#publicationFilterModal').modal('hide');">Apply Date Filter</button>
        </div>
      </div>
    </div>
  </div>
  </div>
  </div>
  </div>
  </div>

  <!-- Author Modal for List View -->
  <div class="modal fade" id="authorModal" tabindex="-1" role="dialog" aria-labelledby="authorModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <div class="modal-content border-0 shadow-lg">
        <div class="modal-header border-0 pb-0">
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body text-center pt-0 pb-5">
          <h4 id="modalAuthorName" class="mb-4" style="color: #3b508f; font-weight: 600; font-family: 'Inter', sans-serif;"></h4>
          <div class="d-flex flex-column align-items-center" style="gap: 15px;">
            <a id="modalOrcidLink" href="#" target="_blank" class="btn btn-outline-success d-flex align-items-center justify-content-center w-75" style="border-radius: 50px; padding: 10px; font-family: 'Inter', sans-serif;">
              <img src="https://orcid.org/assets/vectors/orcid.logo.icon.svg" alt="ORCID iD icon" style="width: 24px; margin-right: 10px;">
              <span id="modalOrcidText">ORCID iD</span>
            </a>
            <a id="modalScholarLink" href="#" target="_blank" class="btn btn-outline-primary d-flex align-items-center justify-content-center w-75" style="border-radius: 50px; padding: 10px; border-color: #4285F4; color: #4285F4; font-family: 'Inter', sans-serif;">
              <i class="fa fa-graduation-cap mr-2" style="font-size: 1.2rem;"></i> Google Scholar
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
  document.addEventListener('DOMContentLoaded', function() {
      // Convert plain text authors in the list view to clickable links
      var authorSpans = document.querySelectorAll('span[itemprop="author"]');
      authorSpans.forEach(function(span) {
          if (span.querySelector('a')) return; // Already processed
          var text = span.textContent;
          if (!text) return;
          var authorList = text.split(',');
          var newHtml = [];
          authorList.forEach(function(authorItem) {
              authorItem = authorItem.trim();
              if (authorItem === '') return;
              var cleanName = authorItem.replace(/[\*\(\d\)]/g, '').trim();
              
              var hash = 0;
              for (var i = 0; i < cleanName.length; i++) {
                  hash = ((hash << 5) - hash) + cleanName.charCodeAt(i);
                  hash |= 0; 
              }
              hash = Math.abs(hash);
              var mockOrcid = '0000-' + 
                              ('0000' + (hash % 10000)).slice(-4) + '-' + 
                              ('0000' + ((hash * 7) % 10000)).slice(-4) + '-' + 
                              ('0000' + ((hash * 13) % 10000)).slice(-4);
              
              var a = document.createElement('a');
              a.href = '#';
              a.className = 'author-link';
              a.setAttribute('data-name', cleanName);
              a.setAttribute('data-orcid', mockOrcid);
              a.setAttribute('data-toggle', 'modal');
              a.setAttribute('data-target', '#authorModal');
              a.style.color = 'inherit';
              a.style.textDecoration = 'underline';
              a.style.textUnderlineOffset = '3px';
              a.textContent = authorItem;
              newHtml.push(a.outerHTML);
          });
          span.innerHTML = newHtml.join(', ');
      });

      // Handle author clicks
      document.body.addEventListener('click', function(e) {
          var link = e.target.closest('.author-link');
          if (link) {
              e.preventDefault();
              var name = link.getAttribute('data-name');
              var orcid = link.getAttribute('data-orcid');
              var scholar = link.getAttribute('data-scholar');
              
              document.getElementById('modalAuthorName').textContent = name;
              
              var orcidUrl = orcid.startsWith('http') ? orcid : 'https://orcid.org/' + orcid;
              document.getElementById('modalOrcidLink').setAttribute('href', orcidUrl);
              document.getElementById('modalOrcidText').textContent = orcidUrl;
              
              var scholarUrl = scholar ? scholar : 'https://scholar.google.com/scholar?q=' + encodeURIComponent(name);
              document.getElementById('modalScholarLink').setAttribute('href', scholarUrl);
          }
      });
  });
  </script>

<script type="text/javascript">window.DocsBotAI=window.DocsBotAI||{},DocsBotAI.init=function(e){return new Promise((t,r)=>{var n=document.createElement("script");n.type="text/javascript",n.async=!0,n.src="https://widget.docsbot.ai/chat.js";let o=document.getElementsByTagName("script")[0];o.parentNode.insertBefore(n,o),n.addEventListener("load",()=>{let n;Promise.all([new Promise((t,r)=>{window.DocsBotAI.mount(Object.assign({}, e)).then(t).catch(r)}),(n=function e(t){return new Promise(e=>{if(document.querySelector(t))return e(document.querySelector(t));let r=new MutationObserver(n=>{if(document.querySelector(t))return e(document.querySelector(t)),r.disconnect()});r.observe(document.body,{childList:!0,subtree:!0})})})("#docsbotai-root"),]).then(()=>t()).catch(r)}),n.addEventListener("error",e=>{r(e.message)})})};</script>
<script type="text/javascript">
  DocsBotAI.init({id: "iObiGayCpgoP4PaVkF2c/yWdHLXZUvbBmqIUqkjIe"});
</script>
</body>

</html>