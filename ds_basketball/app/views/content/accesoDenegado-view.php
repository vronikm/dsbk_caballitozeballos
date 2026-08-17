<?php
/*
| Pagina mostrada cuando un usuario autenticado solicita una vista sobre la
| que su rol no tiene permiso. La renderiza index.php; no forma parte de la
| lista blanca de viewsModel, por lo que no es navegable directamente.
*/
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acceso denegado | <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/css/adminlte.min.css">
</head>
<body class="hold-transition">
    <div class="d-flex align-items-center justify-content-center" style="min-height:100vh;">
        <div class="text-center p-4" style="max-width:520px;">

            <img src="<?php echo APP_URL; ?>app/views/dist/img/Logos/logo_bsc.png"
                 alt="<?php echo APP_NAME; ?>" style="height:90px;opacity:.85;" class="mb-4">

            <h3 class="mb-3">
                <i class="fas fa-lock text-warning mr-2"></i>Acceso denegado
            </h3>

            <p class="text-muted mb-4">
                Su rol no tiene permiso para acceder a esta sección.
                Si considera que se trata de un error, solicite al administrador
                que revise los permisos asignados.
            </p>

            <a href="<?php echo APP_URL; ?>" class="btn btn-primary">
                <i class="fas fa-arrow-left mr-1"></i> Volver al inicio
            </a>
            <a href="<?php echo APP_URL; ?>logOut/" class="btn btn-outline-secondary">
                Cerrar sesión
            </a>

        </div>
    </div>
</body>
</html>
