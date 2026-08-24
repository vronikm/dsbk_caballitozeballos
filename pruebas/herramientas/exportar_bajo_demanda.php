<?php
/*
| pdfmake, sus fuentes y jszip pasan a cargarse solo al exportar.
|
| LO QUE SE MIDIO EN LA PANTALLA DE ALUMNOS
|
|     pdfmake.min.js    1.317 KB
|     vfs_fonts.js        793 KB
|     jszip.min.js         94 KB
|     ------------------------
|                       2.204 KB  de 2.685 KB totales: el 82%
|
| Todo para los botones de PDF y Excel. El resto de la pantalla son 481 KB.
|
| QUE SE HACE
|
| Se quitan las tres etiquetas y se pone en su lugar exportar.js, que
| sustituye la accion de esos dos botones por una que trae la libreria la
| primera vez que se pulsa. DataTables Buttons no la necesita antes: la busca
| en window en el momento de generar el archivo.
|
| DONDE SE PONE
|
| Justo despues de dataTables.buttons.min.js, que es lo que tiene que
| parchear, y antes de que las vistas inicialicen sus tablas.
|
| Uso: exportar_bajo_demanda.php [aplicar]
*/
$base    = 'c:/wamp64/www/barcelona/ds_basketball/app/views/content';
$aplicar = ($argv[1] ?? '') === 'aplicar';

/* Las tres que dejan de cargarse de entrada. */
$pesadas = ['plugins/pdfmake/pdfmake.min.js',
            'plugins/pdfmake/vfs_fonts.js',
            'plugins/jszip/jszip.min.js'];

$nuevo = "\t<?php /* pdfmake y jszip pesan 2,2 MB y sirven a dos botones: se traen\n"
       . "\t\t\t al pulsarlos, no en cada carga. */ ?>\n"
       . "\t<script src=\"<?php echo DS_HUB_URL; ?>ds_core/assets/js/exportar.js\"></script>\n";

$hechas = []; $sinAncla = [];

foreach (glob($base . '/*.php') as $f) {
    $t = (string)file_get_contents($f);
    $v = basename($f, '-view.php');

    $tenia = 0;
    foreach ($pesadas as $p) { if (str_contains($t, $p)) { $tenia++; } }
    if ($tenia === 0) { continue; }

    /* Fuera las tres, con su linea. */
    $n = 0;
    foreach ($pesadas as $p) {
        $t = preg_replace('#^[ \t]*<script[^\r\n]*' . preg_quote($p, '#') . '[^\r\n]*\R#m', '', $t, -1, $c);
        $n += $c;
    }

    /* Y el sustituto, tras la libreria de botones. */
    if (!str_contains($t, 'assets/js/exportar.js')) {
        $m = 0;
        $t = preg_replace('#(^[ \t]*<script[^\r\n]*dataTables\.buttons\.min\.js[^\r\n]*\R)#m',
                          '$1' . $nuevo, $t, 1, $m);
        if ($m === 0) { $sinAncla[] = $v; continue; }
    }

    $hechas[$v] = $n;
    if ($aplicar) { file_put_contents($f, $t); }
}

printf("  %d vistas, %d etiquetas retiradas\n", count($hechas), array_sum($hechas));
if ($sinAncla) { printf("  sin poder anclar: %s\n", implode(' ', $sinAncla)); }

if ($aplicar) {
    $quedan = 0; $malas = [];
    foreach (glob($base . '/*.php') as $f) {
        $t = (string)file_get_contents($f);
        foreach ($pesadas as $p) { $quedan += substr_count($t, $p); }
        /* Ninguna vista puede quedarse con los botones y sin el parche. */
        if (str_contains($t, 'dataTables.buttons.min.js') && !str_contains($t, 'assets/js/exportar.js')) {
            $malas[] = basename($f, '-view.php') . ' (con botones y sin el parche)';
        }
        $s = [];
        exec('"c:/wamp64/bin/php/php8.3.28/php.exe" -l "' . $f . '" 2>&1', $s, $cod);
        if ($cod !== 0) { $malas[] = basename($f) . ' (sintaxis)'; }
    }
    printf("  etiquetas pesadas que quedan: %d\n", $quedan);
    echo $malas ? '  REVISAR: ' . implode(' ', array_unique($malas)) . "\n" : "  APLICADO\n";
} else {
    echo "  simulado (sin escribir)\n";
}
