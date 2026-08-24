<?php
/*
| Tres vistas que la migracion a DataTables 2 se dejo, y por que.
|
| El migrador solo tocaba las vistas que INICIALIZAN una tabla —buscaba
| «.DataTable(» en el codigo de la vista— y estas tres no la inicializan
| ahi:
|
|   carnetList    la inicializa carnet_list.js, un archivo aparte. Sigue
|                 cargando la libreria vieja y los temas de Bootstrap 4.
|
|   egresoList    NO inicializa ninguna tabla, ni aqui ni fuera. Cargaban
|   ingresoList   los trece archivos de DataTables para nada, y ya lo hacian
|                 antes de esta migracion: se comprobo contra el ultimo
|                 commit.
|
| QUE SE HACE
|
|   carnetList    pasa a DataTables 2 como las demas, y su inicializacion
|                 —que vive en carnet_list.js— tambien.
|   las otras dos pierden la pila entera.
|
| Uso: dt2_rezagadas.php [aplicar]
*/
$base    = 'c:/wamp64/www/barcelona/ds_basketball/app/views/content';
$js      = 'c:/wamp64/www/barcelona/ds_basketball/app/views/dist/js';
$aplicar = ($argv[1] ?? '') === 'aplicar';

/*----------  1. Las dos que no usan la tabla: fuera todo  ----------*/
$fuera = ['plugins/datatables/', 'plugins/datatables-bs5/', 'plugins/datatables-responsive/',
          'plugins/datatables-buttons/', 'assets/js/exportar.js'];

foreach (['egresoList', 'ingresoList'] as $v) {
    $f = $base . '/' . $v . '-view.php';
    $t = (string)file_get_contents($f);
    $n = 0;
    foreach ($fuera as $p) {
        $t = preg_replace('#^[ \t]*<(?:script|link)[^\r\n]*' . preg_quote($p, '#') . '[^\r\n]*\R#m',
                          '', $t, -1, $c);
        $n += $c;
    }
    printf("  %-14s %d etiquetas retiradas (no inicializa ninguna tabla)\n", $v, $n);
    if ($aplicar && $n) { file_put_contents($f, $t); }
}

/*----------  2. carnetList: a la version 2  ----------*/
$rutas = [
    'app/views/dist/plugins/datatables/jquery.dataTables.min.js'
        => 'ds_core/assets/vendor/datatables2/js/dataTables.min.js',
    'app/views/dist/plugins/datatables-bs5/js/dataTables.bootstrap5.min.js'
        => 'ds_core/assets/vendor/datatables2/js/dataTables.bootstrap5.min.js',
    'app/views/dist/plugins/datatables-bs5/css/dataTables.bootstrap5.min.css'
        => 'ds_core/assets/vendor/datatables2/css/dataTables.bootstrap5.min.css',
    'app/views/dist/plugins/datatables-responsive/js/dataTables.responsive.min.js'
        => 'ds_core/assets/vendor/datatables2/js/dataTables.responsive.min.js',
    'app/views/dist/plugins/datatables-responsive/js/responsive.bootstrap4.min.js'
        => 'ds_core/assets/vendor/datatables2/js/responsive.bootstrap5.min.js',
    'app/views/dist/plugins/datatables-responsive/css/responsive.bootstrap4.min.css'
        => 'ds_core/assets/vendor/datatables2/css/responsive.bootstrap5.min.css',
    'app/views/dist/plugins/datatables-buttons/css/buttons.bootstrap4.min.css'
        => 'ds_core/assets/vendor/datatables2/css/buttons.bootstrap5.min.css',
];

$f = $base . '/carnetList-view.php';
$t = (string)file_get_contents($f);
$n = 0;
foreach ($rutas as $de => $a) {
    $t = str_replace('<?php echo APP_URL; ?>' . $de, '<?php echo DS_HUB_URL; ?>' . $a, $t, $c);
    $n += $c;
}
printf("  %-14s %d rutas a la versión 2\n", 'carnetList', $n);
if ($aplicar && $n) { file_put_contents($f, $t); }

/*----------  3. Y su inicializacion, que vive aparte  ----------*/
$fjs = $js . '/carnet_list.js';
$tjs = (string)file_get_contents($fjs);
$m = 0;
$tjs2 = preg_replace_callback('/\$\(\s*(["\'])([^"\']+)\1\s*\)\s*\.DataTable\s*\(/',
    function ($x) use (&$m) { $m++; return 'new DataTable(' . $x[1] . $x[2] . $x[1] . ', '; }, $tjs);
printf("  %-14s %d inicializaciones a la sintaxis de la 2\n", 'carnet_list.js', $m);
if ($aplicar && $m) { file_put_contents($fjs, $tjs2); }

if ($aplicar) {
    $malas = [];
    foreach (['egresoList', 'ingresoList', 'carnetList'] as $v) {
        $x = (string)file_get_contents($base . '/' . $v . '-view.php');
        if (str_contains($x, 'bootstrap4.min.css')) { $malas[] = "$v (queda CSS de BS4)"; }
        if (str_contains($x, 'jquery.dataTables.min.js')) { $malas[] = "$v (queda la libreria vieja)"; }
        $s = [];
        exec('"c:/wamp64/bin/php/php8.3.28/php.exe" -l "' . $base . '/' . $v . '-view.php" 2>&1', $s, $cod);
        if ($cod !== 0) { $malas[] = "$v (sintaxis)"; }
    }
    echo $malas ? "\n  REVISAR: " . implode(' ', $malas) . "\n" : "\n  APLICADO\n";
} else {
    echo "\n  simulado (sin escribir)\n";
}
