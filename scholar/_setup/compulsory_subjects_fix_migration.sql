-- ============================================================
-- SCHOLAR — O-LEVEL COMPULSORY SUBJECTS: CATALOG CORRECTION
-- ============================================================
-- The O-Level subject_catalog seed had two mistakes, both fixed here:
--
-- 1) is_compulsory was only ever set on English (ENG) and Mathematics
--    (MTC) -- Biology, Physics, Chemistry, Geography and History were
--    is_compulsory=0 despite being compulsory subjects across Uganda
--    O-Level. The 7 real compulsory subjects: English, Mathematics,
--    Biology, Physics, Chemistry, Geography, History.
--
-- 2) papers_count was a flat 2 for all 7, regardless of class. The real
--    rule: S.1/S.2 are 1 paper for every subject; S.3/S.4 bump to 2
--    papers ONLY for Biology, Physics, Chemistry (plus Computer
--    Studies/ICT, which is elective, not compulsory, handled the same
--    way at adoption time) -- English/Mathematics/Geography/History
--    stay at 1 paper even at S.3/S.4. subject_catalog.papers_count is
--    the S.1/S.2 (and Eng/Math/Geo/Hist S.3/S.4) BASE value; the S.3/S.4
--    bump for Bio/Phy/Che/Computer-Studies is applied per-class at
--    adoption time by scholar_papers_count_for_class() in
--    _subject_helpers.php, not stored here.
--
-- Existing already-adopted `subjects` rows (schools that adopted these
-- before this fix) are NOT touched by this migration -- see
-- backfill_compulsory_subjects.php, which re-adopts/corrects those.
--
-- Apply with: mysql -u root scholar < compulsory_subjects_fix_migration.sql
-- Safe to re-run.
-- ============================================================

USE scholar;

UPDATE subject_catalog
SET is_compulsory = 1
WHERE level_type = 'O-Level' AND subject_code IN ('ENG', 'MTC', 'BIO', 'PHY', 'CHE', 'GEO', 'HIS');

UPDATE subject_catalog
SET papers_count = 1
WHERE level_type = 'O-Level' AND subject_code IN ('ENG', 'MTC', 'BIO', 'PHY', 'CHE', 'GEO', 'HIS');

-- Computer Studies (COS) is Uganda's official name for what's commonly
-- called "ICT" -- elective, not compulsory, but the same S.1/S.2=1,
-- S.3/S.4=2 paper split applies. Its S.1/S.2 base wasn't specified
-- explicitly; defaulted to 1 here for consistency with every other
-- subject's base -- adjust if a school actually runs it differently at
-- S.1/S.2.
UPDATE subject_catalog
SET papers_count = 1
WHERE level_type = 'O-Level' AND subject_code = 'COS';