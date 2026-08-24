<?php
/*
| CARGADO no es lo mismo que USADO — versión corregida.
|
| La primera versión daba 27 vistas usando bs-stepper, lo cual no tenía
| sentido para un asistente por pasos en pantallas de listado. El motivo:
| el patrón «bs-stepper» casaba también con la RUTA del <script>, así que
| todo lo que se cargaba aparecía como usado.
|
| Aquí se retiran primero las etiquetas <script src> y <link href>, y sólo
| entonces se busca la invocación. Sin eso, la medición confirma lo que ya
| se creía en lugar de comprobarlo.
*/
$base = 'c:/wamp64/www/barcelona/ds_basketball/app/views/content';

$invocacion = [
    'tempusdominus-bootstrap-4' => '/datetimepicker\s*\(|data-target-input|datetimepicker\b/',
    'select2'                   => '/\.select2\s*\(|class="[^"]*\bselect2\b[^"]*"/',
    'bootstrap4-duallistbox'    => '/bootstrapDualListbox/i',
    'bs-stepper'                => '/new\s+Stepper|class="bs-stepper/',
    'bootstrap-colorpicker'     => '/\.colorpicker\s*\(|data-colorpicker/',
    'bootstrap-switch'          => '/bootstrapSwitch|data-bootstrap-switch/',
    'icheck-bootstrap'          => '/icheck-(primary|success|danger|warning|info)/',
    'fileinput'                 => '/\.fileinput\s*\(|type="file"[^>]*class="[^"]*file/',
    'ekko-lightbox'             => '/ekkoLightbox|toggle="lightbox"/',
    'datatables-bs4'            => '/\.DataTable\s*\(|\.dataTable\s*\(/',
    'daterangepicker'           => '/\.daterangepicker\s*\(|daterangepicker\s*\(\s*\{/',
    'inputmask'                 => '/\.inputmask\s*\(|Inputmask\s*\(/',
    'dropzone'                  => '/new\s+Dropzone|Dropzone\.options|class="dropzone/',
    'jqvmap'                    => '/\.vectorMap\s*\(/',
    'sparklines'                => '/\.sparkline\s*\(/',
    'jquery-knob'               => '/\.knob\s*\(/',
    'fullcalendar'              => '/new\s+(FullCalendar\.)?Calendar\s*\(/',
    'jquery-validation'         => '/\.validate\s*\(/',
    'chart.js'                  => '/new\s+Chart\s*\(/',
    'summernote'                => '/\.summernote\s*\(/',
    'toastr'                    => '/toastr\.(success|error|info|warning)/',
];

$resultado = [];

foreach (glob($base . '/*.php') as $f) {
    $bruto = (string)file_get_contents($f);
    $vista = basename($f, '-view.php');

    /* Se quitan las etiquetas de carga ANTES de buscar la invocación: son
       lo que hacía que todo pareciese usado. */
    $codigo = preg_replace('#<script[^>]*\bsrc=[^>]*>\s*</script>#i', '', $bruto);
    $codigo = preg_replace('#<link[^>]*>#i', '', $codigo);

    foreach ($invocacion as $plugin => $patron) {
        $cargado = str_contains($bruto, 'plugins/' . $plugin . '/');
        $usado   = (bool)preg_match($patron, $codigo);

        if ($cargado) { $resultado[$plugin]['cargado'][] = $vista; }
        if ($usado)   { $resultado[$plugin]['usan'][] = $vista; }
    }
}

printf("%-28s %8s %7s   %s\n", 'PLUGIN', 'CARGADO', 'USADO', 'DONDE SE USA');
echo str_repeat('-', 112) . "\n";

$muertos = []; $vivos = [];

foreach ($resultado as $p => $r) {
    $usan    = $r['usan'] ?? [];
    $cargado = $r['cargado'] ?? [];
    printf("%-28s %8d %7d   %s\n", $p, count($cargado), count($usan),
           $usan ? implode(', ', array_slice($usan, 0, 6))
                   . (count($usan) > 6 ? ' …+' . (count($usan) - 6) : '')
                 : '—');
    if (!$usan) { $muertos[$p] = count($cargado); } else { $vivos[$p] = $usan; }
}

echo "\n=== PESO MUERTO: se descargan y nadie los llama ===\n";
$kb = 0;
foreach ($muertos as $p => $n) {
    printf("  %-28s cargado en %2d vistas\n", $p, $n);
}

echo "\n=== HAY QUE RESOLVERLOS ===\n";
uasort($vivos, fn($a, $b) => count($b) <=> count($a));
foreach ($vivos as $p => $v) {
    printf("  %-28s %2d vistas: %s\n", $p, count($v), implode(', ', $v));
}
