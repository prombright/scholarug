<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — SUBJECT / PAPER-COUNT HELPERS (shared)
|--------------------------------------------------------------------------
| Two things every "a school now has an O-Level class" trigger point
| needs, kept in one place so classes.php, students.php, and
| subject_catalog.php's manual adopt flow can't drift out of sync with
| each other:
|
| 1. scholar_papers_count_for_class() -- the real Uganda O-Level paper
|    rule: S.1/S.2 are 1 paper for everything; S.3/S.4 bump to 2 papers
|    only for Biology/Physics/Chemistry/Computer Studies (ICT). Every
|    other subject/class combination keeps the catalog's own base
|    papers_count.
|
| 2. scholar_ensure_compulsory_subjects() -- the 7 compulsory O-Level
|    subjects (English, Mathematics, Biology, Physics, Chemistry,
|    Geography, History -- subject_catalog.is_compulsory=1, see
|    _setup/compulsory_subjects_fix_migration.sql) get adopted into
|    `subjects` for a given class automatically, the same way
|    subject_catalog.php's "Adopt Ticked Subjects" button already does
|    manually -- additive-only (skips subjects already adopted there),
|    so calling this on a class that already has them is a safe no-op.
|    Electives are NEVER touched by this -- see student_subjects.sql's
|    own header for why electives stay a manual, per-student choice.
|
| Also home to scholar_class_ladder()/scholar_normalize_class_name()
| (moved from auth_guard.php) and the Primary-school equivalents of the
| two functions above, so every "what does this school start with"
| decision -- for any school_type, from any auth context (school_admin
| pages via auth_guard.php, or scholar/developer/*.php which deliberately
| does NOT include auth_guard.php's session/idle-timeout machinery) --
| comes from this one file.
|--------------------------------------------------------------------------
*/

if (!function_exists('scholar_class_ladder')) {
    /**
     * Single source of truth for a school's class ladder, keyed by level so
     * callers that need level-grouped rendering (classes.php's Add Class form)
     * and callers that just need a flat top-to-bottom sequence (Close Year's
     * promotion loop) can both build off the same array instead of drifting.
     */
    function scholar_class_ladder(string $school_type): array
    {
        return $school_type === 'Primary'
            ? [
                'Pre-Primary' => ['Baby Class', 'Middle Class', 'Top Class'],
                'Primary'     => ['P.1', 'P.2', 'P.3', 'P.4', 'P.5', 'P.6', 'P.7'],
            ]
            : [
                'O-Level' => ['S.1', 'S.2', 'S.3', 'S.4'],
                'A-Level' => ['S.5', 'S.6'],
            ];
    }
}

if (!function_exists('scholar_normalize_class_name')) {
    /**
     * classes.class_name and subjects.class_name aren't consistently
     * formatted across schools ("S1" vs "S.1") -- every dot/case-insensitive
     * comparison in the app (report cards, subject enrollment, and now Close
     * Year's promotion matching) should go through this one function instead
     * of repeating the same strtoupper(str_replace('.', '', ...)) inline.
     */
    function scholar_normalize_class_name(string $name): string
    {
        return strtoupper(str_replace('.', '', $name));
    }
}

if (!function_exists('scholar_papers_count_for_class')) {
    function scholar_papers_count_for_class(string $subjectCode, string $className, int $catalogDefault): int
    {
        $twoPapersAtUpperForm = ['BIO', 'PHY', 'CHE', 'COS'];
        $isUpperForm = in_array($className, ['S.3', 'S.4'], true);

        if ($isUpperForm && in_array($subjectCode, $twoPapersAtUpperForm, true)) {
            return 2;
        }

        return $catalogDefault;
    }
}

if (!function_exists('scholar_ensure_compulsory_subjects')) {
    /**
     * Adopts every is_compulsory=1 O-Level catalog subject into `subjects`
     * for one specific class, skipping any already adopted (matched by
     * subject_reference_id, same convention subject_catalog.php's own
     * "already adopted" check uses). Safe to call on every class
     * creation and every student registration -- does nothing once a
     * class already has them, and does nothing at all for A-Level/
     * Primary classes.
     *
     * @return int how many subjects were newly adopted (0 if already done, or not O-Level)
     */
    function scholar_ensure_compulsory_subjects(PDO $pdo, int $schoolId, string $className): int
    {
        if (!in_array($className, ['S.1', 'S.2', 'S.3', 'S.4'], true)) {
            return 0;
        }

        $catalog_stmt = $pdo->prepare("SELECT * FROM subject_catalog WHERE level_type = 'O-Level' AND is_compulsory = 1 AND is_active = 1");
        $catalog_stmt->execute();
        $compulsory = $catalog_stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($compulsory)) {
            return 0;
        }

        $already_stmt = $pdo->prepare("
            SELECT subject_reference_id FROM subjects
            WHERE school_id = ? AND class_name = ? AND subject_reference_id IS NOT NULL
        ");
        $already_stmt->execute([$schoolId, $className]);
        $already_ids = array_map('intval', array_column($already_stmt->fetchAll(PDO::FETCH_ASSOC), 'subject_reference_id'));

        $ins = $pdo->prepare("
            INSERT INTO subjects (school_id, subject_reference_id, level_type, subject_name, subject_code, is_compulsory, papers_count, class_name, subject_type)
            VALUES (?, ?, 'O-Level', ?, ?, 1, ?, ?, 'Core')
        ");

        $adopted = 0;
        foreach ($compulsory as $cat) {
            if (in_array((int) $cat['id'], $already_ids, true)) {
                continue;
            }
            // 'O-' prefix matches subject_catalog.php's own adoption
            // convention exactly (_setup/subject_catalog.sql's codes are
            // Scholar-internal, not official UNEB codes -- the level
            // prefix disambiguates the same catalog code adopted at both
            // O-Level and A-Level).
            $final_code = 'O-' . $cat['subject_code'];
            $papers = scholar_papers_count_for_class($cat['subject_code'], $className, (int) $cat['papers_count']);

            $ins->execute([$schoolId, $cat['id'], $cat['subject_name'], $final_code, $papers, $className]);
            $adopted++;
        }

        return $adopted;
    }
}

if (!function_exists('scholar_seed_default_primary_subjects')) {
    /**
     * Primary equivalent of scholar_ensure_compulsory_subjects() -- adopts
     * the default subject list into `subjects` for one Primary class,
     * skipping any already seeded (matched by school_id+class_name+
     * subject_code, same convention subject_matrix.php's original manual
     * "Seed Default Subjects" button used). Additive-only, idempotent, and
     * a no-op for any class name outside P.1-P.7.
     *
     * Best-effort structure -- P.1-P.3 follows the thematic curriculum,
     * P.4-P.7 is subject-based -- not a verified official NCDC syllabus.
     * Review the codes/names before relying on them for a real school.
     *
     * @return int how many subjects were newly seeded (0 if already done, or not Primary P.1-P.7)
     */
    function scholar_seed_default_primary_subjects(PDO $pdo, int $schoolId, string $className): int
    {
        if (!in_array($className, ['P.1', 'P.2', 'P.3', 'P.4', 'P.5', 'P.6', 'P.7'], true)) {
            return 0;
        }

        // [name, code, class_name]
        $defaults = [
            ['Literacy 1', 'LIT1', 'P.1'], ['Literacy 2', 'LIT2', 'P.1'], ['Numeracy', 'NUM', 'P.1'], ['Local Language', 'LOC', 'P.1'],
            ['Literacy 1', 'LIT1', 'P.2'], ['Literacy 2', 'LIT2', 'P.2'], ['Numeracy', 'NUM', 'P.2'], ['Local Language', 'LOC', 'P.2'],
            ['Literacy 1', 'LIT1', 'P.3'], ['Literacy 2', 'LIT2', 'P.3'], ['Numeracy', 'NUM', 'P.3'], ['Local Language', 'LOC', 'P.3'],
            ['English', 'ENG', 'P.4'], ['Mathematics', 'MTC', 'P.4'], ['Science', 'SCI', 'P.4'], ['Social Studies', 'SST', 'P.4'], ['Religious Education', 'RE', 'P.4'],
            ['English', 'ENG', 'P.5'], ['Mathematics', 'MTC', 'P.5'], ['Science', 'SCI', 'P.5'], ['Social Studies', 'SST', 'P.5'], ['Religious Education', 'RE', 'P.5'],
            ['English', 'ENG', 'P.6'], ['Mathematics', 'MTC', 'P.6'], ['Science', 'SCI', 'P.6'], ['Social Studies', 'SST', 'P.6'], ['Religious Education', 'RE', 'P.6'],
            ['English', 'ENG', 'P.7'], ['Mathematics', 'MTC', 'P.7'], ['Science', 'SCI', 'P.7'], ['Social Studies', 'SST', 'P.7'], ['Religious Education', 'RE', 'P.7'],
        ];

        $exists = $pdo->prepare("SELECT id FROM subjects WHERE school_id = ? AND class_name = ? AND subject_code = ?");
        $ins = $pdo->prepare("INSERT INTO subjects (school_id, level_type, subject_name, subject_code, is_compulsory, papers_count, class_name, subject_type) VALUES (?, 'Primary', ?, ?, 1, 1, ?, 'Core')");

        $seeded = 0;
        foreach ($defaults as [$name, $code, $class_name]) {
            if ($class_name !== $className) {
                continue;
            }
            $exists->execute([$schoolId, $class_name, $code]);
            if (!$exists->fetchColumn()) {
                $ins->execute([$schoolId, $name, $code, $class_name]);
                $seeded++;
            }
        }

        return $seeded;
    }
}

if (!function_exists('scholar_provision_school_type_defaults')) {
    /**
     * The one shared "make this school's classes+subjects match its type"
     * routine -- used both when a developer first provisions a school
     * (scholar/developer/school_onboarding.php) and when a developer
     * sets/corrects an existing school's type later
     * (scholar/developer/school_profile.php). Builds the full ladder for
     * $schoolType, creates any class row the school doesn't have yet
     * (unstreamed), then seeds every class in the ladder -- not just
     * newly-created ones, so it also backfills subjects onto a class that
     * already existed but never got seeded. Both subject-seeding calls
     * self-guard on class name, so calling both unconditionally for every
     * class is safe regardless of $schoolType. Purely additive: never
     * deletes a class or subject that doesn't match $schoolType.
     */
    function scholar_provision_school_type_defaults(PDO $pdo, int $schoolId, string $schoolType): void
    {
        $ladder = scholar_class_ladder($schoolType);
        $all_class_names = array_merge(...array_values($ladder));

        $existing_stmt = $pdo->prepare("SELECT class_name FROM classes WHERE school_id = ?");
        $existing_stmt->execute([$schoolId]);
        $existing_class_names = $existing_stmt->fetchAll(PDO::FETCH_COLUMN);

        $missing_class_names = array_diff($all_class_names, $existing_class_names);
        if ($missing_class_names) {
            $ins = $pdo->prepare("INSERT INTO classes (school_id, class_name, stream_name) VALUES (?, ?, NULL)");
            foreach ($missing_class_names as $cn) {
                $ins->execute([$schoolId, $cn]);
            }
        }

        foreach ($all_class_names as $cn) {
            scholar_ensure_compulsory_subjects($pdo, $schoolId, $cn);
            scholar_seed_default_primary_subjects($pdo, $schoolId, $cn);
        }
    }
}