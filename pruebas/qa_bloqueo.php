<?php
/*
| Esta carpeta NO se sirve por HTTP.
|
| POR QUE ES LA COMPROBACION MAS IMPORTANTE DE AQUI
|
| En pruebas/ hay scripts que se conectan a la base con credenciales, crean
| y borran usuarios y vacian tablas. El .htaccess de la raiz entrega
| directamente cualquier archivo que exista en disco, asi que sin el
| bloqueo bastaria con acertar un nombre para ejecutar cualquiera de ellos
| desde el navegador.
|
| El bloqueo depende de que Apache tenga AllowOverride activo. Eso no se
| supone: se pide una URL y se mira que responda 403. Si alguien cambia la
| configuracion del servidor, este es el aviso.
|
| Se prueban varias formas de pedirlo, porque un bloqueo que solo cubre la
| carpeta y no sus subcarpetas deja fuera archivo/ y herramientas/, que es
| donde estan casi todos los scripts.
*/
$base = 'http://localhost/barcelona/pruebas/';

$intentos = [
    ''                            => 'el listado de la carpeta',
    'regresion.sh'                => 'el lanzador',
    'qa2fa_usuario.php'           => 'el script que crea usuarios',
    'herramientas/medir_peso.php' => 'una herramienta',
    'archivo/qa_claves.php'       => 'algo del archivo',
    'sonda_layout.js'             => 'una sonda',
    '.htaccess'                   => 'el propio bloqueo',
];

$fallos = 0;

foreach ($intentos as $ruta => $que) {
    $ch = curl_init($base . $ruta);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $cuerpo = (string)curl_exec($ch);
    $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    /* 403 o 404 valen: lo que no vale es que responda con contenido. */
    $bien = in_array($codigo, [403, 404], true);

    /* Y que no se haya colado nada del archivo en la respuesta. */
    if ($bien && $cuerpo !== '' && (str_contains($cuerpo, '<?php')
        || str_contains($cuerpo, 'PDO') || str_contains($cuerpo, 'root'))) {
        $bien = false;
        $codigo = "$codigo pero devolvio contenido";
    }

    printf("  %-34s %s  (HTTP %s)\n", $que, $bien ? 'OK' : 'FALLA', $codigo);
    if (!$bien) { $fallos++; }
}

printf("\nfallos: %d\n", $fallos);
exit($fallos === 0 ? 0 : 1);
