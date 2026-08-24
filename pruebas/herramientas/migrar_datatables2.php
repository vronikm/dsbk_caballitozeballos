<?php
/*
| DataTables 1.11 → 2.x, sin jQuery.
|
| POR QUE SE PUEDE
|
| Se midieron las opciones que usan las 36 vistas: info, responsive,
| autoWidth, lengthChange, buttons, language, paging, searching, ordering,
| pageLength, order, columnDefs. Todas son nombres modernos y NINGUNA usa
| «dom», que es el cambio que rompe al pasar a la 2.x.
|
| LO QUE SI HAY QUE REESCRIBIR
|
|     }).buttons().container().appendTo('#example1_wrapper .col-md-6:eq(0)');
|
| Tres problemas en una linea: appendTo es de jQuery, :eq() no es CSS sino
| una extension de jQuery, y ese marcado —#tabla_wrapper con columnas
| col-md-6— la version 2 ya no lo genera. Su sistema de disposicion coloca
| los botones sin tocar el DOM a mano:
|
|     layout: { topStart: 'buttons' }
|
| DOS SITUACIONES DISTINTAS
|
| De las 36 vistas, 17 configuran botones de verdad y 17 solo encadenan
| .buttons() sin pasarle nada: crean un grupo vacio y lo cuelgan de un sitio
| que ni siquiera existira. En esas la cadena se quita y ya esta.
|
| EL ENVOLTORIO
|
| $(function(){...}) pasa a DOMContentLoaded. Es equivalente y deja de
| depender de jQuery; el codigo de dentro puede seguir usandolo mientras
| otros plugins lo necesiten.
|
| Uso: migrar_datatables2.php <vista|todas> [aplicar]
*/
$base    = 'c:/wamp64/www/barcelona/ds_basketball/app/views/content';
$cual    = $argv[1] ?? 'todas';
$aplicar = ($argv[2] ?? '') === 'aplicar';

/* Rutas viejas → nuevas. El tema de botones y el de responsive pasan de la
   variante de Bootstrap 4 a la de 5, que es la que corresponde. */
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
    'app/views/dist/plugins/datatables-buttons/js/dataTables.buttons.min.js'
        => 'ds_core/assets/vendor/datatables2/js/dataTables.buttons.min.js',
    'app/views/dist/plugins/datatables-buttons/js/buttons.bootstrap4.min.js'
        => 'ds_core/assets/vendor/datatables2/js/buttons.bootstrap5.min.js',
    'app/views/dist/plugins/datatables-buttons/css/buttons.bootstrap4.min.css'
        => 'ds_core/assets/vendor/datatables2/css/buttons.bootstrap5.min.css',
    'app/views/dist/plugins/datatables-buttons/js/buttons.html5.min.js'
        => 'ds_core/assets/vendor/datatables2/js/buttons.html5.min.js',
    'app/views/dist/plugins/datatables-buttons/js/buttons.print.min.js'
        => 'ds_core/assets/vendor/datatables2/js/buttons.print.min.js',
    'app/views/dist/plugins/datatables-buttons/js/buttons.colVis.min.js'
        => 'ds_core/assets/vendor/datatables2/js/buttons.colVis.min.js',
];

$archivos = ($cual === 'todas') ? glob($base . '/*.php') : [$base . '/' . $cual . '-view.php'];
$resumen  = [];

foreach ($archivos as $f) {
    if (!is_file($f)) { printf("  %s: no existe\n", basename($f)); continue; }

    $t = (string)file_get_contents($f);
    $vivo = preg_replace('#<script[^>]*\bsrc=[^>]*>\s*</script>#i', '', $t);
    if (!preg_match('/\.DataTable\s*\(/', $vivo)) { continue; }

    $v = basename($f, '-view.php');
    $orig = $t;
    $cambios = [];

    /*----------  1. Las rutas  ----------*/
    $nr = 0;
    foreach ($rutas as $de => $a) {
        /* La vieja va con APP_URL y la nueva con DS_HUB_URL. */
        $t = str_replace('<?php echo APP_URL; ?>' . $de, '<?php echo DS_HUB_URL; ?>' . $a, $t, $c);
        $nr += $c;
    }
    if ($nr) { $cambios[] = "$nr rutas"; }

    /*----------  2. La inicializacion  ----------*/
    $ni = 0;
    $t = preg_replace_callback(
        '/\$\(\s*([\'"])([^\'"]+)\1\s*\)\s*\.DataTable\s*\(/',
        function ($m) use (&$ni) { $ni++; return 'new DataTable(' . $m[1] . $m[2] . $m[1] . ', '; },
        $t);
    if ($ni) { $cambios[] = "$ni inicializaciones"; }

    /*----------  3. La cadena de botones  ----------*/
    $tieneOpcion = (bool)preg_match('/["\']?buttons["\']?\s*:/', $vivo);
    $nb = 0;
    $t = preg_replace_callback(
        '/\}\s*\)\s*\.buttons\(\)\s*\.container\(\)\s*\.appendTo\(\s*[\'"][^\'"]*[\'"]\s*\)\s*;/',
        function () use (&$nb) { $nb++; return '});'; },
        $t);
    if ($nb) { $cambios[] = "$nb cadenas de botones"; }

    /* Donde SI habia botones configurados, hay que decirle donde ponerlos. */
    if ($tieneOpcion && $nb) {
        $nl = 0;
        $t = preg_replace_callback(
            '/(["\']?buttons["\']?\s*:)/',
            function ($m) use (&$nl) {
                if ($nl++ > 0) { return $m[0]; }   /* solo la primera */
                return "layout: { topStart: 'buttons' },\n\t\t\t" . $m[1];
            }, $t);
        if ($nl) { $cambios[] = 'disposición de botones'; }
    }

    /*----------  4. El envoltorio  ----------*/
    $ne = 0;
    $t = str_replace('$(function () {',
                     "document.addEventListener('DOMContentLoaded', function () {", $t, $ne);
    $t = str_replace('$(function() {',
                     "document.addEventListener('DOMContentLoaded', function () {", $t, $ne2);
    if ($ne + $ne2) { $cambios[] = 'envoltorio'; }

    if ($t === $orig) { continue; }

    $resumen[$v] = implode(', ', $cambios);
    if ($aplicar) { file_put_contents($f, $t); }
}

foreach ($resumen as $v => $c) { printf("  %-32s %s\n", $v, $c); }
printf("\n  %d vistas\n", count($resumen));

if ($aplicar) {
    $malas = [];
    foreach (array_keys($resumen) as $v) {
        $f = $base . '/' . $v . '-view.php';
        $t = (string)file_get_contents($f);
        if (str_contains($t, '.buttons().container()')) { $malas[] = "$v (queda la cadena)"; }
        if (str_contains($t, 'plugins/datatables'))     { $malas[] = "$v (queda una ruta vieja)"; }
        $s = [];
        exec('"c:/wamp64/bin/php/php8.3.28/php.exe" -l "' . $f . '" 2>&1', $s, $cod);
        if ($cod !== 0) { $malas[] = "$v (sintaxis)"; }
    }
    echo $malas ? '  REVISAR: ' . implode(' ', array_unique($malas)) . "\n" : "  APLICADO\n";
} else {
    echo "  simulado (sin escribir)\n";
}
