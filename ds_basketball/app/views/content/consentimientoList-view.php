<?php
	use app\controllers\consentimientoController;

	$insConsent = new consentimientoController();

	$sedeFiltro = isset($url[1]) ? $insConsent->limpiarCadena($url[1]) : '';
	$busqueda   = isset($_GET['q']) ? $insConsent->limpiarCadena($_GET['q']) : '';

	$filas = $insConsent->listarConsentimientos($sedeFiltro, $busqueda);

	/* Devuelve el badge del estado de un consentimiento */
	function badgeConsent($otorgado, $origen, $fecha, $usuario) {
		if ($otorgado === null) {
			return '<span class="badge badge-secondary">Sin registro</span>';
		}
		if ($otorgado === 'N') {
			return '<span class="badge badge-dark" title="Revocado el '.htmlspecialchars($fecha).'">Revocado</span>';
		}
		$fuente = ($origen === 'FORMULARIO')
				? '<span class="badge badge-info" title="Aceptado en línea el '.htmlspecialchars($fecha).'">Formulario</span>'
				: '<span class="badge badge-warning" title="Registrado por '.htmlspecialchars($usuario ?? '').' el '.htmlspecialchars($fecha).'">Administrador</span>';

		return '<span class="badge badge-success">Otorgado</span> '.$fuente;
	}
?>
<!DOCTYPE html>
<html lang="es">
<head>
	<meta charset="UTF-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo APP_NAME; ?> | Consentimientos LOPDP</title>
	<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
	<link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/plugins/fontawesome-free/css/all.min.css">
	<link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/css/adminlte.css">
	<link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/css/sweetalert2.min.css">
	<link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/plugins/datatables-bs4/css/dataTables.bootstrap4.min.css">
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
						<h4 class="m-0">Consentimientos LOPDP</h4>
					</div>
					<div class="col-sm-6">
						<ol class="breadcrumb float-sm-right">
							<li class="breadcrumb-item"><a href="<?php echo APP_URL.'dashboard/'; ?>">Dashboard</a></li>
							<li class="breadcrumb-item active">Consentimientos</li>
						</ol>
					</div>
				</div>
			</div>
		</div>

		<section class="content">
			<div class="container-fluid">

				<div class="callout callout-info">
					<p class="mb-0">
						<i class="fas fa-info-circle"></i>
						Cada autorización indica <strong>de dónde proviene</strong>:
						<span class="badge badge-info">Formulario</span> si el representante la aceptó en el
						formulario de inscripción en línea, o
						<span class="badge badge-warning">Administrador</span> si se registró desde el sistema
						(por ejemplo, al recibir el formulario firmado en papel).
					</p>
				</div>

				<div class="card">
					<div class="card-header">
						<h3 class="card-title"><i class="fas fa-user-shield"></i> Autorizaciones por alumno</h3>
						<div class="card-tools">
							<form method="GET" class="form-inline">
								<div class="input-group input-group-sm" style="width:260px;">
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
						<table id="tablaConsentimientos" class="table table-hover table-sm text-nowrap">
							<thead>
								<tr>
									<th>Alumno</th>
									<th>Cédula</th>
									<th>Sede</th>
									<th>Representante</th>
									<th>Tratamiento de datos</th>
									<th>Uso de imagen</th>
									<th style="width:150px;">Acciones</th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ($filas as $f): ?>
								<tr>
									<td><?php echo htmlspecialchars($f['alumno']); ?></td>
									<td><?php echo htmlspecialchars($f['alumno_identificacion']); ?></td>
									<td><?php echo htmlspecialchars($f['sede_nombre']); ?></td>
									<td><?php echo htmlspecialchars($f['representante']); ?></td>
									<td><?php echo badgeConsent($f['datos_otorgado'], $f['datos_origen'], $f['datos_fecha'], $f['datos_usuario']); ?></td>
									<td><?php echo badgeConsent($f['imagen_otorgado'], $f['imagen_origen'], $f['imagen_fecha'], $f['imagen_usuario']); ?></td>
									<td>
										<button class="btn btn-xs btn-info btn-historial" data-id="<?php echo $f['alumno_id']; ?>"
												data-alumno="<?php echo htmlspecialchars($f['alumno']); ?>" title="Ver historial">
											<i class="fas fa-history"></i>
										</button>
										<button class="btn btn-xs btn-primary btn-registrar" data-id="<?php echo $f['alumno_id']; ?>"
												data-alumno="<?php echo htmlspecialchars($f['alumno']); ?>" title="Registrar o revocar">
											<i class="fas fa-pen"></i>
										</button>
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

<!-- Modal historial -->
<div class="modal fade" id="modalHistorial" tabindex="-1">
	<div class="modal-dialog modal-xl">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title"><i class="fas fa-history"></i> Historial de consentimientos &mdash; <span id="histAlumno"></span></h5>
				<button type="button" class="close" data-dismiss="modal">&times;</button>
			</div>
			<div class="modal-body" id="histContenido">
				<p class="text-muted mb-0">Cargando...</p>
			</div>
		</div>
	</div>
</div>

<!-- Modal registrar -->
<div class="modal fade" id="modalRegistrar" tabindex="-1">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title"><i class="fas fa-pen"></i> Registrar consentimiento</h5>
				<button type="button" class="close" data-dismiss="modal">&times;</button>
			</div>
			<div class="modal-body">
				<p class="text-muted">
					Alumno: <strong id="regAlumno"></strong>
				</p>
				<input type="hidden" id="reg_alumno_id">

				<div class="form-group">
					<label>Consentimiento</label>
					<select class="form-control" id="reg_tipo">
						<option value="DATOS">Tratamiento de datos personales</option>
						<option value="IMAGEN">Uso de imagen del menor</option>
					</select>
				</div>

				<div class="form-group">
					<label>Acción</label>
					<select class="form-control" id="reg_otorgado">
						<option value="S">Otorgar</option>
						<option value="N">Revocar</option>
					</select>
				</div>

				<div class="form-group">
					<label>Observación</label>
					<textarea class="form-control" id="reg_observacion" rows="2" maxlength="200"
							  placeholder="Ej: formulario firmado recibido en la sede el 12/08/2026"></textarea>
				</div>

				<div class="alert alert-warning mb-0" style="font-size:.85rem;">
					<i class="fas fa-exclamation-triangle"></i>
					Quedará registrado con origen <strong>Administrador</strong> y su usuario
					(<strong><?php echo htmlspecialchars($_SESSION['usuario'] ?? ''); ?></strong>).
					Regístrelo solo si tiene respaldo del consentimiento del representante.
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
				<button type="button" class="btn btn-primary" id="btnGuardarConsent">Guardar</button>
			</div>
		</div>
	</div>
</div>

<script src="<?php echo APP_URL; ?>app/views/dist/plugins/jquery/jquery.min.js"></script>
<script src="<?php echo APP_URL; ?>app/views/dist/plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo APP_URL; ?>app/views/dist/js/adminlte.js"></script>
<script src="<?php echo APP_URL; ?>app/views/dist/js/sweetalert2.all.min.js"></script>
<script src="<?php echo APP_URL; ?>app/views/dist/plugins/datatables/jquery.dataTables.min.js"></script>
<script src="<?php echo APP_URL; ?>app/views/dist/plugins/datatables-bs4/js/dataTables.bootstrap4.min.js"></script>

<script>
$(function () {

	$('#tablaConsentimientos').DataTable({
		"pageLength": 25,
		"order": [[0, "asc"]],
		"autoWidth": false,
		"language": {
			"emptyTable":     "No hay alumnos que mostrar",
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

	// Historial
	$(document).on('click', '.btn-historial', function () {
		var id = $(this).data('id');
		$('#histAlumno').text($(this).data('alumno'));
		$('#histContenido').html('<p class="text-muted mb-0">Cargando...</p>');
		$('#modalHistorial').modal('show');

		$.post('<?php echo APP_URL; ?>app/ajax/consentimientoAjax.php',
			{ modulo_consentimiento: 'historial', alumno_id: id },
			function (html) { $('#histContenido').html(html); }
		);
	});

	// Abrir modal de registro
	$(document).on('click', '.btn-registrar', function () {
		$('#reg_alumno_id').val($(this).data('id'));
		$('#regAlumno').text($(this).data('alumno'));
		$('#reg_observacion').val('');
		$('#modalRegistrar').modal('show');
	});

	// Guardar
	$('#btnGuardarConsent').click(function () {
		var btn = $(this);
		btn.prop('disabled', true);

		$.post('<?php echo APP_URL; ?>app/ajax/consentimientoAjax.php', {
			modulo_consentimiento: 'registrar',
			alumno_id:   $('#reg_alumno_id').val(),
			tipo:        $('#reg_tipo').val(),
			otorgado:    $('#reg_otorgado').val(),
			observacion: $('#reg_observacion').val()
		}, function (data) {
			btn.prop('disabled', false);
			var r = (typeof data === 'string') ? JSON.parse(data) : data;

			$('#modalRegistrar').modal('hide');
			Swal.fire({ title: r.titulo, text: r.texto, icon: r.icono })
				.then(function () { if (r.icono === 'success') location.reload(); });
		}).fail(function () {
			btn.prop('disabled', false);
			Swal.fire('Error', 'No se pudo guardar el consentimiento.', 'error');
		});
	});

});
</script>

</body>
</html>
