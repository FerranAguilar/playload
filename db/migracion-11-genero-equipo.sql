-- ═══════════════════════════════════════════════════════════════════
-- Migración 11 · género del equipo
--
-- Ejecutar UNA vez, después de la 10:
--   phpMyAdmin → tu base → pestaña SQL → pega esto → Continuar
--
-- Sin ENUM, por la misma razón que `position` o `foot`: masculino,
-- femenino o mixto los pone la pantalla, no hace falta imponer un
-- catálogo cerrado en la base para tres valores que además podrían
-- ganar alguno más el día de mañana.
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

ALTER TABLE teams
  ADD COLUMN gender VARCHAR(12) NOT NULL DEFAULT '' AFTER category;
