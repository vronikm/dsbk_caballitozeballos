<?php
/*
| FASE 1 — Auditoria. Inventario del frontend, leido de los archivos.
|
| Las versiones se sacan de la cabecera de cada libreria, no de lo que
| alguien recuerde haber instalado. Y se distingue SIEMPRE entre lo que se
| carga y lo que se usa: ya paso dos veces que una libreria contada como
| imprescindible resulto no invocarse en ninguna vista.
|
| Uso: inventario.php
*/
$raiz = 'c:/wamp64/www/barcelona/';

/** Primera version que aparezca en la cabecera del archivo. */
function version(string $f): string
{
    if (!is_file($f)) { return 'no esta'; }
    $cabeza = (string)file_get_contents($f, false, null, 0, 3000);
    foreach ([
        '/v?(\d+\.\d+\.\d+(?:-[a-z0-9.]+)?)/i',
        '/version[^\d]{0,12}(\d+\.\d+(?:\.\d+)?)/i',
    ] as $p) {
        if (preg_match($p, $cabeza, $m)) { return $m[1]; }
    }
    return '?';
}

function peso(string $f): string
{
    return is_file($f) ? round(filesize($f) / 1024) . ' KB' : '—';
}

/*----------  1. Librerias  ----------*/
$libs = [
    'AdminLTE'      => 'ds_core/assets/vendor/adminlte4/css/adminlte.min.css',
    'Bootstrap'     => 'ds_core/assets/vendor/bootstrap5/js/bootstrap.bundle.min.js',
    'OverlayScroll' => 'ds_core/assets/vendor/overlayscrollbars/js/overlayscrollbars.browser.es6.min.js',
    'jQuery'        => 'ds_basketball/app/views/dist/plugins/jquery/jquery.min.js',
    'SweetAlert2'   => 'ds_basketball/app/views/dist/js/sweetalert2.all.min.js',
    'DataTables'    => 'ds_basketball/app/views/dist/plugins/datatables/jquery.dataTables.min.js',
    'DT tema BS5'   => 'ds_basketball/app/views/dist/plugins/datatables-bs5/js/dataTables.bootstrap5.min.js',
    'Select2'       => 'ds_basketball/app/views/dist/plugins/select2/js/select2.full.min.js',
    'Font Awesome'  => 'ds_core/assets/vendor/fontawesome6/css/all.min.css',
    'FullCalendar'  => 'ds_basketball/app/views/dist/plugins/fullcalendar/main.js',
    'inputmask'     => 'ds_basketball/app/views/dist/plugins/inputmask/jquery.inputmask.min.js',
    'dropzone'      => 'ds_basketball/app/views/dist/plugins/dropzone/min/dropzone.min.js',
];

echo "=== LIBRERIAS ===\n";
printf("  %-16s %-12s %10s\n", 'libreria', 'version', 'peso');
foreach ($libs as $n => $f) {
    printf("  %-16s %-12s %10s\n", $n, version($raiz . $f), peso($raiz . $f));
}

/*----------  2. Cuantas vistas hay y como cargan la interfaz  ----------*/
$grupos = [
    'ds_basketball' => $raiz . 'ds_basketball/app/views/content',
    'ds_league'     => $raiz . 'ds_league/views',
    'ds_arena'      => $raiz . 'ds_arena/views',
    'ds_core/admin' => $raiz . 'ds_core/admin/views',
];

echo "\n=== VISTAS Y LAYOUT ===\n";
$totalVistas = 0;
foreach ($grupos as $modulo => $dir) {
    $vistas = array_filter(glob($dir . '/*.php') ?: [],
                           fn($f) => !str_contains($f, 'inc' . DIRECTORY_SEPARATOR));
    $propias = $compartidas = 0;
    foreach ($vistas as $f) {
        $t = (string)file_get_contents($f);
        if (str_contains($t, 'layout-bottom') || str_contains($t, 'layout-modulo')) { $compartidas++; }
        elseif (str_contains($t, '<html'))                                          { $propias++; }
    }
    $totalVistas += count($vistas);
    printf("  %-16s %3d vistas   %3d con layout compartido   %3d con cabecera propia\n",
           $modulo, count($vistas), $compartidas, $propias);
}
printf("  %-16s %3d\n", 'TOTAL', $totalVistas);

/*----------  3. Restos de AdminLTE 3 y Bootstrap 4  ----------*/
$restos = [
    'wrapper de la v3'      => '/class="[^"]*\bwrapper\b/',
    'main-header'           => '/\bmain-header\b/',
    'main-sidebar'          => '/\bmain-sidebar\b/',
    'content-wrapper'       => '/\bcontent-wrapper\b/',
    'data-widget'           => '/\bdata-widget=/',
    'data-toggle (BS4)'     => '/\bdata-toggle=/',
    'data-target (BS4)'     => '/\bdata-target=/',
    'data-dismiss (BS4)'    => '/\bdata-dismiss=/',
    'ml-* / mr-*'           => '/\bclass="[^"]*\bm[lr]-[0-9auto]/',
    'pl-* / pr-*'           => '/\bclass="[^"]*\bp[lr]-[0-9]/',
    'float-left/right'      => '/\bclass="[^"]*\bfloat-(left|right)\b/',
    'badge-* (BS4)'         => '/\bclass="[^"]*\bbadge-[a-z]+/',
    'input-group-append'    => '/\binput-group-(append|prepend)\b/',
    'form-group'            => '/\bclass="[^"]*\bform-group\b/',
    'small-box bg-*'        => '/\bsmall-box\s+bg-/',
    'icon de la v3'         => '/<div class="icon">/',
];

echo "\n=== RESTOS DE LAS VERSIONES ANTERIORES ===\n";
$todos = [];
foreach ($grupos as $dir) { $todos = array_merge($todos, glob($dir . '/*.php') ?: []); }
$todos = array_merge($todos, glob($raiz . 'ds_basketball/app/views/inc/*.php') ?: []);
$todos = array_merge($todos, glob($raiz . 'ds_core/inc/*.php') ?: []);

foreach ($restos as $que => $patron) {
    $n = 0; $donde = [];
    foreach ($todos as $f) {
        $c = preg_match_all($patron, (string)file_get_contents($f));
        if ($c) { $n += $c; $donde[] = basename($f, '-view.php'); }
    }
    printf("  %-22s %4d  %s\n", $que, $n,
           $n ? count($donde) . ' vistas: ' . implode(' ', array_slice($donde, 0, 3))
                . (count($donde) > 3 ? ' …' : '') : '');
}

/*----------  4. Lo que pide el encargo y todavia no existe  ----------*/
echo "\n=== ENCARGO: QUE FALTA ===\n";
$busquedas = [
    'modo oscuro (data-bs-theme)' => ['patron' => '/data-bs-theme/',            'archivos' => $todos],
    'hoja propia del tema'        => ['existe' => $raiz . 'ds_core/assets/css/digisports-theme.css'],
    'js propio unificado'         => ['existe' => $raiz . 'ds_core/assets/js/digisports.js'],
    'scripts con defer'           => ['patron' => '/<script[^>]*\bdefer\b/',    'archivos' => $todos],
    'contadores en el menu'       => ['patron' => '/nav-badge|badge[^"]*ms-auto/', 'archivos' => $todos],
];
foreach ($busquedas as $que => $c) {
    if (isset($c['existe'])) {
        printf("  %-30s %s\n", $que, is_file($c['existe']) ? 'existe' : 'NO existe');
        continue;
    }
    $n = 0;
    foreach ($c['archivos'] as $f) { $n += preg_match_all($c['patron'], (string)file_get_contents($f)); }
    printf("  %-30s %s\n", $que, $n ? "$n apariciones" : 'NO existe');
}
