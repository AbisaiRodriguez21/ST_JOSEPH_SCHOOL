<!DOCTYPE html>
<html lang="es" data-bs-theme="light" data-topbar-color="light" data-menu-color="light">

<head>
    <?= view("partials/title-meta", ["title" => "Activación de Ciclo"]); ?>
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
                            <h4 class="page-title">Activación de Ciclo Escolar</h4>
                        </div>
                    </div>
                </div>

                <?php if (session()->getFlashdata('success')): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bx bx-check-circle me-1"></i> <?= session()->getFlashdata('success') ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                <?php if (session()->getFlashdata('error')): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bx bx-error-circle me-1"></i> <?= session()->getFlashdata('error') ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row justify-content-center">
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-body">

                                <h5 class="card-title mb-1">Subir archivo de matrículas</h5>
                                <p class="text-muted mb-4">
                                    Sube el archivo de matrículas del ciclo en formato <strong>.csv</strong>.
                                    El sistema tomará las <strong>matrículas</strong> y su <strong>grado</strong>
                                    (según la sección del archivo), buscará a cada alumno y lo activará en el ciclo
                                    que elijas. Antes de aplicar verás una vista previa para revisar.
                                </p>

                                <form action="<?= base_url('activacion-ciclo/previsualizar') ?>"
                                      method="post" enctype="multipart/form-data"
                                      id="form-activacion" onsubmit="mostrarCarga()">

                                    <div class="mb-3">
                                        <label class="form-label">Ciclo escolar destino <span class="text-muted">(el NUEVO ciclo)</span></label>
                                        <select name="id_ciclo" class="form-select" required>
                                            <option value="">— Selecciona el ciclo —</option>
                                            <?php foreach ($ciclos as $c): ?>
                                                <?php
                                                    $id = (int) $c['id_cicloEscolar'];
                                                    $etq = '';
                                                    if ($id === (int)$cicloSugerido)   $etq = ' (nuevo — sugerido)';
                                                    elseif ($id === (int)$cicloActivo) $etq = ' (ciclo actual)';
                                                ?>
                                                <option value="<?= $id ?>" <?= ($id === (int)$cicloSugerido) ? 'selected' : '' ?>>
                                                    <?= esc($c['nombreCicloEscolar']) . $etq ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted">Todos los alumnos del archivo se activarán y pasarán a ESTE ciclo escolar (el nuevo año).</small>
                                    </div>

                                    <div class="mb-4">
                                        <label class="form-label">Archivo CSV</label>
                                        <input type="file" name="archivo_csv" class="form-control" accept=".csv" required>
                                        <small class="text-muted">Solo archivos .csv exportados del Excel de matrículas.</small>
                                    </div>

                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-primary" id="btn-analizar">
                                            <i class="bx bx-search-alt me-1"></i> Analizar archivo
                                        </button>
                                    </div>
                                </form>

                            </div>
                        </div>
                    </div>
                </div>

            </div>
            <?= $this->include("partials/footer") ?>
        </div>
    </div>

    <?= $this->include("partials/vendor-scripts") ?>

    <!-- ============ PANTALLA DE CARGA (mismo componente que las boletas) ============ -->
    <div id="pantalla-carga" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(255, 255, 255, 0.85); z-index: 9999; justify-content: center; align-items: center; flex-direction: column;">
        <div class="spinner"></div>
        <h3 style="color: #0c335e; margin-top: 20px; font-family: sans-serif;">Procesando archivo CSV...</h3>
        <p style="color: #666; font-family: sans-serif;">Por favor, no cierres esta ventana ni recargues la página.</p>
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
            const btn = document.getElementById('btn-analizar');
            if (btn) {
                btn.innerHTML = 'Analizando...';
                btn.disabled = true;
            }
        }
    </script>
</body>
</html>
