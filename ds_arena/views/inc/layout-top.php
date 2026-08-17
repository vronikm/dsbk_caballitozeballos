<?php
/*
| Cabecera común de las vistas de DigiSports Arena.
| Espera $tituloVista y $vistaActual definidos por la vista que lo incluye.
*/

use arena\controllers\arenaController;

if (!isset($insArena)) {
    $insArena = new arenaController();
}

$tituloVista = $tituloVista ?? 'Arena';
$vistaActual = $vistaActual ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo APP_NAME; ?> | <?php echo $tituloVista; ?></title>

    <link rel="icon" type="image/png" href="<?php echo DS_HUB_URL; ?>ds_core/assets/img/logo_ds.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700&display=fallback">
    <link rel="stylesheet" href="<?php echo DS_VENDOR_URL; ?>plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="<?php echo DS_VENDOR_URL; ?>css/adminlte.min.css">
    <link rel="stylesheet" href="<?php echo DS_VENDOR_URL; ?>css/sweetalert2.min.css">
    <link rel="stylesheet" href="<?php echo DS_HUB_URL; ?>ds_core/assets/css/digisports.css">
    <link rel="stylesheet" href="<?php echo DS_HUB_URL; ?>ds_core/assets/css/core.css">

    <style>
        /* Arena usa el acento cyan del ecosistema en lugar del naranja. */
        .ds-core .nav-sidebar .nav-link.active { background: var(--ds-arena) !important;
                                                 box-shadow: 0 6px 18px rgba(34,211,238,.28); }
        .ds-core .btn-primary { background: var(--ds-arena); border-color: var(--ds-arena); }
        .ds-core .btn-primary:hover, .ds-core .btn-primary:focus {
            background: #06b6d4; border-color: #06b6d4; }
        .ds-core .switch input:checked + span { background: var(--ds-arena); }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed ds-core">
<div class="wrapper">

    <nav class="main-header navbar navbar-expand navbar-dark ds-core__navbar">
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <a href="<?php echo DS_HUB_URL; ?>" class="nav-link"><i class="fas fa-arrow-left mr-1"></i> Hub</a>
            </li>
        </ul>

        <ul class="navbar-nav ml-auto">
            <?php require_once __DIR__ . "/../../../ds_core/inc/app-launcher.php"; ?>

            <li class="nav-item">
                <span class="nav-link">
                    <i class="fas fa-user-circle mr-1"></i>
                    <?php echo htmlspecialchars(ds_nombre_usuario(), ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </li>
            <li class="nav-item">
                <a href="<?php echo DS_BASKETBALL_URL; ?>logOut/" class="nav-link" title="Cerrar sesión">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </li>
        </ul>
    </nav>

    <aside class="main-sidebar sidebar-dark-primary elevation-4 ds-core__sidebar">
        <a href="<?php echo APP_URL; ?>panel/" class="brand-link">
            <img src="<?php echo DS_HUB_URL; ?>ds_core/assets/img/logo_ds.png"
                 alt="Arena" class="brand-image img-circle elevation-3" style="opacity:.85">
            <span class="brand-text font-weight-light">Arena</span>
        </a>

        <div class="sidebar">
            <nav class="mt-2">
                <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu">
                    <?php echo $insArena->menuLateral($vistaActual); ?>
                </ul>
            </nav>
        </div>
    </aside>

    <div class="content-wrapper">
        <section class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6"><h1 class="m-0"><?php echo $tituloVista; ?></h1></div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="<?php echo DS_HUB_URL; ?>">DigiSports</a></li>
                            <li class="breadcrumb-item"><a href="<?php echo APP_URL; ?>panel/">Arena</a></li>
                            <li class="breadcrumb-item active"><?php echo $tituloVista; ?></li>
                        </ol>
                    </div>
                </div>
            </div>
        </section>

        <section class="content">
            <div class="container-fluid">
