-- =====================================================================
--  PASO 2 — REVERTIR LA ACTIVACIÓN MASIVA DE CICLO
-- =====================================================================
--  CUÁNDO: ejecutar SOLO si la activación salió mal y quieres deshacerla.
--  REQUISITO: haber corrido antes "1_respaldo_antes_de_activar.sql"
--             (necesita la tabla usr_respaldo_activacion).
--
--  QUÉ HACE:
--    1. Borra las boletas (calificaciones + observaciones) generadas
--       para el ciclo que activaste.
--    2. Restaura en cada alumno su grado, estatus, activo, ciclo y
--       contraseña tal como estaban ANTES de activar.
-- =====================================================================

-- ⚙️  AJUSTA aquí el id del ciclo que activaste.
--     2024-2025 = 10  |  2025-2026 = 11  |  2026-2027 = 12  |  2027-2028 = 13
SET @ciclo := 12;

START TRANSACTION;

-- 1) Borrar las boletas generadas por la activación para ese ciclo.
DELETE FROM calificacion               WHERE cicloEscolar = @ciclo;
DELETE FROM calificacion_observaciones WHERE cicloEscolar = @ciclo;

-- 2) Restaurar los datos de los alumnos desde el respaldo.
UPDATE usr u
JOIN usr_respaldo_activacion b ON u.id = b.id
SET u.grado            = b.grado,
    u.activo           = b.activo,
    u.estatus          = b.estatus,
    u.generacionactiva = b.generacionactiva,
    u.pass             = b.pass;

-- ---------------------------------------------------------------------
-- Verificaciones (revisa que se vean bien ANTES de confirmar):
--   - boletas_restantes debe ser 0.
--   - alumnos_restaurados debe coincidir con los que activaste.
-- ---------------------------------------------------------------------
SELECT
  (SELECT COUNT(*) FROM calificacion WHERE cicloEscolar = @ciclo)                 AS boletas_restantes,
  (SELECT COUNT(*) FROM usr u JOIN usr_respaldo_activacion b ON u.id = b.id)       AS alumnos_restaurados;

-- Si todo se ve correcto, confirma los cambios:
COMMIT;

-- Si algo se ve mal, en lugar del COMMIT de arriba ejecuta:  ROLLBACK;
-- (deja la base intacta como antes de correr este script)
