<?php ini_set('display_errors', 1); error_reporting(E_ALL); require_once __DIR__ . '/includes/bootstrap.php'; 

$topic = isset($_GET['topic']) ? $_GET['topic'] : '';

$topicTitle = 'Author Guidelines';
$topicContent = '';

switch ($topic) {
    case 'topics-we-accept':
        $topicTitle = 'Topics We Accept';
        $topicContent = '
<p style="font-size:1.08em;max-width:800px;">
The Global Youth Science Journal welcomes submissions across a broad range of STEM disciplines. Our goal is to provide a platform for young researchers to showcase their innovative work. Accepted fields of study include, but are not limited to:
</p>
<ul style="font-size:1.08em;max-width:800px;">
  <li><strong>Biology & Life Sciences:</strong> Cellular biology, genetics, microbiology, zoology, botany, ecology, and neuroscience.</li>
  <li><strong>Chemistry & Materials Science:</strong> Organic and inorganic chemistry, biochemistry, nanotechnology, and material engineering.</li>
  <li><strong>Physics & Astronomy:</strong> Classical and modern physics, astrophysics, optics, and thermodynamics.</li>
  <li><strong>Earth & Environmental Sciences:</strong> Geology, climate science, oceanography, and environmental conservation.</li>
  <li><strong>Computer Science & Mathematics:</strong> Algorithms, artificial intelligence, software engineering, pure and applied mathematics, and data science.</li>
  <li><strong>Engineering & Technology:</strong> Robotics, mechanical, civil, electrical, and aerospace engineering.</li>
  <li><strong>Medicine & Health Sciences:</strong> Public health, pharmacology, epidemiology, and biomedical sciences.</li>
</ul>
<p style="font-size:1.08em;max-width:800px;">If your research crosses disciplinary boundaries or falls into a specialized niche, we encourage you to submit your manuscript for consideration.</p>
        ';
        break;

    case 'vertebrate-animal-and-human-subject-research':
        $topicTitle = 'Vertebrate Animal and Human Subject Research';
        $topicContent = '
<p style="font-size:1.08em;max-width:800px;">
Research involving vertebrate animals or human subjects must adhere to strict ethical standards. 
</p>
<ul style="font-size:1.08em;max-width:800px;">
  <li><strong>Human Subjects:</strong> Any study involving human participants, including surveys and behavioral studies, must obtain informed consent. Institutional Review Board (IRB) or equivalent ethics committee approval is recommended, not required.</li>
  <li><strong>Vertebrate Animals:</strong> Studies involving non-human vertebrates must follow humane treatment guidelines. Procedures should minimize pain and distress. Institutional or local ethics committee approval (e.g., IACUC) is recommended, not required.</li>
</ul>
<p style="font-size:1.08em;max-width:800px;">The editorial board reserves the right to reject submissions that do not meet these ethical research standards.</p>
        ';
        break;

    case 'academic-honesty-and-ai':
        $topicTitle = 'Academic Honesty and AI';
        $topicContent = '
<p style="font-size:1.08em;max-width:800px;">Authors are expected to uphold the highest standards of academic integrity throughout the research and writing process.</p>
<ul style="font-size:1.08em;max-width:800px;">
  <li><strong>Artificial Intelligence:</strong> AI tools (such as ChatGPT or similar language models) should not be used to generate manuscript content or synthesize original data. However, they may be utilized to support understanding of complex literature or assist in copyediting and language translation.</li>
  <li><strong>Transparency:</strong> If AI tools were used in the preparation of the manuscript, authors should disclose this use transparently in the Acknowledgments section, specifying the tool and its application.</li>
  <li><strong>Accountability:</strong> The listed authors assume full responsibility for the accuracy, validity, and originality of all content presented in the manuscript. If any concerns arise regarding the use of AI, the editorial team will work closely with authors to resolve them.</li>
</ul>
        ';
        break;

    case 'avoiding-plagiarism':
        $topicTitle = 'Avoiding Plagiarism';
        $topicContent = '
<p style="font-size:1.08em;max-width:800px;">Plagiarism is a serious academic offense and is strictly prohibited at the Global Youth Science Journal.</p>
<ul style="font-size:1.08em;max-width:800px;">
  <li><strong>Originality:</strong> All submitted work must be entirely original. Any ideas, data, or text sourced from other works must be appropriately attributed.</li>
  <li><strong>Paraphrasing:</strong> All external material should be properly paraphrased in the author\'s own words. Direct quotations are generally not permitted unless absolutely necessary for context (e.g., historical documents), in which case quotation marks and proper citations must be used.</li>
  <li><strong>Self-Plagiarism:</strong> Re-submitting the same research that has already been published elsewhere is not allowed.</li>
  <li><strong>Plagiarism Checks:</strong> Our editorial team utilizes plagiarism detection software. If significant issues or overlapping text are identified, authors will be notified and asked to revise accordingly, or the manuscript may be rejected.</li>
</ul>
        ';
        break;

    case 'manuscript-format-content':
        $topicTitle = 'Manuscript Format & Content';
        $topicContent = '
<p style="font-size:1.08em;max-width:800px;">
General formatting (Arial, 11pt, 1.5 spacing, standard margins, etc.) is recommended for consistency, but not strictly required at initial submission. Line numbers are helpful for peer review and may be added later if missing.
</p>
<p style="font-size:1.08em;max-width:800px;">We recommend organizing manuscripts using the following structure:</p>
<ol style="font-size:1.08em;max-width:800px;">
  <li><strong>Title Page:</strong> Overview, Authors, Keywords (3-5).</li>
  <li><strong>Abstract:</strong> A concise summary (usually a single paragraph) of the problem, methodology, key results, and conclusions without citations.</li>
  <li><strong>Introduction:</strong> Background context, existing work, gaps in research, and the study\'s objectives.</li>
  <li><strong>Materials and Methods:</strong> Sufficient detail for replication, without step-by-step lists. Unique reagents should be specified.</li>
  <li><strong>Results:</strong> Clear presentation of the findings.</li>
  <li><strong>Discussion:</strong> Interpretation of results, limitations, and future directions.</li>
  <li><strong>Acknowledgments (Optional):</strong> Recognizing non-author contributors and funding sources.</li>
  <li><strong>References:</strong> Chicago style citations.</li>
  <li><strong>Figures & Tables:</strong> Embedded or appended logically.</li>
</ol>
<p style="font-size:1.08em;max-width:800px;">
The manuscript body (Introduction through Materials & Methods) should ideally not exceed 10 pages. Submissions prepared outside the official template are welcome, and formatting adjustments can be made during the editorial process.
</p>
        ';
        break;

    case 'figure-table-formatting':
        $topicTitle = 'Figure/Table Formatting';
        $topicContent = '
<p style="font-size:1.08em;max-width:800px;">Visual aids are crucial for scientific communication. We recommend a maximum of 8 figures and tables combined.</p>
<h4>Figures and Captions</h4>
<p style="font-size:1.08em;max-width:800px;">
Place figures with a bold title and regular-weight caption positioned below the figure. A complete caption includes: a bold title; a description of what is shown; a brief methodological note; statistical tests and values where applicable; and the number of replicates. Within caption text, only the first word, proper nouns, and acronyms should be capitalized.
</p>
<h4>Tables and Captions</h4>
<p style="font-size:1.08em;max-width:800px;">
Tables should appear below their title and caption. Captions must define any abbreviations used within the table. Tables must be created as editable tables (e.g., Word \'Insert Table\') and should fit within a single page where possible. Wide or long tables may be moved to the Appendix.
</p>
<p style="font-size:1.08em;max-width:800px;">Supplementary figures or tables should be included in the Appendix only where essential to comprehension, accompanied by a statement justifying their inclusion.</p>
        ';
        break;

    case 'reference-formatting':
        $topicTitle = 'Reference Formatting';
        $topicContent = '
<p style="font-size:1.08em;max-width:800px;">References must follow Chicago style. Citation tools such as Zotero or Mendeley support this format. Primary peer-reviewed sources should be prioritized; encyclopedias and informal web content should be avoided.</p>
<p style="font-size:1.08em;max-width:800px;">
Citations should appear as footnotes or endnotes where the source is first cited, and a complete reference list should be included at the end. References with three or more authors should use "First Author et al." All references with an online source should include the full URL or DOI beginning with "https://".
</p>
<h4>Format Examples:</h4>
<ul style="font-size:1.08em;max-width:800px;">
  <li><strong>Journal article:</strong> Last Name, First Name. "Article Title." Journal Name X, no. X (Year): XX–XX. https://doi.org/...</li>
  <li><strong>Website:</strong> Last Name, First Name (if available). "Page Title." Website Name. Accessed Day Month Year. https://...</li>
  <li><strong>Book:</strong> Last Name, First Name. <em>Book Title</em>. Xth ed. City: Publisher, Year.</li>
</ul>
        ';
        break;

    case 'common-mistakes':
        $topicTitle = 'Common Mistakes';
        $topicContent = '
<p style="font-size:1.08em;max-width:800px;">To ensure a smooth review process, try to avoid these frequently observed errors:</p>
<ul style="font-size:1.08em;max-width:800px;">
  <li><strong>Incomplete Abstract:</strong> The abstract should be a standalone summary. Avoid referencing figures, tables, or citations within the abstract.</li>
  <li><strong>Methodology as a Recipe:</strong> The Materials & Methods section should be written in a narrative paragraph format using past tense and passive voice, rather than a bulleted step-by-step list.</li>
  <li><strong>Overreliance on Direct Quotes:</strong> Scientific writing prioritizes paraphrasing and synthesis. Avoid direct quotations unless analyzing specific historical texts.</li>
  <li><strong>Poor Quality Figures:</strong> Ensure all charts, graphs, and images are high-resolution and legible. Axes must be clearly labeled with units.</li>
  <li><strong>Missing Control Groups:</strong> For experimental studies, ensure that appropriate control groups are clearly defined and analyzed.</li>
  <li><strong>Informal Language:</strong> Maintain an objective, academic tone. Avoid colloquialisms, contractions, and first-person pronouns (I, we) where possible.</li>
</ul>
        ';
        break;

    case 'submission-checklists':
        $topicTitle = 'Submission Checklists';
        $topicContent = '
<p style="font-size:1.08em;max-width:800px;">Before submitting your manuscript, please verify the following:</p>
<ul style="font-size:1.08em;max-width:800px;">
  <li><strong>Author Eligibility:</strong> All authors have significantly contributed to the research.</li>
  <li><strong>Consent:</strong> All co-authors have reviewed the final draft and consented to its submission.</li>
  <li><strong>Originality:</strong> The manuscript is original, has not been published previously, and is not currently under review at another journal.</li>
  <li><strong>Formatting:</strong> The manuscript generally follows our structural guidelines (Title Page, Abstract, Intro, Methods, Results, Discussion, References).</li>
  <li><strong>Citations:</strong> All external sources are properly cited in Chicago style.</li>
  <li><strong>File Format:</strong> The document is saved in a compatible format (preferably .docx or .pdf for initial review).</li>
  <li><strong>Anonymization (Optional):</strong> If requested, ensure your manuscript has been anonymized for double-blind peer review.</li>
</ul>
<p style="font-size:1.08em;max-width:800px;">If anything is missing, our editorial team will follow up during the initial screening process.</p>
        ';
        break;

    case 'review-process':
        $topicTitle = 'Review Process';
        $topicContent = '
<p style="font-size:1.08em;max-width:800px;">Our review process is designed to be educational, constructive, and rigorous.</p>
<ol style="font-size:1.08em;max-width:800px;">
  <li><strong>Initial Screening:</strong> Upon submission, the editorial team reviews the manuscript to ensure it meets basic scope, formatting, and academic integrity guidelines.</li>
  <li><strong>Peer Review:</strong> Manuscripts that pass the initial screening are assigned to our peer reviewers. Reviewers evaluate the methodology, scientific validity, clarity, and significance of the work.</li>
  <li><strong>Editorial Decision:</strong> Based on reviewer feedback, the editorial board will issue a decision: <em>Accept, Minor Revisions, Major Revisions, or Reject</em>.</li>
  <li><strong>Revisions:</strong> Authors are provided with detailed feedback and are encouraged to revise and resubmit their work within a specified timeframe.</li>
  <li><strong>Final Proofing:</strong> Accepted manuscripts undergo final copyediting and formatting before publication on our website.</li>
</ol>
<p style="font-size:1.08em;max-width:800px;">The entire process typically takes 4 to 8 weeks. Authors can track their submission status via their user dashboard.</p>
        ';
        break;

    case 'permissions-licensing':
        $topicTitle = 'Permissions & Licensing';
        $topicContent = '
<p style="font-size:1.08em;max-width:800px;">The Global Youth Science Journal is committed to open-access research.</p>
<ul style="font-size:1.08em;max-width:800px;">
  <li><strong>Author Copyright:</strong> Authors retain the copyright to their submitted and published work.</li>
  <li><strong>Creative Commons:</strong> All published articles are distributed under the Creative Commons Attribution 4.0 International License (CC BY 4.0). This allows others to freely read, download, copy, distribute, print, search, or link to the full texts of the articles, provided the original authors and source are properly cited.</li>
  <li><strong>Third-Party Material:</strong> If your manuscript includes figures, tables, or extensive text from previously published sources, you must obtain and provide proof of permission from the original copyright holder to reproduce them under our open-access license.</li>
  <li><strong>Open Data:</strong> We strongly encourage authors to make their raw data and code publicly available in repositories (e.g., GitHub, Zenodo) to foster transparency and reproducibility.</li>
</ul>
        ';
        break;

    default:
        header("Location: authorguidelines.php");
        exit;
}
?>
<!DOCTYPE html>
<html lang="en" class="no-js">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <link rel="shortcut icon" type="image/jpg" href="images/iysjournal.png">
  <title><?php echo htmlspecialchars($topicTitle); ?> | Global Youth Science Journal</title>
  <link href="css/media_query.css" rel="stylesheet" type="text/css">
  <link href="css/style.css" rel="stylesheet" type="text/css">
  <link href="css/bootstrap.css" rel="stylesheet" type="text/css">
  <link href="css/font-awesome.min.css" rel="stylesheet" crossorigin="anonymous">
  <link href="css/animate.css" rel="stylesheet" type="text/css">
  <link href="https://fonts.googleapis.com/css?family=Poppins" rel="stylesheet">
  <link href="css/style_1.css" rel="stylesheet" type="text/css">
  <script src="js/modernizr-3.5.0.min.js"></script>
  <style>
    .gysj-guidelines {
      max-width: 920px;
      margin: 0 auto;
      text-align: left;
      padding: 2rem;
      background: #fff;
      margin-top: 2rem;
      margin-bottom: 2rem;
    }
    .gysj-guidelines h2 {
      margin-bottom: 1.5rem;
      color: #333;
      font-weight: 600;
      border-bottom: 2px solid #007bff;
      padding-bottom: 0.5rem;
      display: inline-block;
    }
    .gysj-guidelines h4 {
      margin-top: 1.5rem;
      margin-bottom: 0.5rem;
      color: #444;
    }
    .gysj-guidelines p, .gysj-guidelines li {
      line-height: 1.75;
      color: #555;
    }
    .gysj-guidelines ul, .gysj-guidelines ol {
      margin-bottom: 1.5rem;
    }
    .gysj-guidelines li {
      margin-bottom: 0.5rem;
    }
    .back-btn-container {
      margin-top: 3rem;
      border-top: 1px solid #eee;
      padding-top: 1.5rem;
    }
  </style>
  <script src="js/main.js" defer></script>
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
        <div class="col-md-10 mx-auto">
          <div class="gysj-guidelines">
             <h2><?php echo htmlspecialchars($topicTitle); ?></h2>
             <?php echo $topicContent; ?>
             
             <div class="back-btn-container">
                 <a href="authorguidelines.php" class="btn btn-outline-primary"><i class="fa fa-arrow-left"></i> Back to Author Guidelines</a>
             </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="gototop js-top">
  <a href="#" class="js-gotop"><i class="fa fa-arrow-up"></i></a>
</div>
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
      <div class="col-12 col-md-8 Reserved footer_sub_about"><a href="copyright.php">Â© Copyright 2026</a>, Licensed under <a
          href="https://creativecommons.org/licenses/by/4.0/" target="_blank" rel="noopener">Creative Commons Attribution 4.0 International
          License.</a></div>
      <div class="col-12 col-md-4 spdp_right">

      </div>
    </div>
  </div>
</footer>
<script src="js/jquery.min.js"></script>
<script src="js/owl.carousel.min.js"></script>
<script src="js/tether.min.js" crossorigin="anonymous"></script>
<script src="js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="js/jquery.waypoints.min.js"></script>
</body>
</html>
</body>
</html>
