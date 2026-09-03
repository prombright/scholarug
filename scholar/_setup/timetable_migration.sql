-- ============================================================
-- SCHOLAR — TIMETABLE ENGINE (schema)
-- ============================================================
-- Builds on the FK cleanup already done in scheduling_data_integrity.sql.
-- Three pieces:
--   1. teacher_assignments.periods_per_week -- how many lessons/week this
--      specific teacher+subject+class combo needs. Lives on the assignment
--      row itself (not subjects) because real schools vary this by class
--      level (e.g. Maths might get 8 periods for S.4 but 6 for S.1), and
--      the assignment row is already the unique (teacher, subject, class)
--      unit that represents.
--   2. timetable_periods -- each school's own day structure (how many
--      periods, what times, which are breaks/lunch vs teaching slots).
--      Stored per day_of_week so e.g. a shorter Friday is representable,
--      not forced into one uniform pattern.
--   3. timetable_entries -- the generated/edited timetable itself. Two
--      UNIQUE constraints do the real conflict-prevention work at the DB
--      level: a class can't have two lessons in the same slot, and a
--      teacher can't be in two places in the same slot -- the generator
--      still checks in-memory before inserting (for speed and to report
--      "couldn't place" clearly), but these constraints are the actual
--      backstop against a bug ever double-booking someone.
-- ============================================================

USE scholar;

ALTER TABLE teacher_assignments
    ADD COLUMN IF NOT EXISTS periods_per_week TINYINT UNSIGNED NOT NULL DEFAULT 5 AFTER paper_number;

CREATE TABLE IF NOT EXISTS timetable_periods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL, -- 1=Monday .. 7=Sunday
    period_number TINYINT UNSIGNED NOT NULL, -- display/sort order within the day
    label VARCHAR(50) NOT NULL, -- "Period 1", "Break", "Lunch"
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    is_teaching_period TINYINT(1) NOT NULL DEFAULT 1, -- 0 for break/lunch/assembly slots
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_school_day_period (school_id, day_of_week, period_number)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS timetable_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    class_id INT NOT NULL,
    subject_id INT NOT NULL,
    teacher_id INT NOT NULL,
    paper_number TINYINT UNSIGNED NOT NULL DEFAULT 1,
    day_of_week TINYINT UNSIGNED NOT NULL,
    period_id INT NOT NULL,
    term VARCHAR(20) NOT NULL,
    academic_year VARCHAR(10) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES staff(staff_id) ON DELETE CASCADE,
    FOREIGN KEY (period_id) REFERENCES timetable_periods(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_class_slot (class_id, day_of_week, period_id, term, academic_year),
    UNIQUE KEY uniq_teacher_slot (teacher_id, day_of_week, period_id, term, academic_year)
) ENGINE=InnoDB;
