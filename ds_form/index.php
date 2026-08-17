<?php

/**
 * AF Pedro Larrea — Formulario Público de Inscripción
 *
 * Punto de entrada único. Valida el token HMAC del enlace compartido por
 * WhatsApp, extrae la sede y solo entonces renderiza el formulario.
 * Sin un token válido y vigente no se muestra ningún dato ni formulario.
 */

    require_once "./config/app.php";
    require_once "./autoload.php";

    use app\models\TokenHelper;
    use app\controllers\registroController;

    /*----------  Sesión del formulario público  ----------*/
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    require_once "./app/views/inc/session_start.php";

    /*----------  Cabeceras de seguridad  ----------*/
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    header("Referrer-Policy: same-origin");
    header("Content-Security-Policy: default-src 'self'; "
         . "script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; "
         . "style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; "
         . "font-src https://cdn.jsdelivr.net; "
         . "img-src 'self' data:; "
         . "form-action 'self'; frame-ancestors 'none'");

    /*----------  Validación del token del enlace  ----------*/
    $token  = $_GET['t'] ?? '';
    $estado = 'invalido';   // invalido | expirado | valido

    if ($token !== '') {
        $payload = TokenHelper::validar($token);

        if ($payload) {
            $estado = 'valido';

            // La sede queda fijada por el token, no por lo que envíe el navegador
            $_SESSION['inscripcion_sedeid'] = intval($payload['sede_id']);
            $_SESSION['inscripcion_exp']    = intval($payload['exp']);
            $_SESSION['inscripcion_valida'] = true;
        } elseif (TokenHelper::estaExpirado($token)) {
            $estado = 'expirado';
        }
    }

    if ($estado !== 'valido') {
        // Cortar cualquier sesión previa: un enlace vencido no debe heredar permisos
        unset(
            $_SESSION['inscripcion_sedeid'],
            $_SESSION['inscripcion_exp'],
            $_SESSION['inscripcion_valida']
        );

        http_response_code($estado === 'expirado' ? 410 : 403);
        require_once "./app/views/content/enlaceInvalido-view.php";
        exit();
    }

    /*----------  Token válido  ----------*/
    $sedeId       = intval($_SESSION['inscripcion_sedeid']);
    $expTimestamp = intval($_SESSION['inscripcion_exp']);

    $insRegistro   = new registroController();
    $sedeNombre    = $insRegistro->nombreSede($sedeId);
    $tiposDocumento = $insRegistro->listarCatalogo(1);   // tipo_documento
    $nacionalidades = $insRegistro->listarCatalogo(2);   // nacionalidad
    $parentescos    = $insRegistro->listarCatalogo(4);   // parentesco

    /* Los textos salen del mismo archivo cuyo hash se guarda al aceptar,
       para que lo firmado sea exactamente lo que se mostró en pantalla. */
    $consentimientos = $insRegistro->textosConsentimiento();

    /*----------  CSRF  ----------*/
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $csrfToken = $_SESSION['csrf_token'];

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo htmlspecialchars(ESCUELA_NOMBRE); ?> | Inscripción</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link href="<?php echo APP_URL; ?>assets/css/style.css" rel="stylesheet">
</head>
<body>

<!-- ═══════════════ HEADER ═══════════════ -->
<header class="afpl-header">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex align-items-center gap-3">
                <img src="<?php echo APP_URL; ?>app/views/dist/img/Logos/Logo.png"
                     alt="<?php echo htmlspecialchars(ESCUELA_NOMBRE); ?>" class="afpl-logo">
                <div>
                    <h1><?php echo htmlspecialchars(ESCUELA_NOMBRE); ?></h1>
                    <span class="sede-badge">
                        <i class="bi bi-geo-alt-fill"></i>
                        <?php echo htmlspecialchars($sedeNombre); ?>
                    </span>
                </div>
            </div>
            <div class="afpl-timer" id="afpl_timer">
                <i class="bi bi-clock"></i> Cargando...
            </div>
        </div>
    </div>
</header>

<main class="container py-4">

    <div id="formContainer">
        <form id="formRegistro"
              action="<?php echo APP_URL; ?>app/ajax/registroAjax.php"
              method="POST"
              enctype="multipart/form-data"
              autocomplete="off"
              novalidate>

            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

            <!-- ─── TABS ─── -->
            <ul class="nav nav-pills mb-4" id="formTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tab-repre" data-bs-toggle="pill"
                            data-bs-target="#seccion-repre" type="button" role="tab">
                        <i class="bi bi-person-badge"></i> 1. Representante
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-alumno" data-bs-toggle="pill"
                            data-bs-target="#seccion-alumno" type="button" role="tab">
                        <i class="bi bi-person-lines-fill"></i> 2. Alumno
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-autorizacion" data-bs-toggle="pill"
                            data-bs-target="#seccion-autorizacion" type="button" role="tab">
                        <i class="bi bi-shield-check"></i> 3. Autorización
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="formTabsContent">

                <!-- ═══ SECCIÓN REPRESENTANTE ═══ -->
                <div class="tab-pane fade show active" id="seccion-repre" role="tabpanel">
                    <div class="afpl-card card mb-4">
                        <div class="card-header">
                            <i class="bi bi-person-badge"></i> Datos del Representante
                        </div>
                        <div class="card-body">
                            <div class="row g-3">

                                <div class="col-md-4">
                                    <label for="repre_tipoidentificacion" class="form-label">
                                        Tipo de identificación <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-select" id="repre_tipoidentificacion" name="repre_tipoidentificacion" required>
                                        <?php foreach ($tiposDocumento as $t): ?>
                                            <option value="<?php echo htmlspecialchars($t['catalogo_valor']); ?>"
                                                <?php echo $t['catalogo_valor'] === 'CED' ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($t['catalogo_descripcion']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-4">
                                    <label for="repre_identificacion" class="form-label">
                                        Identificación <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="repre_identificacion" name="repre_identificacion"
                                           maxlength="13" placeholder="Ej: 1104XXXXXX" required>
                                    <div class="invalid-feedback">Ingrese una identificación válida</div>
                                </div>

                                <div class="col-md-4">
                                    <label for="repre_sexo" class="form-label">Sexo</label>
                                    <select class="form-select" id="repre_sexo" name="repre_sexo">
                                        <option value="">Seleccione</option>
                                        <option value="M">Masculino</option>
                                        <option value="F">Femenino</option>
                                    </select>
                                </div>

                                <div class="col-md-3">
                                    <label for="repre_primernombre" class="form-label">
                                        Primer nombre <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="repre_primernombre" name="repre_primernombre"
                                           maxlength="50" placeholder="Primer nombre" required>
                                    <div class="invalid-feedback">Este campo es obligatorio</div>
                                </div>

                                <div class="col-md-3">
                                    <label for="repre_segundonombre" class="form-label">Segundo nombre</label>
                                    <input type="text" class="form-control" id="repre_segundonombre" name="repre_segundonombre"
                                           maxlength="50" placeholder="Segundo nombre">
                                </div>

                                <div class="col-md-3">
                                    <label for="repre_apellidopaterno" class="form-label">
                                        Apellido paterno <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="repre_apellidopaterno" name="repre_apellidopaterno"
                                           maxlength="50" placeholder="Apellido paterno" required>
                                    <div class="invalid-feedback">Este campo es obligatorio</div>
                                </div>

                                <div class="col-md-3">
                                    <label for="repre_apellidomaterno" class="form-label">Apellido materno</label>
                                    <input type="text" class="form-control" id="repre_apellidomaterno" name="repre_apellidomaterno"
                                           maxlength="50" placeholder="Apellido materno">
                                </div>

                                <div class="col-12">
                                    <label for="repre_direccion" class="form-label">
                                        Dirección <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="repre_direccion" name="repre_direccion"
                                           maxlength="400" placeholder="Barrio, calle principal, #casa, calle secundaria" required>
                                    <div class="invalid-feedback">Este campo es obligatorio</div>
                                </div>

                                <div class="col-md-4">
                                    <label for="repre_correo" class="form-label">
                                        Correo electrónico <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                        <input type="email" class="form-control" id="repre_correo" name="repre_correo"
                                               maxlength="50" placeholder="correo@ejemplo.com" required>
                                        <div class="invalid-feedback">Ingrese un correo válido</div>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <label for="repre_celular" class="form-label">
                                        Celular <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-phone"></i></span>
                                        <input type="text" class="form-control" id="repre_celular" name="repre_celular"
                                               maxlength="10" placeholder="09XXXXXXXX" required>
                                        <div class="invalid-feedback">Debe iniciar con 09 y tener 10 dígitos</div>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <label for="repre_parentesco" class="form-label">
                                        Parentesco <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-select" id="repre_parentesco" name="repre_parentesco" required>
                                        <option value="">Seleccione</option>
                                        <?php foreach ($parentescos as $p): ?>
                                            <option value="<?php echo htmlspecialchars($p['catalogo_valor']); ?>">
                                                <?php echo htmlspecialchars($p['catalogo_descripcion']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">Seleccione el parentesco</div>
                                </div>

                            </div>

                            <div class="d-flex justify-content-end mt-4">
                                <button type="button" class="btn btn-afpl-primary" id="btn_to_alumno">
                                    Siguiente: Datos del Alumno <i class="bi bi-arrow-right"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═══ SECCIÓN ALUMNO ═══ -->
                <div class="tab-pane fade" id="seccion-alumno" role="tabpanel">
                    <div class="afpl-card card mb-4">
                        <div class="card-header">
                            <i class="bi bi-person-lines-fill"></i> Datos del Alumno
                        </div>
                        <div class="card-body">
                            <div class="row g-3">

                                <div class="col-12 text-center mb-3">
                                    <label class="form-label d-block">Foto del alumno</label>
                                    <div class="foto-preview-container mb-2" id="foto_container">
                                        <i class="bi bi-camera placeholder-icon" id="foto_placeholder"></i>
                                        <img id="foto_preview" src="" alt="Vista previa" style="display:none;">
                                    </div>
                                    <input type="file" class="form-control mx-auto" id="alumno_foto" name="alumno_foto"
                                           accept="image/jpeg,image/png" style="max-width: 300px;">
                                    <small class="text-muted">JPG o PNG, máximo 4 MB</small>
                                </div>

                                <div class="col-md-4">
                                    <label for="alumno_tipoidentificacion" class="form-label">
                                        Tipo de identificación <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-select" id="alumno_tipoidentificacion" name="alumno_tipoidentificacion" required>
                                        <?php foreach ($tiposDocumento as $t): ?>
                                            <option value="<?php echo htmlspecialchars($t['catalogo_valor']); ?>"
                                                <?php echo $t['catalogo_valor'] === 'CED' ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($t['catalogo_descripcion']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-4">
                                    <label for="alumno_identificacion" class="form-label">
                                        Identificación <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="alumno_identificacion" name="alumno_identificacion"
                                           maxlength="20" placeholder="Ej: 1104XXXXXX" required>
                                    <div class="invalid-feedback">Ingrese una identificación válida</div>
                                </div>

                                <div class="col-md-4">
                                    <label for="alumno_fechanacimiento" class="form-label">
                                        Fecha de nacimiento <span class="text-danger">*</span>
                                    </label>
                                    <input type="date" class="form-control" id="alumno_fechanacimiento" name="alumno_fechanacimiento"
                                           max="<?php echo date('Y-m-d'); ?>" required>
                                    <div class="invalid-feedback">Seleccione la fecha de nacimiento</div>
                                </div>

                                <div class="col-md-3">
                                    <label for="alumno_primernombre" class="form-label">
                                        Primer nombre <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="alumno_primernombre" name="alumno_primernombre"
                                           maxlength="150" placeholder="Primer nombre" required>
                                    <div class="invalid-feedback">Este campo es obligatorio</div>
                                </div>

                                <div class="col-md-3">
                                    <label for="alumno_segundonombre" class="form-label">Segundo nombre</label>
                                    <input type="text" class="form-control" id="alumno_segundonombre" name="alumno_segundonombre"
                                           maxlength="150" placeholder="Segundo nombre">
                                </div>

                                <div class="col-md-3">
                                    <label for="alumno_apellidopaterno" class="form-label">
                                        Apellido paterno <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="alumno_apellidopaterno" name="alumno_apellidopaterno"
                                           maxlength="150" placeholder="Apellido paterno" required>
                                    <div class="invalid-feedback">Este campo es obligatorio</div>
                                </div>

                                <div class="col-md-3">
                                    <label for="alumno_apellidomaterno" class="form-label">Apellido materno</label>
                                    <input type="text" class="form-control" id="alumno_apellidomaterno" name="alumno_apellidomaterno"
                                           maxlength="150" placeholder="Apellido materno">
                                </div>

                                <div class="col-md-4">
                                    <label for="alumno_nacionalidadid" class="form-label">Nacionalidad</label>
                                    <select class="form-select" id="alumno_nacionalidadid" name="alumno_nacionalidadid">
                                        <?php foreach ($nacionalidades as $n): ?>
                                            <option value="<?php echo htmlspecialchars($n['catalogo_valor']); ?>"
                                                <?php echo $n['catalogo_valor'] === 'ECU' ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($n['catalogo_descripcion']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-8">
                                    <label for="alumno_direccion" class="form-label">Dirección</label>
                                    <input type="text" class="form-control" id="alumno_direccion" name="alumno_direccion"
                                           maxlength="400" placeholder="Dirección del alumno (si es distinta a la del representante)">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Género <span class="text-danger">*</span></label>
                                    <div class="d-flex gap-4 mt-1">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="alumno_genero" id="genero_m" value="M">
                                            <label class="form-check-label" for="genero_m">Masculino</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="alumno_genero" id="genero_f" value="F">
                                            <label class="form-check-label" for="genero_f">Femenino</label>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">¿Tiene hermanos en la escuela?</label>
                                    <div class="d-flex gap-4 mt-1">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="alumno_hermanos" id="hermanos_s" value="S">
                                            <label class="form-check-label" for="hermanos_s">Sí</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="alumno_hermanos" id="hermanos_n" value="N" checked>
                                            <label class="form-check-label" for="hermanos_n">No</label>
                                        </div>
                                    </div>
                                </div>

                            </div>

                            <div class="d-flex justify-content-between mt-4">
                                <button type="button" class="btn btn-outline-secondary" id="btn_to_repre">
                                    <i class="bi bi-arrow-left"></i> Volver
                                </button>
                                <button type="button" class="btn btn-afpl-primary" id="btn_to_autorizacion">
                                    Siguiente: Autorización <i class="bi bi-arrow-right"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═══ SECCIÓN AUTORIZACIÓN LOPDP ═══ -->
                <div class="tab-pane fade" id="seccion-autorizacion" role="tabpanel">
                    <div class="afpl-card card mb-4">
                        <div class="card-header">
                            <i class="bi bi-shield-check"></i> Autorización y Consentimiento
                        </div>
                        <div class="card-body">

                            <?php
                                /* Ambos consentimientos son obligatorios: sin los dos
                                   no hay inscripción. El texto sale de config/consentimientos.php,
                                   que es el mismo cuyo hash se guarda al aceptar. */
                                $estilos = [
                                    'DATOS'  => ['borde' => 'border-danger',  'texto' => 'text-danger',  'icono' => 'bi-file-earmark-lock2'],
                                    'IMAGEN' => ['borde' => 'border-success', 'texto' => 'text-success', 'icono' => 'bi-camera-video'],
                                ];
                            ?>

                            <?php foreach ($consentimientos as $tipo => $bloque): ?>
                                <?php $e = $estilos[$tipo]; ?>
                                <div class="card border-start border-4 <?php echo $e['borde']; ?> mb-4">
                                    <div class="card-body">
                                        <h6 class="fw-bold <?php echo $e['texto']; ?> mb-3">
                                            <i class="bi <?php echo $e['icono']; ?>"></i>
                                            <?php echo htmlspecialchars($bloque['titulo']); ?>
                                        </h6>

                                        <div class="bg-light p-3 rounded mb-3" style="max-height: 200px; overflow-y: auto; font-size: 0.875rem; line-height: 1.6;">
                                            <?php echo $bloque['texto']; ?>
                                        </div>

                                        <div class="form-check">
                                            <input class="form-check-input consentimiento-obligatorio" type="checkbox"
                                                   id="acepta_<?php echo strtolower($tipo); ?>"
                                                   name="acepta_<?php echo strtolower($tipo); ?>" value="S" required>
                                            <label class="form-check-label fw-semibold" for="acepta_<?php echo strtolower($tipo); ?>">
                                                <?php echo htmlspecialchars($bloque['casilla']); ?>
                                                <span class="text-danger">*</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <div class="alert alert-info d-flex align-items-start gap-2" role="alert">
                                <i class="bi bi-info-circle-fill mt-1"></i>
                                <div style="font-size: 0.875rem;">
                                    Al marcar las casillas anteriores y enviar este formulario, declaro que soy el representante
                                    legal del menor y que la información proporcionada es verídica. Entiendo que puedo ejercer
                                    mis derechos conforme a la LOPDP comunicándome con la escuela.
                                </div>
                            </div>

                            <div class="alert alert-warning d-flex align-items-start gap-2 py-2" id="aviso_consentimientos" role="alert">
                                <i class="bi bi-exclamation-triangle-fill mt-1"></i>
                                <div style="font-size: 0.875rem;">
                                    Debe aceptar <strong>las dos autorizaciones</strong> para poder completar la inscripción.
                                </div>
                            </div>

                            <div class="d-flex justify-content-between mt-4">
                                <button type="button" class="btn btn-outline-secondary" id="btn_to_alumno_back">
                                    <i class="bi bi-arrow-left"></i> Volver
                                </button>

                                <div>
                                    <button type="submit" class="btn btn-afpl-primary" id="btn_submit" disabled>
                                        <i class="bi bi-check-circle"></i> Registrar Alumno
                                    </button>
                                    <div class="afpl-loading" id="btn_loading">
                                        <div class="spinner-border spinner-border-sm text-danger" role="status"></div>
                                        <span class="text-danger fw-semibold">Registrando...</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </form>
    </div>

    <!-- Pantalla de éxito -->
    <div id="successContainer" style="display:none;">
        <div class="afpl-card card">
            <div class="card-body afpl-success">
                <i class="bi bi-check-circle-fill"></i>
                <h2>Registro completado</h2>
                <p class="text-muted mb-4">
                    El alumno ha sido registrado exitosamente en la sede
                    <strong><?php echo htmlspecialchars($sedeNombre); ?></strong>.
                </p>
                <p class="text-muted">La escuela se pondrá en contacto con usted. Puede cerrar esta ventana.</p>
            </div>
        </div>
    </div>

</main>

<footer class="afpl-footer">
    <div class="container">
        &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars(ESCUELA_NOMBRE); ?> &mdash; Todos los derechos reservados
    </div>
</footer>

<script>var TOKEN_EXPIRY_TIMESTAMP = <?php echo intval($expTimestamp); ?>;</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="<?php echo APP_URL; ?>assets/js/form.js"></script>

</body>
</html>
