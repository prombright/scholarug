-- ============================================================
-- SCHOLAR — STUDENT LEADERSHIP ELECTIONS
-- ============================================================
-- Students self-nominate for a position (election_candidates, status
-- Pending), a school admin reviews/approves or rejects each application
-- after an offline committee vetting process, and the school votes
-- anonymously during a scheduled window (elections.opens_at/closes_at).
--
-- ANONYMITY DESIGN: election_ballots_cast (who voted) and election_votes
-- (what was voted) are deliberately TWO SEPARATE TABLES. election_votes
-- has NO student_id column and NO foreign key to students at all -- not
-- "the app doesn't query it that way", but "the join key doesn't exist in
-- this schema". election_ballots_cast is what prevents double-voting
-- (UNIQUE per position+student) and lets an admin see live turnout
-- ("14 of 312 voted") without ever seeing what anyone voted for.
--
-- HONEST CAVEAT: this gives strong practical/organizational anonymity --
-- no admin, teacher, or ordinary query can look up "who voted for whom",
-- because the column to do that with doesn't exist. It is NOT
-- cryptographically unlinkable -- someone with raw DB access and precise
-- timestamps could theoretically attempt timing correlation in a very
-- small election. Mitigated in the app layer by casting a student's
-- multiple per-position votes in shuffled order, and by never surfacing
-- raw election_votes rows/timestamps in any UI (aggregated counts only).
--
-- election_candidates.student_id is ON DELETE SET NULL (not CASCADE), with
-- a denormalized candidate_name snapshot taken at application time -- a
-- candidate row and its accumulated votes are a historical record the
-- moment voting opens; they must not evaporate or silently change if the
-- student's own record is later edited or deleted.
--
-- Run against the `scholar` database:
--   mysql -u root scholar < elections.sql
-- Nothing here pre-exists, so plain CREATE TABLE IF NOT EXISTS is enough
-- (no ALTER-guard gymnastics needed) -- still safe to re-run.
-- ============================================================

USE scholar;

CREATE TABLE IF NOT EXISTS elections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    term VARCHAR(20) NOT NULL,
    year VARCHAR(10) NOT NULL,
    status ENUM('Draft','Published') NOT NULL DEFAULT 'Draft',
    opens_at DATETIME NOT NULL,
    closes_at DATETIME NOT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_elec_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_elec_creator FOREIGN KEY (created_by) REFERENCES staff(staff_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS election_positions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    election_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    display_order INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_pos_election FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS election_candidates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    position_id INT NOT NULL,
    student_id INT NULL,
    candidate_name VARCHAR(150) NOT NULL,
    manifesto TEXT NULL,
    photo_path VARCHAR(255) NULL,
    status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    reviewed_by INT NULL,
    reviewed_at TIMESTAMP NULL,
    display_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cand_position FOREIGN KEY (position_id) REFERENCES election_positions(id) ON DELETE CASCADE,
    CONSTRAINT fk_cand_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL,
    CONSTRAINT fk_cand_reviewer FOREIGN KEY (reviewed_by) REFERENCES staff(staff_id) ON DELETE SET NULL,
    UNIQUE KEY uniq_position_student (position_id, student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- "Has this student voted for this position" ledger only -- NEVER joined
-- to election_votes, and carries no candidate/choice data whatsoever.
CREATE TABLE IF NOT EXISTS election_ballots_cast (
    id INT AUTO_INCREMENT PRIMARY KEY,
    position_id INT NOT NULL,
    student_id INT NOT NULL,
    cast_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ballot_position FOREIGN KEY (position_id) REFERENCES election_positions(id) ON DELETE CASCADE,
    CONSTRAINT fk_ballot_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_position_student (position_id, student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The tally. Deliberately has NO student_id column -- there is no query
-- path in this schema that can join a vote back to the voter who cast it.
CREATE TABLE IF NOT EXISTS election_votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    position_id INT NOT NULL,
    candidate_id INT NOT NULL,
    cast_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_vote_position FOREIGN KEY (position_id) REFERENCES election_positions(id) ON DELETE CASCADE,
    CONSTRAINT fk_vote_candidate FOREIGN KEY (candidate_id) REFERENCES election_candidates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
