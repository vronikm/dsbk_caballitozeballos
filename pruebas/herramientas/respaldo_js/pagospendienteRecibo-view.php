<?php
	use app\controllers\pagosController;

	include 'app/lib/barcode.php';
	
	$generator = new barcode_generator();
	
	$symbology="qr";
	$optionsQR=array('sx'=>4,'sy'=>4,'p'=>-10);	

	$insAlumno = new pagosController();	

	$pagoid = ds_id_de_url($url, 1, APP_URL . 'pagosList/');
	//$mensaje=$insLogin->limpiarCadena($url[2]);	

	$alerta = "";

	// Capturamos el valor enviado en la URL
	$envio = $url[2] ?? ""; // <---- Asegúrate que $url esté disponible. $url[2] sería 1 o 0

	if($envio !== ""){
		if($envio == "1"){
			$alerta = [
				"tipo" => "simple",
				"titulo" => "Correo enviado",
				"texto" => "El correo fue enviado exitosamente.",
				"icono" => "success"
			];
		} elseif($envio == "0"){
			$alerta = [
				"tipo" => "simple",
				"titulo" => "Error de envío",
				"texto" => "No se pudo enviar el correo. Por favor, intente nuevamente.",
				"icono" => "error"
			];
		}
	}

	$datos=$insAlumno->generarReciboPendiente($pagoid);
	
	if($datos->rowCount()==1){
		$datos=$datos->fetch(); 

		$fecha_recibo = strrev($datos["transaccion_recibo"]);
		$first12Chars =  strrev(substr($datos["transaccion_recibo"], 0, 12));
		$nombre_sede  = $datos["sede_nombre"];
		
		$pairs = [];
		$length = strlen($first12Chars);

		for ($i = 0; $i < $length; $i += 2) {
			$pairs[] = substr($first12Chars, $i, 2);
		}
		$recibo_hora = $pairs[4].":".$pairs[2].":".$pairs[0];
		
	} else {
		/* El registro no existe: se vuelve al listado. Antes se
		   incluía el aviso pero la vista seguía ejecutando con
		   $datos aún como PDOStatement y moría más abajo. */
		header("Location: " . APP_URL . "pagosList/");
		exit();
	}

	$sede=$insAlumno->informacionSede($datos["alumno_sedeid"]);
	if($sede->rowCount()==1){
		$sede=$sede->fetch(); 
	}
?>

<!DOCTYPE html>
<html lang="es">
  <head>
    <meta charset="UTF-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo APP_NAME; ?> | Recibo</title>
	<link rel="icon" type="image/png" href="<?php echo APP_URL; ?>app/views/dist/img/Logos/logo_bsc.png">
	<!-- Google Font: Source Sans Pro -->
	<link rel="stylesheet" href="<?php echo DS_HUB_URL; ?>ds_core/assets/css/fuentes.css">
	<link rel="stylesheet" href="<?php echo DS_HUB_URL; ?>ds_core/assets/css/core.css">
	<!-- Font Awesome -->
	<link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/plugins/fontawesome-free/css/all.min.css">
	
	<!-- daterange picker -->
	<!-- iCheck for checkboxes and radio inputs -->
	<!-- Bootstrap Color Picker -->
	<!-- Tempusdominus Bootstrap 4 -->
	<!-- Select2 -->
	<!-- Bootstrap4 Duallistbox -->
	<!-- BS Stepper -->

	
	<!-- Theme style -->
	<link rel="stylesheet" href="<?php echo DS_HUB_URL; ?>ds_core/assets/vendor/overlayscrollbars/css/overlayscrollbars.min.css">
	<link rel="stylesheet" href="<?php echo DS_HUB_URL; ?>ds_core/assets/vendor/adminlte4/css/adminlte.min.css">


	<link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/css/sweetalert2.min.css">
	<script src="<?php echo APP_URL; ?>app/views/dist/js/sweetalert2.all.min.js" ></script>

	<!-- fileinput -->

  </head>
  <body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
    <div class="app-wrapper">

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
		<div class="app-main">

			<!-- Content Header (Page header) -->
			<div class="app-content-header">
				<div class="container-fluid">
					<div class="row mb-2">
						<div class="col-sm-6">
							<h1 class="m-0">Recibo <?php echo $datos['RUBRO']." ".$datos['pago_periodo'] ; ?></h1>
						</div><!-- /.col -->
						<div class="col-sm-6">
							<ol class="breadcrumb float-sm-end">
								<li class="breadcrumb-item"><a href="#">Inicio</a></li>
								<li class="breadcrumb-item active">Ficha Alumno</li>
							</ol>
						</div><!-- /.col -->
					</div><!-- /.row -->
				</div><!-- /.container-fluid -->
			</div>
			<!-- /.content-header -->
			
			<section class="app-content">
				<div class="container-fluid">
					<div class="row">
						<div class="col-1">
						</div>
						<div class="col-10">
							<!-- Main content -->
							<div class="invoice p-3 mb-3">							
								<!-- info row -->
								<div class="row invoice-info">
									<div class="col-sm-6 invoice-col">										
										<address class="text-center">												
											<img src="<?php echo ds_logo_url((int)$sede["sede_id"]) ?>" style="width: 200px; height: 100px;"/>
											<br>Dirección: <?php echo $sede["sede_direccion"]; ?><br>
											Celular: <?php echo $sede["sede_telefono"]; ?> - ECUADOR										
										</address>
									</div>
									<!-- /.col -->
									<div class="col-sm-6 invoice-col">									
										<address class="text-center">	
											<strong class="profile-username"><?php echo ds_nombre_organizacion_may((int)$sede["sede_id"]); ?></strong><br><br>								
											<div class="row">
												<div class="col-12 table-responsive">
													<div class="row">
														<div class="col-4"></div>														
														<div class="col-4">
															<table class="table table-striped table-sm">
																<thead>
																	<tr style="font-size: 12px">
																		<th>DIA</th>
																		<th>MES</th>
																		<th>AÑO</th>																
																	</tr>
																</thead>
																<tbody>
																	<tr style="font-size: 12px">
																		<td><?php echo  date('d', strtotime($datos['transaccion_fecharegistro'])); ?></td>
																		<td><?php echo date('m', strtotime($datos['transaccion_fecharegistro'])); ?></td>
																		<td><?php echo date('Y', strtotime($datos['transaccion_fecharegistro'])); ?></td>												
																	</tr>														
																</tbody>
															</table>
														</div>
														<div class="col-4"></div>
													</div>	
												</div>
												<!-- /.col -->
											</div>
										</address>
									</div>
									<!-- /.col -->								
								</div>
								<!-- Table row -->
								<div class="row">
									<div class="col-12 table-responsive">
										<table class="table table-striped table-sm">											
											<tbody>
												<tr>													
													<th style="width:200px;">POR</th>	
													<th>$ <?php echo $datos['transaccion_valor']; ?></th>
													<th class="text-end">RECIBO </th>
													<th class="text-center"><?php echo $datos['transaccion_recibo']; ?></th>																							
												</tr>	
												<tr>													
													<th>Recibo de: </th>	
													<td colspan="3"><?php echo $datos['alumno_primernombre']." ".$datos['alumno_segundonombre']." ".$datos['alumno_apellidopaterno'].$datos['alumno_apellidomaterno']." (".date('Y', strtotime($datos['alumno_fechanacimiento'])).")"; ?></td>																										
												</tr>	
												<tr>													
													<th>La Cantidad de: </th>	
													<td colspan="3"><?php echo ucfirst($insAlumno->textoLetras($datos['transaccion_valor'])); ?></td>																										
												</tr>		
												<tr>													
													<th>Por Concepto de: </th>	
													<td colspan="3"><?php echo $datos['RUBRO']." ".$datos['TORNEO']." ".$datos['pago_periodo'].". ".$datos['transaccion_concepto']; ?></td>																										
												</tr>	
												<tr>													
													<th>Forma de pago: </th>	
													<td colspan="3"><?php echo $datos['FORMAPAGO']; ?></td>																										
												</tr>							
											</tbody>
										</table>
									</div>
									<!-- /.col -->
								</div>
								<!-- /.row -->
								<div class="row">
									<div class="col-4">
										<p class="lead">MONTO</p>

										<div class="table-responsive table-sm">
											<table class="table">
												<tr>
													<th style="width:50%">SUBTOTAL:</th>
													<td>$ <?php echo number_format($datos['pago_valor'] + $datos['pago_saldo'], 2); ?></td>
												</tr>
												<tr>
													<th>ABONO:</th>
													<td>$ <?php echo $datos['transaccion_valor']; ?></td>
												</tr>
												<tr>
													<th>SALDO:</th>
													<td>$ <?php echo number_format($datos['transaccion_valorcalculado'] - $datos['transaccion_valor'], 2); ?></td>
												</tr>												
											</table>
										</div>
									</div>
									<!-- accepted payments column -->
									<div class="col-4">										
									</div>

									<div class="col-4">										
										<?php
											('Content-Type: image/svg+xml');											
											$svg = $generator->render_svg($symbology,"Recibo ".$datos["transaccion_recibo"]. "\n".$datos["transaccion_fecharegistro"]. " | ".$recibo_hora."\n".$sede['sede_nombre']."\n".$sede["sede_telefono"]."\n".$sede["sede_email"], $optionsQR);											
											echo $svg;  
										?>								
									</div>
									<!-- /.col -->
									
									<!-- /.col -->
								</div>
								<!-- /.row -->

								<!-- this row will not appear when printing -->
								<div class="row no-print">
									<div class="col-12">
										<?php
										/* Mismo pie que pagosRecibo y por la misma razón:
										   float-end invertía el orden respecto al código
										   y el hueco lo sostenía un margin-right de 135 px
										   puesto a ojo. El id btn_correo se conserva porque
										   ajax.js le engancha la confirmación de envío. */
										?>
										<div class="d-flex flex-wrap align-items-center" style="gap:.5rem;">
											<button class="btn btn-dark btn-sm" onclick="cerrarVentana()">Cerrar</button>

											<a href="<?php echo APP_URL.'pagospendienteReciboPDF/'.$pagoid.'/'; ?>"
											   class="btn btn-dark btn-sm ms-auto" target="_blank">
												<i class="fas fa-print"></i> Ver recibo
											</a>

											<a href="<?php echo APP_URL.'pagospendienteReciboEnvio/'.$pagoid.'/'; ?>"
											   class="btn btn-success btn-sm" id="btn_correo">
												<i class="fas fa-credit-card"></i> Enviar recibo
											</a>
										</div>

									</div>
								</div>
							</div>
							<!-- /.invoice -->
						</div><!-- /.col -->
						<div class="col-1">
						</div>
					</div><!-- /.row -->
				</div><!-- /.container-fluid -->
			</section>      
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
	<script src="<?php echo DS_HUB_URL; ?>ds_core/assets/vendor/overlayscrollbars/js/overlayscrollbars.browser.es6.min.js"></script>
	<script src="<?php echo DS_HUB_URL; ?>ds_core/assets/vendor/bootstrap5/js/bootstrap.bundle.min.js"></script>	
	<!-- Select2 -->
	<!-- Bootstrap4 Duallistbox -->
	<!-- InputMask -->
	<script src="<?php echo APP_URL; ?>app/views/dist/plugins/inputmask/jquery.inputmask.min.js"></script>
	<!-- date-range-picker -->
	<!-- bootstrap color picker -->
	<!-- Tempusdominus Bootstrap 4 -->
	<!-- Bootstrap Switch -->
	<!-- BS-Stepper -->
	<!-- AdminLTE App -->
	<script src="<?php echo DS_HUB_URL; ?>ds_core/assets/vendor/adminlte4/js/adminlte.min.js"></script>		
	<script src="<?php echo APP_URL; ?>app/views/dist/js/ajax.js" ></script>

	<!-- fileinput -->
    
	<script>
        // Esta función se llama cuando el botón es clickeado
        function printPage() {
            window.addEventListener("load", window.print());
        }

		function cerrarVentana() {
			window.close();
		}
    </script>

	<?php if($alerta): ?>
		<script>
		document.addEventListener("DOMContentLoaded", function() {
			let alerta = <?php echo json_encode($alerta); ?>;
			alertas_ajax(alerta); // Usamos tu función de alertas que ya tienes en ajax.js
		});
		</script>
	<?php endif; ?>
	
  </body>
</html>