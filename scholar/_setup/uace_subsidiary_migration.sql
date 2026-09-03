-- ============================================================
-- SCHOLAR — A-LEVEL UACE SUBSIDIARY SUBJECTS + POINTS FIX
-- ============================================================
-- Adds the two subsidiary subjects the real UNEB A-Level system actually
-- has (General Paper already existed in subject_catalog) -- schools
-- "adopt" these the same way as any other catalog subject, via
-- school_admin/subject_catalog.php.
--
-- Also fixes grading_scales.points for grade 'F' from 1 to 0 across every
-- school. UACE's point scale is A=6 B=5 C=4 D=3 E=2 F=0 -- every school's
-- grading band was seeded (by an undocumented one-off script, per
-- school_admin/grading_scales.php's own header comment) with F=1, which
-- silently breaks "a total failure scores 0 points" for the A-Level UACE
-- points total (generate_report.php / _report_card_render.php). This is a
-- correctness fix to a shared system default, not destructive -- every
-- school's F points value was already identical and unedited (confirmed
-- live before writing this), and points aren't rendered anywhere on the
-- O-Level report, only used in this new A-Level points total.
-- Apply with: mysql -u root scholar < uace_subsidiary_migration.sql
-- ============================================================

USE scholar;

INSERT IGNORE INTO subject_catalog (level_type, subject_name, subject_code, papers_count, is_compulsory, display_order) VALUES
('A-Level', 'Subsidiary Mathematics', 'SUBMATH', 1, 0, 19),
('A-Level', 'Subsidiary ICT', 'ICT', 1, 0, 20);

UPDATE grading_scales SET points = 0 WHERE grade = 'F' AND points <> 0;
