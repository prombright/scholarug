<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — ELECTIONS LIST/CREATE: SHARED LOGIC
|--------------------------------------------------------------------------
| Pulled out of elections/index.php so the classic page and the JSON
| endpoint (api/admin/elections.php) validate/create identically.
|--------------------------------------------------------------------------
*/

/** @return array{ok:bool,message:string} */
function admin_elections_create(PDO $pdo, int $schoolId, ?int $staffId, string $title, string $term, string $year, string $opensAt, string $closesAt): array
{
    if ($title === '' || $opensAt === '' || $closesAt === '') {
        return ['ok' => false, 'message' => 'Title, opens-at, and closes-at are all required.'];
    }
    if (strtotime($closesAt) <= strtotime($opensAt)) {
        return ['ok' => false, 'message' => 'Closing time must be after the opening time.'];
    }

    $pdo->prepare('
        INSERT INTO elections (school_id, title, term, year, opens_at, closes_at, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ')->execute([$schoolId, $title, $term, $year, $opensAt, $closesAt, $staffId && $staffId > 0 ? $staffId : null]);

    return ['ok' => true, 'message' => 'Election created as a Draft. Add positions next.'];
}

function admin_elections_publish(PDO $pdo, int $schoolId, int $electionId): void
{
    $pdo->prepare("UPDATE elections SET status = 'Published' WHERE id = ? AND school_id = ? AND status = 'Draft'")
        ->execute([$electionId, $schoolId]);
}

/** @return array<int,array> elections, each with a computed 'phase' key */
function admin_elections_fetch_list(PDO $pdo, int $schoolId): array
{
    $stmt = $pdo->prepare('SELECT * FROM elections WHERE school_id = ? ORDER BY created_at DESC');
    $stmt->execute([$schoolId]);
    $elections = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($elections as &$e) {
        $e['phase'] = election_phase($e, $pdo);
    }
    unset($e);

    return $elections;
}
