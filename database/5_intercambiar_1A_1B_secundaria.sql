-- =============================================================================
-- Intercambia a los alumnos de 1 A Secundaria (id_grado 28) y 1 B Secundaria (34)
-- del ciclo activo: los de 1 A pasan a 1 B y los de 1 B pasan a 1 A.
--
-- 1 A y 1 B tienen MATERIAS DISTINTAS, así que además de mover al alumno:
--   - se borran sus filas de calificación del grupo anterior (están vacías: 0 / 0)
--   - se generan en blanco con las materias del grupo nuevo (igual que la
--     Activación de Ciclo: materia × mes, 10 meses o grados.meses_calificacion)
--   - sus observaciones solo cambian de grado (conservan cualquier texto)
--
-- Solo toca alumnos ACTIVOS (estatus 1) activados en el ciclo actual.
-- Titulares, materias y grados no se modifican.
--
-- ANTES DE CORRER: generar un respaldo en "Respaldos BD".
-- En phpMyAdmin, correr cada PASO COMPLETO de una sola vez (incluye su SET @ciclo).
-- =============================================================================


-- =============================================================================
-- PASO 1: VISTA PREVIA (solo lectura, no cambia nada)
-- =============================================================================
SET @ciclo := (SELECT id_ciclo FROM mesycicloactivo WHERE id = 1);

-- Alumnos que se van a mover. Esperado: 28 -> 26 alumnos, 34 -> 25 alumnos.
SELECT u.grado AS grado_actual,
       IF(u.grado = 28, '1 A  ->  1 B', '1 B  ->  1 A') AS cambio,
       COUNT(*) AS alumnos
FROM usr u
WHERE u.nivel = 7 AND u.estatus = 1 AND u.grado IN (28, 34) AND u.generacionactiva = @ciclo
GROUP BY u.grado;

-- Calificaciones capturadas de esos alumnos. Debe salir 0 en las dos columnas.
-- Si sale algo distinto de 0, NO corras el PASO 2: avísame primero.
SELECT COUNT(*) AS filas,
       SUM(IFNULL(c.calificacion, 0) <> 0) AS con_calificacion,
       SUM(IFNULL(c.faltas, 0) <> 0)       AS con_faltas
FROM calificacion c
JOIN usr u ON u.id = c.id_usr AND c.id_grado = u.grado
WHERE u.nivel = 7 AND u.estatus = 1 AND u.grado IN (28, 34) AND u.generacionactiva = @ciclo
  AND c.cicloEscolar = @ciclo;


-- =============================================================================
-- PASO 2: INTERCAMBIO (sí modifica). Correr completo, de una sola vez.
-- =============================================================================
SET @ciclo := (SELECT id_ciclo FROM mesycicloactivo WHERE id = 1);

START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS tmp_cambio_1sec;
CREATE TEMPORARY TABLE tmp_cambio_1sec (
    id_usr      INT PRIMARY KEY,
    grado_viejo INT NOT NULL,
    grado_nuevo INT NOT NULL
);

INSERT INTO tmp_cambio_1sec (id_usr, grado_viejo, grado_nuevo)
SELECT id, grado, IF(grado = 28, 34, 28)
FROM usr
WHERE nivel = 7 AND estatus = 1 AND grado IN (28, 34) AND generacionactiva = @ciclo;

-- 1) Borrar sus calificaciones VACÍAS del grupo anterior (materias que ya no les tocan)
DELETE c
FROM calificacion c
JOIN tmp_cambio_1sec t ON t.id_usr = c.id_usr AND c.id_grado = t.grado_viejo
WHERE c.cicloEscolar = @ciclo
  AND IFNULL(c.calificacion, 0) = 0
  AND IFNULL(c.faltas, 0) = 0;

-- 2) Generar calificaciones en blanco con las materias del grupo nuevo
INSERT IGNORE INTO calificacion (id_usr, id_materia, id_mes, cicloEscolar, id_grado, calificacion)
SELECT t.id_usr, m.Id_materia, meses.n, @ciclo, t.grado_nuevo, 0
FROM tmp_cambio_1sec t
JOIN materia m ON m.id_grados = t.grado_nuevo
JOIN grados  g ON g.id_grado  = t.grado_nuevo
JOIN (SELECT 1 AS n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5
      UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10) meses
  ON meses.n <= IF(IFNULL(g.meses_calificacion, 0) > 0, g.meses_calificacion, 10);

-- 3) Observaciones: solo cambian de grado (se conserva cualquier texto capturado)
UPDATE calificacion_observaciones o
JOIN tmp_cambio_1sec t ON t.id_usr = o.id_usr AND o.id_grado = t.grado_viejo
SET o.id_grado = t.grado_nuevo
WHERE o.cicloEscolar = @ciclo;

-- 4) Mover a los alumnos de grupo
UPDATE usr u
JOIN tmp_cambio_1sec t ON t.id_usr = u.id
SET u.grado = t.grado_nuevo;

COMMIT;

DROP TEMPORARY TABLE IF EXISTS tmp_cambio_1sec;


-- =============================================================================
-- PASO 3: VERIFICACIÓN (solo lectura)
-- =============================================================================
SET @ciclo := (SELECT id_ciclo FROM mesycicloactivo WHERE id = 1);

-- Esperado: 28 (1 A) -> 25 alumnos, 34 (1 B) -> 26 alumnos.
-- "filas_por_alumno" debe ser el mismo número en todos los alumnos de cada grupo
-- (materias del grupo × 10 meses), y min = max.
SELECT x.grado,
       COUNT(*)      AS alumnos,
       MIN(x.filas)  AS min_filas_por_alumno,
       MAX(x.filas)  AS max_filas_por_alumno
FROM (
    SELECT u.id, u.grado, COUNT(c.id_usr) AS filas
    FROM usr u
    LEFT JOIN calificacion c ON c.id_usr = u.id AND c.id_grado = u.grado AND c.cicloEscolar = @ciclo
    WHERE u.nivel = 7 AND u.estatus = 1 AND u.grado IN (28, 34) AND u.generacionactiva = @ciclo
    GROUP BY u.id, u.grado
) x
GROUP BY x.grado;

-- Debe salir 0: alumnos con observaciones todavía en el grupo anterior.
SELECT COUNT(*) AS observaciones_en_grupo_equivocado
FROM calificacion_observaciones o
JOIN usr u ON u.id = o.id_usr
WHERE u.nivel = 7 AND u.estatus = 1 AND u.grado IN (28, 34) AND u.generacionactiva = @ciclo
  AND o.cicloEscolar = @ciclo AND o.id_grado IN (28, 34) AND o.id_grado <> u.grado;
