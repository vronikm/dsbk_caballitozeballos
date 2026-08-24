<?php
/*
| Alturas fijas que se montaban encima de lo siguiente.
|
| EL FALLO
|
| Varias vistas fijan la altura de un contenedor en pixeles:
|
|     <div class="row" style="font-size: 13px; height: 187px;">
|
| El contenido no siempre cabe ahi. Medido en la pantalla de torneos: ese
| bloque necesita 230 px en escritorio y 900 EN UN MOVIL, porque las columnas
| se apilan. Lo que no cabe no desaparece: se dibuja ENCIMA de lo que viene
| detras. Por eso la foto se montaba sobre la tabla y los botones sobre el
| buscador.
|
| No lo detectaba la comprobacion de responsive porque la pagina no se
| desborda a lo ancho: el problema es vertical.
|
| EL ARREGLO
|
| height pasa a min-height. Se conserva la intencion —que el bloque tenga
| una altura minima y las filas queden parejas— y se deja crecer al
| contenido cuando hace falta. Es una palabra por sitio y no cambia nada en
| las pantallas donde el contenido ya cabia.
|
| SOLO LAS SIETE FORMAS QUE SE MIDIERON
|
| No se convierten todas las alturas fijas del sistema: hay 89 y la mayoria
| no molestan. Se tocan exactamente las que se vio desbordarse, a alguno de
| los cuatro anchos probados.
|
| Uso: alturas_minimas.php [aplicar]
*/
$dirs = ['ds_basketball/app/views/content', 'ds_basketball/app/views/inc',
         'ds_league/views', 'ds_arena/views', 'ds_core/admin/views'];
$raiz    = 'c:/wamp64/www/barcelona/';
$aplicar = ($argv[1] ?? '') === 'aplicar';

/* Medidas en el navegador, a 1500, 992, 768 y 390 px. */
$formas = [
    'font-size: 13px; height: 15px;'                     => 'font-size: 13px; min-height: 15px;',
    'font-size: 13px; height: 20px;'                     => 'font-size: 13px; min-height: 20px;',
    'height: 40px;'                                      => 'min-height: 40px;',
    'font-size: 13px; height: 40px;'                     => 'font-size: 13px; min-height: 40px;',
    'font-size: 13px; height: 187px;'                    => 'font-size: 13px; min-height: 187px;',
    'width: 100%; height: 500px; border: 1px solid #ccc;' => 'width: 100%; min-height: 500px; border: 1px solid #ccc;',
    'width: 110px; height: 130px;'                       => 'width: 110px; min-height: 130px;',
    'width: 130px; height: 158px;'                       => 'width: 130px; min-height: 158px;',
];

$total = 0; $porVista = [];

foreach ($dirs as $d) {
    foreach (glob($raiz . $d . '/*.php') ?: [] as $f) {
        $t = (string)file_get_contents($f);
        $orig = $t;
        $n = 0;

        foreach ($formas as $de => $a) {
            /* Solo dentro de un atributo style, para no tocar una hoja de
               estilos que dijera lo mismo por casualidad. Y con las DOS
               comillas: el proyecto usa unas veces dobles y otras simples,
               y mirar solo las dobles dejo fuera todas las cabeceras de
               tarjeta en la primera pasada. */
            foreach (['"', "'"] as $q) {
                $t = str_replace('style=' . $q . $de . $q,
                                 'style=' . $q . $a . $q, $t, $c);
                $n += $c;
            }
        }

        if ($n === 0) { continue; }
        $porVista[basename($f, '-view.php')] = $n;
        $total += $n;
        if ($aplicar) { file_put_contents($f, $t); }
    }
}

printf("  %d alturas convertidas en %d vistas\n\n", $total, count($porVista));
arsort($porVista);
foreach ($porVista as $v => $n) { printf("  %-34s %d\n", $v, $n); }

if ($aplicar) {
    /* Ninguna de las siete formas puede quedar viva. */
    $quedan = [];
    foreach ($dirs as $d) {
        foreach (glob($raiz . $d . '/*.php') ?: [] as $f) {
            $t = (string)file_get_contents($f);
            foreach (array_keys($formas) as $de) {
                foreach (['"', "'"] as $q) {
                    if (str_contains($t, 'style=' . $q . $de . $q)) { $quedan[] = basename($f); }
                }
            }
            $salida = [];
            exec('"c:/wamp64/bin/php/php8.3.28/php.exe" -l "' . $f . '" 2>&1', $salida, $cod);
            if ($cod !== 0) { $quedan[] = basename($f) . ' (sintaxis)'; }
        }
    }
    echo $quedan ? "\n  REVISAR: " . implode(' ', array_unique($quedan)) . "\n"
                 : "\n  APLICADO, ninguna de las siete formas queda viva\n";
} else {
    echo "\n  simulado (sin escribir)\n";
}
