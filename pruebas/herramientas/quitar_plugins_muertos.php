<?php
/*
| Retira las etiquetas que cargan plugins que nadie invoca.
|
| Cada uno se comprobó antes buscando su llamada real en el código, con
| las etiquetas <script src> y <link href> ya descartadas para que la
| propia ruta no contara como uso. Ninguno tiene una sola invocación viva
| en las 75 vistas:
|
|   tempusdominus-bootstrap-4   apuntaba a #reservationdate, inexistente
|   daterangepicker             a #reservation y #daterange-btn, inexistentes
|   moment                      sólo era dependencia de los dos anteriores
|   bootstrap4-duallistbox      a .duallistbox, inexistente
|   bs-stepper                  a .bs-stepper, inexistente
|   bootstrap-colorpicker       a .my-colorpicker1/2, inexistentes
|   bootstrap-switch            a input[data-bootstrap-switch], inexistente
|   select2-bootstrap4-theme    sólo lo usaba .select2bs4, inexistente
|   icheck-bootstrap            ninguna clase icheck-* en el HTML
|   fileinput                   ninguna llamada a .fileinput()
|   jqvmap, sparklines, jquery-knob   ninguna llamada
|
| Son megabytes que el navegador descarga en cada pantalla para no hacer
| nada, y —lo que importa aquí— son también los que ataban el módulo a
| Bootstrap 4 e impedían migrar.
|
| Uso: quitar_plugins_muertos.php [aplicar]
*/
$base    = 'c:/wamp64/www/barcelona/ds_basketball/app/views/content';
$aplicar = ($argv[1] ?? '') === 'aplicar';

$muertos = [
    'tempusdominus-bootstrap-4',
    'daterangepicker',
    'moment',
    'bootstrap4-duallistbox',
    'bs-stepper',
    'bootstrap-colorpicker',
    'bootstrap-switch',
    'select2-bootstrap4-theme',
    'icheck-bootstrap',
    'fileinput',
    'jqvmap',
    'sparklines',
    'jquery-knob',
];

$total = 0; $archivos = 0; $porPlugin = [];

foreach (glob($base . '/*.php') as $f) {
    $t = (string)file_get_contents($f);
    $orig = $t;
    $n = 0;

    foreach ($muertos as $p) {
        /* La etiqueta entera, con su línea y su sangría. Sólo <script src>
           y <link href>: nunca un <script> con código dentro. */
        $patron = '#^[ \t]*<(?:script[^>]*\bsrc|link[^>]*\bhref)="[^"]*plugins/'
                . preg_quote($p, '#') . '/[^"]*"[^>]*>(?:\s*</script>)?[ \t]*\R#mi';

        $t = preg_replace($patron, '', $t, -1, $c);
        if ($c > 0) { $n += $c; $porPlugin[$p] = ($porPlugin[$p] ?? 0) + $c; }
    }

    if ($n > 0) {
        $archivos++;
        $total += $n;
        printf("  %-38s %2d etiquetas  (-%d bytes)\n",
               basename($f, '-view.php'), $n, strlen($orig) - strlen($t));
        if ($aplicar) { file_put_contents($f, $t); }
    }
}

echo "\n=== por plugin ===\n";
arsort($porPlugin);
foreach ($porPlugin as $p => $c) { printf("  %-28s %3d\n", $p, $c); }

echo "\n  " . ($aplicar ? 'APLICADO' : 'simulado') . ": {$total} etiquetas en {$archivos} vistas\n";
