<?php
    // Helper: nombre de grado por id
    $gname = function ($id) use ($nombresGrado) {
        $id = (int) $id;
        return $nombresGrado[$id] ?? ('Grado ' . $id);
    };
    $totalConMatricula = count($r['listos']) + count($r['yaActivos'] ?? []) + count($r['noEncontradas'])
                       + count($r['gradoNoReconocido']) + count($r['duplicadas']);
    $totalGeneral = $totalConMatricula + count($r['pendientes'] ?? []);
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="light" data-topbar-color="light" data-menu-color="light">

<head>
    <?= view("partials/title-meta", ["title" => "Vista previa de activación"]); ?>
    <?= $this->include("partials/head-css") ?>
</head>

<body>
    <div class="wrapper">
        <?= $this->include("partials/menu") ?>

        <div class="page-content">
            <div class="container-fluid">

                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-flex justify-content-between align-items-center">
                            <h4 class="page-title mb-0">Vista previa — Ciclo: <?= esc($nombreCiclo) ?></h4>
                            <div>
                                <a href="<?= base_url('activacion-ciclo/reporte') ?>" class="btn btn-sm btn-danger" target="_blank">
                                    <i class="bx bxs-file-pdf me-1"></i> Descargar informe (PDF)
                                </a>
                                <a href="<?= base_url('activacion-ciclo') ?>" class="btn btn-sm btn-light">
                                    <i class="bx bx-arrow-back me-1"></i> Volver
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="alert alert-secondary">
                    <strong>Total de alumnos detectados en los archivos: <?= $totalGeneral ?></strong>
                    (<?= $totalConMatricula ?> con matrícula + <?= count($r['pendientes'] ?? []) ?> sin matrícula, pendientes de alta manual).
                    Compáralo contra el total real de tus archivos de origen — si no coincide, algún alumno se está
                    perdiendo en el camino.
                </div>

                <!-- Tarjetas resumen -->
                <div class="row">
                    <?php
                        $tarjetas = [
                            ['Listos para activar', count($r['listos']),            'success', 'bx-check-circle'],
                            ['Ya activos (se saltan)', count($r['yaActivos'] ?? []),'secondary','bx-time-five'],
                            ['Manual — sin matrícula', count($r['pendientes'] ?? []), 'primary', 'bx-user-plus'],
                            ['No encontradas',      count($r['noEncontradas']),     'danger',  'bx-x-circle'],
                            ['Grado no reconocido', count($r['gradoNoReconocido']), 'warning', 'bx-help-circle'],
                            ['Repetidos en archivo', count($r['duplicadas']),       'info',    'bx-copy'],
                        ];
                        foreach ($tarjetas as $t): ?>
                        <div class="col-md-4 col-xl">
                            <div class="card">
                                <div class="card-body text-center">
                                    <i class="bx <?= $t[3] ?> text-<?= $t[2] ?>" style="font-size:1.6rem;"></i>
                                    <h3 class="mt-1 mb-0"><?= $t[1] ?></h3>
                                    <p class="text-muted mb-0 small"><?= $t[0] ?></p>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">

                                <?php if (empty($r['listos'])): ?>
                                    <div class="alert alert-warning mb-0">
                                        <i class="bx bx-error me-1"></i>
                                        No hay alumnos listos para activar con este archivo. Revisa los casos de abajo.
                                    </div>
                                <?php else: ?>
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h5 class="mb-0">Alumnos que se van a activar (<?= count($r['listos']) ?>)</h5>
                                        <form action="<?= base_url('activacion-ciclo/aplicar') ?>" method="post"
                                              onsubmit="return confirmarActivacion();">
                                            <button type="submit" class="btn btn-success">
                                                <i class="bx bx-check-double me-1"></i>
                                                Confirmar y activar <?= count($r['listos']) ?>
                                            </button>
                                        </form>
                                    </div>

                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered table-striped align-middle">
                                            <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>Matrícula</th>
                                                    <th>Alumno</th>
                                                    <th>Sección (archivo)</th>
                                                    <th>Grado actual</th>
                                                    <th>Grado destino</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($r['listos'] as $i => $a): ?>
                                                    <tr>
                                                        <td><?= $i + 1 ?></td>
                                                        <td><?= esc($a['matricula']) ?></td>
                                                        <td><?= esc($a['nombre'] ?? '') ?></td>
                                                        <td><?= esc($a['seccion']) ?></td>
                                                        <td><?= esc($gname($a['grado_actual'])) ?></td>
                                                        <td>
                                                            <span class="badge bg-success"><?= esc($gname($a['id_grado'])) ?></span>
                                                            <?php if (!empty($a['grupo_real'])): ?>
                                                                <span class="badge bg-info" title="Grupo tomado de la lista real que mando la escuela">grupo real</span>
                                                            <?php elseif (!empty($a['revuelto'])): ?>
                                                                <span class="badge bg-primary" title="No vino en ninguna lista de grupos: repartido entre A y B al azar">repartido A/B</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>

                                <!-- Alumnos SIN matrícula: se hacen a mano con datos personales -->
                                <?php if (!empty($r['pendientes'])): ?>
                                    <hr class="my-4">
                                    <div class="alert alert-primary">
                                        <i class="bx bx-user-plus me-1"></i>
                                        <strong><?= count($r['pendientes']) ?> alumno(s) sin matrícula.</strong>
                                        Estos NO se activan aquí: hay que darlos de alta manualmente con sus datos personales.
                                    </div>
                                    <div class="table-responsive mb-3">
                                        <table class="table table-sm table-bordered">
                                            <thead><tr><th>#</th><th>Sección</th><th>Datos del alumno (del archivo)</th></tr></thead>
                                            <tbody>
                                                <?php foreach ($r['pendientes'] as $i => $p): ?>
                                                    <tr>
                                                        <td><?= $i + 1 ?></td>
                                                        <td><?= esc($p['seccion'] ?? '—') ?></td>
                                                        <td><?= esc($p['datos'] ?? '') ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>

                                <!-- Casos que se saltan -->
                                <?php
                                    $secciones = [
                                        ['No encontradas en el sistema', $r['noEncontradas'], 'danger'],
                                        ['Grado no reconocido',      $r['gradoNoReconocido'], 'warning'],
                                    ];
                                    $hayCasos = count($r['noEncontradas']) + count($r['gradoNoReconocido']);
                                ?>

                                <?php if (!empty($r['duplicadas'])): ?>
                                    <hr class="my-4">
                                    <h5 class="mb-1 text-info">Alumnos repetidos en el archivo (<?= count($r['duplicadas']) ?>)</h5>
                                    <p class="text-muted small mb-2">La misma matrícula viene en dos grados distintos en el archivo (error de captura). Se activó en el primero; revisa en el archivo cuál es el correcto.</p>
                                    <div class="table-responsive mb-3">
                                        <table class="table table-sm table-bordered">
                                            <thead><tr><th>Matrícula</th><th>Se activó en</th><th>También aparecía en (ignorado)</th></tr></thead>
                                            <tbody>
                                                <?php foreach ($r['duplicadas'] as $d): ?>
                                                    <tr>
                                                        <td><?= esc($d['matricula']) ?></td>
                                                        <td><span class="badge bg-success"><?= esc($d['seccion_usada'] ?? '—') ?></span></td>
                                                        <td><span class="badge bg-secondary"><?= esc($d['seccion_repetida'] ?? $d['seccion'] ?? '—') ?></span></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                                <?php if ($hayCasos > 0): ?>
                                    <hr class="my-4">
                                    <h5 class="mb-3">Casos que se saltarán</h5>
                                    <?php foreach ($secciones as $sec): ?>
                                        <?php if (!empty($sec[1])): ?>
                                            <details class="mb-2">
                                                <summary class="text-<?= $sec[2] ?> fw-bold" style="cursor:pointer;">
                                                    <?= esc($sec[0]) ?> (<?= count($sec[1]) ?>)
                                                </summary>
                                                <div class="table-responsive mt-2">
                                                    <table class="table table-sm table-bordered mb-0">
                                                        <thead><tr><th>Matrícula</th><th>Sección</th></tr></thead>
                                                        <tbody>
                                                            <?php foreach ($sec[1] as $c): ?>
                                                                <tr>
                                                                    <td><?= esc($c['matricula']) ?></td>
                                                                    <td><?= esc($c['seccion'] ?? '—') ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </details>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                            </div>
                        </div>
                    </div>
                </div>

            </div>
            <?= $this->include("partials/footer") ?>
        </div>
    </div>

    <?= $this->include("partials/vendor-scripts") ?>

    <!-- ============ PANTALLA DE CARGA ============ -->
    <div id="pantalla-carga" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(255, 255, 255, 0.85); z-index: 9999; justify-content: center; align-items: center; flex-direction: column;">
        <div class="spinner"></div>
        <h3 style="color: #0c335e; margin-top: 20px; font-family: sans-serif;">Activando alumnos...</h3>
        <p style="color: #666; font-family: sans-serif;">Por favor, no cierres esta ventana ni recargues la página.</p>
        <style>
            .spinner { border: 8px solid #f3f3f3; border-top: 8px solid #17a2b8; border-radius: 50%; width: 70px; height: 70px; animation: girar 1s linear infinite; }
            @keyframes girar { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        </style>
    </div>

    <script>
        function confirmarActivacion() {
            if (!confirm('¿Activar <?= count($r['listos']) ?> alumno(s) en el ciclo <?= esc($nombreCiclo) ?>? Revisa que todo esté correcto antes de continuar.')) {
                return false;
            }
            document.getElementById('pantalla-carga').style.display = 'flex';
            return true;
        }
    </script>
</body>
</html>
