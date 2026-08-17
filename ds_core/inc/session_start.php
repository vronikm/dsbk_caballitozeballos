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
