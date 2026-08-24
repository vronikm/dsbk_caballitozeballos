<?php
/*
| Nueve suites decian comprobar los errores de JavaScript y no comprobaban
| nada.
|
| LO QUE PASO
|
| Todas se apoyaban en el evento pageerror del navegador automatizado. Se
| probo la sonda contra un error provocado a proposito:
|
|     new NoExisteEstaCosa()   →  la sonda no vio NADA
|
| Ni pageerror, ni console.error, ni un throw dentro de un setTimeout. En
| este entorno —patchright, con el contexto aislado— esos avisos no llegan.
| Mientras tanto habia DIEZ vistas lanzando excepciones de verdad, incluida
| una que dejaba una pantalla entera sin envio por AJAX.
|
| Es el peor tipo de fallo en una prueba: la que da verde sin mirar.
|
| QUE SE HACE
|
| No se borra el listener —recoge algo en ciertos casos y no estorba— pero
| se deja escrito en cada archivo de donde viene la comprobacion de verdad,
| para que nadie vuelva a confiar en el.
|
| Uso: avisar_pageerror.php [aplicar]
*/
$dir     = 'c:/wamp64/www/barcelona/pruebas';
$aplicar = ($argv[1] ?? '') === 'aplicar';

$aviso = "/*\n"
       . "| AVISO SOBRE «sin errores de JavaScript» EN ESTE ARCHIVO\n"
       . "|\n"
       . "| El evento pageerror NO es de fiar en este entorno: se probo con un\n"
       . "| error provocado y no lo detecto. Quien comprueba las excepciones de\n"
       . "| verdad es qa_errores_js.mjs, que usa Runtime.exceptionThrown del\n"
       . "| protocolo del motor y ademas verifica su propia sonda antes de\n"
       . "| barrer. Lo que sigue capturando aqui son las respuestas 4xx, que esas\n"
       . "| si llegan.\n"
       . "*/\n";

$tocados = [];

foreach (glob($dir . '/qa_*.mjs') as $f) {
    $v = basename($f);
    if ($v === 'qa_errores_js.mjs') { continue; }   /* es el que si funciona */

    $t = (string)file_get_contents($f);
    if (!str_contains($t, 'pageerror')) { continue; }
    if (str_contains($t, 'AVISO SOBRE')) { continue; }   /* ya avisado */

    /* Se pone justo despues del comentario de cabecera, antes del primer
       import, para que sea lo primero que se lea. */
    $n = 0;
    $t2 = preg_replace('/^(import \{ createRequire \})/m', $aviso . "\n$1", $t, 1, $n);
    if ($n !== 1) { printf("  %-26s no se pudo insertar\n", $v); continue; }

    $tocados[] = $v;
    if ($aplicar) { file_put_contents($f, $t2); }
}

printf("  suites avisadas: %d\n    %s\n", count($tocados), implode("\n    ", $tocados));
echo "\n" . ($aplicar ? "  APLICADO\n" : "  simulado (sin escribir)\n");
