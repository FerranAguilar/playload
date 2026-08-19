-- ═══════════════════════════════════════════════════════════════════
-- Migración 12 · sesiones visuales: color por tipo, bloques con
-- catálogo fijo y biblioteca de ejercicios
--
-- Ejecutar UNA vez, después de la 11:
--   phpMyAdmin → tu base → pestaña SQL → pega esto → Continuar
--
-- La sesión pasa de ser una fila plana a montarse con color, tipo de
-- bloque, espacio y ejercicios. El color de sesión se elige y se
-- recuerda por tipo (session_type_colors); el de bloque es un
-- catálogo fijo que no hace falta guardar en ningún sitio, así que
-- solo se guarda el tipo, no el color. La biblioteca de ejercicios es
-- del usuario, no del equipo: la comparten todos los equipos que
-- gestiona.
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

-- ── Sesión: tipo ampliado y color propio ────────────────────────────
-- `color` en NULL significa "hereda el color de su tipo"; en cuanto el
-- usuario elige uno para esta sesión concreta, deja de ser NULL y ya
-- no le afecta que cambie el color por defecto del tipo.
--
-- Separado en dos ALTER e IF NOT EXISTS en la columna: así, si la
-- migración se ha ejecutado a medias antes, volver a lanzarla entera
-- no falla por lo que ya estuviera hecho.
ALTER TABLE sessions
  MODIFY COLUMN kind ENUM('entrenamiento','partido','charla','recuperacion','descanso','otro')
                NOT NULL DEFAULT 'entrenamiento';

ALTER TABLE sessions
  ADD COLUMN IF NOT EXISTS color CHAR(7) DEFAULT NULL AFTER kind;

-- ── Color por defecto de cada tipo de sesión, por equipo ────────────
CREATE TABLE IF NOT EXISTS session_type_colors (
  team_id INT UNSIGNED NOT NULL,
  kind    ENUM('entrenamiento','partido','charla','recuperacion','descanso','otro') NOT NULL,
  color   CHAR(7) NOT NULL,
  PRIMARY KEY (team_id, kind),
  CONSTRAINT fk_stc_team FOREIGN KEY (team_id)
    REFERENCES teams (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Bloques: tipo y espacio, catálogo fijo ──────────────────────────
-- '' en location significa "sin definir": no todos los bloques
-- necesitan decir dónde se hacen (una charla, por ejemplo). Por eso va
-- como miembro explícito del ENUM: un DEFAULT que no está en la lista
-- de valores no es válido en modo estricto.
ALTER TABLE session_blocks
  ADD COLUMN IF NOT EXISTS block_type ENUM('activacion','fisico','tecnico','tactico','posesion',
                              'estrategia','competicion','charla','vuelta_calma','otro')
             NOT NULL DEFAULT 'otro' AFTER name,
  ADD COLUMN IF NOT EXISTS location   ENUM('','campo','gimnasio','sala','piscina','exterior','otro')
             NOT NULL DEFAULT '' AFTER block_type;

-- ── Biblioteca de ejercicios ─────────────────────────────────────────
-- Del usuario, no del equipo: un mismo entrenador con varios equipos
-- ve y reutiliza los mismos ejercicios en todos.
CREATE TABLE IF NOT EXISTS exercises (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_user_id INT UNSIGNED NOT NULL,
  name          VARCHAR(120) NOT NULL,
  block_type    ENUM('activacion','fisico','tecnico','tactico','posesion',
                      'estrategia','competicion','charla','vuelta_calma','otro')
                NOT NULL DEFAULT 'otro',
  description   VARCHAR(400) NOT NULL DEFAULT '',
  materials     VARCHAR(200) NOT NULL DEFAULT '',
  duration_min  SMALLINT UNSIGNED DEFAULT NULL,
  intensity     TINYINT UNSIGNED DEFAULT NULL,
  space         ENUM('','campo','gimnasio','sala','piscina','exterior','otro')
                NOT NULL DEFAULT '',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_exercises_owner (owner_user_id, block_type),
  CONSTRAINT fk_exercises_owner FOREIGN KEY (owner_user_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Ejercicios enganchados a un bloque de una sesión concreta ───────
-- `name` va copiado: si el ejercicio se borra de la biblioteca, el
-- bloque no se queda con un hueco en blanco.
CREATE TABLE IF NOT EXISTS block_exercises (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  block_id    INT UNSIGNED NOT NULL,
  exercise_id INT UNSIGNED DEFAULT NULL,
  name        VARCHAR(120) NOT NULL,
  notes       VARCHAR(240) NOT NULL DEFAULT '',
  sort        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_be_block (block_id),
  CONSTRAINT fk_be_block FOREIGN KEY (block_id)
    REFERENCES session_blocks (id) ON DELETE CASCADE,
  CONSTRAINT fk_be_exercise FOREIGN KEY (exercise_id)
    REFERENCES exercises (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
