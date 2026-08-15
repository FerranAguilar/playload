-- ═══════════════════════════════════════════════════════════════════
-- Migración 04 · preferencias de la cuenta
--
-- Ejecutar UNA vez, después de la 03:
--   phpMyAdmin → tu base → pestaña SQL → pega esto → Continuar
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

ALTER TABLE users
  ADD COLUMN locale VARCHAR(5)  NOT NULL DEFAULT 'es'      AFTER role,
  ADD COLUMN theme  VARCHAR(10) NOT NULL DEFAULT 'sistema' AFTER locale;

-- locale: es · ca · en
-- theme : sistema · claro · oscuro
