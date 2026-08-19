<?php
/*
| Cabecera comun de las vistas de DigiSports Core.
| Espera $tituloVista y $vistaActual definidos por la vista que lo incluye.
| Reutiliza AdminLTE y SweetAlert2 del modulo Basketball (DS_VENDOR_URL).
*/

use admin\controllers\coreController;

if (!isset($insCore)) {
    $insCore = new coreController();
}

$tituloVista = $tituloVista ?? 'Core';
$vistaActual = $vistaActual ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo APP_NAME; ?> | <?php echo $tituloVista; ?></title>

    <link rel="icon" type="image/png" href="<?php echo DS_HUB_URL; ?>ds_core/assets/img/logo_ds.png">
    <link rel="stylesheet" href="<?php echo DS_HUB_URL; ?>ds_core/assets/css/fuentes.css">
    <link rel="stylesheet" href="<?php echo DS_VENDOR_URL; ?>plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="<?php echo DS_VENDOR_URL; ?>css/adminlte.min.css">
    <link rel="stylesheet" href="<?php echo DS_VENDOR_URL; ?>css/sweetalert2.min.css">
    <link rel="stylesheet" href="<?php echo DS_HUB_URL; ?>ds_core/assets/css/digisports.css">
    <link rel="stylesheet" href="<?php echo DS_HUB_URL; ?>ds_core/assets/css/core.css">
</head>
<body class="hold-transition sidebar-mini layout-fixed ds-core">
<div class="wrapper">

    <!-- ================= Barra superior ================= -->
    <nav class="main-header navbar navbar-expand navbar-dark ds-core__navbar">
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <a href="<?php echo DS_HUB_URL; ?>" class="nav-link">
                    <i class="fas fa-arrow-left mr-1"></i> Hub
                </a>
            </li>
        </ul>

        <ul class="navbar-nav ml-auto">
            <?php require_once __DIR__ . "/../../../inc/app-launcher.php"; ?>

            <li class="nav-item">
                <span class="nav-link">
                    <i class="fas fa-user-circle mr-1"></i>
                    <?php echo htmlspecialchars(ds_nombre_usuario(), ENT_QUOTES, 'UTF-8'); ?>
                    <?php if (es_superadministrador()): ?>
                        <span class="badge badge-warning ml-1">Super Admin</span>
                    <?php endif; ?>
                </span>
            </li>
            <li class="nav-item">
                <a href="<?php echo DS_BASKETBALL_URL; ?>logOut/" class="nav-link" title="Cerrar sesión">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </li>
        </ul>
    </nav>

    <!-- ================= Menú lateral ================= -->
    <aside class="main-sidebar sidebar-dark-primary elevation-4 ds-core__sidebar">
        <a href="<?php echo APP_URL; ?>panel/" class="brand-link">
            <img src="<?php echo DS_HUB_URL; ?>ds_core/assets/img/logo_ds.png"
                 alt="Core" class="brand-image img-circle elevation-3" style="opacity:.85">
            <span class="brand-text font-weight-light">Core</span>
        </a>

        <div class="sidebar">
            <nav class="mt-2">
                <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu">
                    <?php echo $insCore->menuLateral($vistaActual); ?>
                </ul>
            </nav>
        </div>
    </aside>

    <!-- ================= Contenido ================= -->
    <div class="content-wrapper">
        <section class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0"><?php echo $tituloVista; ?></h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="<?php echo DS_HUB_URL; ?>">DigiSports</a></li>
                            <li class="breadcrumb-item"><a href="<?php echo APP_URL; ?>panel/">Core</a></li>
                            <li class="breadcrumb-item active"><?php echo $tituloVista; ?></li>
                        </ol>
                    </div>
                </div>
            </div>
        </section>

        <section class="content">
            <div class="container-fluid">
