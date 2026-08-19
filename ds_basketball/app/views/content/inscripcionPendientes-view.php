<?php
    use app\controllers\inscripcionController;

    $insInscripcion = new inscripcionController();

    $sedeFiltro = isset($_GET['sede']) ? $insInscripcion->limpiarCadena($_GET['sede']) : '';
    $busqueda   = isset($_GET['q'])    ? $insInscripcion->limpiarCadena($_GET['q'])    : '';

    $sedes  = $insInscripcion->listarSedesActivas();
    $filas  = $insInscripcion->listarPendientesInscripcion($sedeFiltro, $busqueda);

    /* Badge de un requisito: verde si está cubierto, rojo si falta */
    function badgePendiente($cubierto, $textoOk, $textoFalta) {
        return $cubierto
            ? '<span class="badge badge-success"><i class="fas fa-check"></i> '.$textoOk.'</span>'
            : '<span class="badge badge-danger"><i class="fas fa-times"></i> '.$textoFalta.'</span>';
    }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> | Inscripciones en línea pendientes</title>
    <link rel="stylesheet" href="<?php echo DS_HUB_URL; ?>ds_core/assets/css/fuentes.css">
	<link rel="stylesheet" href="<?php echo DS_HUB_URL; ?>ds_core/assets/css/core.css">
    <link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/css/adminlte.css">
    <link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/css/sweetalert2.min.css">
    <link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/plugins/datatables-bs4/css/dataTables.bootstrap4.min.css">
    <style>
        .dias-espera { font-weight: 600; }
        .dias-alerta { color: #dc3545; }
        .dias-aviso  { color: #fd7e14; }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

    <?php require_once "app/views/inc/navbar.php"; ?>
    <?php require_once "app/views/inc/main-sidebar.php"; ?>

    <div class="content-wrapper">

        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h4 class="m-0">Inscripciones en línea pendientes</h4>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="<?php echo APP_URL.'dashboard/'; ?>">Dashboard</a></li>
                            <li class="breadcrumb-item active">Inscripciones pendientes</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <section class="content">
            <div class="container-fluid">

                <div class="callout callout-warning">
                    <p class="mb-0">
                        <i class="fas fa-info-circle"></i>
                        Alumnos que se registraron desde el <strong>enlace de inscripción</strong> y a los que todavía
                        les falta completar algo. El formulario público solo captura los datos personales: no asigna
                        horario, no registra el rubro de inscripción ni el contacto de emergencia.
                        Al completar todos los requisitos el alumno desaparece de esta lista.
                    </p>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-user-clock"></i> Por completar
                            <span class="badge badge-warning ml-1"><?php echo count($filas); ?></span>
                        </h3>
                        <div class="card-tools">
                            <form method="GET" class="form-inline">
                                <select name="sede" class="form-control form-control-sm mr-2">
                                    <option value="">Todas las sedes</option>
                                    <?php foreach ($sedes as $sede): ?>
                                        <option value="<?php echo $sede['sede_id']; ?>"
                                            <?php echo ((string)$sedeFiltro === (string)$sede['sede_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($sede['sede_nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="input-group input-group-sm" style="width:240px;">
                                    <input type="text" name="q" class="form-control" placeholder="Buscar alumno o cédula"
                                           value="<?php echo htmlspecialchars($busqueda); ?>">
                                    <div class="input-group-append">
                                        <button type="submit" class="btn btn-default"><i class="fas fa-search"></i></button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="card-body table-responsive p-0">
                        <table id="tablaPendientes" class="table table-hover table-sm text-nowrap">
                            <thead>
                                <tr>
                                    <th>Alumno</th>
                                    <th>Cédula</th>
                                    <th>Edad</th>
                                    <th>Sede</th>
                                    <th>Representante</th>
                                    <th>Se inscribió</th>
                                    <th>Espera</th>
                                    <th>Inscripción</th>
                                    <th>Horario</th>
                                    <th>Cont. emergencia</th>
                                    <th>Foto</th>
                                    <th style="width:150px;">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($filas as $f): ?>
                                <?php
                                    $dias  = (int) $f['dias_espera'];
                                    $clase = $dias >= 15 ? 'dias-alerta' : ($dias >= 7 ? 'dias-aviso' : '');

                                    /* El rubro puede existir pero seguir impago: se distingue,
                                       porque "inscrito" y "cobrado" no son lo mismo. */
                                    if ((int) $f['tiene_inscripcion'] === 0) {
                                        $badgeIns = '<span class="badge badge-danger"><i class="fas fa-times"></i> Sin registrar</span>';
                                    } elseif ($f['estado_inscripcion'] === 'P') {
                                        $badgeIns = '<span class="badge badge-warning"><i class="fas fa-clock"></i> Por cobrar</span>';
                                    } else {
                                        $badgeIns = '<span class="badge badge-success"><i class="fas fa-check"></i> Registrada</span>';
                                    }
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($f['alumno']); ?></td>
                                    <td><?php echo htmlspecialchars($f['alumno_identificacion']); ?></td>
                                    <td><?php echo (int) $f['edad']; ?> años</td>
                                    <td><?php echo htmlspecialchars($f['sede_nombre']); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($f['representante']); ?>
                                        <?php if (!empty($f['repre_celular'])): ?>
                                            <br><small class="text-muted"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($f['repre_celular']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td data-order="<?php echo htmlspecialchars($f['fecha_online']); ?>">
                                        <?php echo htmlspecialchars(date('d/m/Y', strtotime($f['fecha_online']))); ?>
                                    </td>
                                    <td class="dias-espera <?php echo $clase; ?>" data-order="<?php echo $dias; ?>">
                                        <?php echo $dias; ?> d
                                    </td>
                                    <td><?php echo $badgeIns; ?></td>
                                    <td><?php echo badgePendiente((int) $f['tiene_horario'] > 0, 'Asignado', 'Sin horario'); ?></td>
                                    <td><?php echo badgePendiente((int) $f['tiene_emergencia'] > 0, 'Registrado', 'Falta'); ?></td>
                                    <td><?php echo badgePendiente((int) $f['sin_foto'] === 0, 'Sí', 'Sin foto'); ?></td>
                                    <td>
                                        <a href="<?php echo APP_URL.'alumnoUpdate/'.$f['alumno_id'].'/'; ?>" target="_blank"
                                           class="btn btn-xs btn-primary" title="Completar información">
                                            <i class="fas fa-pen"></i> Completar
                                        </a>
                                        <a href="<?php echo APP_URL.'alumnoProfile/'.$f['alumno_id'].'/'; ?>" target="_blank"
                                           class="btn btn-xs btn-info" title="Ver ficha">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </section>
    </div>

    <?php require_once "app/views/inc/footer.php"; ?>
</div>

<script src="<?php echo APP_URL; ?>app/views/dist/plugins/jquery/jquery.min.js"></script>
<script src="<?php echo APP_URL; ?>app/views/dist/plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo APP_URL; ?>app/views/dist/js/adminlte.js"></script>
<script src="<?php echo APP_URL; ?>app/views/dist/js/sweetalert2.all.min.js"></script>
<script src="<?php echo APP_URL; ?>app/views/dist/plugins/datatables/jquery.dataTables.min.js"></script>
<script src="<?php echo APP_URL; ?>app/views/dist/plugins/datatables-bs4/js/dataTables.bootstrap4.min.js"></script>

<script>
$(function () {
    $('#tablaPendientes').DataTable({
        "pageLength": 25,
        "order": [[6, "desc"]],   // los que llevan más tiempo esperando, primero
        "autoWidth": false,
        "columnDefs": [{ "orderable": false, "targets": [11] }],
        "language": {
            "emptyTable":     "No hay inscripciones en línea pendientes",
            "info":           "Mostrando _START_ a _END_ de _TOTAL_ alumnos",
            "infoEmpty":      "Mostrando 0 a 0 de 0 alumnos",
            "infoFiltered":   "(filtrado de _MAX_ alumnos)",
            "lengthMenu":     "Mostrar _MENU_ alumnos",
            "loadingRecords": "Cargando...",
            "processing":     "Procesando...",
            "search":         "Buscar:",
            "zeroRecords":    "No se encontraron registros coincidentes",
            "paginate": {
                "first":    "Primero",
                "last":     "Último",
                "next":     "Siguiente",
                "previous": "Anterior"
            }
        }
    });
});
</script>

</body>
</html>
