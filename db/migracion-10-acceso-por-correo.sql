-- ═══════════════════════════════════════════════════════════════════
-- Migración 10 · el jugador entra por correo, no por código
--
-- Ejecutar UNA vez, después de la 09:
--   phpMyAdmin → tu base → pestaña SQL → pega esto → Continuar
--
-- El código de siete caracteres se podía dictar por teléfono, pero
-- también se podía apuntar mal, perder o pasar de mano en mano sin que
-- nadie se enterara. Se sustituye por un enlace de un solo destinatario:
-- el correo que ya tiene la ficha del jugador. El enlace no caduca —es
-- el mismo que antes hacía el código, para poder guardarlo en la
-- pantalla de inicio del móvil y que siga sirviendo— pero cambia en
-- cuanto se manda una invitación nueva, así que un enlace reenviado dos
-- veces solo deja vivo el último.
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

ALTER TABLE players
  DROP COLUMN access_code,
  ADD COLUMN login_token_hash CHAR(64) DEFAULT NULL AFTER registered_at,
  ADD UNIQUE KEY uniq_player_token (login_token_hash);
