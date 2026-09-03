-- ============================================================
-- iLEARNING PREREQUISITE FIX — MARKS PIPELINE
-- ============================================================
-- `student_marks`, `grading_scales`, and `assessments` already exist in
-- the live database (built ad-hoc via phpMyAdmin at some point, not from
-- a repo migration file -- there is no single schema.sql for `scholar`,
-- unlike bulksms/medicare/devportal). Their shapes are mostly correct
-- already; this migration fixes the two real gaps found by comparing the
-- live schema against what teachers_portal.php/generate_report.php
-- actually read and write:
--
-- 1. [SUPERSEDED -- see note below] student_marks' only UNIQUE key was
--    (student_id, subject_id, exam_id), which didn't protect
--    assessment-based rows from duplicating. This migration originally
--    added uniq_student_subject_assessment(student_id, subject_id,
--    assessment_id) to fix that -- written without awareness that
--    _setup/subject_papers.sql (a separate, already-applied migration)
--    had *already* solved the exact same problem with a wider key,
--    uniq_student_subject_assessment_paper(student_id, subject_id,
--    assessment_id, paper_number), needed because a multi-paper subject
--    legitimately has two real rows (Paper 1 and Paper 2) for the same
--    student+subject+assessment. Applying THIS file's narrower key on
--    top would have re-broken multi-paper marks the first time a Paper 2
--    score was saved -- caught during live-deploy verification
--    (2026-08-14) when two genuine Paper 1/Paper 2 rows for the same
--    assessment were misread as a duplicate-data problem. The ALTER TABLE
--    below is intentionally removed; subject_papers.sql's key already
--    covers this.
--
-- 2. grading_scales has zero rows for 14 of this school's 16 real
--    schools (only two recently-created test schools have bands seeded).
--    generate_report.php's fallback for this ("Grade scale not
--    configured for this score") is graceful, not a crash -- but it
--    means report cards are currently unusable for those 14 schools.
--    Seed the same default band already used for the two schools that do
--    have one, for every school currently missing all of them.
--
-- NOTE ON ORDERING: apply this file BEFORE uace_subsidiary_migration.sql
-- -- that migration corrects grading_scales.points for grade F from 1 to
-- 0 across every EXISTING row at the time it runs; running it first would
-- leave the newly-seeded rows below stuck at F=1.
--
-- Run against the `scholar` database:
--   mysql -u root scholar < ilearning_prereq_migration.sql
-- ============================================================

INSERT INTO grading_scales (school_id, grade, min_mark, max_mark, remark, points)
SELECT s.id, band.grade, band.min_mark, band.max_mark, band.remark, band.points
FROM schools s
CROSS JOIN (
    SELECT 'A' AS grade, 80.00 AS min_mark, 100.00 AS max_mark, 'Excellent performance, keep it up.' AS remark, 6.00 AS points
    UNION ALL SELECT 'B', 70.00, 79.99, 'Very good work this term.', 5.00
    UNION ALL SELECT 'C', 60.00, 69.99, 'Good effort, room to improve.', 4.00
    UNION ALL SELECT 'D', 50.00, 59.99, 'Fair performance, needs more focus.', 3.00
    UNION ALL SELECT 'E', 40.00, 49.99, 'Below average, extra revision required.', 2.00
    UNION ALL SELECT 'F', 0.00, 39.99, 'Weak performance, needs close support.', 1.00
) AS band
WHERE NOT EXISTS (SELECT 1 FROM grading_scales g WHERE g.school_id = s.id);
