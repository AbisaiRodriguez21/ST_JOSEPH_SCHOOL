-- =====================================================================
--  LIMPIEZA DE CALIFICACIONES DUPLICADAS
-- =====================================================================
--  Problema: la activación se corrió varias veces antes de tener el
--  candado anti-duplicados, y quedaron calificaciones repetidas (2x).
--
--  Qué hace: deja UNA sola calificación por (alumno, materia, mes, grado)
--  del ciclo indicado, borrando las copias extra (conserva la más antigua).
--
--  Las observaciones NO se tocan (esas no se duplicaron).
-- =====================================================================

-- ⚙️  Ciclo a limpiar (2026-2027 = 12).
SET @ciclo := 12;

START TRANSACTION;

-- Antes: cuántas filas hay.
SELECT COUNT(*) AS filas_antes FROM calificacion WHERE cicloEscolar = @ciclo;

-- Borra duplicados: si existe otra fila igual con Id_cal menor, esta sobra.
DELETE c1 FROM calificacion c1
JOIN calificacion c2
  ON  c1.id_usr       = c2.id_usr
  AND c1.id_materia   = c2.id_materia
  AND c1.id_mes       = c2.id_mes
  AND c1.id_grado     = c2.id_grado
  AND c1.cicloEscolar = c2.cicloEscolar
  AND c1.Id_cal       > c2.Id_cal
WHERE c1.cicloEscolar = @ciclo;

-- Después: deben quedar las únicas (sin duplicados).
SELECT COUNT(*) AS filas_despues FROM calificacion WHERE cicloEscolar = @ciclo;

-- Revisa que 'filas_despues' sea la mitad (aprox) de 'filas_antes'.
-- Si se ve bien, confirma:
COMMIT;

-- Si algo se ve mal, en vez del COMMIT ejecuta:  ROLLBACK;
