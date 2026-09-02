<header class="topbar">
     <div class="container-fluid">
          <div class="navbar-header">
               <div class="d-flex align-items-center gap-2">
                    <div class="topbar-item">
                         <button type="button" class="button-toggle-menu topbar-button">
                              <iconify-icon icon="solar:hamburger-menu-broken" class="fs-24 align-middle"></iconify-icon>
                         </button>
                    </div>

               </div>

               <div class="d-flex align-items-center gap-1">
                    <div class="topbar-item">
                         <button type="button" class="topbar-button" data-toggle="theme">
                              <iconify-icon icon="solar:moon-broken" class="fs-24 align-middle light-mode"></iconify-icon>
                              <iconify-icon icon="solar:sun-broken" class="fs-24 align-middle dark-mode"></iconify-icon>
                         </button>
                    </div>

                    <div class="dropdown topbar-item d-none d-lg-flex">
                         <button type="button" class="topbar-button" data-toggle="fullscreen">
                              <iconify-icon icon="solar:full-screen-broken" class="fs-24 align-middle fullscreen"></iconify-icon>
                              <iconify-icon icon="solar:quit-full-screen-broken" class="fs-24 align-middle quit-fullscreen"></iconify-icon>
                         </button>
                    </div>

                    <?php
                    $foto = session('foto');
                    if ($foto) {
                    $fotoUsuario = base_url($foto);
                    } else {
                    $fotoUsuario = base_url('images/avatar_placeholder.jpg');
                    }
                    $nombreUsuario = session('nombre') ?? 'Usuario';
                    ?>

                    <div class="dropdown topbar-item">
                    <a type="button"
                         class="topbar-button d-flex align-items-center gap-2"
                         id="page-header-user-dropdown"
                         data-bs-toggle="dropdown"
                         aria-haspopup="true"
                         aria-expanded="false">

                         <img class="rounded-circle" width="32" height="32"
                              src="<?= esc($fotoUsuario) ?>" alt="avatar">

                         <span class="fw-semibold text-dark">
                              <?= esc($nombreUsuario) ?>
                         </span>
                    </a>

                    <div class="dropdown-menu dropdown-menu-end">
                         <h6 class="dropdown-header">Bienvenido <?= esc($nombreUsuario) ?>!</h6>

                         <a class="dropdown-item text-danger" href="<?= base_url('logout') ?>">
                              <i class="bx bx-log-out fs-18 align-middle me-1"></i>
                              <span class="align-middle">Logout</span>
                         </a>
                    </div>
                    </div>

               </div>
          </div>
     </div>
</header>

<?= $this->include('partials/right-sidebar') ?>

<script>
document.addEventListener("DOMContentLoaded", function() {
    // Buscamos el botón por su CLASE, no por ID, porque así viene en tu HTML
    const toggleBtn = document.querySelector('.button-toggle-menu');
    
    if(toggleBtn) {
        toggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            
            const width = window.innerWidth;
            const html = document.documentElement;
            const body = document.body;
            
            if (width >= 992) {
                // ESCRITORIO: Cambiar entre menú grande y pequeño
                const currentSize = html.getAttribute('data-sidebar-size');
                if (currentSize === 'sm') {
                    html.setAttribute('data-sidebar-size', 'lg');
                } else {
                    html.setAttribute('data-sidebar-size', 'sm');
                }
            } else {
                // MÓVIL: Mostrar u ocultar el menú
                // 'sidebar-enable' es la clase estándar para mostrar el menú móvil en Rasket
                body.classList.toggle('sidebar-enable');
            }
        });
    }
});
</script>