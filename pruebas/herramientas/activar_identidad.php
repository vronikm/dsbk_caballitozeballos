<?php
/*
| FASE 2 — La identidad visual llega a los listados.
|
| POR QUE SE PUEDE IR POR FASES
|
| Las 39 reglas de core.css estan escritas como descendientes —.ds-core .x—,
| asi que basta una clase en el envoltorio de UNA vista para activarlas en
| esa vista y en ninguna otra. Eso permite avanzar por bloques y comparar,
| en lugar de encender el sistema entero de golpe.
|
| Las otras 5 reglas son BEM (.ds-core__navbar, .ds-core__sidebar) y viven
| en includes que comparten 69 vistas: activarlas afecta a todas a la vez y
| va en una fase aparte.
|
| QUE SE ACTIVO ANTES DE ESTO
|
| Los colores clavados del archivo ya se pasaron a las variables de
| Bootstrap 5.3, asi que lo que se enciende aqui ya respeta el tema oscuro.
| Al reves —extender primero y corregir despues— habria obligado a revisar
| dos veces cada vista activada.
|
| Uso: activar_identidad.php <patron> [aplicar]
|      activar_identidad.php "*List" aplicar
|      activar_identidad.php estado          ← que hay activado hoy
*/
$base   = 'c:/wamp64/www/barcelona/ds_basketball/app/views/content';
$patron = $argv[1] ?? 'estado';

if ($patron === 'estado') {
    $con = $sin = [];
    foreach (glob($base . '/*.php') ?: [] as $f) {
        $t = (string)file_get_contents($f);
        if (!str_contains($t, 'app-wrapper')) { continue; }
        $v = basename($f, '-view.php');
        if (str_contains($t, 'app-wrapper ds-core')) { $con[] = $v; } else { $sin[] = $v; }
    }
    printf("  con identidad activa: %d\n    %s\n\n", count($con), implode(' ', $con));
    printf("  sin activar:          %d\n    %s\n", count($sin),
           wordwrap(implode(' ', $sin), 96, "\n    "));
    exit;
}

$aplicar = ($argv[2] ?? '') === 'aplicar';
$tocados = []; $yaEstaban = 0;

foreach (glob($base . '/' . $patron . '-view.php') ?: [] as $f) {
    $t = (string)file_get_contents($f);
    $v = basename($f, '-view.php');

    if (str_contains($t, 'app-wrapper ds-core')) { $yaEstaban++; continue; }

    $n = 0;
    $t2 = str_replace('<div class="app-wrapper">',
                      '<div class="app-wrapper ds-core">', $t, $n);

    if ($n === 0) { printf("  %-24s SIN el envoltorio esperado\n", $v); continue; }
    if ($n > 1)   { printf("  %-24s %d envoltorios, se salta\n", $v, $n); continue; }

    $tocados[] = $v;
    if ($aplicar) { file_put_contents($f, $t2); }
}

printf("  vistas activadas   %d\n", count($tocados));
printf("  ya lo estaban      %d\n", $yaEstaban);
if ($tocados) { echo '    ' . wordwrap(implode(' ', $tocados), 92, "\n    ") . "\n"; }

if ($aplicar) {
    /* Comprobacion: ni una vista puede haber quedado con dos envoltorios ni
       con la clase escrita dos veces. */
    $malas = [];
    foreach ($tocados as $v) {
        $t = (string)file_get_contents($base . '/' . $v . '-view.php');
        if (substr_count($t, 'ds-core') !== 1) { $malas[] = $v; }
    }
    echo $malas ? '  REVISAR: ' . implode(' ', $malas) . "\n" : "  APLICADO\n";
} else {
    echo "  simulado (sin escribir)\n";
}
