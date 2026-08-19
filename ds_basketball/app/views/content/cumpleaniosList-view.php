<?php
	use app\controllers\cumpleaniosController;
	$insCumple = new cumpleaniosController();

	// Fecha seleccionada (parámetro URL o hoy)
	$fechaFiltro = isset($url[1]) && $url[1] !== '' ? $insCumple->limpiarCadena($url[1]) : date('Y-m-d');

	// Validar formato de fecha
	$fechaObj = DateTime::createFromFormat('Y-m-d', $fechaFiltro);
	if (!$fechaObj || $fechaObj->format('Y-m-d') !== $fechaFiltro) {
		$fechaFiltro = date('Y-m-d');
		$fechaObj    = new DateTime();
	}

	$datos = $insCumple->listarCumpleanios($fechaFiltro);
	$alumnos = $datos->rowCount() > 0 ? $datos->fetchAll() : [];

	// Formatear fecha en español
	$meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
	$dias  = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
	$fechaLabel = $dias[(int)$fechaObj->format('w')] . ', ' . (int)$fechaObj->format('d') . ' de ' . $meses[(int)$fechaObj->format('n') - 1] . ' de ' . $fechaObj->format('Y');
	$esHoy = ($fechaFiltro === date('Y-m-d'));
?>
<!DOCTYPE html>
<html lang="es">
<head>
	<meta charset="UTF-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo APP_NAME; ?> | Cumpleaños</title>
	<link rel="icon" type="image/png" href="<?php echo APP_URL; ?>app/views/dist/img/Logos/logo_bsc.png">
	<!-- Google Font -->
	<link rel="stylesheet" href="<?php echo DS_HUB_URL; ?>ds_core/assets/css/fuentes.css">
	<link rel="stylesheet" href="<?php echo DS_HUB_URL; ?>ds_core/assets/css/core.css">
	<!-- Font Awesome -->
	<link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/plugins/fontawesome-free/css/all.min.css">
	<!-- AdminLTE -->
	<link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/css/adminlte.css">
	<!-- SweetAlert2 -->
	<link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/css/sweetalert2.min.css">
	<link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/css/cumples.css">
</head>

<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

	<!-- Navbar -->
	<?php require_once "app/views/inc/navbar.php"; ?>
	<!-- Sidebar -->
	<?php require_once "app/views/inc/main-sidebar.php"; ?>

	<!-- Content Wrapper -->
	<div class="content-wrapper">

		<!-- Content Header -->
		<div class="content-header">
			<div class="container-fluid">
				<div class="row mb-2">
					<div class="col-sm-6">
						<h3 class="m-0" style="color:var(--azul);">
							<i class="fas fa-birthday-cake" style="color:var(--amarillo);"></i>
							Cumpleaños
						</h3>
					</div>
					<div class="col-sm-6">
						<ol class="breadcrumb float-sm-right">
							<li class="breadcrumb-item"><a href="<?php echo APP_URL; ?>dashboard/">Inicio</a></li>
							<li class="breadcrumb-item active">Cumpleaños</li>
						</ol>
					</div>
				</div>
			</div>
		</div>

		<!-- Main content -->
		<section class="content">
			<div class="container-fluid">
				<!-- Barra unificada: filtro + fecha -->
				<div class="cumple-filtro mb-4">
					<i class="fas fa-<?php echo $esHoy ? 'star' : 'birthday-cake'; ?>"></i>
					<span class="fecha-info">
						<?php if ($esHoy): ?>
							Cumpleaños de <strong>hoy</strong> — <?php echo ucfirst($fechaLabel); ?>
						<?php else: ?>
							Cumpleaños del <?php echo ucfirst($fechaLabel); ?>
						<?php endif; ?>
					</span>
					<label for="inputFecha">Fecha:</label>
					<input type="date" id="inputFecha" value="<?php echo htmlspecialchars($fechaFiltro); ?>">
					<button class="btn-hoy" id="btnHoy">
						<i class="fas fa-home"></i> Hoy
					</button>
					<span class="cumple-badge">
						<i class="fas fa-birthday-cake"></i>
						<?php echo count($alumnos); ?> cumpleaño<?php echo count($alumnos) !== 1 ? 's' : ''; ?>
					</span>
				</div>

				<!-- Grid de alumnos -->
				<?php if (empty($alumnos)): ?>
					<div class="cumple-empty">
						<i class="fas fa-birthday-cake"></i>
						<p style="font-size:1.1rem; font-weight:600; color:var(--azul);">
							No hay alumnos que cumplan años <?php echo $esHoy ? 'hoy' : 'en esta fecha'; ?>.
						</p>
						<p>Prueba seleccionando otra fecha.</p>
					</div>
				<?php else: ?>
					<div class="row">
						<?php foreach ($alumnos as $a): ?>
							<?php
								$fotoSrc = ($a['alumno_imagen'] !== '')
									? media_url('alumno', $a['alumno_imagen'])
									: media_url('alumno', 'alumno.png');
								$nombreCompleto = trim($a['alumno_primernombre'] . ' ' . $a['alumno_segundonombre'])
												. ' ' . trim($a['alumno_apellidopaterno'] . ' ' . $a['alumno_apellidomaterno']);
								$edad = (int)$a['edad'];
								$fechaNac = DateTime::createFromFormat('Y-m-d', $a['alumno_fechanacimiento']);
								$fechaNacLabel = $fechaNac ? $fechaNac->format('d/m/Y') : $a['alumno_fechanacimiento'];
							?>
							<div class="col-xl-3 col-lg-3 col-md-4 col-sm-6 mb-4"> <!-- Ajusta el número de columnas según el tamaño de pantalla-->
								<div class="alumno-card card">
									<div class="card-header-azul">
										<i class="fas fa-birthday-cake"
										   style="position:absolute;top:14px;right:14px;color:rgba(255,224,1,.45);font-size:1.1rem;"></i>
									</div>
									<div class="card-body-custom">
										<div class="foto-wrapper">
											<img src="<?php echo $fotoSrc; ?>"
											     alt="<?php echo htmlspecialchars($nombreCompleto); ?>"
											     onerror="this.src='<?php echo media_url('alumno', 'alumno.png'); ?>'">
										</div>
										<div class="mt-3 alumno-nombre"><?php echo htmlspecialchars($nombreCompleto); ?></div>
										<div class="alumno-id"><?php echo htmlspecialchars($a['alumno_identificacion']); ?></div>
										<span class="edad-badge">
											<i class="fas fa-birthday-cake"></i>
											<?php echo $edad; ?> años
										</span>
										<div class="fecha-nacimiento">
											<i class="fas fa-calendar"></i> <?php echo $fechaNacLabel; ?>
										</div>
										<a href="<?php echo APP_URL . 'cumpleaniosTarjeta/' . $a['alumno_id'] . '/'; ?>"
										   class="btn-tarjeta"
										   target="_blank">
											<i class="fas fa-gift"></i> Ver tarjeta
										</a>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

			</div>
		</section>
	</div>

	<!-- Footer -->
	<?php require_once "app/views/inc/footer.php"; ?>

</div>

<!-- Scripts -->
<script src="<?php echo APP_URL; ?>app/views/dist/plugins/jquery/jquery.min.js"></script>
<script src="<?php echo APP_URL; ?>app/views/dist/plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo APP_URL; ?>app/views/dist/js/adminlte.js"></script>
<script src="<?php echo APP_URL; ?>app/views/dist/js/sweetalert2.all.min.js"></script>
<script src="<?php echo APP_URL; ?>app/views/dist/js/ajax.js"></script>

<script>
	const inputFecha = document.getElementById('inputFecha');
	const btnHoy     = document.getElementById('btnHoy');

	// Navegar al cambiar la fecha
	inputFecha.addEventListener('change', function () {
		const fecha = this.value;
		if (fecha) {
			window.location.href = '<?php echo APP_URL; ?>cumpleaniosList/' + fecha + '/';
		}
	});

	// Botón "Hoy"
	btnHoy.addEventListener('click', function () {
		window.location.href = '<?php echo APP_URL; ?>cumpleaniosList/';
	});
</script>

</body>
</html>
