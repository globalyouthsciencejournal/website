<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Submissions are handled via the user dashboard so authors can track status.
$submitUrl = 'user-dashboard.php?view=submit';

if (auth_is_logged_in()) {
  redirect($submitUrl);
}

redirect('login.php?redirect=' . urlencode($submitUrl));
?>
<!DOCTYPE html>
<html lang="en" class="no-js">

<head>
  <!-- Required meta tags -->
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <link rel="shortcut icon" type="image/jpg" href="images/iysjournal.png">
  <title>Submit Your Research  Global Youth Science Journal | Online Submission</title>
  <meta name="description"
    content="Submit your research paper online to the Global Youth Science Journal. Join young scientists worldwide in publishing innovative research.">
  <meta name="keywords"
    content="submit, research paper, online submission, Global Youth Science Journal, GYS Journal, young scientists, publish">
  <link href="css/media_query.css" rel="stylesheet" type="text/css">
  <link href="css/style.css " rel="stylesheet" type="text/css">
  <link href="css/bootstrap.css" rel="stylesheet" type="text/css">
  <link href="css/font-awesome.min.css" rel="stylesheet" crossorigin="anonymous">
  <link href="css/animate.css" rel="stylesheet" type="text/css">
  <link href="https://fonts.googleapis.com/css?family=Poppins" rel="stylesheet">
  <link href="css/owl.carousel.css" rel="stylesheet" type="text/css">
  <link href="css/owl.theme.default.css" rel="stylesheet" type="text/css">
  <!-- Bootstrap CSS -->
  <link href="css/style_1.css" rel="stylesheet" type="text/css">
  <!-- Modernizr JS -->
  <script src="js/modernizr-3.5.0.min.js"></script>
  <!-- Schema.org Article Structured Data -->
  <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "Article",
      "headline": "Submit Your Research  Global Youth Science Journal",
      "description": "Submit your research paper online to the Global Youth Science Journal. Join young scientists worldwide in publishing innovative research.",
      "publisher": {
        "@type": "Organization",
        "name": "Global Youth Science Journal",
        "logo": {"@type": "ImageObject", "url": "https://<?php echo $_SERVER["HTTP_HOST"]; ?>/images/logo.png"}
      },
      "mainEntityOfPage": "https://<?php echo $_SERVER["HTTP_HOST"]; ?>/submit.php"
    }
    </script>
  <!-- Google tag (gtag.js) -->
  <script async="" src="https://www.googletagmanager.com/gtag/js?id=G-P6B5PL5DMH"></script>
  <script> window.dataLayer = window.dataLayer || []; function gtag() { dataLayer.push(arguments); } gtag('js', new Date()); gtag('config', 'G-P6B5PL5DMH'); </script>
  <!-- SEO: Canonical, meta, schema, and academic optimizations -->
  <link rel="canonical" href="https://<?php echo $_SERVER["HTTP_HOST"]; ?>/submit.php" />
  <meta name="robots" content="index, follow">
  <meta name="author" content="Global Youth Science Journal Editorial Board">
  <meta name="citation_journal_title" content="Global Youth Science Journal">
  <meta name="citation_publication_date" content="2026/08/03">
  <meta name="citation_language" content="en">
  <meta name="citation_publisher" content="Global Youth Science Journal">
  <meta name="citation_title" content="Submit Your Research  Global Youth Science Journal">
  <meta name="citation_keywords"
    content="peer-reviewed, open-access, research journal, young scientists, science, technology, engineering, mathematics, STEM, publication, scholarly, academic, global, youth, research paper, submission, journal, scientific publishing, Google Scholar, citation, author guidelines, editorial board, call for papers, free publication, under 20, international">
  <meta property="og:type" content="website">
  <meta property="og:title" content="Submit Your Research  Global Youth Science Journal">
  <meta property="og:description"
    content="Submit your research to the Global Youth Science Journal, a peer-reviewed, open-access platform for young scientists.">
  <meta property="og:url" content="https://<?php echo $_SERVER["HTTP_HOST"]; ?>/submit.php">
  <meta property="og:image" content="https://<?php echo $_SERVER["HTTP_HOST"]; ?>/images/logo.png">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="Submit Your Research  Global Youth Science Journal">
  <meta name="twitter:description" content="Peer-reviewed, open-access research for young scientists worldwide.">
  <meta name="twitter:image" content="https://<?php echo $_SERVER["HTTP_HOST"]; ?>/images/logo.png">
  <!-- BreadcrumbList Schema -->
  <script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [
    {"@type": "ListItem", "position": 1, "name": "Home", "item": "https://<?php echo $_SERVER["HTTP_HOST"]; ?>/"},
    {"@type": "ListItem", "position": 2, "name": "Submit", "item": "https://<?php echo $_SERVER["HTTP_HOST"]; ?>/submit.php"}
  ]
}
</script>
  <!-- ScholarlyArticle Schema for submission page -->
  <script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "ScholarlyArticle",
  "headline": "Submit Your Research",
  "name": "Submit Your Research",
  "description": "Submit your original research to the Global Youth Science Journal. Open to young scientists worldwide.",
  "datePublished": "2026-08-03",
  "publisher": {
    "@type": "Organization",
    "name": "Global Youth Science Journal",
    "logo": {"@type": "ImageObject", "url": "https://<?php echo $_SERVER["HTTP_HOST"]; ?>/images/logo.png"}
  },
  "isPartOf": {
    "@type": "Periodical",
    "name": "Global Youth Science Journal"
  },
  "keywords": ["peer-reviewed", "open-access", "research journal", "young scientists", "submission", "science", "technology", "engineering", "mathematics", "STEM", "academic", "global", "youth", "journal", "Google Scholar", "citation", "author guidelines", "call for papers", "free publication", "under 20", "international"]
}
</script>
  <!-- FAQPage Schema -->
  <script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "FAQPage",
  "mainEntity": [
    {
      "@type": "Question",
      "name": "How do I submit my research?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "Use the online submission form to upload your manuscript and supporting documents."
      }
    },
    {
      "@type": "Question",
      "name": "What are the requirements for submission?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "Submissions must be original research by authors aged 12 to 20. See Author Guidelines for details."
      }
    }
  ]
}
</script>
  <!-- End SEO additions -->
    <script src="/js/main.js" defer></script>
</head>

<body>
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
                        <li class="nav-item active">
                            <a class="nav-link" href="index.php">Home <span class="sr-only">(current)</span></a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="publication.php">Publication</a>
                        </li>
                        <li class="nav-item ">
                            <a class="nav-link" href="editorial-board.php">Editorial Board</a>
                        </li>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="dropdownMenuButton2" data-toggle="dropdown"
                                aria-haspopup="true" aria-expanded="false">About Us</a>
                            <div class="dropdown-menu" aria-labelledby="dropdownMenuButton2">
                                <a class="dropdown-item" href="our-founders.php">Our Founders</a>
                                <a class="dropdown-item" href="our-mission.php">Our Mission</a>
                                <a class="dropdown-item" href="our-funding.php">Our Funding</a>
                            </div>
                        </li>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="dropdownMenuButton3" data-toggle="dropdown"
                                aria-haspopup="true" aria-expanded="false">Paper Submissions</a>
                            <div class="dropdown-menu" aria-labelledby="dropdownMenuButton3">
                                <a class="dropdown-item" href="submit.php">Online Submission</a>
                                <a class="dropdown-item" href="call-for-paper.php">Call for Paper</a>
                                <a class="dropdown-item" href="authorguidelines.php">Guidelines for authors</a>
                                <a class="dropdown-item" href="copyright.php">Copyright</a>
                            </div>
                        </li>
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

                        <?php if (auth_is_logged_in()): $navUser = auth_current_user(); ?>
                        <li class="nav-item dropdown">
                          <a class="nav-link dropdown-toggle" href="#" id="accountMenu" data-toggle="dropdown"
                            aria-haspopup="true" aria-expanded="false"><?php echo e(($navUser['name'] ?? '') !== '' ? $navUser['name'] : ($navUser['email'] ?? 'Account')); ?></a>
                            <div class="dropdown-menu" aria-labelledby="accountMenu">
                                <a class="dropdown-item" href="<?php echo e((($navUser['role'] ?? '') === 'admin') ? 'admin-dashboard.php' : 'user-dashboard.php'); ?>">Dashboard</a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="../account.php">Account Settings</a>
                                <a class="dropdown-item" href="../logout.php">Log Out</a>
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
  <div class="container" style="padding: 20px; max-width: 860px;">
    <div class="alert alert-warning" role="alert" style="text-align:center; margin: 0 0 14px;">
      <strong>Before you submit:</strong> please ensure your manuscript follows the format provided in our
      <a href="authorguidelines.php">Author Guidelines</a>.
    </div>

    <div class="card shadow-sm">
      <div class="card-body" style="text-align:center; padding: 28px 18px;">
        <h2 class="h4 mb-2">Online Submission</h2>
        <p class="text-muted mb-4">To submit a manuscript and track its review status, please log in or create an account.</p>
        <a class="btn btn-primary" href="login.php?redirect=submit.php">Login</a>
        <a class="btn btn-outline-primary" href="signup.php" style="margin-left: 8px;">Sign Up</a>
      </div>
    </div>
  </div>

  <div class="gototop js-top">
    <a href="#" class="js-gotop"><i class="fa fa-arrow-up"></i></a>
  </div>

  <script src="js/jquery.min.js"></script>
  <script src="js/owl.carousel.min.js"></script>
  <script src="js/tether.min.js" crossorigin="anonymous"></script>
  <script src="js/bootstrap.min.js" crossorigin="anonymous"></script>
  <!-- Waypoints -->
  <script src="js/jquery.waypoints.min.js"></script>
  <footer class="fh5co_footer_bg" role="contentinfo">
    <div class="container pb-4">
      <div class="row">
        <div class="col-12 col-lg-4 mb-4">
          <a href="index.php" class="d-inline-flex align-items-center text-decoration-none">
            <img src="images/iysjournal.png" class="footer_logo mr-2" alt="Global Youth Science Journal logo">
            <span class="footer_main_title">Global Youth Science Journal</span>
          </a>
          <p class="footer_sub_about mt-3 mb-2">Peer-reviewed, open-access research for young scientists (ages 12 to 20).</p>
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
            <li><a href="submit.php">Online Submission</a></li>
            <li><a href="call-for-paper.php">Call for Papers</a></li>
            <li><a href="authorguidelines.php">Author Guidelines</a></li>
            <li><a href="copyright.php">Copyright</a></li>
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
          <a href="copyright.php">Â© Copyright 2026</a>, Licensed under
          <a href="https://creativecommons.org/licenses/by/4.0/" target="_blank" rel="noopener">Creative Commons Attribution 4.0 International License.</a>
        </div>
       
      </div>
    </div>
  </footer>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      document.querySelectorAll('img').forEach(function (img) {
        if (!img.alt || img.alt.trim() === '') {
          img.alt = 'Global Youth Science Journal image';
        }
      });
    });
  </script>
  <div style="display:none;">
    <a href="/publication.php">Latest Publications</a>
    <a href="/authorguidelines.php">Author Guidelines</a>
    <a href="/call-for-paper.php">Call for Papers</a>
    <a href="/editorial-board.php">Editorial Board</a>
    <a href="/contact.php">Contact</a>
  </div>
  <div style="display:none;">
    <span itemprop="citation">Global Youth Science Journal Editorial Board. (2026). Submit Your Research. Global Youth
      Science Journal. https://<?php echo $_SERVER["HTTP_HOST"]; ?>/submit.php</span>
  </div>

<script type="text/javascript">window.DocsBotAI=window.DocsBotAI||{},DocsBotAI.init=function(e){return new Promise((t,r)=>{var n=document.createElement("script");n.type="text/javascript",n.async=!0,n.src="https://widget.docsbot.ai/chat.js";let o=document.getElementsByTagName("script")[0];o.parentNode.insertBefore(n,o),n.addEventListener("load",()=>{let n;Promise.all([new Promise((t,r)=>{window.DocsBotAI.mount(Object.assign({}, e)).then(t).catch(r)}),(n=function e(t){return new Promise(e=>{if(document.querySelector(t))return e(document.querySelector(t));let r=new MutationObserver(n=>{if(document.querySelector(t))return e(document.querySelector(t)),r.disconnect()});r.observe(document.body,{childList:!0,subtree:!0})})})("#docsbotai-root"),]).then(()=>t()).catch(r)}),n.addEventListener("error",e=>{r(e.message)})})};</script>
<script type="text/javascript">
  DocsBotAI.init({id: "iObiGayCpgoP4PaVkF2c/yWdHLXZUvbBmqIUqkjIe"});
</script>
</body>

</html>