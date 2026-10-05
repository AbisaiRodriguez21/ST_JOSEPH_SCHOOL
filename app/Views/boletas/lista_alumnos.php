<!DOCTYPE html>
<html lang="es" data-bs-theme="light" data-topbar-color="light" data-menu-color="light">

<head>
    <?= view("partials/title-meta", ["title" => "Lista de Alumnos"]) ?>
    <?= $this->include("partials/head-css") ?>
</head>

<body>
    <div class="wrapper">
        <?= $this->include("partials/menu") ?>
        <div class="page-content">
            <div class="container-fluid">

                <div class="row mb-3">
                    <div class="col-12">
                        <div class="page-title-box">
                            <h4 class="page-title">
                                Alumnos de <?= esc($grado['nombreGrado']) ?>
                                <span class="badge bg-primary ms-2" style="font-size: 0.8em;">
                                    Total: <span id="totalAlumnos"><?= !empty($alumnos) ? count($alumnos) : 0 ?></span>
                                </span>
                            </h4>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover table-striped align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 50px;">#</th>
                                        <th>Matrícula</th>
                                        <th>Nombre Completo</th>
                                        <th>Estatus</th>
                                        <th class="text-center">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($alumnos)): ?>
                                        <?php
                                        // Inicializamos contador
                                        $contador = 1;
                                        foreach ($alumnos as $a):
                                        ?>
                                            <tr id="fila-alumno-<?= $a['id'] ?>">
                                                <td class="text-muted fw-bold num-fila"><?= $contador++ ?></td>

                                                <td><?= esc($a['matricula']) ?></td>
                                                <td class="fw-bold">
                                                    <?= esc($a['ap_Alumno']) ?> <?= esc($a['am_Alumno']) ?> <?= esc($a['Nombre']) ?>
                                                </td>
                                                <td><span class="badge bg-success">Activo</span></td>
                                                <td class="text-center">
                                                    <?php
                                                    // Lógica para decidir a dónde apunta el link
                                                    if (isset($is_titular) && $is_titular) {
                                                        // Ruta para Titular (Solo lleva ID Alumno, el grado lo sabe por sesión)
                                                        $urlBoleta = base_url('titular/ver-boleta/' . $a['id']);
                                                    } else {
                                                        // Ruta para Admin (Lleva ID Grado y ID Alumno)
                                                        $urlBoleta = base_url('boleta/ver/' . $id_grado . '/' . $a['id']);
                                                    }
                                                    ?>

                                                    <a href="<?= $urlBoleta ?>" class="btn btn-sm btn-primary">
                                                        <i class="bx bx-file"></i> Ver Boleta
                                                    </a>

                                                    <?php if (empty($is_titular)): ?>
                                                        <button type="button" class="btn btn-sm btn-outline-danger ms-1 btn-quitar-alumno"
                                                                title="Eliminar de la lista"
                                                                data-id="<?= $a['id'] ?>"
                                                                data-nombre="<?= esc($a['ap_Alumno'] . ' ' . $a['am_Alumno'] . ' ' . $a['Nombre']) ?>">
                                                            <i class="bx bx-x"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center">No hay alumnos.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
            <?= $this->include("partials/footer") ?>
        </div>
    </div>
    <?= $this->include("partials/vendor-scripts") ?>

    <?php if (empty($is_titular)): ?>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Quitar alumno de la lista: pasa a estatus "En proceso" (no se borra de la BD)
        document.body.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-quitar-alumno');
            if (!btn) return;

            const id = btn.dataset.id;

            Swal.fire({
                title: '¿Eliminar al alumno de la lista?',
                text: btn.dataset.nombre,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f46a6a',
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (!result.isConfirmed) return;

                const formData = new FormData();
                formData.append('id', id);
                formData.append('<?= csrf_token() ?>', '<?= csrf_hash() ?>');

                fetch('<?= base_url("boleta/quitar-alumno") ?>', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(r => r.json())
                .then(d => {
                    if (d.status !== 'success') {
                        Swal.fire('Error', d.msg || 'No se pudo eliminar al alumno.', 'error');
                        return;
                    }
                    document.getElementById('fila-alumno-' + id)?.remove();

                    // Recorrer la numeración de las filas que quedan
                    document.querySelectorAll('.num-fila').forEach((td, i) => td.textContent = i + 1);

                    const total = document.getElementById('totalAlumnos');
                    total.textContent = Math.max(0, parseInt(total.textContent, 10) - 1);
                    Swal.fire({ icon: 'success', title: 'Alumno eliminado de la lista', timer: 1500, showConfirmButton: false });
                })
                .catch(() => Swal.fire('Error', 'No se pudo conectar con el servidor.', 'error'));
            });
        });
    </script>
    <?php endif; ?>
</body>

</html>