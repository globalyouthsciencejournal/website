-- Global Youth Science Journal (GYSJ) core schema
--
-- This schema adds:
-- - users (login/signup + roles)
-- - paper_submissions (user uploads + admin review + publication)
--
-- Notes
-- - Default role is "user". To promote an account to admin:
--     UPDATE users SET role = 'admin' WHERE email = 'admin@example.com';
-- - Manuscripts are stored on disk (see /uploads/submissions) and referenced by path.

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL,
  username VARCHAR(64) NULL,
  phone VARCHAR(32) NULL,
  country VARCHAR(100) NULL,

  title VARCHAR(32) NULL,
  first_name VARCHAR(255) NULL,
  middle_name VARCHAR(255) NULL,
  last_name VARCHAR(255) NULL,

  position VARCHAR(100) NULL,
  institution VARCHAR(255) NULL,
  department VARCHAR(255) NULL,

  grade_level VARCHAR(100) NULL,
  school_name VARCHAR(255) NULL,
  school_email VARCHAR(255) NULL,
  admission_number VARCHAR(100) NULL,

  city VARCHAR(255) NULL,
  state VARCHAR(255) NULL,
  postal_code VARCHAR(32) NULL,
  reviewer_experience_text TEXT NULL,
  reviewer_reason_text TEXT NULL,
  reviewer_weekly_availability VARCHAR(32) NULL,
  reviewer_profile_links TEXT NULL,
  reviewer_cv_path VARCHAR(1024) NULL,
  reviewer_cv_original_name VARCHAR(255) NULL,
  reviewer_cv_mime VARCHAR(100) NULL,
  reviewer_cv_size INT UNSIGNED NULL,
  reviewer_supporting_documents_json TEXT NULL,
  reviewer_declaration_confirmed TINYINT(1) NULL DEFAULT 0,
  reset_token VARCHAR(255) NULL,
  reset_expires DATETIME NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('user','admin') NOT NULL DEFAULT 'user',
  assigned_journals_json TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_users_email (email),
  UNIQUE KEY uniq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS paper_submissions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  slug VARCHAR(255) NOT NULL,
  title VARCHAR(500) NOT NULL,
  authors TEXT NOT NULL,
  abstract TEXT NOT NULL,
  author_bio TEXT NULL,
  submission_details TEXT NULL,
  keywords VARCHAR(500) NULL,
  category VARCHAR(255) NULL,
  authors_json TEXT NULL,
  eissn VARCHAR(100) DEFAULT '2833-8022',
  accepted_at DATETIME NULL,
  revised_at DATETIME NULL,
  corresponding_author_email VARCHAR(255) NULL,
  doi VARCHAR(100) NULL UNIQUE,
  funding_info TEXT NULL,
  license_url VARCHAR(255) DEFAULT 'https://creativecommons.org/licenses/by/4.0/',

  volume_year INT UNSIGNED NULL,
  issue_number INT UNSIGNED NULL,

  tracking_id VARCHAR(64) NULL,
  tracking_country3 CHAR(3) NULL,
  tracking_year INT UNSIGNED NULL,
  tracking_seq INT UNSIGNED NULL,

  manuscript_path VARCHAR(1024) NOT NULL,
  manuscript_original_name VARCHAR(255) NOT NULL,
  manuscript_mime VARCHAR(100) NOT NULL,
  manuscript_size INT UNSIGNED NOT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  
  view_count INT UNSIGNED NOT NULL DEFAULT 0,
  download_count INT UNSIGNED NOT NULL DEFAULT 0,

  status ENUM('submitted','needs_edits','accepted','rejected') NOT NULL DEFAULT 'submitted',
  admin_comment TEXT NULL,
  assigned_admin_ids_json TEXT NULL,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  published_at DATETIME NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_paper_submissions_slug (slug),
  UNIQUE KEY uniq_paper_submissions_tracking_id (tracking_id),
  UNIQUE KEY uniq_paper_submissions_tracking_year_seq (tracking_year, tracking_seq),
  KEY idx_paper_submissions_user_id (user_id),
  KEY idx_paper_submissions_status (status),
  KEY idx_paper_submissions_volume_issue (volume_year, issue_number),
  CONSTRAINT fk_paper_submissions_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_paper_submissions_reviewed_by
    FOREIGN KEY (reviewed_by) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS paper_submission_versions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  paper_submission_id INT UNSIGNED NOT NULL,
  version_number INT UNSIGNED NOT NULL,
  title VARCHAR(500) NOT NULL,
  authors TEXT NOT NULL,
  abstract TEXT NOT NULL,
  author_bio TEXT NULL,
  submission_details TEXT NULL,
  keywords VARCHAR(500) NULL,
  category VARCHAR(255) NULL,
  authors_json TEXT NULL,
  eissn VARCHAR(100) DEFAULT '2833-8022',
  accepted_at DATETIME NULL,
  revised_at DATETIME NULL,
  corresponding_author_email VARCHAR(255) NULL,
  manuscript_path VARCHAR(1024) NOT NULL,
  manuscript_original_name VARCHAR(255) NOT NULL,
  manuscript_mime VARCHAR(100) NOT NULL,
  manuscript_size INT UNSIGNED NOT NULL,
  status ENUM('submitted','needs_edits','accepted','rejected') NOT NULL DEFAULT 'submitted',
  admin_comment TEXT NULL,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  published_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_paper_submission_versions_submission_id (paper_submission_id),
  KEY idx_paper_submission_versions_submission_version (paper_submission_id, version_number),
  CONSTRAINT fk_paper_submission_versions_submission
    FOREIGN KEY (paper_submission_id) REFERENCES paper_submissions(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_paper_submission_versions_reviewed_by
    FOREIGN KEY (reviewed_by) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS paper_submission_attachments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  paper_submission_id INT UNSIGNED NOT NULL,
  version_number INT UNSIGNED NOT NULL DEFAULT 1,
  category VARCHAR(255) NOT NULL DEFAULT 'Manuscript',
  description VARCHAR(500) NULL,
  file_path VARCHAR(1024) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  file_size INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_psa_submission (paper_submission_id, version_number),
  CONSTRAINT fk_psa_submission
    FOREIGN KEY (paper_submission_id) REFERENCES paper_submissions(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_applications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  assigned_journals_json TEXT NULL,
  country VARCHAR(100) NULL,
  institution VARCHAR(255) NULL,
  grade_level VARCHAR(100) NULL,
  reviewer_experience_text TEXT NULL,
  reviewer_reason_text TEXT NULL,
  reviewer_weekly_availability VARCHAR(32) NULL,
  reviewer_profile_links TEXT NULL,
  reviewer_cv_path VARCHAR(1024) NULL,
  reviewer_cv_original_name VARCHAR(255) NULL,
  reviewer_cv_mime VARCHAR(100) NULL,
  reviewer_cv_size INT UNSIGNED NULL,
  reviewer_supporting_documents_json TEXT NULL,
  reviewer_declaration_confirmed TINYINT(1) NULL DEFAULT 0,

  cv_path VARCHAR(1024) NOT NULL,
  cv_original_name VARCHAR(255) NOT NULL,
  cv_mime VARCHAR(100) NOT NULL,
  cv_size INT UNSIGNED NOT NULL,

  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_admin_applications_email (email),
  KEY idx_admin_applications_status (status),
  KEY idx_admin_applications_reviewed_by (reviewed_by),
  CONSTRAINT fk_admin_applications_reviewed_by
    FOREIGN KEY (reviewed_by) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
