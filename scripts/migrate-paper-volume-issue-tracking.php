<?php
declare(strict_types=1);

// CLI-only migration: adds volume/issue + tracking fields to the `paper_submissions` table.
// Safe to run multiple times: duplicate-column/index errors are ignored.
// Also backfills volume_year/issue_number/tracking_id for existing papers when possible.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo 'Not Found';
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

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

$isSqlite = ($driver === 'sqlite');

$columns = [
    'volume_year' => [
        'mysql' => 'INT UNSIGNED NULL',
        'sqlite' => 'INTEGER NULL',
    ],
    'issue_number' => [
        'mysql' => 'INT UNSIGNED NULL',
        'sqlite' => 'INTEGER NULL',
    ],
    'tracking_id' => [
        'mysql' => 'VARCHAR(64) NULL',
        'sqlite' => 'TEXT NULL',
    ],
    'tracking_country3' => [
        'mysql' => 'CHAR(3) NULL',
        'sqlite' => 'TEXT NULL',
    ],
    'tracking_year' => [
        'mysql' => 'INT UNSIGNED NULL',
        'sqlite' => 'INTEGER NULL',
    ],
    'tracking_seq' => [
        'mysql' => 'INT UNSIGNED NULL',
        'sqlite' => 'INTEGER NULL',
    ],
];

$added = 0;
foreach ($columns as $columnName => $types) {
    $type = $isSqlite ? $types['sqlite'] : $types['mysql'];
    $sql = 'ALTER TABLE paper_submissions ADD COLUMN ' . $columnName . ' ' . $type;

    try {
        $pdo->exec($sql);
        $added++;
        echo "OK: added column {$columnName}\n";
    } catch (Throwable $e) {
        $msg = strtolower((string) $e->getMessage());
        $code = strtolower((string) $e->getCode());

        $duplicateColumn = (strpos($msg, 'duplicate column') !== false)
            || (strpos($msg, 'duplicate column name') !== false)
            || (strpos($msg, 'already exists') !== false && strpos($msg, 'column') !== false);

        $missingTable = ($code === '42s02')
            || (strpos($msg, "doesn't exist") !== false)
            || (strpos($msg, 'no such table') !== false);

        if ($duplicateColumn) {
            echo "SKIP: column {$columnName} already exists\n";
            continue;
        }

        if ($missingTable) {
            fwrite(STDERR, "ERROR: paper_submissions table not found. Run: php scripts/init-db.php\n");
            exit(1);
        }

        fwrite(STDERR, "ERROR: failed to add column {$columnName}: " . $e->getMessage() . "\n");
        exit(1);
    }
}

function gysj_pick_effective_published_at(array $row): string
{
    $publishedAt = trim((string) ($row['published_at'] ?? ''));
    if ($publishedAt !== '') {
        return $publishedAt;
    }

    $reviewedAt = trim((string) ($row['reviewed_at'] ?? ''));
    if ($reviewedAt !== '') {
        return $reviewedAt;
    }

    return trim((string) ($row['created_at'] ?? ''));
}

function gysj_next_available_seq(array $used): int
{
    $i = 1;
    while (isset($used[$i])) {
        $i++;
        if ($i > 1000000) {
            return $i;
        }
    }
    return $i;
}

// Fetch papers for backfill.
$acceptedRows = [];
try {
    $stmt = $pdo->query("SELECT id, user_id, slug, published_at, reviewed_at, created_at, volume_year, issue_number, tracking_id, tracking_country3, tracking_year, tracking_seq FROM paper_submissions ORDER BY id ASC");
    $rows = $stmt->fetchAll();
    if (is_array($rows)) {
        $acceptedRows = $rows;
    }
} catch (Throwable $e) {
    $msg = strtolower((string) $e->getMessage());
    $code = strtolower((string) $e->getCode());
    $missingTable = ($code === '42s02')
        || (strpos($msg, "doesn't exist") !== false)
        || (strpos($msg, 'no such table') !== false);

    if ($missingTable) {
        fwrite(STDERR, "ERROR: paper_submissions table not found. Run: php scripts/init-db.php\n");
        exit(1);
    }

    fwrite(STDERR, "ERROR: failed to query accepted papers: " . $e->getMessage() . "\n");
    exit(1);
}

if (count($acceptedRows) === 0) {
    echo "OK: no accepted papers found to backfill\n";
} else {
    // Build year->months (unique) and group papers by year.
    $yearMonths = [];
    $papersByYear = [];
    $userIds = [];

    foreach ($acceptedRows as $row) {
        $effective = gysj_pick_effective_published_at($row);
        $ts = $effective !== '' ? strtotime($effective) : false;
        if ($ts === false) {
            continue;
        }

        $year = (int) date('Y', $ts);
        $month = (int) date('n', $ts);
        if ($year <= 0 || $month < 1 || $month > 12) {
            continue;
        }

        if (!isset($yearMonths[$year])) {
            $yearMonths[$year] = [];
        }
        $yearMonths[$year][$month] = true;

        if (!isset($papersByYear[$year])) {
            $papersByYear[$year] = [];
        }
        $papersByYear[$year][] = [
            'row' => $row,
            'ts' => (int) $ts,
        ];

        $uid = (int) ($row['user_id'] ?? 0);
        if ($uid > 0) {
            $userIds[$uid] = true;
        }
    }

    // Compute month->issue mapping for each year.
    $monthToIssue = [];
    foreach ($yearMonths as $year => $monthsSet) {
        $months = array_keys($monthsSet);
        sort($months);
        foreach ($months as $idx => $m) {
            $monthToIssue[$year][(int) $m] = $idx + 1;
        }
    }

    // Load submitter countries (best-effort).
    $countryByUserId = [];
    if (count($userIds) > 0) {
        $ids = array_keys($userIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        try {
            $stmt = $pdo->prepare('SELECT id, country FROM users WHERE id IN (' . $placeholders . ')');
            $stmt->execute($ids);
            $urows = $stmt->fetchAll();
            if (is_array($urows)) {
                foreach ($urows as $u) {
                    $uid = (int) ($u['id'] ?? 0);
                    if ($uid > 0) {
                        $countryByUserId[$uid] = (string) ($u['country'] ?? '');
                    }
                }
            }
        } catch (Throwable $e) {
            // If the `country` column doesn't exist yet, fall back to XXX.
            $countryByUserId = [];
        }
    }

    // Backfill volume_year/issue_number.
    $volIssueUpdated = 0;
    $updateVolIssue = $pdo->prepare('UPDATE paper_submissions SET volume_year = ?, issue_number = ? WHERE id = ?');

    foreach ($acceptedRows as $row) {
        $effective = gysj_pick_effective_published_at($row);
        $ts = $effective !== '' ? strtotime($effective) : false;
        if ($ts === false) {
            continue;
        }

        $year = (int) date('Y', $ts);
        $month = (int) date('n', $ts);
        $issue = (int) ($monthToIssue[$year][$month] ?? 0);
        if ($year <= 0 || $issue <= 0) {
            continue;
        }

        $existingVol = (int) ($row['volume_year'] ?? 0);
        $existingIssue = (int) ($row['issue_number'] ?? 0);

        $newVol = $existingVol > 0 ? $existingVol : $year;
        $newIssue = $existingIssue > 0 ? $existingIssue : $issue;

        if ($existingVol <= 0 || $existingIssue <= 0) {
            $updateVolIssue->execute([$newVol, $newIssue, (int) $row['id']]);
            $volIssueUpdated++;
        }
    }

    // Backfill tracking fields.
    $trackingUpdated = 0;
    $updateTracking = $pdo->prepare('UPDATE paper_submissions SET tracking_id = ?, tracking_country3 = ?, tracking_year = ?, tracking_seq = ? WHERE id = ?');

    foreach ($papersByYear as $year => $items) {
        usort($items, function (array $a, array $b): int {
            if ($a['ts'] === $b['ts']) {
                return ((int) ($a['row']['id'] ?? 0)) <=> ((int) ($b['row']['id'] ?? 0));
            }
            return $a['ts'] <=> $b['ts'];
        });

        // First pass: mark used sequences from existing tracking fields (or parseable tracking_id).
        $used = [];
        foreach ($items as $item) {
            $r = $item['row'];
            $tid = strtoupper(trim((string) ($r['tracking_id'] ?? '')));
            $ty = (int) ($r['tracking_year'] ?? 0);
            $tseq = (int) ($r['tracking_seq'] ?? 0);

            if ($ty === (int) $year && $tseq > 0) {
                $used[$tseq] = true;
                continue;
            }

            $parsed = gysj_parse_tracking_id($tid);
            if (is_array($parsed)) {
                $parsedYear = (int) ($parsed['year'] ?? 0);
                $parsedSeq = (int) ($parsed['seq'] ?? 0);
                if ($parsedYear === (int) $year && $parsedSeq > 0) {
                    $used[$parsedSeq] = true;
                }
            }
        }

        // Second pass: fill missing tracking info.
        foreach ($items as $item) {
            $r = $item['row'];
            $id = (int) ($r['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $tid = strtoupper(trim((string) ($r['tracking_id'] ?? '')));
            $tc3 = strtoupper(trim((string) ($r['tracking_country3'] ?? '')));
            $ty = (int) ($r['tracking_year'] ?? 0);
            $tseq = (int) ($r['tracking_seq'] ?? 0);

            $parsed = gysj_parse_tracking_id($tid);
            if (is_array($parsed)) {
                if ($tc3 === '') {
                    $tc3 = (string) ($parsed['country3'] ?? '');
                }
                if ($ty <= 0) {
                    $ty = (int) ($parsed['year'] ?? 0);
                }
                if ($tseq <= 0) {
                    $tseq = (int) ($parsed['seq'] ?? 0);
                }
            }

            if ($tc3 === '') {
                $uid = (int) ($r['user_id'] ?? 0);
                $country = (string) ($countryByUserId[$uid] ?? '');
                $tc3 = gysj_country_to_country3($country);
            }

            if ($tid === '') {
                $seq = gysj_next_available_seq($used);
                $used[$seq] = true;

                $uid = (int) ($r['user_id'] ?? 0);
                $country = (string) ($countryByUserId[$uid] ?? '');
                $tc3 = gysj_country_to_country3($country);
                $ty = (int) $year;
                $tseq = $seq;
            } elseif ($ty === (int) $year && $tseq > 0) {
                $used[$tseq] = true;
            }

            $normalizedTrackingId = gysj_format_tracking_id($tc3, $ty, $tseq);
            if ($normalizedTrackingId === '') {
                continue;
            }

            $needsUpdate = ((string) ($r['tracking_id'] ?? '')) === ''
                || strtoupper(trim((string) ($r['tracking_id'] ?? ''))) !== $normalizedTrackingId
                || (int) ($r['tracking_year'] ?? 0) <= 0
                || (int) ($r['tracking_seq'] ?? 0) <= 0
                || trim((string) ($r['tracking_country3'] ?? '')) === '';

            if ($needsUpdate) {
                $updateTracking->execute([$normalizedTrackingId, $tc3, $ty, $tseq, $id]);
                $trackingUpdated++;
            }
        }
    }

    echo "OK: backfilled volume/issue for {$volIssueUpdated} paper(s)\n";
    echo "OK: backfilled tracking for {$trackingUpdated} paper(s)\n";
}

// Ensure indexes.
if ($isSqlite) {
    try {
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_paper_submissions_tracking_id ON paper_submissions(tracking_id)');
        echo "OK: ensured unique index uniq_paper_submissions_tracking_id\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR: failed to create uniq_paper_submissions_tracking_id index: " . $e->getMessage() . "\n");
        exit(1);
    }

    try {
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_paper_submissions_tracking_year_seq ON paper_submissions(tracking_year, tracking_seq)');
        echo "OK: ensured unique index uniq_paper_submissions_tracking_year_seq\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR: failed to create uniq_paper_submissions_tracking_year_seq index: " . $e->getMessage() . "\n");
        exit(1);
    }

    try {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_paper_submissions_volume_issue ON paper_submissions(volume_year, issue_number)');
        echo "OK: ensured index idx_paper_submissions_volume_issue\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR: failed to create idx_paper_submissions_volume_issue index: " . $e->getMessage() . "\n");
        exit(1);
    }
} else {
    try {
        $pdo->exec('ALTER TABLE paper_submissions ADD UNIQUE KEY uniq_paper_submissions_tracking_id (tracking_id)');
        echo "OK: ensured unique index uniq_paper_submissions_tracking_id\n";
    } catch (Throwable $e) {
        $msg = strtolower((string) $e->getMessage());
        $duplicateKeyName = (strpos($msg, 'duplicate key name') !== false)
            || (strpos($msg, 'already exists') !== false && strpos($msg, 'key') !== false);

        if ($duplicateKeyName) {
            echo "SKIP: unique index uniq_paper_submissions_tracking_id already exists\n";
        } else {
            fwrite(STDERR, "ERROR: failed to create uniq_paper_submissions_tracking_id index: " . $e->getMessage() . "\n");
            exit(1);
        }
    }

    try {
        $pdo->exec('ALTER TABLE paper_submissions ADD UNIQUE KEY uniq_paper_submissions_tracking_year_seq (tracking_year, tracking_seq)');
        echo "OK: ensured unique index uniq_paper_submissions_tracking_year_seq\n";
    } catch (Throwable $e) {
        $msg = strtolower((string) $e->getMessage());
        $duplicateKeyName = (strpos($msg, 'duplicate key name') !== false)
            || (strpos($msg, 'already exists') !== false && strpos($msg, 'key') !== false);

        if ($duplicateKeyName) {
            echo "SKIP: unique index uniq_paper_submissions_tracking_year_seq already exists\n";
        } else {
            fwrite(STDERR, "ERROR: failed to create uniq_paper_submissions_tracking_year_seq index: " . $e->getMessage() . "\n");
            exit(1);
        }
    }

    try {
        $pdo->exec('ALTER TABLE paper_submissions ADD KEY idx_paper_submissions_volume_issue (volume_year, issue_number)');
        echo "OK: ensured index idx_paper_submissions_volume_issue\n";
    } catch (Throwable $e) {
        $msg = strtolower((string) $e->getMessage());
        $duplicateKeyName = (strpos($msg, 'duplicate key name') !== false)
            || (strpos($msg, 'already exists') !== false && strpos($msg, 'key') !== false);

        if ($duplicateKeyName) {
            echo "SKIP: index idx_paper_submissions_volume_issue already exists\n";
        } else {
            fwrite(STDERR, "ERROR: failed to create idx_paper_submissions_volume_issue index: " . $e->getMessage() . "\n");
            exit(1);
        }
    }
}

echo "OK: migration complete (added {$added} column(s))\n";
