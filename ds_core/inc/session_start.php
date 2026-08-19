<?php
    /*
    |----------------------------------------------------------------------
    | Inicio de sesion endurecido
    |----------------------------------------------------------------------
    | SameSite=Lax evita que el navegador adjunte la cookie de sesion en
    | peticiones POST originadas en otro sitio: es la primera barrera
    | contra CSRF. HttpOnly impide que JavaScript (o un XSS) lea el
    | identificador de sesion. Secure se activa solo bajo HTTPS para no
    | romper el entorno local por HTTP.
    */

    if (session_status() === PHP_SESSION_NONE) {

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        /*
        | Es una maquina local si el nombre es localhost o la IP pertenece
        | a un rango privado. Ahi se trabaja por HTTP y no se fuerza nada;
        | fuera de ahi, el login sin cifrar entrega la contrasena a
        | cualquiera que este en el camino.
        */
        $anfitrion = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $anfitrion = explode(':', $anfitrion)[0];
        $ipServidor = (string)($_SERVER['SERVER_ADDR'] ?? '');

        $esLocal = in_array($anfitrion, ['localhost', '127.0.0.1', '::1'], true)
                || str_ends_with($anfitrion, '.local')
                || str_ends_with($anfitrion, '.test')
                || ($ipServidor !== '' && filter_var(
                        $ipServidor, FILTER_VALIDATE_IP,
                        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false);

        if (defined('DS_FORZAR_HTTPS') && DS_FORZAR_HTTPS && !$https && !$esLocal) {
            $destino = 'https://' . ($_SERVER['HTTP_HOST'] ?? $anfitrion)
                     . ($_SERVER['REQUEST_URI'] ?? '/');
            header('Location: ' . $destino, true, 301);
            exit();
        }

        /*
        | Cabeceras de seguridad, comunes a todo el ecosistema.
        |
        | No se pone Content-Security-Policy: la aplicacion usa scripts en
        | linea por todas partes (los avisos de SweetAlert2 se imprimen
        | como <script> desde PHP) y una CSP estricta la dejaria muda.
        | Ponerla exige antes retirar esos scripts en linea; frame-ancestors
        | cubre mientras tanto la parte que mas importa aqui.
        */
        if (!headers_sent()) {
            // Impide incrustar el login en un iframe ajeno para superponerle
            // una capa y robar las pulsaciones (clickjacking).
            header('X-Frame-Options: SAMEORIGIN');
            header("Content-Security-Policy: frame-ancestors 'self'");

            // Que el navegador no adivine el tipo de un archivo servido.
            header('X-Content-Type-Options: nosniff');

            // No filtrar la URL interna al salir hacia otro sitio.
            header('Referrer-Policy: same-origin');

            // Nada de esto necesita la aplicacion.
            header('Permissions-Policy: geolocation=(self), camera=(), microphone=()');

            // HSTS solo si ya se llego por HTTPS: anunciarlo por HTTP no
            // sirve de nada y el navegador lo ignora.
            if ($https && defined('DS_HSTS_MESES') && DS_HSTS_MESES > 0) {
                header('Strict-Transport-Security: max-age=' . (DS_HSTS_MESES * 2592000));
            }
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_name(APP_SESSION_NAME);
        session_start();
    }

    require_once __DIR__ . "/seguridad.php";

    /* Nombre legal y logo de la organización: los consumen los PDF,
       recibos y reportes de todos los módulos. */
    require_once __DIR__ . "/organizacion.php";

    /* Botones, iconos y pies de formulario del ecosistema: el estándar de
       interfaz vive en funciones para que las vistas no puedan desviarse. */
    require_once __DIR__ . "/ui.php";
