-- SQLite schema for local/dev usage
--
-- This mirrors sql/schema.sql but uses SQLite-compatible types.

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  email TEXT NOT NULL,
  username TEXT NULL,
  phone TEXT NULL,
  country TEXT NULL,

  title TEXT NULL,
  first_name TEXT NULL,
  middle_name TEXT NULL,
  last_name TEXT NULL,

  position TEXT NULL,
  institution TEXT NULL,
  department TEXT NULL,

  grade_level TEXT NULL,
  school_name TEXT NULL,
  school_email TEXT NULL,
  admission_number TEXT NULL,

  city TEXT NULL,
  state TEXT NULL,
  postal_code TEXT NULL,
  reviewer_experience_text TEXT NULL,
  reviewer_reason_text TEXT NULL,
  reviewer_weekly_availability TEXT NULL,
  reviewer_profile_links TEXT NULL,
  reviewer_cv_path TEXT NULL,
  reviewer_cv_original_name TEXT NULL,
  reviewer_cv_mime TEXT NULL,
  reviewer_cv_size INTEGER NULL,
  reviewer_supporting_documents_json TEXT NULL,
  reviewer_declaration_confirmed INTEGER NULL DEFAULT 0,
  reset_token TEXT NULL,
  reset_expires TEXT NULL,
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'user' CHECK (role IN ('user','admin')),
  assigned_journals_json TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS uniq_users_username ON users(username);

CREATE TABLE IF NOT EXISTS paper_submissions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  slug TEXT NOT NULL,
  title TEXT NOT NULL,
  authors TEXT NOT NULL,
  abstract TEXT NOT NULL,
  author_bio TEXT NULL,
  submission_details TEXT NULL,
  keywords TEXT NULL,
  category TEXT NULL,
  authors_json TEXT NULL,
  eissn TEXT DEFAULT '2833-8022',
  accepted_at TEXT NULL,
  revised_at TEXT NULL,
  corresponding_author_email TEXT NULL,

  volume_year INTEGER NULL,
  issue_number INTEGER NULL,

  tracking_id TEXT NULL,
  tracking_country3 TEXT NULL,
  tracking_year INTEGER NULL,
  tracking_seq INTEGER NULL,

  manuscript_path TEXT NOT NULL,
  manuscript_original_name TEXT NOT NULL,
  manuscript_mime TEXT NOT NULL,
  manuscript_size INTEGER NOT NULL,
  version INTEGER NOT NULL DEFAULT 1,
  
  view_count INTEGER NOT NULL DEFAULT 0,
  download_count INTEGER NOT NULL DEFAULT 0,

  status TEXT NOT NULL DEFAULT 'submitted' CHECK (status IN ('submitted','needs_edits','accepted','rejected')),
  admin_comment TEXT NULL,
  assigned_admin_ids_json TEXT NULL,
  reviewed_by INTEGER NULL,
  reviewed_at TEXT NULL,
  published_at TEXT NULL,

  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE (slug),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS paper_submission_versions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  paper_submission_id INTEGER NOT NULL,
  version_number INTEGER NOT NULL,
  title TEXT NOT NULL,
  authors TEXT NOT NULL,
  abstract TEXT NOT NULL,
  author_bio TEXT NULL,
  submission_details TEXT NULL,
  keywords TEXT NULL,
  category TEXT NULL,
  authors_json TEXT NULL,
  eissn TEXT DEFAULT '2833-8022',
  accepted_at TEXT NULL,
  revised_at TEXT NULL,
  corresponding_author_email TEXT NULL,
  manuscript_path TEXT NOT NULL,
  manuscript_original_name TEXT NOT NULL,
  manuscript_mime TEXT NOT NULL,
  manuscript_size INTEGER NOT NULL,
  status TEXT NOT NULL DEFAULT 'submitted' CHECK (status IN ('submitted','needs_edits','accepted','rejected')),
  admin_comment TEXT NULL,
  reviewed_by INTEGER NULL,
  reviewed_at TEXT NULL,
  published_at TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  archived_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (paper_submission_id) REFERENCES paper_submissions(id) ON DELETE CASCADE,
  FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_paper_submission_versions_submission_id ON paper_submission_versions(paper_submission_id);
CREATE INDEX IF NOT EXISTS idx_paper_submission_versions_submission_version ON paper_submission_versions(paper_submission_id, version_number);

CREATE UNIQUE INDEX IF NOT EXISTS uniq_paper_submissions_tracking_id ON paper_submissions(tracking_id);
CREATE UNIQUE INDEX IF NOT EXISTS uniq_paper_submissions_tracking_year_seq ON paper_submissions(tracking_year, tracking_seq);
CREATE INDEX IF NOT EXISTS idx_paper_submissions_volume_issue ON paper_submissions(volume_year, issue_number);

CREATE INDEX IF NOT EXISTS idx_paper_submissions_user_id ON paper_submissions(user_id);
CREATE INDEX IF NOT EXISTS idx_paper_submissions_status ON paper_submissions(status);

CREATE TABLE IF NOT EXISTS paper_submission_attachments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  paper_submission_id INTEGER NOT NULL,
  version_number INTEGER NOT NULL DEFAULT 1,
  category TEXT NOT NULL DEFAULT 'Manuscript',
  description TEXT,
  file_path TEXT NOT NULL,
  original_name TEXT NOT NULL,
  mime_type TEXT NOT NULL,
  file_size INTEGER NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (paper_submission_id) REFERENCES paper_submissions(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_psa_submission ON paper_submission_attachments(paper_submission_id, version_number);

CREATE TABLE IF NOT EXISTS admin_applications (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  email TEXT NOT NULL,
  password_hash TEXT NOT NULL,
  assigned_journals_json TEXT NULL,
  country TEXT NULL,
  institution TEXT NULL,
  grade_level TEXT NULL,
  reviewer_experience_text TEXT NULL,
  reviewer_reason_text TEXT NULL,
  reviewer_weekly_availability TEXT NULL,
  reviewer_profile_links TEXT NULL,
  reviewer_cv_path TEXT NULL,
  reviewer_cv_original_name TEXT NULL,
  reviewer_cv_mime TEXT NULL,
  reviewer_cv_size INTEGER NULL,
  reviewer_supporting_documents_json TEXT NULL,
  reviewer_declaration_confirmed INTEGER NULL DEFAULT 0,

  cv_path TEXT NOT NULL,
  cv_original_name TEXT NOT NULL,
  cv_mime TEXT NOT NULL,
  cv_size INTEGER NOT NULL,

  status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected')),
  reviewed_by INTEGER NULL,
  reviewed_at TEXT NULL,

  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_admin_applications_email ON admin_applications(email);
CREATE INDEX IF NOT EXISTS idx_admin_applications_status ON admin_applications(status);
