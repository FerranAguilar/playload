-- ═══════════════════════════════════════════════════════════════════
-- Migración 01 · pruebas cerradas y licencias
--
-- Ejecutar UNA vez sobre la base ya creada:
--   phpMyAdmin → elige tu base → pestaña SQL → pega esto → Continuar
--
-- Añade tres cosas:
--   · quién es administrador
--   · qué licencia tiene cada cuenta
--   · la lista de correos autorizados a registrarse
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

-- ── Cuentas: administrador y licencia ──────────────────────────────
ALTER TABLE users
  ADD COLUMN is_admin   TINYINT(1)  NOT NULL DEFAULT 0            AFTER two_factor,
  ADD COLUMN plan       VARCHAR(20) NOT NULL DEFAULT 'tester'     AFTER is_admin,
  ADD COLUMN plan_until DATE        DEFAULT NULL                  AFTER plan;

-- Valores posibles de `plan`:
--   tester         · acceso de prueba durante el periodo cerrado
--   rookie / amateur / pro          · planes de profesional independiente
--   club-amateur / club-pro         · planes de club
--   suspendido     · la cuenta existe pero no puede entrar

-- ── Correos autorizados a crear cuenta ─────────────────────────────
CREATE TABLE IF NOT EXISTS allowed_emails (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email         VARCHAR(190) NOT NULL,
  note          VARCHAR(160) NOT NULL DEFAULT '',
  plan          VARCHAR(20)  NOT NULL DEFAULT 'tester',
  invited_by    INT UNSIGNED DEFAULT NULL,
  registered_at DATETIME     DEFAULT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_allowed_email (email),
  KEY idx_allowed_by (invited_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Hazte administrador ────────────────────────────────────────────
-- Cambia el correo por el tuyo y ejecútalo. Sin esto, el panel de
-- administración te dirá que no tienes permiso.
--
--   UPDATE users SET is_admin = 1, plan = 'pro' WHERE email = 'tu@correo.com';
--
-- Si aún no tienes cuenta, primero autorízate a ti mismo:
--
--   INSERT INTO allowed_emails (email, note) VALUES ('tu@correo.com', 'Administrador');
--
-- luego crea la cuenta en registro.html y entonces ejecuta el UPDATE.
