<?php
declare(strict_types=1);

// CLI-only migration: adds the paper_submission_versions history table.
// Safe to run multiple times.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo 'Not Found';
    exit;
}

require_once __DIR__ . '/../includes/db.php';

try {
    $pdo = db();
} catch (Throwable $e) {
    fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

$driver = '';
try {
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
} catch (Throwable $e) {
    $driver = '';
}

$sql = $driver === 'sqlite' ? <<<SQL
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
SQL
 : <<<SQL
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
SQL;

try {
    $pdo->exec($sql);
    echo "OK: ensured paper_submission_versions table\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: failed to create paper_submission_versions table: " . $e->getMessage() . "\n");
    exit(1);
}