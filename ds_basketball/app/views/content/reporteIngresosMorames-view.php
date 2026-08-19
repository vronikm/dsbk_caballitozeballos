<?php
	use app\controllers\reporteController;
	$insLEIngresos = new reporteController();

	$le_fecha_inicio = isset($_POST['le_fecha_inicio'])
		? $insLEIngresos->limpiarCadena($_POST['le_fecha_inicio'])
		: date('Y-m-01');

	$le_fecha_fin = isset($_POST['le_fecha_fin'])
		? $insLEIngresos->limpiarCadena($_POST['le_fecha_fin'])
		: date('Y-m-t');

	$insIngresos = $insLEIngresos->ingresosMoraLugarEntr($le_fecha_inicio, $le_fecha_fin);
	$datos = $insIngresos->fetchAll();

	$sede = $lugar = $alumno = $estadoalumno = $fechaultpago = [];
	$situacion = $periodo = $concepto = $valor = $saldo = $estadopago = [];

	foreach($datos as $rows){
		$sede[]        = $rows['SEDE'];
		$lugar[]       = $rows['LUGARENTRENAMIENTO'];
		$alumno[]      = $rows['ALUMNO'];
		$estadoalumno[]= $rows['ESTADOALUMNO'];
		$fechaultpago[]= $rows['FECHA_ULTPAGO'];
		$situacion[]   = $rows['SITUACION'];
		$periodo[]     = $rows['PAGO_PERIODO'];
		$concepto[]    = $rows['PAGO_CONCEPTO'];
		$valor[]       = (float)$rows['PAGO_VALOR'];
		$saldo[]       = (float)$rows['PAGO_SALDO'];
		$estadopago[]  = $rows['ESTADOPAGO'];
	}

	// KPI totales
	$totalRegistros  = count($lugar);
	$totalValor      = array_sum($valor);
	$totalSaldo      = array_sum($saldo);
	$countMora       = count(array_filter($situacion, fn($s) => $s === 'EN MORA'));
	$countAlDia      = count(array_filter($situacion, fn($s) => $s === 'AL DÍA'));
	$countSinReg     = count(array_filter($situacion, fn($s) => $s === 'No Registra'));

	// Etiqueta período
	$meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
	$labelPeriodo = date('d', strtotime($le_fecha_inicio)) . ' ' . $meses[(int)date('n', strtotime($le_fecha_inicio))]
		. ' – ' . date('d', strtotime($le_fecha_fin))   . ' ' . $meses[(int)date('n', strtotime($le_fecha_fin))]
		. ' ' . date('Y', strtotime($le_fecha_fin));

	$nombreArchivo = ds_nombre_archivo('IngresosMora') . '-' . $le_fecha_inicio . '_' . $le_fecha_fin;
?>
<!DOCTYPE html>
<html lang="es">
  <head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> | Ingresos y mora por sede</title>
    <link rel="icon" type="image/png" href="<?php echo APP_URL; ?>app/views/dist/img/Logos/logo_bsc.png">
    <link rel="stylesheet" href="<?php echo DS_HUB_URL; ?>ds_core/assets/css/fuentes.css">
	<link rel="stylesheet" href="<?php echo DS_HUB_URL; ?>ds_core/assets/css/core.css">
    <link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/plugins/datatables-bs4/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/plugins/datatables-responsive/css/responsive.bootstrap4.min.css">
    <link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/plugins/datatables-buttons/css/buttons.bootstrap4.min.css">
    <link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/css/adminlte.css">
    <style>
      /* KPI cards */
      .kpi-card            { border-left: 4px solid; position: relative; overflow: hidden; }
      .kpi-azul            { border-color: #3498db; }
      .kpi-rojo            { border-color: #e74c3c; }
      .kpi-verde           { border-color: #2ecc71; }
      .kpi-naranja         { border-color: #e67e22; }
      .kpi-amarillo        { border-color: #f1c40f; }
      .kpi-gris            { border-color: #95a5a6; }
      .kpi-card .kpi-valor { font-size: 1.6rem; font-weight: 700; line-height: 1.2; }
      .kpi-card .kpi-label { font-size: 0.78rem; color: #888; text-transform: uppercase; letter-spacing: .5px; }
      .kpi-card .kpi-icon  {
        position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
        font-size: 3.2rem; opacity: 0.08; pointer-events: none;
      }

      /* Badges de situación */
      .badge-sit           { font-size: 0.8rem; padding: 3px 9px; border-radius: 12px; font-weight: 600; white-space: nowrap; }
      .sit-mora            { background: #f8d7da; color: #721c24; }
      .sit-aldia           { background: #d4edda; color: #155724; }
      .sit-sinreg          { background: #fff3cd; color: #856404; }
      .sit-otro            { background: #e2e3e5; color: #383d41; }

      /* Badges de estado pago */
      .badge-ep            { font-size: 0.78rem; padding: 2px 8px; border-radius: 10px; font-weight: 600; }
      .ep-completo         { background: #d4edda; color: #155724; }
      .ep-pendiente        { background: #fff3cd; color: #856404; }
      .ep-justificado      { background: #d1ecf1; color: #0c5460; }
      .ep-otro             { background: #e2e3e5; color: #383d41; }

      /* Color filas */
      tr.fila-mora td      { background-color: #fff5f5 !important; }
      tr.fila-aldia td     { background-color: #f0fff4 !important; }
      tr.fila-sinreg td    { background-color: #fffdf0 !important; }

      /* Tabla */
      .tabla-mora th       { background-color: #343a40; color: #fff; text-align: center; vertical-align: middle; font-size: 0.8rem; }
      .tabla-mora td       { vertical-align: middle; font-size: 0.85rem; }
      .tabla-mora tfoot td { background-color: #e9ecef; font-weight: 700; text-align: center; }

      /* Botones icono */
      .boton-icono {
        width: 30px; height: 30px;
        background-color: transparent; background-size: contain;
        background-repeat: no-repeat; background-position: center;
        border: none; cursor: pointer; transition: transform 0.2s;
      }
      .boton-icono:hover { transform: scale(1.15); }
    </style>
  </head>
  <body class="hold-transition sidebar-mini layout-fixed">
    <div class="wrapper">

      <?php require_once "app/views/inc/navbar.php"; ?>
      <?php require_once "app/views/inc/main-sidebar.php"; ?>

      <div class="content-wrapper">

        <!-- Content Header -->
        <div class="content-header">
          <div class="container-fluid">
            <div class="row mb-2">
              <div class="col-sm-7">
                <h1 class="m-0">Ingresos y mora por lugar de entrenamiento
                  <small class="text-muted" style="font-size:0.5em; font-weight:400;">
                    &nbsp;— <?php echo $labelPeriodo; ?>
                  </small>
                </h1>
              </div>
              <div class="col-sm-5">
                <ol class="breadcrumb float-sm-right">
                  <li class="breadcrumb-item"><a href="<?php echo APP_URL; ?>dashboard/">Inicio</a></li>
                  <li class="breadcrumb-item active">Ingresos y mora</li>
                </ol>
              </div>
            </div>
          </div>
        </div>

        <section class="content">
          <div class="container-fluid">

            <!-- ── Card filtro ─────────────────────────────────── -->
            <form action="<?php echo APP_URL."reporteIngresosMorames/" ?>" method="POST" autocomplete="off">
              <div class="card card-default">
                <div class="card-header">
                  <h3 class="card-title"><i class="fas fa-filter mr-1"></i> Período de consulta</h3>
                  <div class="card-tools">
                    <button type="button" class="btn btn-tool" data-card-widget="collapse">
                      <i class="fas fa-minus"></i>
                    </button>
                  </div>
                </div>
                <div class="card-body">
                  <div class="row align-items-end">
                    <div class="col-md-3">
                      <div class="form-group mb-0">
                        <label for="le_fecha_inicio">Fecha inicio</label>
                        <div class="input-group">
                          <div class="input-group-prepend">
                            <span class="input-group-text"><i class="far fa-calendar-alt"></i></span>
                          </div>
                          <input type="date" class="form-control" id="le_fecha_inicio" name="le_fecha_inicio"
                                 value="<?php echo $le_fecha_inicio; ?>" required>
                        </div>
                      </div>
                    </div>
                    <div class="col-md-3">
                      <div class="form-group mb-0">
                        <label for="le_fecha_fin">Fecha fin</label>
                        <div class="input-group">
                          <div class="input-group-prepend">
                            <span class="input-group-text"><i class="far fa-calendar-alt"></i></span>
                          </div>
                          <input type="date" class="form-control" id="le_fecha_fin" name="le_fecha_fin"
                                 value="<?php echo $le_fecha_fin; ?>" required>
                        </div>
                      </div>
                    </div>
                    <div class="col-md-2">
                      <button type="submit" class="btn btn-info btn-block mt-1">
                        <i class="fas fa-search mr-1"></i> Buscar
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            </form>

            <?php if(empty($lugar)): ?>
            <!-- Estado vacío -->
            <div class="card card-default">
              <div class="card-body text-center py-5">
                <i class="fas fa-search-dollar fa-4x text-muted mb-3"></i>
                <h4 class="text-muted">Sin resultados para el período seleccionado</h4>
                <p class="text-muted">No se encontraron registros entre
                  <strong><?php echo $le_fecha_inicio; ?></strong> y
                  <strong><?php echo $le_fecha_fin; ?></strong>.
                </p>
              </div>
            </div>
            <?php else: ?>

            <!-- ── KPI cards ──────────────────────────────────── -->
            <div class="row mb-3">
              <div class="col-6 col-md-4 col-lg mb-2">
                <div class="card kpi-card kpi-azul h-100 mb-0">
                  <div class="card-body py-3 px-3">
                    <div class="kpi-label">Total registros</div>
                    <div class="kpi-valor text-primary"><?php echo $totalRegistros; ?></div>
                    <i class="fas fa-list-alt kpi-icon text-primary"></i>
                  </div>
                </div>
              </div>
              <div class="col-6 col-md-4 col-lg mb-2">
                <div class="card kpi-card kpi-rojo h-100 mb-0">
                  <div class="card-body py-3 px-3">
                    <div class="kpi-label">En mora</div>
                    <div class="kpi-valor text-danger"><?php echo $countMora; ?></div>
                    <i class="fas fa-exclamation-triangle kpi-icon text-danger"></i>
                  </div>
                </div>
              </div>
              <div class="col-6 col-md-4 col-lg mb-2">
                <div class="card kpi-card kpi-verde h-100 mb-0">
                  <div class="card-body py-3 px-3">
                    <div class="kpi-label">Al día</div>
                    <div class="kpi-valor text-success"><?php echo $countAlDia; ?></div>
                    <i class="fas fa-check-circle kpi-icon text-success"></i>
                  </div>
                </div>
              </div>
              <div class="col-6 col-md-4 col-lg mb-2">
                <div class="card kpi-card kpi-naranja h-100 mb-0">
                  <div class="card-body py-3 px-3">
                    <div class="kpi-label">Sin registro pagos</div>
                    <div class="kpi-valor text-warning"><?php echo $countSinReg; ?></div>
                    <i class="fas fa-user-clock kpi-icon text-warning"></i>
                  </div>
                </div>
              </div>
              <div class="col-6 col-md-4 col-lg mb-2">
                <div class="card kpi-card kpi-amarillo h-100 mb-0">
                  <div class="card-body py-3 px-3">
                    <div class="kpi-label">Total valor pagos</div>
                    <div class="kpi-valor" style="color:#b8860b;">$<?php echo number_format($totalValor, 2); ?></div>
                    <i class="fas fa-money-bill-wave kpi-icon" style="color:#b8860b;"></i>
                  </div>
                </div>
              </div>
              <div class="col-6 col-md-4 col-lg mb-2">
                <div class="card kpi-card kpi-gris h-100 mb-0">
                  <div class="card-body py-3 px-3">
                    <div class="kpi-label">Total saldo pendiente</div>
                    <div class="kpi-valor text-secondary">$<?php echo number_format($totalSaldo, 2); ?></div>
                    <i class="fas fa-hourglass-half kpi-icon text-secondary"></i>
                  </div>
                </div>
              </div>
            </div>

            <!-- ── Card tabla ─────────────────────────────────── -->
            <div class="card card-default">
              <div class="card-header">
                <h3 class="card-title">
                  <i class="fas fa-table mr-1"></i> Detalle de alumnos con pagos y mora
                </h3>
                <div class="card-tools d-flex align-items-center" style="gap:6px;">
                  <!-- Leyenda semáforo -->
                  <span class="badge-sit sit-mora"><i class="fas fa-circle mr-1"></i> En mora</span>
                  <span class="badge-sit sit-aldia" style="margin-left:4px;"><i class="fas fa-circle mr-1"></i> Al día</span>
                  <span class="badge-sit sit-sinreg" style="margin-left:4px;"><i class="fas fa-circle mr-1"></i> Sin registro de pagos</span>
                  <button onclick="exportarTablaAExcel('tablaDatos','<?php echo $nombreArchivo; ?>')"
                          class="boton-icono"
                          style="background-image: url('<?php echo APP_URL; ?>app/views/imagenes/iconos/Excel.png');"
                          title="Exportar a Excel"></button>
                  <button onclick="exportarTablaAPDF('tablaDatos','<?php echo $nombreArchivo; ?>')"
                          class="boton-icono"
                          style="background-image: url('<?php echo APP_URL; ?>app/views/imagenes/iconos/Pdf.png');"
                          title="Exportar a PDF"></button>
                  <button type="button" class="btn btn-tool" data-card-widget="collapse">
                    <i class="fas fa-minus"></i>
                  </button>
                </div>
              </div>
              <div class="card-body p-0">
                <div class="table-responsive">
                  <table id="tablaDatos" class="table table-bordered table-sm tabla-mora mb-0">
                    <thead>
                      <tr>
                        <th>Sede</th>
                        <th>Lugar de Entrenamiento</th>
                        <th>Alumno</th>
                        <th>F. Último Pago</th>
                        <th>Situación</th>
                        <th>Período Pago</th>
                        <th>Concepto Pago</th>
                        <th>Valor ($)</th>
                        <th>Saldo ($)</th>
                        <th>Estado Pago</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php for ($i = 0; $i < count($lugar); $i++):
                        // Clase de fila según situación
                        $sit = $situacion[$i];
                        if ($sit === 'EN MORA')       $filaClass = 'fila-mora';
                        elseif ($sit === 'AL DÍA')    $filaClass = 'fila-aldia';
                        else                           $filaClass = 'fila-sinreg';

                        // Badge situación
                        if ($sit === 'EN MORA')       $sitClass = 'sit-mora';
                        elseif ($sit === 'AL DÍA')    $sitClass = 'sit-aldia';
                        elseif ($sit === 'No Registra') $sitClass = 'sit-sinreg';
                        else                           $sitClass = 'sit-otro';

                        // Badge estado pago
                        $ep = $estadopago[$i];
                        if ($ep === 'Completo')        $epClass = 'ep-completo';
                        elseif ($ep === 'Pendiente')   $epClass = 'ep-pendiente';
                        elseif ($ep === 'Justificado') $epClass = 'ep-justificado';
                        else                           $epClass = 'ep-otro';
                      ?>
                      <tr class="<?php echo $filaClass; ?>">
                        <td><?php echo $sede[$i]; ?></td>
                        <td><?php echo $lugar[$i]; ?></td>
                        <td><?php echo $alumno[$i]; ?></td>
                        <td class="text-center"><?php echo $fechaultpago[$i]; ?></td>
                        <td class="text-center">
                          <span class="badge-sit <?php echo $sitClass; ?>"><?php echo $sit; ?></span>
                        </td>
                        <td class="text-center"><?php echo $periodo[$i]; ?></td>
                        <td><?php echo $concepto[$i]; ?></td>
                        <td class="text-right"><?php echo $valor[$i] > 0 ? '$'.number_format($valor[$i], 2) : '—'; ?></td>
                        <td class="text-right"><?php echo $saldo[$i] > 0 ? '$'.number_format($saldo[$i], 2) : '—'; ?></td>
                        <td class="text-center">
                          <?php if($ep): ?>
                            <span class="badge-ep <?php echo $epClass; ?>"><?php echo $ep; ?></span>
                          <?php endif; ?>
                        </td>
                      </tr>
                      <?php endfor; ?>
                    </tbody>
                    <tfoot>
                      <tr>
                        <td colspan="7" class="text-right">Totales:</td>
                        <td class="text-right">$<?php echo number_format($totalValor, 2); ?></td>
                        <td class="text-right">$<?php echo number_format($totalSaldo, 2); ?></td>
                        <td></td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              </div>
            </div>

            <!-- Leyenda semáforo -->
            <div class="mb-3" style="display:flex; gap:12px; flex-wrap:wrap;">
              <span class="badge-sit sit-mora"><i class="fas fa-circle mr-1"></i> En mora</span>
              <span class="badge-sit sit-aldia" style="margin-left:4px;"><i class="fas fa-circle mr-1"></i> Al día</span>
              <span class="badge-sit sit-sinreg" style="margin-left:4px;"><i class="fas fa-circle mr-1"></i> Sin registro de pagos</span>
            </div>

            <?php endif; ?>

          </div>
        </section>
      </div>

      <?php require_once "app/views/inc/footer.php"; ?>
      <aside class="control-sidebar control-sidebar-dark"></aside>
    </div>

    <!-- jQuery -->
    <script src="<?php echo APP_URL; ?>app/views/dist/plugins/jquery/jquery.min.js"></script>
    <!-- Bootstrap 4 -->
    <script src="<?php echo APP_URL; ?>app/views/dist/plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
    <!-- DataTables -->
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
    <!-- AdminLTE -->
    <script src="<?php echo APP_URL; ?>app/views/dist/js/adminlte.min.js"></script>
    <!-- Exportar -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

    <?php if(!empty($lugar)): ?>
    <script>
    $(function () {
      $("#tablaDatos").DataTable({
        "paging":       true,
        "lengthChange": true,
        "searching":    true,
        "ordering":     true,
        "info":         true,
        "autoWidth":    false,
        "responsive":   true,
        "pageLength":   25,
        "order":        [[4, "asc"]],   // Ordenar por Situación por defecto
        "language": {
          "emptyTable":    "No hay datos disponibles",
          "info":          "Mostrando _START_ a _END_ de _TOTAL_ entradas",
          "infoEmpty":     "Mostrando 0 a 0 de 0 entradas",
          "infoFiltered":  "(filtrado de _MAX_ entradas totales)",
          "thousands":     ",",
          "lengthMenu":    "Mostrar _MENU_ entradas",
          "loadingRecords":"Cargando...",
          "processing":    "Procesando...",
          "search":        "Buscar:",
          "zeroRecords":   "No se encontraron registros coincidentes",
          "paginate": { "first":"Primero","last":"Último","next":"Siguiente","previous":"Anterior" },
          "buttons": { "copy":"Copiar","print":"Imprimir","colvis":"Columnas" }
        },
        "buttons": ["copy", "csv", "excel", "pdf", "print", "colvis"]
      }).buttons().container().appendTo('#tablaDatos_wrapper .col-md-6:eq(0)');
    });

    function exportarTablaAExcel(tablaID, nombreArchivo) {
      const tabla = document.getElementById(tablaID);
      const libro = XLSX.utils.table_to_book(tabla, { sheet: "Mora" });
      XLSX.writeFile(libro, nombreArchivo + ".xlsx");
    }

    async function exportarTablaAPDF(tablaID, nombreArchivo) {
      const tabla = document.getElementById(tablaID);
      const canvas = await html2canvas(tabla, { scale: 2 });
      const imgData = canvas.toDataURL('image/png');
      const { jsPDF } = window.jspdf;
      const pdf = new jsPDF({ orientation: 'landscape' });
      const imgProps = pdf.getImageProperties(imgData);
      const pdfWidth  = pdf.internal.pageSize.getWidth();
      const pdfHeight = (imgProps.height * pdfWidth) / imgProps.width;
      pdf.addImage(imgData, 'PNG', 0, 10, pdfWidth, pdfHeight);
      pdf.save(nombreArchivo + ".pdf");
    }
    </script>
    <?php endif; ?>

  </body>
</html>
