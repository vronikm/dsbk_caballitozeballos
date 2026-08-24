<?php
/*
| Diez imagenes con el atributo src VACIO.
|
| POR QUE NO ES cosmetico
|
| <img src=""> no es «una imagen sin poner»: segun la especificacion, el
| navegador resuelve la cadena vacia contra la URL del documento y vuelve a
| PEDIR LA PAGINA ENTERA como si fuera una imagen. En una ficha de alumno eso
| son 50 KB de HTML descargados dos veces, mas una consulta a la base, para
| acabar dibujando un icono de imagen rota.
|
| Ademas ahora que el selector de foto funciona de verdad, la caja vacia se
| ve: antes tampoco habia previsualizacion y no se notaba.
|
| QUE MARCADOR LLEVA CADA UNA
|
| Segun lo que se va a subir ahi, con las imagenes que ya estan en el
| proyecto: la silueta para la foto del alumno, el comprobante en blanco
| para las imagenes de pago y la generica para las cedulas.
|
| Uso: marcador_foto.php [aplicar]
*/
$base    = 'c:/wamp64/www/barcelona/ds_basketball/app/views/content';
$aplicar = ($argv[1] ?? '') === 'aplicar';

/* Se decide por el rotulo que precede a cada widget. */
$porContexto = [
    '/foto/i'    => 'app/views/dist/img/alumno.jpg',
    '/pago/i'    => 'app/views/dist/img/sinpago.jpg',
    '/c[eé]dula/i' => 'app/views/dist/img/default.png',
];
$porDefecto = 'app/views/dist/img/default.png';

$hechos = [];

foreach (glob($base . '/*.php') as $f) {
    $t = (string)file_get_contents($f);
    if (!str_contains($t, 'img src=""')) { continue; }

    $v = basename($f, '-view.php');
    $n = 0;

    $t2 = preg_replace_callback(
        '#<img src=""([^>]*)>#',
        function ($m) use ($t, $porContexto, $porDefecto, &$n) {
            /* Se mira el rotulo mas cercano por encima para saber que se
               sube ahi. */
            $pos = strpos($t, $m[0]);
            $antes = substr($t, max(0, $pos - 600), min(600, $pos));
            $ruta = $porDefecto;
            if (preg_match_all('#<label[^>]*>([^<]+)</label>#', $antes, $l)) {
                $rotulo = end($l[1]);
                foreach ($porContexto as $patron => $r) {
                    if (preg_match($patron, $rotulo)) { $ruta = $r; break; }
                }
            }
            $n++;
            return '<img src="<?php echo APP_URL; ?>' . $ruta . '" alt=""' . $m[1] . '>';
        }, $t);

    if ($n === 0) { continue; }
    $hechos[$v] = $n;
    if ($aplicar) { file_put_contents($f, $t2); }
}

foreach ($hechos as $v => $n) { printf("  %-24s %d imágenes\n", $v, $n); }
printf("\n  %d en total\n", array_sum($hechos));

if ($aplicar) {
    $quedan = 0; $malas = [];
    foreach (glob($base . '/*.php') as $f) {
        $quedan += substr_count((string)file_get_contents($f), 'img src=""');
        $s = [];
        exec('"c:/wamp64/bin/php/php8.3.28/php.exe" -l "' . $f . '" 2>&1', $s, $cod);
        if ($cod !== 0) { $malas[] = basename($f); }
    }
    if ($malas)      { echo "  SINTAXIS ROTA: " . implode(' ', $malas) . "\n"; }
    elseif ($quedan) { echo "  QUEDAN $quedan con src vacío\n"; }
    else             { echo "  APLICADO, ninguna queda con src vacío\n"; }
} else {
    echo "  simulado (sin escribir)\n";
}
