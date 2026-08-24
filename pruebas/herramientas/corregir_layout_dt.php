<?php
/*
| Corrige la disposicion de los botones, que quedo en el sitio equivocado.
|
| EL ERROR
|
| La migracion a DataTables 2 inserta «layout: { topStart: 'buttons' }» antes
| de la opcion buttons. Se busco la PRIMERA aparicion de «buttons:» y en
| estas vistas la primera no es la buena:
|
|     "language": {
|         ...
|         "buttons": { "copy": "Copiar", "print": "Imprimir", ... }   ← traducciones
|     },
|     "buttons": ["copy", "csv", "excel", "pdf", "print", "colvis"]   ← la de verdad
|
| El layout acabo DENTRO del bloque de idioma, donde no significa nada, y
| los botones no aparecian en ninguna de las dieciseis vistas.
|
| Las dos se llaman igual; lo que las distingue es el valor: las
| traducciones son un objeto y la configuracion real es un ARRAY. Por eso
| ahora se busca «buttons» seguido de un corchete.
|
| Uso: corregir_layout_dt.php [aplicar]
*/
$base    = 'c:/wamp64/www/barcelona/ds_basketball/app/views/content';
$aplicar = ($argv[1] ?? '') === 'aplicar';

$arreglados = []; $sinArray = [];

foreach (glob($base . '/*.php') as $f) {
    $t = (string)file_get_contents($f);
    if (!str_contains($t, "layout: { topStart: 'buttons' }")) { continue; }

    $v = basename($f, '-view.php');

    /* 1. Se quita de donde esta, con su linea. */
    $n = 0;
    $t2 = preg_replace('/^[ \t]*layout: \{ topStart: \x27buttons\x27 \},[ \t]*\R/m', '', $t, -1, $n);
    if ($n === 0) {
        /* Puede haber quedado en la misma linea que otra cosa. */
        $t2 = str_replace("layout: { topStart: 'buttons' },\n\t\t\t", '', $t, $n);
    }
    if ($n === 0) { $sinArray[] = $v . ' (no se pudo quitar)'; continue; }

    /* 2. Y se pone antes de la configuracion DE VERDAD: la que es un array. */
    $m = 0;
    $t2 = preg_replace_callback(
        '/^([ \t]*)(["\']?buttons["\']?\s*:\s*\[)/m',
        function ($x) use (&$m) {
            if ($m++ > 0) { return $x[0]; }
            return $x[1] . "layout: { topStart: 'buttons' },\n" . $x[1] . $x[2];
        }, $t2);

    if ($m === 0) { $sinArray[] = $v . ' (no tiene buttons en array)'; continue; }

    $arreglados[] = $v;
    if ($aplicar) { file_put_contents($f, $t2); }
}

printf("  vistas corregidas: %d\n    %s\n", count($arreglados),
       wordwrap(implode(' ', $arreglados), 86, "\n    "));
if ($sinArray) { printf("\n  sin array de botones: %d\n    %s\n", count($sinArray), implode("\n    ", $sinArray)); }

if ($aplicar) {
    $malas = [];
    foreach ($arreglados as $v) {
        $f = $base . '/' . $v . '-view.php';
        $t = (string)file_get_contents($f);
        if (substr_count($t, 'layout: { topStart') !== 1) { $malas[] = "$v (layout duplicado o ausente)"; }
        /* Y que ahora este DELANTE del array, no dentro de language. */
        $pl = strpos($t, 'layout: { topStart');
        $pb = preg_match('/["\']?buttons["\']?\s*:\s*\[/', $t, $x, PREG_OFFSET_CAPTURE) ? $x[0][1] : -1;
        if ($pl === false || $pb < 0 || $pl > $pb) { $malas[] = "$v (sigue mal colocado)"; }
        $s = [];
        exec('"c:/wamp64/bin/php/php8.3.28/php.exe" -l "' . $f . '" 2>&1', $s, $cod);
        if ($cod !== 0) { $malas[] = "$v (sintaxis)"; }
    }
    echo $malas ? "\n  REVISAR: " . implode(' ', array_unique($malas)) . "\n" : "\n  APLICADO\n";
} else {
    echo "\n  simulado (sin escribir)\n";
}
