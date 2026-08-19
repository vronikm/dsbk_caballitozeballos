<?php
	use app\controllers\reporteController;
	$insPendientes = new reporteController();	
	
	$sede_id 	= ($url[1] != "") ? $url[1] : 0;
?>


<html lang="es">
  <head>
    <meta charset="UTF-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo APP_NAME; ?>| Pagos Pendientes</title>
	<link rel="icon" type="image/png" href="<?php echo APP_URL; ?>app/views/dist/img/Logos/logo_bsc.png">
	<!-- Google Font: Source Sans Pro -->
	<link rel="stylesheet" href="<?php echo DS_HUB_URL; ?>ds_core/assets/css/fuentes.css">
	<link rel="stylesheet" href="<?php echo DS_HUB_URL; ?>ds_core/assets/css/core.css">
	<!-- Font Awesome -->
	<link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/plugins/fontawesome-free/css/all.min.css">
	<!-- DataTables -->
	<link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/plugins/datatables-bs4/css/dataTables.bootstrap4.min.css">
	<link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/plugins/datatables-responsive/css/responsive.bootstrap4.min.css">
	<link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/plugins/datatables-buttons/css/buttons.bootstrap4.min.css">
	<!-- Theme style -->
	<link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/css/adminlte.css">


	<link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/css/sweetalert2.min.css">
	<script src="<?php echo APP_URL; ?>app/views/dist/js/sweetalert2.all.min.js" ></script>
    
  </head>
  <body class="hold-transition sidebar-mini layout-fixed">
    <div class="wrapper">

		<!-- Preloader -->
		<!--?php require_once "app/views/inc/preloader.php"; ?-->
		<!-- /.Preloader -->

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
				<div class="row mb-2">
					<div class="col-sm-6">
					<h1 class="m-0">Pagos pendientes</h1>
					</div><!-- /.col -->
					<div class="col-sm-6">
					<ol class="breadcrumb float-sm-right">
						<li class="breadcrumb-item"><a href="#">Nuevo</a></li>
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
					
					<div class="row">
						<div class="col-12">
							<div class="card">
							<div class="card-header">
								<h3 class="card-title">Alumnos</h3>
							</div>
							<!-- ./card-header -->
							<div class="card-body">
								<table class="table table-bordered table-striped table-sm">
									<thead>
										<tr>
											<th>Identificación</th>
											<th>Nombres</th>
											<th>T.Pendientes</th>
											<th>Saldos Pendientes</th>
											<th>T.Pensiones</th>
											<th>Valor Pensiones</th>									
										</tr>
									</thead>
									<tbody>
										<?php 
											echo $insPendientes->valoresPendientes($sede_id); 
										?>
									</tbody>
								</table>
							</div>
							<!-- /.card-body -->
							</div>
							<!-- /.card -->
						</div>
					</div>
				
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
	<!-- Bootstrap 4 -->
	<script src="<?php echo APP_URL; ?>app/views/dist/plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
	<!-- DataTables  & Plugins -->
	<script src="<?php echo APP_URL; ?>app/views/dist/plugins/datatables/jquery.dataTables.min.js"></script>
	<script src="<?php echo APP_URL; ?>app/views/dist/plugins/datatables-bs4/js/dataTables.bootstrap4.min.js"></script>
	<script src="<?php echo APP_URL; ?>app/views/dist/plugins/datatables-responsive/js/dataTables.responsive.min.js"></script>
	<script src="<?php echo APP_URL; ?>app/views/dist/plugins/datatables-responsive/js/responsive.bootstrap4.min.js"></script>
	<script src="<?php echo APP_URL; ?>app/views/dist/plugins/datatables-buttons/js/dataTables.buttons.min.js"></script>
	<script src="<?php echo APP_URL; ?>app/views/dist/plugins/datatables-buttons/js/buttons.bootstrap4.min.js"></script>
	<script src="<?php echo APP_URL; ?>app/views/dist/plugins/jszip/jszip.min.js"></script>
	<script src="<?php echo APP_URL; ?>app/views/dist/plugins/pdfmake/pdfmake.min.js"></script>
	<script src="<?php echo APP_URL; ?>app/views/dist/plugins/pdfmake/vfs_fonts.js"></script>
	<script src="<?php echo APP_URL; ?>app/views/dist/plugins/datatables-buttons/js/buttons.html5.min.js"></script>
	<script src="<?php echo APP_URL; ?>app/views/dist/plugins/datatables-buttons/js/buttons.print.min.js"></script>
	<script src="<?php echo APP_URL; ?>app/views/dist/plugins/datatables-buttons/js/buttons.colVis.min.js"></script>
	<!-- AdminLTE App -->
	<script src="<?php echo APP_URL; ?>app/views/dist/js/adminlte.min.js"></script>
	
	<!-- Page specific script -->
	<script>
		$(function () {
			$("#example1").DataTable({
			"responsive": true, "lengthChange": false, "autoWidth": false,
			}).buttons().container().appendTo('#example1_wrapper .col-md-6:eq(0)');			    
		});
	</script>

	<script src="<?php echo APP_URL; ?>app/views/dist/js/ajax.js" ></script>
	<script src="<?php echo APP_URL; ?>app/views/dist/js/main.js" ></script>
    
  </body>
</html>








