-- ═══════════════════════════════════════════════════════════════════
-- Migración 09 · ficha del jugador y su invitación
--
-- Ejecutar UNA vez, después de la 08:
--   phpMyAdmin → tu base → pestaña SQL → pega esto → Continuar
--
-- Hasta ahora un jugador era nombre, dorsal, posición y su código de
-- acceso. Con esto gana una ficha completa —nacimiento, correo, posición
-- alternativa, pie, comentarios del staff— y la posibilidad de que se le
-- invite por correo. El nombre sigue siendo el único campo obligatorio.
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

ALTER TABLE players
  ADD COLUMN birth_date DATE DEFAULT NULL AFTER position,
  ADD COLUMN position_alt VARCHAR(10) NOT NULL DEFAULT '' AFTER birth_date,
  -- Sin ENUM en pie ni en posición alternativa por la misma razón que ya
  -- vale para `position`: son iniciales que pone la pantalla, no un
  -- catálogo cerrado que convenga imponer en la base.
  ADD COLUMN foot VARCHAR(12) NOT NULL DEFAULT '' AFTER position_alt,
  ADD COLUMN email VARCHAR(190) DEFAULT NULL AFTER foot,
  -- Solo la lee el cuerpo técnico y el club: nunca sale en ninguna
  -- respuesta que pueda llegar a una pantalla de jugador.
  ADD COLUMN notes VARCHAR(500) NOT NULL DEFAULT '' AFTER email,
  -- `sin_invitar` = no se le ha mandado nada todavía.
  -- `invitado`    = tiene un correo de acceso esperando.
  -- `registrado`  = ya ha entrado alguna vez con su código, se lo haya
  --                 mandado un correo o se lo haya dictado el
  --                 entrenador de viva voz: registrarse es usar el
  --                 código, no abrir un enlace concreto.
  ADD COLUMN invite_status ENUM('sin_invitar','invitado','registrado')
             NOT NULL DEFAULT 'sin_invitar' AFTER notes,
  ADD COLUMN invited_at DATETIME DEFAULT NULL AFTER invite_status,
  ADD COLUMN registered_at DATETIME DEFAULT NULL AFTER invited_at;
