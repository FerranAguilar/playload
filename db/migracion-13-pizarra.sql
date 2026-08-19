-- ═══════════════════════════════════════════════════════════════════
-- Migración 13 · pizarra táctica en la biblioteca de ejercicios
--
-- Ejecutar UNA vez, después de la 12:
--   phpMyAdmin → tu base → pestaña SQL → pega esto → Continuar
--
-- El dibujo de la pizarra (conos, picas, jugadores, balones, porterías,
-- flechas, zonas y texto) se guarda como el JSON del array de piezas,
-- en % del lienzo para que no dependa de resolución. NULL = sin dibujar.
-- IF NOT EXISTS para poder relanzarla sin miedo si ya se aplicó a medias.
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

ALTER TABLE exercises
  ADD COLUMN IF NOT EXISTS diagram MEDIUMTEXT DEFAULT NULL AFTER space;
