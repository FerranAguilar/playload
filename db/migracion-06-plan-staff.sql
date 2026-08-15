-- ═══════════════════════════════════════════════════════════════════
-- Migración 06 · el plan de quien entra invitado por un club
--
-- Ejecutar UNA vez, después de la 05:
--   phpMyAdmin → tu base → pestaña SQL → pega esto → Continuar
--
-- Sin esto, quien se daba de alta porque un club le había dado una
-- plaza nacía como `tester`, que durante las pruebas no tiene techo:
-- le regalábamos equipos propios ilimitados a alguien a quien solo se
-- le había dado acceso a los de un club.
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

-- `teams = '0'` es el punto entero de este plan: puede trabajar en los
-- equipos que le haya dado el club, y no puede crear ninguno suyo. Si
-- algún día quiere los suyos, paga una licencia y los del club siguen
-- sin contarle (lo garantiza team_count(), que mira `owner_user_id`).
--
-- `active = 0` lo deja fuera de precios.html: no es un plan que nadie
-- compre, es el estado en el que nace una cuenta invitada.
INSERT INTO plans
  (id, track, name, tagline, price_m, price_y, teams, players, staff, feats, best, active, sort)
VALUES
  ('staff', 'pro', 'Staff de club',
   'Acceso a los equipos que te ha dado un club, sin equipos propios.',
   NULL, NULL, '0', '30', 'Solo tú',
   'Todas las funciones en los equipos del club\nCalendario, sesiones y control de carga\nLa licencia la paga el club',
   0, 0, 9)
ON DUPLICATE KEY UPDATE
  name    = VALUES(name),
  tagline = VALUES(tagline),
  teams   = VALUES(teams),
  active  = VALUES(active);
