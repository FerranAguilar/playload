-- ═══════════════════════════════════════════════════════════════════
-- Migración 15 · tests físicos: bloque nuevo y hoja de cálculo
--
-- Ejecutar UNA vez, después de la 14:
--   phpMyAdmin → tu base → pestaña SQL → pega esto → Continuar
--
-- Un test físico es un ejercicio más (mismo block_type, mismo
-- owner_user_id, misma tabla `exercises`) al que se le añaden dos
-- cosas: la cualidad que valora (quality_label/quality_color) y, en
-- tablas propias enganchadas por exercise_id, sus columnas de la
-- hoja (test_columns), sus parámetros globales como la altura de un
-- cajón (test_parameters), y los resultados que se van guardando
-- cada vez que se pasa (test_results / test_result_values).
--
-- Los valores de un resultado se guardan por clave de columna
-- (col_key), no por el id de test_columns: así, si más adelante se
-- borra o se renombra una columna, los resultados ya guardados no
-- pierden su referencia.
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

-- ── Bloque "Tests" en el catálogo fijo de sesiones y biblioteca ────
ALTER TABLE session_blocks
  MODIFY COLUMN block_type ENUM('activacion','fisico','tecnico','tactico','posesion',
                      'estrategia','competicion','charla','vuelta_calma','tests','otro')
             NOT NULL DEFAULT 'otro';

ALTER TABLE exercises
  MODIFY COLUMN block_type ENUM('activacion','fisico','tecnico','tactico','posesion',
                      'estrategia','competicion','charla','vuelta_calma','tests','otro')
                NOT NULL DEFAULT 'otro';

-- ── La cualidad que valora el test (solo tiene sentido si es uno) ──
ALTER TABLE exercises
  ADD COLUMN IF NOT EXISTS quality_label VARCHAR(80) NOT NULL DEFAULT '' AFTER diagram,
  ADD COLUMN IF NOT EXISTS quality_color CHAR(7) DEFAULT NULL AFTER quality_label;

-- ── Parámetros globales del test (altura del cajón, etc.) ──────────
CREATE TABLE IF NOT EXISTS test_parameters (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  exercise_id INT UNSIGNED NOT NULL,
  col_key     VARCHAR(60) NOT NULL,
  label       VARCHAR(120) NOT NULL,
  value       DECIMAL(10,3) NOT NULL DEFAULT 0,
  sort        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_testparam_exercise (exercise_id),
  CONSTRAINT fk_testparam_exercise FOREIGN KEY (exercise_id)
    REFERENCES exercises (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Columnas de la hoja: de entrada, o calculadas con fórmula ──────
CREATE TABLE IF NOT EXISTS test_columns (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  exercise_id INT UNSIGNED NOT NULL,
  col_key     VARCHAR(60) NOT NULL,
  label       VARCHAR(120) NOT NULL,
  type        ENUM('entrada','formula') NOT NULL DEFAULT 'entrada',
  formula     VARCHAR(300) DEFAULT NULL,
  sort        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_testcol_exercise (exercise_id),
  CONSTRAINT fk_testcol_exercise FOREIGN KEY (exercise_id)
    REFERENCES exercises (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Cada vez que se pasa el test a la plantilla ─────────────────────
CREATE TABLE IF NOT EXISTS test_results (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  exercise_id INT UNSIGNED NOT NULL,
  team_id     INT UNSIGNED NOT NULL,
  date        DATE NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_testresult_exercise (exercise_id, date),
  CONSTRAINT fk_testresult_exercise FOREIGN KEY (exercise_id)
    REFERENCES exercises (id) ON DELETE CASCADE,
  CONSTRAINT fk_testresult_team FOREIGN KEY (team_id)
    REFERENCES teams (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── El valor de cada jugador, en cada columna, de cada resultado ───
-- El índice (player_id, col_key) es el que hará falta el día que la
-- ficha del jugador enseñe la progresión de un test en el tiempo.
CREATE TABLE IF NOT EXISTS test_result_values (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  result_id  INT UNSIGNED NOT NULL,
  player_id  INT UNSIGNED NOT NULL,
  col_key    VARCHAR(60) NOT NULL,
  value      DECIMAL(10,3) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_testvalor (result_id, player_id, col_key),
  KEY idx_testvalor_player (player_id, col_key),
  CONSTRAINT fk_testvalor_result FOREIGN KEY (result_id)
    REFERENCES test_results (id) ON DELETE CASCADE,
  CONSTRAINT fk_testvalor_player FOREIGN KEY (player_id)
    REFERENCES players (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
