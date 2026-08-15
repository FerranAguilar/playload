-- ═══════════════════════════════════════════════════════════════════
-- Migración 03 · microciclos, sesiones y carga
--
-- Ejecutar UNA vez, después de la 02:
--   phpMyAdmin → tu base → pestaña SQL → pega esto → Continuar
--
-- Con esto el panel deja de estar vacío por falta de tablas: ya hay
-- dónde guardar lo que se planifica y lo que de verdad pasa.
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

-- ── Microciclo: la semana de trabajo ───────────────────────────────
CREATE TABLE IF NOT EXISTS microcycles (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  team_id    INT UNSIGNED NOT NULL,
  number     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  start_date DATE NOT NULL,
  end_date   DATE NOT NULL,
  note       VARCHAR(160) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_micro (team_id, start_date),
  CONSTRAINT fk_micro_team FOREIGN KEY (team_id)
    REFERENCES teams (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Sesión ─────────────────────────────────────────────────────────
-- La carga se guarda en unidades arbitrarias (sRPE × minutos). Se
-- guardan las dos: la prevista al planificar y la real al cerrarla,
-- porque comparar ambas es justo lo que vende el producto.
CREATE TABLE IF NOT EXISTS sessions (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  team_id       INT UNSIGNED NOT NULL,
  microcycle_id INT UNSIGNED DEFAULT NULL,
  date          DATE NOT NULL,
  time          TIME DEFAULT NULL,
  title         VARCHAR(120) NOT NULL DEFAULT '',
  kind          ENUM('entrenamiento','partido','recuperacion','descanso')
                NOT NULL DEFAULT 'entrenamiento',
  md_label      VARCHAR(8)  NOT NULL DEFAULT '',   -- MD-3, MD+1, MD…
  place         VARCHAR(80) NOT NULL DEFAULT '',
  duration_min  SMALLINT UNSIGNED NOT NULL DEFAULT 90,
  planned_rpe   TINYINT UNSIGNED DEFAULT NULL,     -- 1 a 10
  planned_load  INT UNSIGNED DEFAULT NULL,
  actual_rpe    DECIMAL(3,1) DEFAULT NULL,
  actual_load   INT UNSIGNED DEFAULT NULL,
  status        ENUM('planificada','realizada','cancelada')
                NOT NULL DEFAULT 'planificada',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sessions_team_date (team_id, date),
  KEY idx_sessions_micro (microcycle_id),
  CONSTRAINT fk_sessions_team FOREIGN KEY (team_id)
    REFERENCES teams (id) ON DELETE CASCADE,
  CONSTRAINT fk_sessions_micro FOREIGN KEY (microcycle_id)
    REFERENCES microcycles (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Bloques de la sesión ───────────────────────────────────────────
-- Todavía sin pantalla propia, pero la tabla ya está para cuando
-- llegue el diseño de sesiones por bloques.
CREATE TABLE IF NOT EXISTS session_blocks (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id INT UNSIGNED NOT NULL,
  name       VARCHAR(120) NOT NULL,
  minutes    SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  intensity  TINYINT UNSIGNED DEFAULT NULL,
  sort       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_blocks_session (session_id),
  CONSTRAINT fk_blocks_session FOREIGN KEY (session_id)
    REFERENCES sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── RPE por jugador ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS rpe_entries (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id INT UNSIGNED NOT NULL,
  player_id  INT UNSIGNED NOT NULL,
  rpe        TINYINT UNSIGNED NOT NULL,
  minutes    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  load       INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_rpe (session_id, player_id),
  KEY idx_rpe_player (player_id),
  CONSTRAINT fk_rpe_session FOREIGN KEY (session_id)
    REFERENCES sessions (id) ON DELETE CASCADE,
  CONSTRAINT fk_rpe_player FOREIGN KEY (player_id)
    REFERENCES players (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Wellness diario ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS wellness_entries (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  player_id  INT UNSIGNED NOT NULL,
  date       DATE NOT NULL,
  sleep      TINYINT UNSIGNED NOT NULL,   -- 1 a 5
  fatigue    TINYINT UNSIGNED NOT NULL,
  soreness   TINYINT UNSIGNED NOT NULL,
  stress     TINYINT UNSIGNED NOT NULL,
  score      DECIMAL(3,2) NOT NULL,       -- media de los cuatro
  note       VARCHAR(200) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_wellness (player_id, date),
  KEY idx_wellness_date (date),
  CONSTRAINT fk_wellness_player FOREIGN KEY (player_id)
    REFERENCES players (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
