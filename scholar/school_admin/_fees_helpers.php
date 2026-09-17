<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — FEES: SHARED LOGIC
|--------------------------------------------------------------------------
| Pulled out of school_admin/fees.php so the classic page and the JSON
| endpoint (api/admin/fees.php) compute the ledger/balances identically.
|--------------------------------------------------------------------------
*/

function admin_fees_ensure_schema(PDO $pdo): void
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `fee_structures` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `school_id` INT NOT NULL,
              `class_id` INT NOT NULL,
              `term` VARCHAR(20) NULL,
              `year` VARCHAR(10) NULL,
              `day_tuition` DECIMAL(12,2) DEFAULT 0.00,
              `boarding_tuition` DECIMAL(12,2) DEFAULT 0.00,
              `entry_fee` DECIMAL(12,2) DEFAULT 0.00,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY `school_class_term_year_unique` (`school_id`, `class_id`, `term`, `year`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS `fee_payments` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `school_id` INT NOT NULL,
              `student_id` INT NOT NULL,
              `term` VARCHAR(20) NULL,
              `year` VARCHAR(10) NULL,
              `amount_paid` DECIMAL(12,2) DEFAULT 0.00,
              `bursary_discount` DECIMAL(12,2) DEFAULT 0.00,
              `residence_type` ENUM('Day', 'Boarding') DEFAULT 'Day',
              `is_new_student` TINYINT(1) DEFAULT 0,
              `notes` VARCHAR(255) DEFAULT NULL,
              `paid_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    } catch (PDOException $e) {
        // Schema exists
    }
}

/**
 * Picks the best fee_structures row for a given class/term/year: an exact
 * (school_id, class_id, term, year) match if one exists, otherwise that
 * class's most recent prior rate (so a school that doesn't proactively
 * re-save a rate every term still sees a sensible number instead of a
 * blank/zero fee for a term nobody configured). $classIdExpr/$schoolIdExpr
 * are the caller's own column references (the two queries below join from
 * a different starting table -- students vs classes -- so the "current
 * class/school" columns aren't always named the same thing).
 */
function fee_structure_lookup_sql(string $classIdExpr, string $schoolIdExpr): string
{
    return "(
        SELECT fs2.id FROM fee_structures fs2
        WHERE fs2.school_id = {$schoolIdExpr} AND fs2.class_id = {$classIdExpr}
        ORDER BY (fs2.term = :fs_term AND fs2.year = :fs_year) DESC, fs2.year DESC, fs2.term DESC
        LIMIT 1
    )";
}

/** @return array{ok:bool,message:string} */
function admin_fees_save_structure(PDO $pdo, int $schoolId, int $classId, float $dayTuition, float $boardTuition, float $entryFee, string $term, string $year): array
{
    if ($classId <= 0) {
        return ['ok' => false, 'message' => 'Please select a valid class to configure fees.'];
    }
    if ($dayTuition < 0 || $boardTuition < 0 || $entryFee < 0) {
        return ['ok' => false, 'message' => 'Fee amounts cannot be negative.'];
    }
    if ($term === '' || $year === '') {
        return ['ok' => false, 'message' => 'Term and year are required.'];
    }

    $stmt = $pdo->prepare('
        INSERT INTO fee_structures (school_id, class_id, term, year, day_tuition, boarding_tuition, entry_fee)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            day_tuition = VALUES(day_tuition),
            boarding_tuition = VALUES(boarding_tuition),
            entry_fee = VALUES(entry_fee)
    ');
    if ($stmt->execute([$schoolId, $classId, $term, $year, $dayTuition, $boardTuition, $entryFee])) {
        return ['ok' => true, 'message' => "Fee structure updated for {$term} {$year}!"];
    }
    return ['ok' => false, 'message' => 'Failed to update fee structure.'];
}

/** @return array{ok:bool,message:string} */
function admin_fees_record_payment(PDO $pdo, int $schoolId, int $studentId, float $amountPaid, float $bursaryAmount, string $residenceType, bool $isNewStudent, string $notes, string $term, string $year): array
{
    if ($studentId <= 0) {
        return ['ok' => false, 'message' => 'Invalid student selected.'];
    }
    if ($amountPaid < 0 || $bursaryAmount < 0) {
        return ['ok' => false, 'message' => 'Payment and bursary amounts cannot be negative.'];
    }
    if ($term === '' || $year === '') {
        return ['ok' => false, 'message' => 'Term and year are required.'];
    }

    $stmt = $pdo->prepare('
        INSERT INTO fee_payments (school_id, student_id, term, year, amount_paid, bursary_discount, residence_type, is_new_student, notes, paid_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ');
    if ($stmt->execute([$schoolId, $studentId, $term, $year, $amountPaid, $bursaryAmount, $residenceType, $isNewStudent ? 1 : 0, $notes])) {
        return ['ok' => true, 'message' => "Payment recorded for {$term} {$year}!"];
    }
    return ['ok' => false, 'message' => 'Error recording payment transaction.'];
}

function admin_fees_fetch_structures(PDO $pdo, int $schoolId, string $term, string $year): array
{
    // All-named placeholders throughout -- PDO doesn't allow mixing "?"
    // and ":name" placeholders in the same prepared statement, and the
    // fee_structure_lookup_sql() subquery always uses named ones.
    $stmt = $pdo->prepare('
        SELECT c.id AS class_id, c.class_name,
               COALESCE(fs.day_tuition, 0) AS day_tuition,
               COALESCE(fs.boarding_tuition, 0) AS boarding_tuition,
               COALESCE(fs.entry_fee, 0) AS entry_fee,
               fs.term AS structure_term, fs.year AS structure_year
        FROM classes c
        LEFT JOIN fee_structures fs ON fs.id = ' . fee_structure_lookup_sql('c.id', 'c.school_id') . '
        WHERE c.school_id = :school_id
        ORDER BY c.class_name ASC
    ');
    $stmt->execute([':school_id' => $schoolId, ':fs_term' => $term, ':fs_year' => $year]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function admin_fees_fetch_ledger(PDO $pdo, int $schoolId, string $search, string $classFilter, string $term, string $year): array
{
    $sql = "
        SELECT
            s.id AS student_id,
            s.full_name,
            s.class_id,
            c.class_name,
            COALESCE(fs.day_tuition, 0) AS base_day,
            COALESCE(fs.boarding_tuition, 0) AS base_boarding,
            COALESCE(fs.entry_fee, 0) AS base_entry,
            COALESCE(SUM(fp.amount_paid), 0) AS total_paid,
            COALESCE(SUM(fp.bursary_discount), 0) AS total_bursary,
            MAX(fp.residence_type) AS active_residence,
            MAX(fp.is_new_student) AS is_new
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        LEFT JOIN fee_structures fs ON fs.id = " . fee_structure_lookup_sql('s.class_id', 's.school_id') . "
        LEFT JOIN fee_payments fp ON s.id = fp.student_id AND fp.school_id = s.school_id AND fp.term = :fp_term AND fp.year = :fp_year
        WHERE s.school_id = :school_id
    ";
    $params = [':school_id' => $schoolId, ':fs_term' => $term, ':fs_year' => $year, ':fp_term' => $term, ':fp_year' => $year];

    if ($search !== '') {
        $sql .= ' AND s.full_name LIKE :search';
        $params[':search'] = '%' . $search . '%';
    }
    if ($classFilter !== '') {
        $sql .= ' AND s.class_id = :class_filter';
        $params[':class_filter'] = $classFilter;
    }
    $sql .= ' GROUP BY s.id ORDER BY s.full_name ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Single-student equivalent of admin_fees_fetch_ledger() -- same query
 * shape, filtered to one student_id instead of search/class. Exists so
 * student_portal.php/student_fees.php can run their balance through
 * admin_fees_annotate_ledger() too, instead of maintaining their own
 * separate formula: those pages used to compute "expected" as flat
 * day_tuition + entry_fee unconditionally, ignoring boarding_tuition
 * entirely, always charging the entry fee even for a returning student,
 * and never subtracting bursary_discount -- a different, uncorrelated
 * number from what the bursar's office actually tracks for the same
 * student. A boarder, a bursary recipient, or a returning (non-new)
 * student would all see a balance that simply didn't match the real
 * ledger.
 *
 * @return array|null null if the student doesn't belong to this school
 */
function admin_fees_fetch_ledger_for_student(PDO $pdo, int $schoolId, int $studentId, string $term, string $year): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            s.id AS student_id,
            s.full_name,
            s.class_id,
            c.class_name,
            COALESCE(fs.day_tuition, 0) AS base_day,
            COALESCE(fs.boarding_tuition, 0) AS base_boarding,
            COALESCE(fs.entry_fee, 0) AS base_entry,
            COALESCE(SUM(fp.amount_paid), 0) AS total_paid,
            COALESCE(SUM(fp.bursary_discount), 0) AS total_bursary,
            MAX(fp.residence_type) AS active_residence,
            MAX(fp.is_new_student) AS is_new
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        LEFT JOIN fee_structures fs ON fs.id = " . fee_structure_lookup_sql('s.class_id', 's.school_id') . "
        LEFT JOIN fee_payments fp ON s.id = fp.student_id AND fp.school_id = s.school_id AND fp.term = :fp_term AND fp.year = :fp_year
        WHERE s.school_id = :school_id AND s.id = :student_id
        GROUP BY s.id
    ");
    $stmt->execute([
        ':school_id' => $schoolId,
        ':student_id' => $studentId,
        ':fs_term' => $term,
        ':fs_year' => $year,
        ':fp_term' => $term,
        ':fp_year' => $year,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $annotated = admin_fees_annotate_ledger([$row]);
    return $annotated['ledger'][0];
}

/**
 * Per-row due/balance figures, folded onto each ledger row, plus the
 * whole-ledger summary metrics (fully paid/partial/unpaid counts, total
 * collected). Same "boarding vs day tuition + entry fee if new, minus
 * bursary" formula the classic page computes twice (once for the summary
 * loop, once again per table row) -- here it's the one place.
 */
function admin_fees_annotate_ledger(array $ledger): array
{
    $fully_paid = 0;
    $partial_paid = 0;
    $unpaid = 0;
    $total_collected = 0.0;

    foreach ($ledger as &$row) {
        $is_boarder = $row['active_residence'] === 'Boarding';
        $tuition = $is_boarder ? (float) $row['base_boarding'] : (float) $row['base_day'];
        $entry = ((int) $row['is_new'] === 1) ? (float) $row['base_entry'] : 0.0;

        $gross_due = $tuition + $entry;
        $net_due = max(0.0, $gross_due - (float) $row['total_bursary']);
        $balance = $net_due - (float) $row['total_paid'];

        $row['gross_due'] = $gross_due;
        $row['net_due'] = $net_due;
        $row['balance'] = max(0.0, $balance);

        $total_collected += (float) $row['total_paid'];

        // Matches the classic page's summary-card formula exactly (which
        // requires balance > 0 for "partial") -- its per-row table badge
        // used a slightly looser check (no balance>0) that could disagree
        // with the summary counts in an edge case (net_due = 0 with some
        // payment recorded anyway); this keeps the stricter, more correct
        // summary-card behavior as the one source of truth.
        if ((float) $row['total_paid'] >= $net_due && $net_due > 0) {
            $row['status'] = 'cleared';
            $fully_paid++;
        } elseif ((float) $row['total_paid'] > 0 && $balance > 0) {
            $row['status'] = 'partial';
            $partial_paid++;
        } else {
            $row['status'] = 'unpaid';
            $unpaid++;
        }
    }
    unset($row);

    return [
        'ledger' => $ledger,
        'metrics' => [
            'total_students' => count($ledger),
            'fully_paid' => $fully_paid,
            'partial_paid' => $partial_paid,
            'unpaid' => $unpaid,
            'total_collected' => $total_collected,
        ],
    ];
}
