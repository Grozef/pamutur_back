-- PMU Database Schema
-- Drop existing tables if they exist
DROP TABLE IF EXISTS performances;
DROP TABLE IF EXISTS races;
DROP TABLE IF EXISTS horses;
DROP TABLE IF EXISTS trainers;
DROP TABLE IF EXISTS jockeys;

-- Create Jockeys table
CREATE TABLE jockeys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create Trainers table
CREATE TABLE trainers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create Horses table with genealogy
CREATE TABLE horses (
    id_cheval_pmu VARCHAR(255) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    sex ENUM('MALES', 'FEMELLES', 'HONGRES') NULL,
    age INT NULL,
    father_id VARCHAR(255) NULL,
    mother_id VARCHAR(255) NULL,
    dam_sire_name VARCHAR(255) NULL,
    breed VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (father_id) REFERENCES horses(id_cheval_pmu) ON DELETE SET NULL,
    FOREIGN KEY (mother_id) REFERENCES horses(id_cheval_pmu) ON DELETE SET NULL,
    INDEX idx_name (name),
    INDEX idx_father (father_id),
    INDEX idx_mother (mother_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create Races table
CREATE TABLE races (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    race_date DATETIME NOT NULL,
    hippodrome VARCHAR(255) NULL,
    distance INT NULL,
    discipline VARCHAR(50) NULL,
    track_condition VARCHAR(100) NULL,
    race_code VARCHAR(50) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_race_date_hippodrome (race_date, hippodrome),
    INDEX idx_race_code (race_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create Performances table
CREATE TABLE performances (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    horse_id VARCHAR(255) NOT NULL,
    race_id BIGINT UNSIGNED NOT NULL,
    jockey_id BIGINT UNSIGNED NULL,
    trainer_id BIGINT UNSIGNED NULL,
    rank INT NULL COMMENT '0 for DNF/Disqualified',
    weight INT NULL COMMENT 'Weight in grams',
    draw INT NULL COMMENT 'Starting position (placeCorde)',
    raw_musique TEXT NULL,
    odds_ref FLOAT NULL,
    gains_race INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (horse_id) REFERENCES horses(id_cheval_pmu) ON DELETE CASCADE,
    FOREIGN KEY (race_id) REFERENCES races(id) ON DELETE CASCADE,
    FOREIGN KEY (jockey_id) REFERENCES jockeys(id) ON DELETE SET NULL,
    FOREIGN KEY (trainer_id) REFERENCES trainers(id) ON DELETE SET NULL,
    INDEX idx_horse_race (horse_id, race_id),
    INDEX idx_jockey (jockey_id),
    INDEX idx_trainer (trainer_id),
    INDEX idx_rank (rank)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create indexes for performance optimization
CREATE INDEX idx_performances_horse_created ON performances(horse_id, created_at);
CREATE INDEX idx_races_date ON races(race_date);
CREATE INDEX idx_performances_jockey_trainer ON performances(jockey_id, trainer_id);

-- Show created tables
SHOW TABLES;
