<?php
    /**
     * Pantalla mostrada cuando el enlace de inscripción no es utilizable.
     * Espera la variable $estado con el valor 'expirado' o 'invalido'.
     */
    $estado = $estado ?? 'invalido';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo htmlspecialchars(ESCUELA_NOMBRE); ?> | Enlace de inscripción</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo APP_URL; ?>assets/css/style.css" rel="stylesheet">
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh; padding:20px;">

    <div class="afpl-card card" style="max-width:500px; width:100%;">
        <div class="card-body afpl-expired">
            <?php if ($estado === 'expirado'): ?>
                <i class="bi bi-clock-history text-warning"></i>
                <h2>Enlace expirado</h2>
                <p>El tiempo para completar la inscripción ha finalizado.</p>
                <p>Por favor, solicite un nuevo enlace al administrador de la escuela.</p>
            <?php else: ?>
                <i class="bi bi-shield-exclamation"></i>
                <h2>Enlace no válido</h2>
                <p>El enlace de inscripción no es válido o no fue proporcionado.</p>
                <p>Utilice el enlace exacto que le fue compartido por WhatsApp.</p>
            <?php endif; ?>

            <div class="afpl-footer mt-4 pt-3 border-top">
                <?php echo htmlspecialchars(ESCUELA_NOMBRE); ?>
            </div>
        </div>
    </div>

</body>
</html>
