-- ═══════════════════════════════════════════════════════════════════
-- Migración 05 · staff de club por correo
--
-- Ejecutar UNA vez, después de la 04:
--   phpMyAdmin → tu base → pestaña SQL → pega esto → Continuar
--
-- Hasta ahora un equipo solo lo veía quien lo creó o el dueño de su
-- club. Con esto el club puede dar acceso a un entrenador escribiendo
-- su correo, tenga cuenta o no.
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

-- ── Quién trabaja en qué equipo ────────────────────────────────────
-- La fila se crea con el correo y nada más: `user_id` se rellena solo
-- cuando esa persona entra por primera vez. Así el club puede repartir
-- su plantilla técnica antes de que nadie se haya dado de alta, que es
-- justo como se organiza un club en julio.
--
-- `club_id` está de más si se mira solo el equipo, pero contar las
-- licencias gastadas es la consulta más frecuente de la pantalla del
-- club y con esta columna sale sin unir con `teams`.
CREATE TABLE IF NOT EXISTS team_staff (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  club_id    INT UNSIGNED NOT NULL,
  team_id    INT UNSIGNED NOT NULL,
  email      VARCHAR(190) NOT NULL,
  user_id    INT UNSIGNED DEFAULT NULL,
  name       VARCHAR(120) NOT NULL DEFAULT '',
  role       VARCHAR(60)  NOT NULL DEFAULT 'Entrenador',
  -- invitado = todavía no ha entrado nunca con ese correo
  status     ENUM('invitado','activo') NOT NULL DEFAULT 'invitado',
  invited_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  linked_at  DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  -- La misma persona no puede estar dos veces en el mismo equipo, pero
  -- sí en varios equipos: cada pareja gasta una licencia del club.
  UNIQUE KEY uniq_staff_team_email (team_id, email),
  KEY idx_staff_club (club_id),
  KEY idx_staff_email (email),
  KEY idx_staff_user (user_id),
  CONSTRAINT fk_staff_club FOREIGN KEY (club_id)
    REFERENCES clubs (id) ON DELETE CASCADE,
  CONSTRAINT fk_staff_team FOREIGN KEY (team_id)
    REFERENCES teams (id) ON DELETE CASCADE,
  -- Si se borra la cuenta, la invitación sigue viva: el club le dio
  -- acceso a un correo, no a una cuenta concreta.
  CONSTRAINT fk_staff_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
