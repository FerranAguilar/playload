-- ═══════════════════════════════════════════════════════════════════
-- PlayLoad · esquema de autenticación
-- MySQL / MariaDB (Hostinger). Importar desde hPanel → phpMyAdmin.
--
-- No incluye las tablas de negocio (sesiones de entrenamiento, cargas,
-- wellness): solo lo que necesita el acceso y el alta.
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ── Cuentas ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email           VARCHAR(190)  NOT NULL,
  -- NULL cuando la cuenta solo entra con Google
  password_hash   VARCHAR(255)  DEFAULT NULL,
  name            VARCHAR(120)  NOT NULL DEFAULT '',
  account_type    ENUM('pro','club') NOT NULL DEFAULT 'pro',
  -- rol profesional declarado en el alta: Entrenador, Preparador físico…
  role            VARCHAR(60)   NOT NULL DEFAULT '',
  google_sub      VARCHAR(64)   DEFAULT NULL,
  avatar_url      VARCHAR(255)  DEFAULT NULL,
  email_verified  TINYINT(1)    NOT NULL DEFAULT 0,
  two_factor      TINYINT(1)    NOT NULL DEFAULT 0,
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at   DATETIME      DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_users_email (email),
  UNIQUE KEY uniq_users_google (google_sub)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Clubes y equipos (lo mínimo para el selector de espacio) ────────
CREATE TABLE IF NOT EXISTS clubs (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(120) NOT NULL,
  city          VARCHAR(120) NOT NULL DEFAULT '',
  badge_url     VARCHAR(255) DEFAULT NULL,
  owner_user_id INT UNSIGNED NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_clubs_owner (owner_user_id),
  CONSTRAINT fk_clubs_owner FOREIGN KEY (owner_user_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teams (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- NULL = equipo propio de un profesional independiente
  club_id       INT UNSIGNED DEFAULT NULL,
  owner_user_id INT UNSIGNED NOT NULL,
  name          VARCHAR(120) NOT NULL,
  category      VARCHAR(120) NOT NULL DEFAULT '',
  modality      VARCHAR(40)  NOT NULL DEFAULT 'Fútbol 11',
  formation     VARCHAR(20)  NOT NULL DEFAULT '1-4-3-3',
  tint          CHAR(7)      NOT NULL DEFAULT '#9184d9',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_teams_club (club_id),
  KEY idx_teams_owner (owner_user_id),
  CONSTRAINT fk_teams_club  FOREIGN KEY (club_id)
    REFERENCES clubs (id) ON DELETE CASCADE,
  CONSTRAINT fk_teams_owner FOREIGN KEY (owner_user_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Un espacio al que la cuenta tiene acceso: el club entero, un equipo
-- concreto, o su propia cuenta profesional. Alimenta el paso «¿Dónde
-- quieres entrar?» del acceso.
CREATE TABLE IF NOT EXISTS memberships (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  scope_type  ENUM('club','team','personal') NOT NULL,
  scope_id    INT UNSIGNED DEFAULT NULL,
  role        VARCHAR(60)  NOT NULL DEFAULT '',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_membership (user_id, scope_type, scope_id),
  CONSTRAINT fk_memberships_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Seguridad del acceso ───────────────────────────────────────────

-- Recuperación de contraseña. Se guarda el hash del testigo, nunca el
-- testigo: si alguien lee la tabla no puede usar los enlaces.
CREATE TABLE IF NOT EXISTS password_resets (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  token_hash  CHAR(64)     NOT NULL,
  expires_at  DATETIME     NOT NULL,
  used_at     DATETIME     DEFAULT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_reset_token (token_hash),
  KEY idx_reset_user (user_id),
  CONSTRAINT fk_resets_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Códigos de verificación en dos pasos (se envían por correo).
CREATE TABLE IF NOT EXISTS login_codes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  code_hash   CHAR(64)     NOT NULL,
  expires_at  DATETIME     NOT NULL,
  attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  used_at     DATETIME     DEFAULT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_codes_user (user_id),
  CONSTRAINT fk_codes_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- «Mantener la sesión»: par selector + validador, con el validador
-- guardado en hash (patrón de cookie persistente de Barry Jaspan).
CREATE TABLE IF NOT EXISTS remember_tokens (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED NOT NULL,
  selector       CHAR(24)     NOT NULL,
  validator_hash CHAR(64)     NOT NULL,
  expires_at     DATETIME     NOT NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_remember_selector (selector),
  KEY idx_remember_user (user_id),
  CONSTRAINT fk_remember_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Intentos de acceso, para frenar la fuerza bruta y para auditoría.
CREATE TABLE IF NOT EXISTS login_attempts (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email      VARCHAR(190) NOT NULL DEFAULT '',
  ip         VARBINARY(16) DEFAULT NULL,
  ok         TINYINT(1)   NOT NULL DEFAULT 0,
  at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_attempts_email_at (email, at),
  KEY idx_attempts_ip_at (ip, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Acceso del jugador ─────────────────────────────────────────────
-- Entra con un código del club, sin contraseña, como promete la portada.
CREATE TABLE IF NOT EXISTS players (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  team_id    INT UNSIGNED NOT NULL,
  name       VARCHAR(120) NOT NULL,
  dorsal     SMALLINT UNSIGNED DEFAULT NULL,
  position   VARCHAR(10)  NOT NULL DEFAULT '',
  access_code VARCHAR(12) NOT NULL,
  active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_player_code (access_code),
  KEY idx_players_team (team_id),
  CONSTRAINT fk_players_team FOREIGN KEY (team_id)
    REFERENCES teams (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
