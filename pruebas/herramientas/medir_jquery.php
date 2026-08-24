<?php
/*
| jQuery: quién lo carga, quién lo usa de verdad y para qué.
|
| POR QUÉ NO VALE CONTAR APARICIONES
|
| Ya pasó con los plugins y ahora otra vez con ajax.js: allí se contaron
| cinco usos de jQuery y resultó que los cinco estaban DENTRO DE UN
| COMENTARIO, restos de la demo de AdminLTE con su «Lorem ipsum» incluido.
| El archivo llevaba tiempo sin ejecutar una sola línea de jQuery.
|
| Así que aquí se descarta antes de contar:
|
|   - los comentarios de bloque y de línea,
|   - las rutas de los script src, donde «jquery» es parte del nombre del
|     archivo y no una llamada.
|
| Y se separa lo que un día podría quitarse de lo que ata de verdad: si el
| único jQuery de una vista lo mete un plugin, la vista no se toca; se
| cambia el plugin o no se cambia nada.
*/
$modulos = [
    'ds_basketball' => 'c:/wamp64/www/barcelona/ds_basketball/app/views/content',
    'ds_core'       => 'c:/wamp64/www/barcelona/ds_core',
    'ds_league'     => 'c:/wamp64/www/barcelona/ds_league/views',
    'ds_arena'      => 'c:/wamp64/www/barcelona/ds_arena/views',
];

/** Deja el archivo sin comentarios ni etiquetas de carga. */
function codigoVivo(string $t): string
{
    $t = preg_replace('#<script[^>]*\bsrc=[^>]*>\s*</script>#i', '', $t);
    $t = preg_replace('#/\*.*?\*/#s', '', $t);
    $t = preg_replace('#^\s*//.*$#m', '', $t);
    $t = preg_replace('#<\?php\s*/\*.*?\*/\s*\?>#s', '', $t);
    return $t;
}

/* Los plugins que arrastran jQuery, y en qué se les reconoce. */
$plugins = [
    'DataTables'       => '/\bDataTable\s*\(|dataTables/',
    'select2'          => '/\.select2\s*\(/',
    'inputmask'        => '/\.inputmask\s*\(|Inputmask\s*\(/',
    'ekko-lightbox'    => '/ekkoLightbox/',
    'dropzone'         => '/Dropzone|dropzone/',
    'jquery-validation'=> '/\.validate\s*\(/',
    'summernote'       => '/\.summernote\s*\(/',
    'bootstrap-switch' => '/bootstrapSwitch/',
];

$total = ['carga' => 0, 'propio' => 0, 'solo_plugin' => 0, 'limpias' => 0];
$porPlugin = [];
$soloPropio = [];

foreach ($modulos as $modulo => $dir) {
    $archivos = glob($dir . '/*.php') ?: [];
    /* Core y los módulos nuevos tienen las vistas repartidas. */
    foreach (['/*/*.php'] as $mas) {
        $archivos = array_merge($archivos, glob($dir . $mas) ?: []);
    }

    foreach ($archivos as $f) {
        $bruto = (string)file_get_contents($f);
        if (!str_contains($bruto, 'jquery')) { continue; }

        $total['carga']++;
        $vivo = codigoVivo($bruto);

        /* Llamadas propias: el dólar o jQuery seguidos de paréntesis. */
        $propias = preg_match_all('/(?<![\w$])\$\s*\(|(?<![\w.])jQuery\s*\(/', $vivo);

        /* Qué plugin lo justifica. */
        $suyos = [];
        foreach ($plugins as $nombre => $patron) {
            if (preg_match($patron, $vivo)) {
                $suyos[] = $nombre;
                $porPlugin[$nombre] = ($porPlugin[$nombre] ?? 0) + 1;
            }
        }

        if ($propias > 0 && !$suyos) {
            $total['propio']++;
            $soloPropio[] = [$modulo . '/' . basename($f), $propias];
        } elseif ($suyos) {
            $total['solo_plugin']++;
        } else {
            $total['limpias']++;
            $soloPropio[] = [$modulo . '/' . basename($f) . '  (carga y no usa)', 0];
        }
    }
}

echo "=== Vistas que cargan jQuery ===\n";
printf("  %d en total\n\n", $total['carga']);

echo "=== Qué lo justifica ===\n";
arsort($porPlugin);
foreach ($porPlugin as $n => $c) { printf("  %-20s %3d vistas\n", $n, $c); }

printf("\n  con código propio y sin plugin      %3d\n", $total['propio']);
printf("  sólo por algún plugin               %3d\n", $total['solo_plugin']);
printf("  lo cargan y no lo usan para nada    %3d\n", $total['limpias']);

if ($soloPropio) {
    echo "\n=== Las que habría que reescribir a mano ===\n";
    foreach ($soloPropio as [$v, $n]) {
        printf("  %-52s %s\n", $v, $n ? $n . ' llamadas' : '');
    }
}
