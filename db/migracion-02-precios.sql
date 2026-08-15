-- ═══════════════════════════════════════════════════════════════════
-- Migración 02 · precios y disponibilidad gestionables
--
-- Ejecutar UNA vez, después de la migración 01:
--   phpMyAdmin → tu base → pestaña SQL → pega esto → Continuar
--
-- Saca los planes del HTML y los mete en la base, para que el panel de
-- administración pueda cambiar precios y abrir o cerrar el registro sin
-- tocar código.
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

-- ── Ajustes generales ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS settings (
  k          VARCHAR(50)  NOT NULL,
  v          VARCHAR(255) NOT NULL DEFAULT '',
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
             ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (k, v) VALUES
  ('registro_abierto', '0'),   -- 0 = solo invitados · 1 = abierto a cualquiera
  ('precios_publicos', '0')    -- 0 = «Por determinar» · 1 = se muestran
ON DUPLICATE KEY UPDATE k = k;

-- ── Planes ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS plans (
  id       VARCHAR(20)  NOT NULL,
  track    ENUM('pro','club') NOT NULL,
  name     VARCHAR(60)  NOT NULL,
  tagline  VARCHAR(200) NOT NULL DEFAULT '',
  -- NULL = por determinar, aunque los precios estén publicados
  price_m  DECIMAL(8,2) DEFAULT NULL,
  price_y  DECIMAL(8,2) DEFAULT NULL,
  teams    VARCHAR(20)  NOT NULL DEFAULT '1',
  players  VARCHAR(20)  NOT NULL DEFAULT '30',
  staff    VARCHAR(60)  NOT NULL DEFAULT 'Solo tú',
  feats    TEXT,                       -- una ventaja por línea
  best     TINYINT(1)   NOT NULL DEFAULT 0,
  active   TINYINT(1)   NOT NULL DEFAULT 1,
  sort     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_plans_track (track, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO plans (id, track, name, tagline, price_m, price_y, teams, players, staff, feats, best, active, sort) VALUES
('rookie', 'pro', 'Rookie',
 'Para quien lleva un equipo y quiere dejar la hoja de cálculo.',
 NULL, NULL, '1', '30', 'Solo tú',
 'Todos los módulos, sin recortes\nInforme del microciclo en PDF\nIntegraciones de GPS y wearables\nSoporte por correo',
 0, 1, 1),

('amateur', 'pro', 'Amateur',
 'Para quien compagina varias categorías o varios clubes.',
 NULL, NULL, '5', '45', 'Solo tú',
 'Todo lo de Rookie\nPlantillas de temporada reutilizables entre equipos\nComparativa entre tus equipos\nSoporte prioritario',
 1, 1, 2),

('pro', 'pro', 'Pro',
 'Para quien vive de esto y no quiere pensar en límites.',
 NULL, NULL, '∞', '∞', 'Solo tú',
 'Todo lo de Amateur\nAPI abierta y webhooks\nHistórico completo de temporadas\nSoporte con preparador físico',
 0, 1, 3),

('club-amateur', 'club', 'Amateur',
 'Para clubes que empiezan a ordenar sus categorías.',
 NULL, NULL, '5', '30', '3 miembros del staff',
 'Panel con todas las categorías\nRoles y permisos por miembro\nInforme del club para dirección deportiva\nFacturación única',
 0, 1, 1),

('club-pro', 'club', 'Pro',
 'Para el club entero, del alevín al primer equipo.',
 NULL, NULL, '∞', '∞', '10 miembros del staff',
 'Todo lo de Amateur\nComparativa entre categorías\nAPI abierta y webhooks\nOnboarding con el cuerpo técnico',
 1, 1, 2)

ON DUPLICATE KEY UPDATE id = id;
