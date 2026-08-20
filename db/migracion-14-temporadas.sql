-- ═══════════════════════════════════════════════════════════════════
-- Migración 14 · temporadas y sus periodos
--
-- Ejecutar UNA vez, después de la 13:
--   phpMyAdmin → tu base → pestaña SQL → pega esto → Continuar
--
-- `microcycles` ya existía (migración 03) pero sin nombre propio ni
-- pantalla: se le añade `name` para que el microciclo pueda llamarse
-- algo más que su número, y se ensancha `note` porque el objetivo de
-- la semana pide más que 160 caracteres.
--
-- La temporada es nueva: un equipo la divide en periodos (Pretemporada,
-- Liga, Playoffs…) y los microciclos ya existentes se encajan dentro
-- por fecha — no llevan una columna de periodo, se calcula al vuelo.
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

ALTER TABLE microcycles
  ADD COLUMN name VARCHAR(60) NOT NULL DEFAULT '' AFTER number,
  MODIFY COLUMN note VARCHAR(300) NOT NULL DEFAULT '';

-- ── Temporada: el curso completo de un equipo ──────────────────────
CREATE TABLE IF NOT EXISTS seasons (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  team_id    INT UNSIGNED NOT NULL,
  name       VARCHAR(40) NOT NULL,
  start_date DATE NOT NULL,
  end_date   DATE NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_seasons_team (team_id),
  CONSTRAINT fk_seasons_team FOREIGN KEY (team_id)
    REFERENCES teams (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Periodo (mesociclo) dentro de una temporada ────────────────────
CREATE TABLE IF NOT EXISTS season_periods (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  season_id  INT UNSIGNED NOT NULL,
  name       VARCHAR(60) NOT NULL,
  start_date DATE NOT NULL,
  end_date   DATE NOT NULL,
  color      VARCHAR(7) NOT NULL DEFAULT '#9184d9',
  sort       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_periods_season (season_id),
  CONSTRAINT fk_periods_season FOREIGN KEY (season_id)
    REFERENCES seasons (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
