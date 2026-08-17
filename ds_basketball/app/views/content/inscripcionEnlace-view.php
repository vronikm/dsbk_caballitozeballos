<?php
    use app\controllers\inscripcionController;
    $insInscripcion = new inscripcionController();
    $sedes = $insInscripcion->listarSedesActivas();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> | Enlace de Inscripción</title>

    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/plugins/fontawesome-free/css/all.min.css">
    <!-- Theme style -->
    <link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/css/adminlte.css">
    <link rel="stylesheet" href="<?php echo APP_URL; ?>app/views/dist/css/sweetalert2.min.css">
    <script src="<?php echo APP_URL; ?>app/views/dist/js/sweetalert2.all.min.js"></script>

    <style>
        .enlace-resultado {
            display: none;
            margin-top: 20px;
        }
        .enlace-url {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 12px 15px;
            word-break: break-all;
            font-family: monospace;
            font-size: 0.85rem;
            color: #495057;
        }
        .btn-whatsapp {
            background: #25D366;
            border-color: #25D366;
            color: #fff;
            font-weight: 600;
        }
        .btn-whatsapp:hover {
            background: #1ebe5c;
            border-color: #1ebe5c;
            color: #fff;
        }
        .btn-copiar {
            background: #6c757d;
            border-color: #6c757d;
            color: #fff;
        }
        .enlace-info {
            background: #e8f5e9;
            border-left: 4px solid #4caf50;
            padding: 12px 15px;
            border-radius: 0 8px 8px 0;
            margin-top: 15px;
        }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
    <div class="wrapper">
        <!-- Navbar -->
        <?php require_once "app/views/inc/navbar.php"; ?>

        <!-- Main Sidebar Container -->
        <?php require_once "app/views/inc/main-sidebar.php"; ?>

        <!-- Vista -->
        <div class="content-wrapper">
            <!-- Content Header -->
            <div class="content-header">
                <div class="container-fluid">
                    <div class="row mb-2">
                        <div class="col-sm-6">
                            <h4 class="m-0">Generar Enlace de Inscripción</h4>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-right">
                                <li class="breadcrumb-item"><a href="<?php echo APP_URL . 'dashboard/'; ?>">Dashboard</a></li>
                                <li class="breadcrumb-item active">Enlace de Inscripción</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main content -->
            <section class="content">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-md-8 offset-md-2">

                            <div class="card card-primary">
                                <div class="card-header">
                                    <h3 class="card-title">
                                        <i class="fas fa-link"></i> Generar enlace para compartir por WhatsApp
                                    </h3>
                                </div>
                                <div class="card-body">
                                    <p class="text-muted mb-4">
                                        Seleccione la sede y el tiempo de vigencia del enlace.
                                        El representante podrá completar el formulario de inscripción desde su celular.
                                    </p>

                                    <form id="formGenerarEnlace" action="<?php echo APP_URL; ?>app/ajax/inscripcionAjax.php" method="POST" class="FormularioAjax" autocomplete="off">
                                        <input type="hidden" name="modulo_inscripcion" value="generar_enlace">

                                        <div class="row">
                                            <!-- Sede -->
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="sede_id">
                                                        <i class="fas fa-map-marker-alt text-primary"></i> Sede <span class="text-danger">*</span>
                                                    </label>
                                                    <select class="form-control" id="sede_id" name="sede_id" required>
                                                        <option value="">Seleccione una sede</option>
                                                        <?php foreach ($sedes as $sede): ?>
                                                            <option value="<?php echo $sede['sede_id']; ?>">
                                                                <?php echo htmlspecialchars($sede['sede_nombre']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>

                                            <!-- Vigencia -->
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="horas_vigencia">
                                                        <i class="fas fa-clock text-primary"></i> Vigencia del enlace
                                                    </label>
                                                    <select class="form-control" id="horas_vigencia" name="horas_vigencia">
                                                        <option value="24">24 horas (1 día)</option>
                                                        <option value="48">48 horas (2 días)</option>
                                                        <option value="72" selected>72 horas (3 días)</option>
                                                        <option value="168">168 horas (7 días)</option>
                                                        <option value="336">336 horas (14 días)</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>

                                        <button type="button" id="btnGenerar" class="btn btn-primary btn-block">
                                            <i class="fas fa-magic"></i> Generar Enlace
                                        </button>
                                    </form>

                                    <!-- Resultado -->
                                    <div id="enlaceResultado" class="enlace-resultado">
                                        <hr>
                                        <h5><i class="fas fa-check-circle text-success"></i> Enlace generado para: <strong id="enlaceSede"></strong></h5>

                                        <div class="enlace-info">
                                            <small><i class="fas fa-info-circle"></i> Este enlace expira en <strong id="enlaceVigencia"></strong> horas</small>
                                        </div>

                                        <label class="mt-3"><i class="fas fa-link"></i> URL del formulario:</label>
                                        <div class="enlace-url" id="enlaceURL"></div>

                                        <div class="row mt-3">
                                            <div class="col-md-6 mb-2">
                                                <button type="button" id="btnCopiar" class="btn btn-copiar btn-block">
                                                    <i class="fas fa-copy"></i> Copiar enlace
                                                </button>
                                            </div>
                                            <div class="col-md-6 mb-2">
                                                <a href="#" id="btnWhatsApp" target="_blank" class="btn btn-whatsapp btn-block">
                                                    <i class="fab fa-whatsapp"></i> Compartir por WhatsApp
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </section>
        </div>

        <!-- Footer -->
        <?php require_once "app/views/inc/footer.php"; ?>
    </div>

    <!-- jQuery -->
    <script src="<?php echo APP_URL; ?>app/views/dist/plugins/jquery/jquery.min.js"></script>
    <!-- Bootstrap 4 -->
    <script src="<?php echo APP_URL; ?>app/views/dist/plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
    <!-- AdminLTE App -->
    <script src="<?php echo APP_URL; ?>app/views/dist/js/adminlte.js"></script>

    <script>
    $(document).ready(function () {

        $('#btnGenerar').click(function () {
            var sedeId = $('#sede_id').val();
            if (!sedeId) {
                Swal.fire('Campo requerido', 'Seleccione una sede para generar el enlace.', 'warning');
                return;
            }

            var btn = $(this);
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Generando...');

            $.ajax({
                url: '<?php echo APP_URL; ?>app/ajax/inscripcionAjax.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    modulo_inscripcion: 'generar_enlace',
                    sede_id: sedeId,
                    horas_vigencia: $('#horas_vigencia').val()
                },
                success: function (data) {
                    btn.prop('disabled', false).html('<i class="fas fa-magic"></i> Generar Enlace');

                    if (data.tipo === 'enlace') {
                        $('#enlaceSede').text(data.sede_nombre);
                        $('#enlaceVigencia').text(data.vigencia_horas);
                        $('#enlaceURL').text(data.url_formulario);
                        $('#btnWhatsApp').attr('href', data.enlace_whatsapp);
                        $('#enlaceResultado').slideDown();

                        Swal.fire({
                            title: data.titulo,
                            text: data.texto,
                            icon: data.icono,
                            confirmButtonColor: '#007bff'
                        });
                    } else {
                        Swal.fire(data.titulo, data.texto, data.icono);
                    }
                },
                error: function () {
                    btn.prop('disabled', false).html('<i class="fas fa-magic"></i> Generar Enlace');
                    Swal.fire('Error', 'No se pudo generar el enlace. Intente nuevamente.', 'error');
                }
            });
        });

        // Copiar al portapapeles
        $('#btnCopiar').click(function () {
            var url = $('#enlaceURL').text();
            navigator.clipboard.writeText(url).then(function () {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: 'Enlace copiado al portapapeles',
                    showConfirmButton: false,
                    timer: 2000
                });
            });
        });
    });
    </script>
</body>
</html>
