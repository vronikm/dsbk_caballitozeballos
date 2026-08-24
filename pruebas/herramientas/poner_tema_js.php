<?php
/*
| El script del tema, en la CABECERA de cada vista.
|
| POR QUE EN LA CABECERA Y NO AL FINAL, COMO TODO LO DEMAS
|
| El tema se aplica poniendo data-bs-theme en la raiz del documento. Si eso
| ocurre al final del cuerpo —o con defer— el navegador ya ha pintado la
| pagina en claro y se ve saltar a oscuro. Ese parpadeo, en una pantalla a
| oscuras, deslumbra.
|
| La unica forma de evitarlo es que el script corra mientras se lee la
| cabecera, antes del primer pintado. Es la excepcion consciente a la regla
| de cargar el JavaScript al final: son dos kilobytes y evitan un defecto
| que se nota en cada carga de cada pantalla.
|
| SE ANCLA EN </head>
|
| No en la hoja de estilos del nucleo: nueve vistas no la cargan —el acceso,
| el hub, la pantalla de foto del carnet— y tambien tienen que respetar el
| tema. Toda vista completa tiene su </head>.
|
| Uso: poner_tema_js.php [aplicar]
*/
$dirs = ['ds_basketball/app/views/content', 'ds_league/views', 'ds_arena/views',
         'ds_core/admin/views', 'ds_core/inc', 'ds_core/hub'];
$raiz    = 'c:/wamp64/www/barcelona/';
$aplicar = ($argv[1] ?? '') === 'aplicar';

$etiqueta = "\t<?php /* El tema, antes del primer pintado: sin defer a proposito. */ ?>\n"
          . "\t<script src=\"<?php echo DS_HUB_URL; ?>ds_core/assets/js/tema.js\"></script>\n";

$puestas = []; $yaEstaban = 0; $sinCabecera = [];

foreach ($dirs as $d) {
    foreach (glob($raiz . $d . '/*.php') ?: [] as $f) {
        $t = (string)file_get_contents($f);
        if (!preg_match('/<html[\s>]/i', $t)) { continue; }

        $v = basename($f, '-view.php');
        if (str_contains($t, 'assets/js/tema.js')) { $yaEstaban++; continue; }

        if (!preg_match('#</head>#i', $t)) { $sinCabecera[] = $v; continue; }

        $crlf = str_contains($t, "\r\n");
        $eti  = $crlf ? str_replace("\n", "\r\n", $etiqueta) : $etiqueta;

        $n = 0;
        $t2 = preg_replace('#([ \t]*)</head>#i', $eti . '$1</head>', $t, 1, $n);
        if ($n !== 1) { $sinCabecera[] = $v . ' (no se pudo insertar)'; continue; }

        $puestas[] = $v;
        if ($aplicar) { file_put_contents($f, $t2); }
    }
}

printf("  vistas que reciben el script: %d\n", count($puestas));
printf("  ya lo tenian:                 %d\n", $yaEstaban);
if ($sinCabecera) { printf("  sin </head>:                  %d  %s\n",
                           count($sinCabecera), implode(' ', $sinCabecera)); }

if ($aplicar) {
    $malas = [];
    foreach ($dirs as $d) {
        foreach (glob($raiz . $d . '/*.php') ?: [] as $f) {
            $t = (string)file_get_contents($f);
            if (!preg_match('/<html[\s>]/i', $t)) { continue; }
            /* Ni dos veces, ni fuera de la cabecera. */
            if (substr_count($t, 'assets/js/tema.js') > 1) { $malas[] = basename($f) . ' (dos veces)'; }
            $cabeza = substr($t, 0, stripos($t, '</head>') ?: strlen($t));
            if (str_contains($t, 'assets/js/tema.js') && !str_contains($cabeza, 'assets/js/tema.js')) {
                $malas[] = basename($f) . ' (fuera de la cabecera)';
            }
            $salida = [];
            exec('"c:/wamp64/bin/php/php8.3.28/php.exe" -l "' . $f . '" 2>&1', $salida, $cod);
            if ($cod !== 0) { $malas[] = basename($f) . ' (sintaxis)'; }
        }
    }
    echo $malas ? "\n  REVISAR: " . implode(' ', $malas) . "\n" : "\n  APLICADO y comprobado\n";
} else {
    echo "\n  simulado (sin escribir)\n";
}
