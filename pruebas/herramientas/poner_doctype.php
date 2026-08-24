<?php
/*
| Veintidos vistas se dibujaban en MODO QUIRKS.
|
| COMO SE ENCONTRO
|
| Persiguiendo un texto ilegible en el calendario: en tema oscuro las
| cabeceras de dia salian oscuras sobre fondo oscuro, 1.00 de contraste.
| Ninguna hoja de estilos lo explicaba. Preguntandole al motor con CDP que
| declaraciones aplicaban al nodo aparecio esta:
|
|     table { color: -internal-quirk-inherit }   ← hoja del navegador
|
| Esa regla solo existe en modo quirks, donde las tablas NO heredan el color
| como en modo estandar. Y el modo quirks se activa cuando falta el DOCTYPE.
|
| POR QUE IMPORTA MAS ALLA DEL CALENDARIO
|
| En modo quirks cambian cosas que Bootstrap 5 da por sentadas: el modelo de
| caja, la herencia en tablas, el manejo de line-height, el porcentaje de
| altura. La pagina puede parecer correcta y estar comportandose distinto en
| detalles que solo se notan al cambiar de tema o de tamaño de pantalla.
|
| No hay ninguna vista que quiera estar en modo quirks: 57 de las 79 ya
| llevan el DOCTYPE. Las 22 restantes es un olvido, no una decision.
|
| Uso: poner_doctype.php [aplicar]
*/
$dirs = ['ds_basketball/app/views/content', 'ds_league/views', 'ds_arena/views',
         'ds_core/admin/views', 'ds_core/inc', 'ds_core/hub'];
$raiz    = 'c:/wamp64/www/barcelona/';
$aplicar = ($argv[1] ?? '') === 'aplicar';

$tocadas = [];

foreach ($dirs as $d) {
    foreach (glob($raiz . $d . '/*.php') ?: [] as $f) {
        $t = (string)file_get_contents($f);

        if (!preg_match('/<html[\s>]/i', $t))        { continue; }
        if (preg_match('/<!DOCTYPE\s+html/i', $t))   { continue; }

        $crlf = str_contains($t, "\r\n");
        $fin  = $crlf ? "\r\n" : "\n";

        /* Se pone justo delante de la etiqueta html, conservando la
           sangria que tuviera. */
        $n = 0;
        $t2 = preg_replace('/([ \t]*)(<html[\s>])/i',
                           '<!DOCTYPE html>' . $fin . '$1$2', $t, 1, $n);

        if ($n !== 1) { printf("  %-30s no se pudo insertar\n", basename($f)); continue; }

        $tocadas[] = basename($f, '-view.php');
        if ($aplicar) { file_put_contents($f, $t2); }
    }
}

printf("  vistas sin doctype: %d\n", count($tocadas));
echo '    ' . wordwrap(implode(' ', $tocadas), 92, "\n    ") . "\n";

if ($aplicar) {
    /* Ni una puede haber quedado con dos, ni con la sintaxis rota. */
    $malas = [];
    foreach ($dirs as $d) {
        foreach (glob($raiz . $d . '/*.php') ?: [] as $f) {
            $t = (string)file_get_contents($f);
            if (substr_count(strtolower($t), '<!doctype') > 1) { $malas[] = basename($f) . ' (doble)'; }
            $salida = [];
            exec('"c:/wamp64/bin/php/php8.3.28/php.exe" -l "' . $f . '" 2>&1', $salida, $cod);
            if ($cod !== 0) { $malas[] = basename($f) . ' (sintaxis)'; }
        }
    }
    echo "\n" . ($malas ? '  REVISAR: ' . implode(' ', $malas) . "\n" : "  APLICADO, sin duplicados y con sintaxis correcta\n");
} else {
    echo "\n  simulado (sin escribir)\n";
}
