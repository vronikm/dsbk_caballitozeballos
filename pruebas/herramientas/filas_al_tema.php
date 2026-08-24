<?php
/*
| Las filas resaltadas de los informes, al tema.
|
| EL FALLO
|
| Tres informes pintan filas de color segun el estado: verde al dia, amarillo
| sin registro, rojo en mora. El fondo estaba clavado en un tono palido:
|
|     tr.fila-rojo td { background-color: #fff5f5 !important; }
|
| El fondo es fijo, pero el color del TEXTO lo pone Bootstrap y cambia con el
| tema. En oscuro el texto se vuelve blanco y queda sobre ese rosa casi
| blanco: 1.03 de contraste. Ilegible.
|
| LA SOLUCION
|
| Bootstrap 5.3 trae parejas hechas para esto, y cambian solas:
|
|     claro   --bs-danger-bg-subtle  rosa palido  + texto granate oscuro
|     oscuro  --bs-danger-bg-subtle  granate muy oscuro + texto rosa claro
|
| Se fija el fondo Y el texto de la pareja. Fijar solo el fondo dejaria el
| mismo fallo al reves. El significado del color no cambia: verde sigue
| siendo al dia y rojo sigue siendo mora, en los dos temas.
|
| Uso: filas_al_tema.php [aplicar]
*/
$base    = 'c:/wamp64/www/barcelona/ds_basketball/app/views/content';
$aplicar = ($argv[1] ?? '') === 'aplicar';

/* Que significa cada clase, y con que pareja de Bootstrap se corresponde. */
$mapa = [
    'fila-verde'    => 'success',
    'fila-aldia'    => 'success',
    'fila-amarillo' => 'warning',
    'fila-sinreg'   => 'warning',
    'fila-rojo'     => 'danger',
    'fila-mora'     => 'danger',
];

$vistas = ['estadisticas', 'ingresosLugarEntrenamiento', 'reporteIngresosMorames'];
$total = 0;

foreach ($vistas as $v) {
    $f = $base . '/' . $v . '-view.php';
    if (!is_file($f)) { printf("  %-30s no existe\n", $v); continue; }

    $t = (string)file_get_contents($f);
    $n = 0;

    $t2 = preg_replace_callback(
        '/tr\.(fila-[a-z]+)(\s+)td(\s*)\{\s*background-color:\s*#[0-9a-f]{3,6}\s*!important;\s*\}/i',
        function ($m) use ($mapa, &$n, $v) {
            $clase = strtolower($m[1]);
            if (!isset($mapa[$clase])) { return $m[0]; }   /* clase no prevista: se deja */
            $c = $mapa[$clase];
            $n++;
            return 'tr.' . $m[1] . $m[2] . 'td' . $m[3] . '{'
                 . ' background-color: var(--bs-' . $c . '-bg-subtle) !important;'
                 . ' color: var(--bs-' . $c . '-text-emphasis) !important; }';
        }, $t);

    if ($n === 0) { printf("  %-30s sin cambios\n", $v); continue; }

    printf("  %-30s %d filas\n", $v, $n);
    $total += $n;
    if ($aplicar) { file_put_contents($f, $t2); }
}

printf("\n  %d reglas\n", $total);

if ($aplicar) {
    $quedan = 0;
    foreach ($vistas as $v) {
        $t = (string)file_get_contents($base . '/' . $v . '-view.php');
        $quedan += preg_match_all('/tr\.fila-[a-z]+\s+td\s*\{\s*background-color:\s*#/i', $t);
        $salida = [];
        exec('"c:/wamp64/bin/php/php8.3.28/php.exe" -l "' . $base . '/' . $v . '-view.php" 2>&1', $salida, $cod);
        if ($cod !== 0) { echo "  SINTAXIS ROTA: $v\n"; }
    }
    echo $quedan ? "  QUEDAN $quedan con color fijo\n" : "  APLICADO, ninguna con color fijo\n";
} else {
    echo "  simulado (sin escribir)\n";
}
