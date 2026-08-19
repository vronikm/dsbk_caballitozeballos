<?php
	use app\controllers\dashboardController;
	$insDashboard = new dashboardController();

	/*
	| El dashboard muestra dos cosas distintas según a quién sirve:
	|
	|   · Panel operativo: horarios a cargo y días con asistencia tomada.
	|     Se muestra a quien tiene una ficha de empleado detrás, que es
	|     quien está en la cancha.
	|   · Panel gerencial: alumnos, recaudación y mora por sede. Se reserva
	|     a quien puede ver el balance del mes; un profesor no tiene por qué
	|     conocer la caja de la escuela.
	|
	| La condición del bloque gerencial se apoya en un permiso ya existente
	| en lugar de mirar el número de rol, para que siga funcionando cuando
	| se creen roles nuevos.
	*/
	$verOperativo = empleado_actual() > 0;
	$verGerencial = es_superadministrador() || usuario_tiene_permiso('balanceResultados');

	$sedes = [];
	$totalRepresentantes = $totalAlumnosActivos = $totalAlumnosInactivos = 0;

	// Helper: extrae un valor escalar de un PDOStatement; 0 si no hay fila
	$valorEscalar = function($stmt, $col){
		if($stmt && $stmt->rowCount() > 0){
			$row = $stmt->fetch();
			return isset($row[$col]) ? $row[$col] : 0;
		}
		return 0;
	};

	if($verGerencial){
		// Sedes dinámicas (una tarjeta por cada registro de general_sede)
		$sedes = $insDashboard->obtenerSedes()->fetchAll();

		// Totales globales del consolidado
		$totalRepresentantes   = $valorEscalar($insDashboard->obtenerRepresentantes(),   "totalRepresentantes");
		$totalAlumnosActivos   = $valorEscalar($insDashboard->totalAlumnosActivos(),     "totalAlumnosActivos");
		$totalAlumnosInactivos = $valorEscalar($insDashboard->totalAlumnosInactivos(),   "totalAlumnosInactivos");
	}

	// Se acumula recorriendo las sedes (ver bucle de tarjetas)
	$totalPendientes = 0;
?>

<!DOCTYPE html>
<html lang="es">
  	<head>
		<meta charset="UTF-8">
		<meta http-equiv="X-UA-Compatible" content="IE=edge">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<title><?php echo APP_NAME; ?>| Dashboard</title>
		<link rel="icon" type="image/png" href="<?php echo APP_URL; ?>app/views/dist/img/Logos/logo_bsc.png">
		<!-- Google Font: Source Sans Pro -->
		<link rel="stylesheet" href="<?php echo DS_HUB_URL; ?>ds_core/assets/css/fuentes.css">
	<link rel="stylesheet" href="<?php echo DS_HUB_URL; ?>ds_core/assets/css/core.css">
		<!-- Font Awesome -->
		<link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/plugins/fontawesome-free/css/all.min.css">
		<!-- Ionicons -->
		<link rel="stylesheet" href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css">
		<!-- Tempusdominus Bootstrap 4 -->
		<link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/plugins/tempusdominus-bootstrap-4/css/tempusdominus-bootstrap-4.min.css">
		<!-- Bootstrap 5 -->
		<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
		  integrity="sha256-9kPW/n5nn53j4WMRYAxe9c1rCY96Oogo/MKSVdKzPmI="
		  crossorigin="anonymous"
		/>
		<!-- iCheck -->
		<link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/plugins/icheck-bootstrap/icheck-bootstrap.min.css">
		<!-- JQVMap -->
		<link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/plugins/jqvmap/jqvmap.min.css">
		<!-- Theme style -->
		<link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/css/adminlte.css">
		<link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/css/apexcharts.css">
		<link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/css/bootstrap-icons.min.css">

		<link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/css/sweetalert2.min.css">
		<script src="<?php echo APP_URL; ?>app/views/dist/js/sweetalert2.all.min.js" ></script>
	
  	</head>
	<body class="hold-transition sidebar-mini layout-fixed">
		<div class="wrapper">

			<!-- Navbar -->
			<?php require_once "app/views/inc/navbar.php"; ?>
			<!-- /.navbar -->

			<!-- Main Sidebar Container -->
			<?php require_once "app/views/inc/main-sidebar.php"; ?>
			<!-- /.Main Sidebar Container -->  

			<!-- vista -->
			<div class="content-wrapper">

				<!-- Content Header (Page header) -->
				<div class="content-header">
					<div class="container-fluid">
					<div class="row mb-1">
						<div class="col-sm-6">
						<h1 class="m-0">Dashboard</h1>
						</div><!-- /.col -->
						<div class="col-sm-6">
						<ol class="breadcrumb float-sm-right">
							<li class="breadcrumb-item"><a href="#">Inicio</a></li>
							<li class="breadcrumb-item active">Dashboard v1</li>
						</ol>
						</div><!-- /.col -->
					</div><!-- /.row -->
					</div><!-- /.container-fluid -->
				</div>
				<!-- /.content-header -->

				<!-- Main content -->
				<section class="content">
					
					<div class="container-fluid">

					<!-- Panel operativo: horarios a cargo y asistencia del mes -->
					<?php if($verOperativo): ?>
						<?php require "app/views/inc/dashboard-operativo.php"; ?>
					<?php endif; ?>

					<?php if(!$verOperativo && !$verGerencial): ?>
						<div class="alert alert-info">
							<i class="fas fa-info-circle mr-1"></i>
							No hay indicadores que mostrar para su rol. Use el menú lateral
							para ir a las pantallas que tenga asignadas.
						</div>
					<?php endif; ?>

					<!-- Panel gerencial: alumnos, recaudación y mora por sede -->
					<?php if($verGerencial && empty($sedes)): ?>
						<div class="alert alert-info">No hay sedes registradas. Registre una sede para ver sus indicadores.</div>
					<?php endif; ?>

					<?php foreach($sedes as $sede):
						$sedeId          = $sede["sede_id"];
						$totalActivos    = $valorEscalar($insDashboard->obtenerAlumnosActivos($sedeId),   "totalActivos");
						$totalInactivos  = $valorEscalar($insDashboard->obtenerAlumnosInactivos($sedeId), "totalInactivos");
						$totalCancelado  = $valorEscalar($insDashboard->obtenerPagosCancelados($sedeId),  "totalCancelados");
						$totalPendiente  = $valorEscalar($insDashboard->obtenerPagosPendientes($sedeId),  "totalPendientes");
						$totalPendientes += $totalPendiente; // acumula para el consolidado
					?>
						<div class="card card-default">
							<div class="card-header">
								<h3 class="card-title"><?php echo strtoupper(htmlspecialchars($sede["sede_nombre"])); ?></h3>
								<div class="card-tools">
									<button type="button" class="btn btn-tool" data-card-widget="collapse">
										<i class="fas fa-minus"></i>
									</button>
								</div>
							</div>

							<div class="card-body">
								<div class="row">
									<div class="col-lg-3 col-6">
									<!-- small box -->
										<div class="small-box bg-info">
											<div class="inner">
											<h3><?php echo $totalActivos; ?></h3>

											<p>Alumnos activos</p>
											</div>
											<div class="icon">
											<i class="ion ion-person"></i>
											</div>
											<a href="<?php echo APP_URL;?>alumnoList/" class="small-box-footer">Ver detalle <i class="fas fa-arrow-circle-right"></i></a>
										</div>
									</div>
									<!-- ./col -->
									<div class="col-lg-3 col-6">
									<!-- small box -->
									<div class="small-box bg-success">
										<div class="inner">
										<h3><?php echo $totalCancelado; ?></h3>

										<p>Pagos receptados mes</p>
										</div>
										<div class="icon">
										<i class="ion ion-cash"></i>
										</div>
										<a href="<?php echo APP_URL;?>reportePagos/<?php echo $sedeId; ?>" class="small-box-footer">Ver detalle <i class="fas fa-arrow-circle-right"></i></a>
									</div>
									</div>
									<!-- ./col -->
									<div class="col-lg-3 col-6">
									<!-- small box -->
									<div class="small-box bg-warning">
										<div class="inner">
										<h3><?php echo $totalInactivos; ?></h3>

										<p>Alumnos inactivos</p>
										</div>
										<div class="icon">
										<i class="ion ion-android-warning"></i>
										</div>
										<a href="<?php echo APP_URL;?>alumnoList/" class="small-box-footer">Ver detalle <i class="fas fa-arrow-circle-right"></i></a>
									</div>
									</div>
									<!-- ./col -->
									<div class="col-lg-3 col-6">
									<!-- small box -->
									<div class="small-box bg-danger">
										<div class="inner">
										<h3><?php echo $totalPendiente; ?></h3>

										<p>Pagos pendientes</p>
										</div>
										<div class="icon">
										<i class="ion ion-cash"></i>
										</div>
										<a href="<?php echo APP_URL;?>reportePendientes/<?php echo $sedeId; ?>" class="small-box-footer">Ver detalle <i class="fas fa-arrow-circle-right"></i></a>
									</div>
									</div>
									<!-- ./col -->
								</div>
							</div>
						</div>
					<?php endforeach; ?>

					<?php if($verGerencial): ?>
					<div class="card card-default" style="padding: 0.5rem;">
					<div class="card-header" style="padding: 0.1rem 0.5rem;">
						<h3 class="card-title">CONSOLIDADO</h3>
						<div class="card-tools">
							<button type="button" class="btn btn-tool" data-card-widget="collapse">
								<i class="fas fa-minus"></i>
							</button>
						</div>
					</div>
					<div class="card-body" style="padding: 0.5rem;">
						<div class="row">
							<!-- Representantes activos -->
							<div class="col-md-3 mb-3">
								<a href="<?php echo APP_URL; ?>representanteList/" class="text-decoration-none">
									<div class="info-box d-flex shadow-sm rounded border">
										<span class="info-box-icon bg-warning text-white d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
										<i class="bi bi-people-fill fs-5"></i>
										</span>
										<div class="info-box-content ms-2">
											<span class="info-box-text h7 text-muted">Representantes</span>
											<span class="info-box-number h5 text-dark"><?php echo $totalRepresentantes; ?></span>
										</div>
									</div>
								</a>
							</div>

							<!-- Alumnos activos -->
							<div class="col-md-3 mb-3 align-items-center">
								<a href="#" class="text-decoration-none">
									<div class="info-box d-flex shadow-sm rounded border">
										<span class="info-box-icon bg-warning text-white d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
										<i class="bi bi-people-fill fs-5"></i>
										</span>
										<div class="info-box-content ms-2">
											<span class="info-box-text h7 text-muted">Alumnos activos</span>
											<span class="info-box-number h5 text-dark"><?php echo $totalAlumnosActivos; ?></span>
										</div>
									</div>
								</a>
							</div>

							<!-- Alumnos inactivos -->
							<div class="col-md-3 mb-3 align-items-center">
								<a href="#" class="text-decoration-none">
									<div class="info-box d-flex shadow-sm rounded border">
										<span class="info-box-icon bg-warning text-white d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
										<i class="bi bi-people-fill fs-5"></i>
										</span>
										<div class="info-box-content ms-2">
											<span class="info-box-text h7 text-muted">Alumnos Inactivos</span>
											<span class="info-box-number h5 text-dark"><?php echo $totalAlumnosInactivos; ?></span>
										</div>
									</div>
								</a>
							</div>
							<!-- Pendientes -->
							<div class="col-md-3 mb-3 align-items-center">
								<a href="#" class="text-decoration-none">
									<div class="info-box d-flex shadow-sm rounded border">
										<span class="info-box-icon bg-warning text-white d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
										<i class="bi bi-people-fill fs-5"></i>
										</span>
										<div class="info-box-content ms-2">
											<span class="info-box-text h7 text-muted">Alumnos con mora</span>
											<span class="info-box-number h5 text-dark"><?php echo $totalPendientes; ?></span>
										</div>
									</div>
								</a>
							</div>
						</div>	
					</div>
				</div>
				<?php endif; ?>
					<!-- /.row -->
					</div><!-- /.container-fluid -->
				</section>
				<!-- /.content -->
			
			</div>
			<!-- /.vista -->

			<?php require_once "app/views/inc/footer.php"; ?>

			<!-- Control Sidebar -->
			<aside class="control-sidebar control-sidebar-dark">
				<!-- Control sidebar content goes here -->
			</aside>
		<!-- /.control-sidebar -->
		</div>
		<!-- ./wrapper -->
    
		<!-- jQuery -->
		<script src="<?php echo APP_URL; ?>app/views/dist/plugins/jquery/jquery.min.js"></script>
		<!-- jQuery UI 1.11.4 -->
		<script src="<?php echo APP_URL; ?>app/views/dist/plugins/jquery-ui/jquery-ui.min.js"></script>
		<!-- Resolve conflict in jQuery UI tooltip with Bootstrap tooltip -->

		<!-- Bootstrap 4 -->
		<script src="<?php echo APP_URL; ?>app/views/dist/plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
		<!-- ChartJS -->
		<script src="<?php echo APP_URL; ?>app/views/dist/plugins/chart.js/Chart.min.js"></script>
		<!-- Sparkline -->
		<script src="<?php echo APP_URL; ?>app/views/dist/plugins/sparklines/sparkline.js"></script>
		<!-- JQVMap -->
		<script src="<?php echo APP_URL; ?>app/views/dist/plugins/jqvmap/jquery.vmap.min.js"></script>
		<script src="<?php echo APP_URL; ?>app/views/dist/plugins/jqvmap/maps/jquery.vmap.usa.js"></script>
		<!-- jQuery Knob Chart -->
		<script src="<?php echo APP_URL; ?>app/views/dist/plugins/jquery-knob/jquery.knob.min.js"></script>
		<!-- daterangepicker -->
		<script src="<?php echo APP_URL; ?>app/views/dist/plugins/moment/moment.min.js"></script>
		<!-- Tempusdominus Bootstrap 4 -->
		<script src="<?php echo APP_URL; ?>app/views/dist/plugins/tempusdominus-bootstrap-4/js/tempusdominus-bootstrap-4.min.js"></script>
		<!-- AdminLTE App -->
		<script src="<?php echo APP_URL; ?>app/views/dist/js/adminlte.js"></script>

		<script src="<?php echo APP_URL; ?>app/views/dist/js/ajax.js" ></script>
		<script src="<?php echo APP_URL; ?>app/views/dist/js/main.js" ></script>
	
	</body>
</html>