<?php
/**
 * Informe PDF de activación de ciclo (Dompdf).
 * $r = clasificación, $nombresGrado = [id=>nombre], $nombreCiclo, $fecha, $usuario
 */
$gname = function ($id) use ($nombresGrado) {
    $id = (int) $id;
    return $nombresGrado[$id] ?? ('Grado ' . $id);
};
$pendientes = $r['pendientes'] ?? [];
$totalConMatricula = count($r['listos']) + count($r['yaActivos'] ?? []) + count($r['noEncontradas'])
                   + count($r['gradoNoReconocido']) + count($r['duplicadas']);
$totalGeneral = $totalConMatricula + count($pendientes);
?>
<style>
    * { font-family: DejaVu Sans, sans-serif; }
    body { color: #333; font-size: 11px; }
    h1 { color: #0c335e; font-size: 18px; margin: 0 0 2px; }
    h2 { color: #0c335e; font-size: 13px; margin: 18px 0 6px; border-bottom: 2px solid #0c335e; padding-bottom: 3px; }
    .sub { color: #666; font-size: 10px; margin: 0 0 12px; }
    .desc { color: #555; font-size: 10px; margin: 0 0 6px; font-style: italic; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    th { background: #0c335e; color: #fff; font-size: 9px; padding: 4px 5px; text-align: left; }
    td { border: 1px solid #ddd; font-size: 9px; padding: 3px 5px; }
    tr:nth-child(even) td { background: #f7f9fb; }
    .resumen td { border: 1px solid #ccc; padding: 6px; text-align: center; }
    .num { font-size: 16px; font-weight: bold; }
</style>

<h1>Informe de Activación de Ciclo Escolar</h1>
<p class="sub">
    Ciclo destino: <strong><?= esc($nombreCiclo) ?></strong> &nbsp;|&nbsp;
    Generado: <?= esc($fecha) ?> &nbsp;|&nbsp;
    Por: <?= esc($usuario) ?>
</p>
<p class="sub">
    <strong>Total de alumnos detectados en los archivos: <?= $totalGeneral ?></strong>
    (<?= $totalConMatricula ?> con matrícula + <?= count($pendientes) ?> sin matrícula, pendientes de alta manual).
    Esta suma debe coincidir con el total de alumnos de tus archivos de origen — si no coincide, algún alumno
    se está perdiendo en el camino y hay que revisar el formato del archivo.
</p>

<h2>Resumen</h2>
<table class="resumen">
    <tr>
        <td><div class="num" style="color:#198754"><?= count($r['listos']) ?></div>Listos para activar</td>
        <td><div class="num" style="color:#6c757d"><?= count($r['yaActivos'] ?? []) ?></div>Ya activos (se saltan)</td>
        <td><div class="num" style="color:#0d6efd"><?= count($pendientes) ?></div>Manual (sin matrícula)</td>
        <td><div class="num" style="color:#dc3545"><?= count($r['noEncontradas']) ?></div>No encontradas</td>
        <td><div class="num" style="color:#fd7e14"><?= count($r['gradoNoReconocido']) ?></div>Grado no reconocido</td>
        <td><div class="num" style="color:#0dcaf0"><?= count($r['duplicadas']) ?></div>Duplicadas</td>
    </tr>
</table>

<?php if (!empty($pendientes)): ?>
    <h2 style="color:#0d6efd; border-color:#0d6efd;">★ ACCIÓN MANUAL: Alumnos SIN matrícula (<?= count($pendientes) ?>)</h2>
    <p class="desc" style="color:#0d6efd;">Estos alumnos vienen en el archivo <strong>sin matrícula</strong>.
       <strong>NO se activaron.</strong> Hay que darlos de alta manualmente capturando sus datos personales
       (nombre, CURP, etc.) para que se les genere su matrícula, y ya después activarlos.</p>
    <table>
        <thead><tr><th>#</th><th>Sección</th><th>Datos del alumno (tal cual el archivo)</th></tr></thead>
        <tbody>
        <?php foreach ($pendientes as $i => $p): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= esc($p['seccion'] ?? '—') ?></td>
                <td><?= esc($p['datos'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h2>1. Listos para activar (<?= count($r['listos']) ?>)</h2>
<p class="desc">Estos alumnos quedarán <strong>activos e inscritos</strong> en el ciclo <?= esc($nombreCiclo) ?>, en su grado correspondiente, y se les generarán automáticamente sus <strong>boletas de calificaciones</strong>.</p>
<table>
    <thead><tr><th>#</th><th>Matrícula</th><th>Alumno</th><th>Sección archivo</th><th>Grado actual</th><th>Grado destino</th></tr></thead>
    <tbody>
    <?php foreach ($r['listos'] as $i => $a): ?>
        <tr>
            <td><?= $i + 1 ?></td>
            <td><?= esc($a['matricula']) ?></td>
            <td><?= esc($a['nombre'] ?? '') ?></td>
            <td><?= esc($a['seccion']) ?></td>
            <td><?= esc($gname($a['grado_actual'])) ?></td>
            <td><?= esc($gname($a['id_grado'])) ?><?= !empty($a['revuelto']) ? ' <span style="color:#0d6efd">(repartido en grupo A/B)</span>' : '' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php if (!empty($r['noEncontradas'])): ?>
    <h2>2. Matrículas no encontradas en el sistema (<?= count($r['noEncontradas']) ?>)</h2>
    <p class="desc">Estas matrículas del archivo no están registradas en el sistema. Verificar que los alumnos estén dados de alta.</p>
    <table>
        <thead><tr><th>Matrícula</th><th>Sección archivo</th></tr></thead>
        <tbody>
        <?php foreach ($r['noEncontradas'] as $a): ?>
            <tr><td><?= esc($a['matricula']) ?></td><td><?= esc($a['seccion'] ?? '—') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php if (!empty($r['gradoNoReconocido'])): ?>
    <h2>3. Grado no reconocido (<?= count($r['gradoNoReconocido']) ?>)</h2>
    <p class="desc">La sección del archivo no coincide con ningún grado del sistema. Revisar el nombre de la sección.</p>
    <table>
        <thead><tr><th>Matrícula</th><th>Alumno</th><th>Sección archivo</th></tr></thead>
        <tbody>
        <?php foreach ($r['gradoNoReconocido'] as $a): ?>
            <tr><td><?= esc($a['matricula']) ?></td><td><?= esc($a['nombre'] ?? '') ?></td><td><?= esc($a['seccion'] ?? '—') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php if (!empty($r['duplicadas'])): ?>
    <h2>4. Alumnos repetidos en el archivo (<?= count($r['duplicadas']) ?>)</h2>
    <p class="desc">La misma matrícula aparece en DOS grados distintos dentro del archivo (error de captura).
       Se activó en el primero; hay que revisar en el archivo cuál grado es el correcto.</p>
    <table>
        <thead><tr><th>Matrícula</th><th>Se activó en</th><th>También aparecía en (ignorado)</th></tr></thead>
        <tbody>
        <?php foreach ($r['duplicadas'] as $a): ?>
            <tr>
                <td><?= esc($a['matricula']) ?></td>
                <td><?= esc($a['seccion_usada'] ?? '—') ?></td>
                <td><?= esc($a['seccion_repetida'] ?? $a['seccion'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
