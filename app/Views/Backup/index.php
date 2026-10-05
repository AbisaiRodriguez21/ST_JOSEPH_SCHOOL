<!DOCTYPE html>
<html lang="es" data-bs-theme="light" data-topbar-color="light" data-menu-color="light">

<head>
    <?= view("partials/title-meta", ["title" => "Respaldos de Base de Datos"]); ?>
    <?= $this->include("partials/head-css") ?>
</head>

<body>
    <div class="wrapper">
        <?= $this->include("partials/menu") ?>

        <div class="page-content">
            <div class="container-fluid">

                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box">
                            <h4 class="page-title">Respaldos de Base de Datos</h4>
                        </div>
                    </div>
                </div>

                <?php if (session()->getFlashdata('success')): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bx bx-check-circle me-1"></i> <?= esc(session()->getFlashdata('success')) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                <?php if (session()->getFlashdata('error')): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bx bx-error-circle me-1"></i> <?= esc(session()->getFlashdata('error')) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <!-- Generar respaldo -->
                    <div class="col-lg-4 mb-4">
                        <div class="card h-100">
                            <div class="card-body">
                                <h5 class="card-title mb-1">
                                    <i class="bx bx-cloud-upload me-1"></i> Generar Respaldo
                                </h5>
                                <p class="text-muted mb-2">
                                    Los respaldos automáticos se ejecutan cada noche de
                                    <strong>lunes a sábado a las 00:00 h</strong>.
                                </p>
                                <p class="text-muted mb-4">
                                    Cada lunes se elimina la semana anterior para liberar espacio.
                                    También puedes generar uno manualmente ahora:
                                </p>
                                <form id="form-generar" method="post" action="<?= base_url('backup/generar') ?>" onsubmit="mostrarCarga()">
                                    <div class="d-grid">
                                        <button type="submit" id="btn-generar" class="btn btn-primary">
                                            <i class="bx bx-refresh me-1"></i> Generar Respaldo Ahora
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Lista de respaldos -->
                    <div class="col-lg-8 mb-4">
                        <div class="card">
                            <div class="card-header d-flex align-items-center justify-content-between">
                                <span class="fw-semibold">
                                    <i class="bx bx-data me-1"></i>
                                    Respaldos Disponibles
                                    <span class="badge bg-info-subtle text-info ms-2"><?= count($archivos) ?></span>
                                </span>
                                <?php if (!empty($archivos)): ?>
                                    <button type="button" id="btn-eliminar-todo" class="btn btn-sm btn-danger">
                                        <i class="bx bx-trash me-1"></i> Eliminar todos
                                    </button>
                                <?php endif; ?>
                            </div>
                            <div class="card-body p-0">
                                <?php if (empty($archivos)): ?>
                                    <div class="text-center text-muted py-5">
                                        <i class="bx bx-data" style="font-size:3rem;opacity:.3;"></i>
                                        <p class="mt-3">No hay respaldos aún.<br>Genera uno manualmente o espera el respaldo automático.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Archivo</th>
                                                    <th class="text-end">Tamaño</th>
                                                    <th>Fecha</th>
                                                    <th class="text-center" style="width:120px;">Acciones</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($archivos as $f): ?>
                                                    <tr>
                                                        <td>
                                                            <i class="bx bx-data text-primary me-1"></i>
                                                            <?= esc($f['nombre']) ?>
                                                        </td>
                                                        <td class="text-end text-muted">
                                                            <?= $f['tamano'] >= 1048576
                                                                ? round($f['tamano'] / 1048576, 1) . ' MB'
                                                                : round($f['tamano'] / 1024, 1) . ' KB' ?>
                                                        </td>
                                                        <td class="text-muted"><?= $f['fecha'] ?></td>
                                                        <td class="text-center">
                                                            <a class="btn btn-sm btn-outline-success me-1"
                                                               href="<?= base_url('backup/descargar/' . urlencode($f['nombre'])) ?>"
                                                               title="Descargar">
                                                                <i class="bx bx-download"></i>
                                                            </a>
                                                            <button type="button"
                                                                    class="btn btn-sm btn-outline-danger btn-eliminar"
                                                                    data-nombre="<?= esc($f['nombre']) ?>"
                                                                    title="Eliminar">
                                                                <i class="bx bx-x"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
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

    <!-- Form oculto para eliminar uno -->
    <form id="form-eliminar" method="post" style="display:none;"></form>

    <!-- Form oculto para eliminar todos -->
    <form id="form-eliminar-todo" method="post" action="<?= base_url('backup/eliminar-todo') ?>" style="display:none;"></form>

    <!-- ============ PANTALLA DE CARGA (mismo componente que Activación de Ciclo) ============ -->
    <div id="pantalla-carga" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(255, 255, 255, 0.85); z-index: 9999; justify-content: center; align-items: center; flex-direction: column;">
        <div class="spinner"></div>
        <h3 style="color: #0c335e; margin-top: 20px; font-family: sans-serif;">Generando respaldo…</h3>
        <p style="color: #666; font-family: sans-serif;">Esto puede tardar unos segundos.</p>
        <style>
            .spinner {
                border: 8px solid #f3f3f3;
                border-top: 8px solid #17a2b8;
                border-radius: 50%;
                width: 70px;
                height: 70px;
                animation: girar 1s linear infinite;
            }
            @keyframes girar {
                0%   { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        </style>
    </div>

    <script>
        function mostrarCarga() {
            document.getElementById('pantalla-carga').style.display = 'flex';
            const btn = document.getElementById('btn-generar');
            if (btn) {
                btn.innerHTML = 'Generando...';
                btn.disabled = true;
            }
        }

        document.querySelectorAll('.btn-eliminar').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var nombre = this.dataset.nombre;
                if (!confirm('¿Eliminar el respaldo "' + nombre + '"?\nEsta acción no se puede deshacer.')) return;
                var form = document.getElementById('form-eliminar');
                form.action = '<?= base_url('backup/eliminar/') ?>' + encodeURIComponent(nombre);
                form.submit();
            });
        });

        var btnEliminarTodo = document.getElementById('btn-eliminar-todo');
        if (btnEliminarTodo) {
            btnEliminarTodo.addEventListener('click', function () {
                if (!confirm('¿Eliminar TODOS los respaldos?\nEsta acción no se puede deshacer.')) return;
                document.getElementById('form-eliminar-todo').submit();
            });
        }
    </script>
</body>
</html>
