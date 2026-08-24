<?php
/*
| Llamadas a plugins que la vista no carga: diez excepciones en siete vistas.
|
| COMO APARECIERON
|
| Estaban ahi desde siempre, pero ninguna suite las veia: las
| comprobaciones de «sin errores de JavaScript» se apoyaban en un evento que
| en este navegador automatizado NO llega. Se comprobo inyectando un error a
| proposito y la sonda no vio nada. Con el detector correcto —las
| excepciones que reporta el propio motor— salieron diez vistas.
|
| QUE SE QUITA, Y POR QUE ES SEGURO
|
|   filterizr (6 vistas)   $('.filter-container').filterizr(...). La clase
|                          .filter-container no existe en ninguna de esas
|                          vistas: la unica aparicion es la propia llamada.
|
|   Dropzone (2 vistas)    Un bloque que empieza con «// DropzoneJS Demo Code
|                          Start» y acaba con «End». Lo dice el comentario:
|                          es la demo de la plantilla. No hay ningun elemento
|                          dropzone en la pagina.
|
|   ekkoLightbox (1)       Sin la libreria cargada y sin galeria que abrir.
|
|   select2 (1)            Esta SI la dejo esta migracion: al retirar select2
|                          de las vistas donde no aportaba, el patron exigia
|                          que la linea acabara en «)» y esta acaba en «;»,
|                          asi que la llamada sobrevivio a la libreria.
|
|   ajax.js dos veces (1)  El mismo archivo cargado dos veces declara
|                          «formularios_ajax» dos veces con const: error de
|                          sintaxis y el archivo entero deja de aplicarse. Es
|                          decir, esa pantalla se quedaba sin el envio por
|                          AJAX.
|
| Uso: quitar_llamadas_muertas.php [aplicar]
*/
$base    = 'c:/wamp64/www/barcelona/ds_basketball/app/views/content';
$aplicar = ($argv[1] ?? '') === 'aplicar';

$hechos = [];

/*----------  1. Llamadas de una linea  ----------*/
$deUnaLinea = [
    'filterizr'    => '/^[ \t]*\$\(\s*[\'"]\.filter-container[\'"]\s*\)\s*\.filterizr\([^;]*\);[ \t]*\R/m',
    'select2'      => '/^[ \t]*\$\(\s*[\'"]\.select2[\'"]\s*\)\s*\.select2\([^;]*\);[ \t]*\R/m',
];

foreach (glob($base . '/*.php') as $f) {
    $t = (string)file_get_contents($f);
    $orig = $t;
    $v = basename($f, '-view.php');
    $notas = [];

    foreach ($deUnaLinea as $nombre => $patron) {
        $t = preg_replace($patron, '', $t, -1, $n);
        if ($n) { $notas[] = "$nombre ($n)"; }
    }

    /*----------  2. El bloque de demostracion de Dropzone  ----------*/
    $t = preg_replace(
        '#[ \t]*// DropzoneJS Demo Code Start.*?// DropzoneJS Demo Code End[ \t]*\R#s',
        '', $t, -1, $nd);
    if ($nd) { $notas[] = "bloque Dropzone ($nd)"; }

    /*----------  3. La galeria que no existe  ----------*/
    if (str_contains($t, 'ekkoLightbox')) {
        /* Solo si la libreria no se carga en esta vista. */
        if (!preg_match('#ekko-lightbox[^"]*\.js#i', $t)) {
            /* Se quita solo la llamada, no el manejador que la envuelve: un
               manejador vacio sobre enlaces que no existen no hace nada, y
               aislarlo con una expresion regular es mas arriesgado que
               dejarlo. (Un intento anterior uso [^\R], que no es valido
               dentro de una clase de caracteres, y devolvia null.) */
            $t = preg_replace('#^[ \t]*\$\(this\)\.ekkoLightbox\(\{[^}]*\}\);[ \t]*\R#m',
                              '', $t, -1, $nl);
            if ($nl) { $notas[] = "lightbox ($nl)"; }
        }
    }

    /*----------  4. ajax.js repetido  ----------*/
    if (substr_count($t, 'js/ajax.js') > 1) {
        /* Se deja la primera y se quitan las demas. */
        $partes = preg_split('#(^[ \t]*<script[^\r\n]*js/ajax\.js[^\r\n]*\R)#m', $t, -1,
                             PREG_SPLIT_DELIM_CAPTURE);
        $vistas = 0; $salida = '';
        foreach ($partes as $p) {
            if (preg_match('#js/ajax\.js#', $p) && str_contains($p, '<script')) {
                $vistas++;
                if ($vistas > 1) { continue; }   /* se descarta la repetida */
            }
            $salida .= $p;
        }
        if ($vistas > 1) { $t = $salida; $notas[] = 'ajax.js repetido (' . ($vistas - 1) . ')'; }
    }

    if ($t === $orig) { continue; }
    $hechos[$v] = implode(', ', $notas);
    if ($aplicar) { file_put_contents($f, $t); }
}

foreach ($hechos as $v => $n) { printf("  %-26s %s\n", $v, $n); }
printf("\n  %d vistas\n", count($hechos));

if ($aplicar) {
    $malas = [];
    foreach (array_keys($hechos) as $v) {
        $f = $base . '/' . $v . '-view.php';
        $s = [];
        exec('"c:/wamp64/bin/php/php8.3.28/php.exe" -l "' . $f . '" 2>&1', $s, $cod);
        if ($cod !== 0) { $malas[] = $v; }
    }
    echo $malas ? '  SINTAXIS ROTA: ' . implode(' ', $malas) . "\n" : "  APLICADO\n";
} else {
    echo "  simulado (sin escribir)\n";
}
