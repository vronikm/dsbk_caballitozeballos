<?php
/*
| Font Awesome 5.15.4 → 6.7.2.
|
| QUE CAMBIA
|
| La 6 renombro iconos —fa-times pasa a fa-xmark, fa-adjust a
| fa-circle-half-stroke— pero MANTIENE alias para los nombres de la 5. La
| pregunta no es si la tabla de equivalencias es correcta, sino si TODOS los
| iconos que usa este sistema siguen dibujando.
|
| Eso no se supone: se mide. qa_iconos.mjs recorre las setenta vistas y le
| pregunta al navegador, icono por icono, si esa clase produce un caracter.
| Se tomo la foto con la version 5 —2.462 iconos, 65 clases distintas— y
| despues de este cambio se compara. Cualquier clase que dibujara antes y no
| despues sale por su nombre.
|
| DONDE SE INSTALA
|
| En ds_core/assets/vendor/fontawesome6/, como AdminLTE 4 y DataTables 2: es
| una libreria del ecosistema, no de un modulo. La 5 vivia dentro de
| Basketball, de modo que los otros tres modulos dependian de que Basketball
| siguiera ahi.
|
| Uso: migrar_fa6.php [aplicar]
*/
$dirs = ['ds_basketball/app/views/content', 'ds_basketball/app/views/inc',
         'ds_league/views', 'ds_arena/views', 'ds_core/admin/views',
         'ds_core/inc', 'ds_core/hub'];
$raiz    = 'c:/wamp64/www/barcelona/';
$aplicar = ($argv[1] ?? '') === 'aplicar';

/* Todas las formas con que las vistas cargan la 5. */
$viejas = [
    '<?php echo APP_URL; ?>app/views/dist/plugins/fontawesome-free/css/all.min.css',
    '<?php echo DS_HUB_URL; ?>ds_basketball/app/views/dist/plugins/fontawesome-free/css/all.min.css',
    '<?php echo DS_VENDOR_URL; ?>plugins/fontawesome-free/css/all.min.css',
];
$nueva = '<?php echo DS_HUB_URL; ?>ds_core/assets/vendor/fontawesome6/css/all.min.css';

$hechas = []; $total = 0;

foreach ($dirs as $d) {
    foreach (glob($raiz . $d . '/*.php') ?: [] as $f) {
        $t = (string)file_get_contents($f);
        $n = 0;
        foreach ($viejas as $v) {
            $t = str_replace($v, $nueva, $t, $c);
            $n += $c;
        }
        if ($n === 0) { continue; }
        $hechas[basename($f, '-view.php')] = $n;
        $total += $n;
        if ($aplicar) { file_put_contents($f, $t); }
    }
}

printf("  %d cargas cambiadas en %d archivos\n", $total, count($hechas));

if ($aplicar) {
    /* Que no quede ninguna referencia a la 5 y que la 6 este servible. */
    $quedan = 0;
    foreach ($dirs as $d) {
        foreach (glob($raiz . $d . '/*.php') ?: [] as $f) {
            $quedan += substr_count((string)file_get_contents($f), 'plugins/fontawesome-free/css');
        }
    }
    $css = $raiz . 'ds_core/assets/vendor/fontawesome6/css/all.min.css';
    $fuentes = glob($raiz . 'ds_core/assets/vendor/fontawesome6/webfonts/*') ?: [];

    printf("  cargas de la versión 5 que quedan: %d\n", $quedan);
    printf("  la hoja de la 6 está:              %s\n", is_file($css) ? 'sí' : 'NO');
    printf("  archivos de fuente:                %d\n", count($fuentes));

    echo ($quedan === 0 && is_file($css) && count($fuentes) >= 6)
        ? "\n  APLICADO. Falta comparar los iconos con la foto de la versión 5.\n"
        : "\n  REVISAR\n";
} else {
    echo "  simulado (sin escribir)\n";
}
