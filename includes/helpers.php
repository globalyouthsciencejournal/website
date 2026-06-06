<?php
declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function gysj_submission_journal_options(): array
{
    return [
        'Computer Science & Engineering',
        'Mathematics & Mathematical Sciences',
        'Applied Physics',
        'Applied Chemistry',
        'Civil Engineering',
        'Mechanical Engineering',
        'Business, Management & Accounting',
        'Electronics & Communication Engineering',
        'Humanities & Social Science',
        'Advance Research (General)',
        'Biology & Pharmacy',
        'Environmental Science',
    ];
}

function gysj_normalize_journal_selection($value): array
{
    $allowed = array_fill_keys(gysj_submission_journal_options(), true);

    if (is_string($value)) {
        $value = trim($value);
        if ($value === '') {
            $value = [];
        } else {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = preg_split('/\s*,\s*/', $value) ?: [];
            }
        }
    }

    if (!is_array($value)) {
        return [];
    }

    $journals = [];
    foreach ($value as $item) {
        if (!is_string($item)) {
            continue;
        }

        $journal = trim($item);
        if ($journal === '' || !isset($allowed[$journal]) || in_array($journal, $journals, true)) {
            continue;
        }

        $journals[] = $journal;
    }

    return $journals;
}

function gysj_journal_selection_json(array $journals): ?string
{
    $journals = gysj_normalize_journal_selection($journals);
    if (empty($journals)) {
        return null;
    }

    $json = json_encode(array_values($journals), JSON_UNESCAPED_UNICODE);
    return is_string($json) ? $json : null;
}

function safe_redirect_target(?string $target, string $fallback): string
{
    $target = trim((string) $target);
    if ($target === '') {
        return $fallback;
    }

    // Disallow absolute URLs.
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $target) === 1) {
        return $fallback;
    }

    // Disallow protocol-relative URLs.
    if (substr($target, 0, 2) === '//') {
        return $fallback;
    }

    // Keep it local.
    if ($target[0] !== '/' && strpos($target, '.php') === false) {
        return $fallback;
    }

    return $target;
}

function is_https_request(): bool
{
    if (!isset($_SERVER['HTTPS'])) {
        return false;
    }

    $https = (string) $_SERVER['HTTPS'];
    return $https !== '' && strtolower($https) !== 'off';
}

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('Session not started; cannot use CSRF protection.');
    }

    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_validate(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $token = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (!is_string($token) || !is_string($sessionToken) || $token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
        http_response_code(400);
        echo 'Invalid CSRF token.';
        exit;
    }
}

function slugify(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    // Try to transliterate to ASCII when possible.
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if (is_string($converted) && $converted !== '') {
            $text = $converted;
        }
    }

    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/i', '-', $text) ?? $text;
    $text = trim($text, '-');

    return $text;
}

function gysj_normalize_tracking_country3(string $country3): string
{
    $country3 = strtoupper(trim($country3));
    if (preg_match('/^[A-Z]{3}$/', $country3) === 1) {
        return $country3;
    }

    return 'XXX';
}

function gysj_format_tracking_id(string $country3, int $year, int $seq): string
{
    if ($year <= 0 || $seq <= 0) {
        return '';
    }

    return 'GYSJ-' . gysj_normalize_tracking_country3($country3) . '-' . sprintf('%04d', $year) . '-' . sprintf('%04d', $seq);
}

function gysj_parse_tracking_id(string $trackingId): ?array
{
    $trackingId = strtoupper(trim($trackingId));
    if ($trackingId === '') {
        return null;
    }

    if (preg_match('/^GYSJ-([A-Z]{3})-(\d{4})-(\d+)$/', $trackingId, $matches) !== 1) {
        return null;
    }

    return [
        'country3' => $matches[1],
        'year' => (int) $matches[2],
        'seq' => (int) $matches[3],
    ];
}

function gysj_tracking_id_from_row(array $row): string
{
    $country3 = trim((string) ($row['tracking_country3'] ?? ''));
    $year = (int) ($row['tracking_year'] ?? 0);
    $seq = (int) ($row['tracking_seq'] ?? 0);

    $formatted = gysj_format_tracking_id($country3, $year, $seq);
    if ($formatted !== '') {
        return $formatted;
    }

    $trackingId = trim((string) ($row['tracking_id'] ?? ''));
    $parsed = gysj_parse_tracking_id($trackingId);
    if (!is_array($parsed)) {
        return '';
    }

    return gysj_format_tracking_id((string) $parsed['country3'], (int) $parsed['year'], (int) $parsed['seq']);
}

function gysj_country_to_country3(string $country): string
{
    $raw = strtoupper(trim($country));
    if ($raw === '') {
        return 'XXX';
    }

    if (preg_match('/^[A-Z]{3}$/', $raw) === 1) {
        return $raw;
    }

    $map = [
        'UNITED STATES' => 'USA',
        'UNITED STATES OF AMERICA' => 'USA',
        'US' => 'USA',
        'U.S.' => 'USA',
        'USA' => 'USA',
        'INDIA' => 'IND',
        'UNITED KINGDOM' => 'GBR',
        'UK' => 'GBR',
        'U.K.' => 'GBR',
        'GREAT BRITAIN' => 'GBR',
        'ENGLAND' => 'GBR',
        'SCOTLAND' => 'GBR',
        'WALES' => 'GBR',
        'CANADA' => 'CAN',
        'AUSTRALIA' => 'AUS',
        'NEW ZEALAND' => 'NZL',
        'BANGLADESH' => 'BGD',
        'NEPAL' => 'NPL',
        'PAKISTAN' => 'PAK',
        'SRI LANKA' => 'LKA',
        'GERMANY' => 'DEU',
        'FRANCE' => 'FRA',
        'ITALY' => 'ITA',
        'SPAIN' => 'ESP',
        'CHINA' => 'CHN',
        'JAPAN' => 'JPN',
        'SOUTH KOREA' => 'KOR',
        'REPUBLIC OF KOREA' => 'KOR',
        'BRAZIL' => 'BRA',
        'MEXICO' => 'MEX',
        'SOUTH AFRICA' => 'ZAF',
        'SINGAPORE' => 'SGP',
        'UNITED ARAB EMIRATES' => 'ARE',
        'UAE' => 'ARE',
        'SAUDI ARABIA' => 'SAU',
    ];

    if (isset($map[$raw])) {
        return $map[$raw];
    }

    $lettersOnly = preg_replace('/[^A-Z]/', '', $raw) ?? '';
    if ($lettersOnly === '') {
        return 'XXX';
    }

    return strtoupper(str_pad(substr($lettersOnly, 0, 3), 3, 'X'));
}

function gysj_next_tracking_seq(PDO $pdo, int $year): int
{
    try {
        $stmt = $pdo->query('SELECT tracking_id, tracking_year, tracking_seq FROM paper_submissions');
        $rows = $stmt ? $stmt->fetchAll() : [];

        $maxSeq = 0;
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $trackingYear = (int) ($row['tracking_year'] ?? 0);
                $trackingSeq = (int) ($row['tracking_seq'] ?? 0);
                if ($trackingYear === $year && $trackingSeq > $maxSeq) {
                    $maxSeq = $trackingSeq;
                    continue;
                }

                $parsed = gysj_parse_tracking_id((string) ($row['tracking_id'] ?? ''));
                if (is_array($parsed) && (int) ($parsed['year'] ?? 0) === $year) {
                    $parsedSeq = (int) ($parsed['seq'] ?? 0);
                    if ($parsedSeq > $maxSeq) {
                        $maxSeq = $parsedSeq;
                    }
                }
            }
        }

        return $maxSeq > 0 ? ($maxSeq + 1) : 1;
    } catch (Throwable $e) {
        return 1;
    }
}

function paper_submission_versions_table_columns(PDO $pdo): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $driver = '';
    try {
        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    } catch (Throwable $e) {
        $driver = '';
    }

    $cols = [];
    try {
        if ($driver === 'sqlite') {
            $rows = $pdo->query('PRAGMA table_info(paper_submission_versions)')->fetchAll();
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $name = strtolower((string) ($row['name'] ?? ''));
                    if ($name !== '') {
                        $cols[$name] = true;
                    }
                }
            }
        } else {
            $rows = $pdo->query('SHOW COLUMNS FROM paper_submission_versions')->fetchAll();
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $name = strtolower((string) ($row['Field'] ?? $row['field'] ?? ''));
                    if ($name !== '') {
                        $cols[$name] = true;
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $cols = [];
    }

    $cached = $cols;
    return $cols;
}

function gysj_table_columns(PDO $pdo, string $table): array
{
    static $cache = [];
    $table = trim($table);
    if ($table === '') {
        return [];
    }

    if (isset($cache[$table]) && is_array($cache[$table])) {
        return $cache[$table];
    }

    $driver = '';
    try {
        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    } catch (Throwable $e) {
        $driver = '';
    }

    $cols = [];
    try {
        if ($driver === 'sqlite') {
            $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
            $rows = $stmt ? $stmt->fetchAll() : [];
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $name = strtolower((string) ($row['name'] ?? ''));
                    if ($name !== '') {
                        $cols[$name] = true;
                    }
                }
            }
        } else {
            $stmt = $pdo->prepare('SHOW COLUMNS FROM ' . $table);
            $stmt->execute();
            $rows = $stmt->fetchAll();
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $name = strtolower((string) ($row['Field'] ?? $row['field'] ?? ''));
                    if ($name !== '') {
                        $cols[$name] = true;
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $cols = [];
    }

    $cache[$table] = $cols;
    return $cols;
}

function gysj_table_has_columns(PDO $pdo, string $table, array $required): bool
{
    $cols = gysj_table_columns($pdo, $table);
    foreach ($required as $column) {
        $column = strtolower((string) $column);
        if ($column === '' || !isset($cols[$column])) {
            return false;
        }
    }

    return true;
}

function paper_submission_versions_has_columns(PDO $pdo, array $required): bool
{
    $cols = paper_submission_versions_table_columns($pdo);
    foreach ($required as $column) {
        $column = strtolower((string) $column);
        if ($column === '' || !isset($cols[$column])) {
            return false;
        }
    }

    return true;
}

function paper_archive_submission_version(PDO $pdo, array $submission): void
{
    if (!paper_submission_versions_has_columns($pdo, ['paper_submission_id', 'version_number'])) {
        return;
    }

    $submissionId = (int) ($submission['id'] ?? 0);
    $versionNumber = (int) ($submission['version'] ?? 1);
    if ($submissionId <= 0 || $versionNumber <= 0) {
        return;
    }

    $fields = [
        'paper_submission_id' => $submissionId,
        'version_number' => $versionNumber,
        'title' => (string) ($submission['title'] ?? ''),
        'authors' => (string) ($submission['authors'] ?? ''),
        'abstract' => (string) ($submission['abstract'] ?? ''),
        'author_bio' => (string) ($submission['author_bio'] ?? ''),
        'submission_details' => (string) ($submission['submission_details'] ?? ''),
        'keywords' => (string) ($submission['keywords'] ?? ''),
        'category' => (string) ($submission['category'] ?? ''),
        'manuscript_path' => (string) ($submission['manuscript_path'] ?? ''),
        'manuscript_original_name' => (string) ($submission['manuscript_original_name'] ?? ''),
        'manuscript_mime' => (string) ($submission['manuscript_mime'] ?? ''),
        'manuscript_size' => (int) ($submission['manuscript_size'] ?? 0),
        'status' => (string) ($submission['status'] ?? ''),
        'admin_comment' => (string) ($submission['admin_comment'] ?? ''),
        'reviewed_by' => $submission['reviewed_by'] ?? null,
        'reviewed_at' => $submission['reviewed_at'] ?? null,
        'published_at' => $submission['published_at'] ?? null,
        'created_at' => $submission['created_at'] ?? null,
        'updated_at' => $submission['updated_at'] ?? null,
    ];

    $columns = array_keys($fields);
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $sql = 'INSERT INTO paper_submission_versions (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($fields));
    } catch (Throwable $e) {
        // If the version table is not migrated yet, keep the submission flow working.
    }
}
