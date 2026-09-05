-- =====================================================================
--  PRENDER EL CICLO NUEVO EN EL SISTEMA
-- =====================================================================
--  Para qué: que las boletas, calificaciones, etc. muestren y usen el
--  ciclo NUEVO (2026-2027). El sistema toma el "ciclo activo" de la
--  tabla mesycicloactivo; hay que apuntarla al ciclo nuevo.
--
--  OJO: este es un cambio A NIVEL DE TODO EL SISTEMA (cambia el ciclo
--  activo para todos). Hazlo cuando ya vayas a arrancar el ciclo nuevo.
--  Es reversible (volver a poner 11).
-- =====================================================================

-- ⚙️  Ciclos:  2025-2026 = 11  |  2026-2027 = 12
SET @ciclo_viejo  := 11;
SET @ciclo_nuevo  := 12;

START TRANSACTION;

-- Antes:
SELECT * FROM mesycicloactivo;

-- Cambiar el ciclo activo del sistema al nuevo.
UPDATE mesycicloactivo SET id_ciclo = @ciclo_nuevo WHERE id_ciclo = @ciclo_viejo;

-- Después (debe mostrar id_ciclo = 12):
SELECT * FROM mesycicloactivo;

COMMIT;

-- Para revertir (volver al ciclo anterior):
--   UPDATE mesycicloactivo SET id_ciclo = 11 WHERE id_ciclo = 12;
