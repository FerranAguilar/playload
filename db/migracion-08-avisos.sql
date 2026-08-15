-- ═══════════════════════════════════════════════════════════════════
-- Migración 08 · avisos del staff al club
--
-- Ejecutar UNA vez, después de la 07:
--   phpMyAdmin → tu base → pestaña SQL → pega esto → Continuar
--
-- El club ve los calendarios de sus equipos, pero un calendario no
-- cuenta que el campo estaba encharcado o que el central se ha roto.
-- Esto es el canal corto para eso: el entrenador escribe desde su
-- panel y el club lo encuentra al entrar.
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS club_messages (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  club_id    INT UNSIGNED NOT NULL,
  team_id    INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED DEFAULT NULL,
  -- Copia del nombre de quien escribe. Se guarda aquí a propósito: si
  -- esa persona deja el club y se borra su cuenta, el aviso sigue
  -- diciendo quién lo mandó, que es la mitad de su valor.
  author     VARCHAR(120) NOT NULL DEFAULT '',
  kind       ENUM('aviso','incidencia','material') NOT NULL DEFAULT 'aviso',
  body       VARCHAR(600) NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- NULL = el club todavía no lo ha dado por leído
  read_at    DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_msg_club (club_id, created_at),
  KEY idx_msg_sin_leer (club_id, read_at),
  CONSTRAINT fk_msg_club FOREIGN KEY (club_id)
    REFERENCES clubs (id) ON DELETE CASCADE,
  CONSTRAINT fk_msg_team FOREIGN KEY (team_id)
    REFERENCES teams (id) ON DELETE CASCADE,
  CONSTRAINT fk_msg_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
