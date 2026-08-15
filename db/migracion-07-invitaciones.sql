-- ═══════════════════════════════════════════════════════════════════
-- Migración 07 · enlace de invitación por correo
--
-- Ejecutar UNA vez, después de la 06:
--   phpMyAdmin → tu base → pestaña SQL → pega esto → Continuar
--
-- Hasta ahora meter un correo en la lista de acceso no avisaba a nadie:
-- el administrador tenía que escribir por su cuenta. Con esto la
-- invitación sale por correo con un enlace, y ese enlace además prueba
-- que quien lo abre tiene ese buzón.
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

-- Se guarda el HASH del testigo, nunca el testigo, igual que en
-- `password_resets`: quien lea esta tabla no puede usar los enlaces.
ALTER TABLE allowed_emails
  ADD COLUMN token_hash    CHAR(64) DEFAULT NULL AFTER plan,
  ADD COLUMN token_expires DATETIME DEFAULT NULL AFTER token_hash,
  ADD COLUMN sent_at       DATETIME DEFAULT NULL AFTER token_expires,
  ADD UNIQUE KEY uniq_allowed_token (token_hash);

-- Las invitaciones que ya existían se quedan sin enlace: siguen valiendo
-- para registrarse a mano, y desde el panel se les puede reenviar uno.
