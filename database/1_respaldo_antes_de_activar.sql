-- =====================================================================
--  PASO 1 — RESPALDO ANTES DE LA ACTIVACIÓN MASIVA DE CICLO
-- =====================================================================
--  CUÁNDO: ejecutar ESTO *antes* de dar "Confirmar y activar" en el módulo.
--  QUÉ HACE: guarda una copia de las columnas de los alumnos (usr) que la
--            activación modifica, para poder revertir todo si sale mal.
--
--  NOTA: se respaldan SOLO las columnas necesarias (id, grado, activo,
--        estatus, generacionactiva, pass). No se copia la tabla completa
--        a propósito, para evitar el error de la columna vieja
--        'archi_extra5' (date con default '0000-00-00', inválido en MySQL
--        moderno) y para que el respaldo sea liviano.
--
--  NOTA 2: el ciclo nuevo (ej. 2026-2027) debe estar VACÍO de calificaciones
--          antes de activar; las boletas del ciclo nuevo se borran al revertir.
-- =====================================================================

-- Copia de las columnas que se van a modificar (se reemplaza si ya existía).
DROP TABLE IF EXISTS usr_respaldo_activacion;
CREATE TABLE usr_respaldo_activacion AS
SELECT id, grado, activo, estatus, generacionactiva, pass
FROM usr;

-- Verificación: debe mostrar el total de alumnos respaldados.
SELECT COUNT(*) AS alumnos_respaldados FROM usr_respaldo_activacion;
