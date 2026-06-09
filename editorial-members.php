<?php
require_once __DIR__ . '/includes/bootstrap.php';
$pdo = db();

// Fetch admins
$stmt = $pdo->prepare("SELECT name, country, admin_role, institution FROM users WHERE role = 'admin' ORDER BY name ASC");
$stmt->execute();
$members = $stmt->fetchAll();

function formatRole($role) {
    if (!$role) return 'Editor';
    return ucwords(str_replace('_', ' ', $role));
}
?>
<!DOCTYPE html>
<html lang="en" class="no-js">

<head>
  <!-- Required meta tags -->
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <link rel="shortcut icon" type="image/jpg" href="images/iysjournal.png">
  <title>Editorial Members | Global Youth Science Journal</title>
  <meta name="description"
    content="Meet the editorial board members of the Global Youth Science Journal. Our expert editors ensure high-quality, peer-reviewed research for young scientists.">
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
  <!-- Google tag (gtag.js) -->
  <script async="" src="https://www.googletagmanager.com/gtag/js?id=G-P6B5PL5DMH"></script>
  <script> window.dataLayer = window.dataLayer || []; function gtag() { dataLayer.push(arguments); } gtag('js', new Date()); gtag('config', 'G-P6B5PL5DMH'); </script>
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


  <style>
    .announcement-bar {
      width: 100%;
      background: #007bff;
      color: #fff;
      text-align: center;
      font-size: 0.95em;
      padding: 4px 0 2px 0;
      letter-spacing: 0.5px;
      font-family: 'Poppins', Arial, sans-serif;
      z-index: 1050;
    }
    
    .members-search-wrap {
        position: relative;
        max-width: 600px;
        margin: 0 auto 40px;
    }
    
    .members-search-wrap input {
        width: 100%;
        padding: 12px 20px 12px 45px;
        border-radius: 30px;
        border: 1px solid #ddd;
        font-size: 16px;
        outline: none;
        box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        transition: all 0.3s ease;
    }
    
    .members-search-wrap input:focus {
        border-color: #f0b429;
        box-shadow: 0 4px 12px rgba(240,180,41,0.2);
    }
    
    .members-search-wrap i {
        position: absolute;
        left: 18px;
        top: 50%;
        transform: translateY(-50%);
        color: #999;
        font-size: 16px;
    }

    .members-container {
        max-height: 800px;
        overflow-y: auto;
        padding: 10px;
        scrollbar-width: thin;
        scrollbar-color: #f0b429 #f1f1f1;
    }
    
    .members-container::-webkit-scrollbar {
        width: 8px;
    }
    
    .members-container::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 4px;
    }
    
    .members-container::-webkit-scrollbar-thumb {
        background: #f0b429;
        border-radius: 4px;
    }

    .member-card {
        background: #fff;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        border: 1px solid #f0f0f0;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        display: flex;
        flex-direction: column;
        height: 100%;
    }
    
    .member-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 15px rgba(0,0,0,0.1);
        border-color: #e5e7eb;
    }

    .member-name {
        font-size: 18px;
        font-weight: 600;
        color: #333;
        margin-bottom: 5px;
    }

    .member-role {
        font-size: 14px;
        color: #f0b429;
        font-weight: 500;
        margin-bottom: 12px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .member-detail {
        font-size: 14px;
        color: #666;
        margin-bottom: 5px;
        display: flex;
        align-items: center;
    }
    
    .member-detail i {
        width: 20px;
        color: #999;
    }
    
    .no-results {
        text-align: center;
        padding: 40px;
        color: #666;
        font-style: italic;
        display: none;
    }
  </style>

  <div class="container-fluid pb-4 pt-4 paddding">
    <div class="container paddding">

      <div class="section-header mt-5 text-center mb-5">
        <h2>Editorial Board Members</h2>
        <p class="text-muted mt-2">Meet the dedicated team of editors driving the Global Youth Science Journal.</p>
      </div>

      <div class="row">
        <div class="col-lg-10 mx-auto">
            
            <div class="members-search-wrap">
                <i class="fa fa-search"></i>
                <input type="text" id="memberSearch" placeholder="Search by name, country, or affiliation...">
            </div>

            <div class="members-container">
                <div class="row" id="membersGrid">
                    <?php if (count($members) > 0): ?>
                        <?php foreach ($members as $member): ?>
                            <div class="col-md-6 col-lg-4 member-item">
                                <div class="member-card">
                                    <div class="member-name"><?php echo e($member['name']); ?></div>
                                    <div class="member-role"><?php echo e(formatRole($member['admin_role'])); ?></div>
                                    
                                    <?php if (!empty($member['institution'])): ?>
                                    <div class="member-detail">
                                        <i class="fa fa-university"></i>
                                        <span class="member-institution"><?php echo e($member['institution']); ?></span>
                                    </div>
                                    <?php else: ?>
                                    <div class="member-detail">
                                        <i class="fa fa-university"></i>
                                        <span class="member-institution">Independent</span>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($member['country'])): ?>
                                    <div class="member-detail">
                                        <i class="fa fa-globe"></i>
                                        <span class="member-country"><?php echo e($member['country']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="col-12 text-center py-5">
                            <p class="text-muted">No editorial members found.</p>
                        </div>
                    <?php endif; ?>
                </div>
                <div id="noResults" class="no-results">No members match your search criteria.</div>
            </div>

        </div>
      </div>

    </div>
  </div>
  
  <script>
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('memberSearch');
        const memberItems = document.querySelectorAll('.member-item');
        const noResults = document.getElementById('noResults');
        
        searchInput.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            let visibleCount = 0;
            
            memberItems.forEach(function(item) {
                const name = item.querySelector('.member-name').textContent.toLowerCase();
                const institutionElement = item.querySelector('.member-institution');
                const institution = institutionElement ? institutionElement.textContent.toLowerCase() : '';
                const countryElement = item.querySelector('.member-country');
                const country = countryElement ? countryElement.textContent.toLowerCase() : '';
                const role = item.querySelector('.member-role').textContent.toLowerCase();
                
                if (name.includes(searchTerm) || 
                    institution.includes(searchTerm) || 
                    country.includes(searchTerm) ||
                    role.includes(searchTerm)) {
                    item.style.display = 'block';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                }
            });
            
            if (visibleCount === 0 && memberItems.length > 0) {
                noResults.style.display = 'block';
            } else {
                noResults.style.display = 'none';
            }
        });
    });
  </script>

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
        
        </div>
      </div>
    </div>

    <div class="container pt-3">
      <div class="row align-items-center">
        <div class="col-12 col-md-8 Reserved footer_sub_about">
          <a href="copyright.php">© Copyright 2026</a>, Licensed under
          <a href="https://creativecommons.org/licenses/by/4.0/" target="_blank" rel="noopener">Creative Commons Attribution 4.0 International License.</a>
        </div>
       
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
</body>

</html>
