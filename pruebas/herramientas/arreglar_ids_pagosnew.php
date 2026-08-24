<?php
/*
| Identificadores únicos en pagosNew.
|
| EL PROBLEMA
|
| La pantalla tiene seis pestañas —pensión, inscripción, torneo, uniforme,
| kit y otros— y cada una lleva su propia copia del formulario de pago,
| con los MISMOS id: pago_valor, pago_saldo, pago_fecha… seis veces cada
| uno. Un id repetido no es un descuido de estilo:
|
|   · $("#pago_fecha") engancha SÓLO al primero. El texto que convierte la
|     fecha a palabras funciona en la pestaña de pensión y en ninguna otra,
|     y nadie lo nota porque no falla: simplemente no ocurre.
|   · 36 <label for="..."> apuntan al primero. Pulsar la etiqueta de un
|     campo de la cuarta pestaña enfoca el de la primera.
|   · select2 construye el id de su contenedor desde el del <select>, así
|     que con duplicados sólo convierte 3 de 8.
|
| EL name NO SE TOCA
|
| Cada pestaña es un <form> independiente, y el atributo name se resuelve
| dentro de su formulario: ahí la repetición es correcta y necesaria —el
| servidor espera pago_valor en los seis—. Cambiarlo rompería el envío.
| Sólo el id debe ser único en el documento.
|
| Uso: arreglar_ids_pagosnew.php [aplicar]
*/
$f       = 'c:/wamp64/www/barcelona/ds_basketball/app/views/content/pagosNew-view.php';
$aplicar = ($argv[1] ?? '') === 'aplicar';

$t = (string)file_get_contents($f);

/* Los límites de cada pestaña, por el id del tab-pane. */
preg_match_all('/<div class="(?:active )?tab-pane[^"]*" id="([a-z]+)"/', $t, $m,
               PREG_OFFSET_CAPTURE);

if (!$m[1]) { fwrite(STDERR, "no se encontraron pestañas\n"); exit(1); }

$panes = [];
foreach ($m[1] as $i => $par) {
    $panes[] = [
        'nombre' => $par[0],
        'ini'    => $m[0][$i][1],
        'fin'    => $m[0][$i + 1][1] ?? strlen($t),
    ];
}

printf("  %d pestañas: %s\n\n", count($panes), implode(', ', array_column($panes, 'nombre')));

/* Se recorren de atrás hacia adelante para que los desplazamientos de las
   sustituciones no invaliden los límites de las pestañas anteriores. */
$totalIds = 0; $totalFor = 0;

foreach (array_reverse($panes) as $i => $pane) {
    /* La primera pestaña conserva los ids tal cual: así el JavaScript
       existente que apunte a ellos sigue funcionando igual que hasta
       ahora, y el cambio no puede empeorar nada. */
    if ($pane['nombre'] === 'pension') { continue; }

    $bloque = substr($t, $pane['ini'], $pane['fin'] - $pane['ini']);
    $pre    = $pane['nombre'] . '_';

    /* Sólo los ids que empiezan por pago_ o son del formulario: no se
       tocan los de las propias pestañas ni los de elementos de AdminLTE. */
    /* EL ID DE LA PROPIA PESTANA NO SE TOCA.
       El bloque empieza justo en <div class="tab-pane" id="inscripcion">,
       asi que sin esta excepcion quedaria «inscripcion_inscripcion» y el
       enlace <a href="#inscripcion"> dejaria de encontrarla: las pestanas
       2 a 6 no abririan. Se detecto simulando el resultado antes de
       escribir nada. */
    $bloque = preg_replace_callback(
        '/\bid\s*=\s*"([a-zA-Z][a-zA-Z0-9_-]*)"/',
        function ($x) use ($pre, $pane, &$totalIds) {
            if ($x[1] === $pane['nombre']) { return $x[0]; }
            $totalIds++;
            return 'id="' . $pre . $x[1] . '"';
        },
        $bloque);

    $bloque = preg_replace_callback(
        '/\bfor\s*=\s*"([a-zA-Z][a-zA-Z0-9_-]*)"/',
        function ($x) use ($pre, &$totalFor) { $totalFor++; return 'for="' . $pre . $x[1] . '"'; },
        $bloque);

    $t = substr($t, 0, $pane['ini']) . $bloque . substr($t, $pane['fin']);
}

/* El script de la fecha en palabras debe alcanzar a las SEIS pestañas.
   Con el id sólo llegaba a la primera; con una clase llega a todas. */
$t = str_replace('$("#pago_fecha")', '$(".js-fecha-en-palabras")', $t, $nJs);

/* Y hay que ponerle esa clase a los seis campos de fecha. */
$t = preg_replace(
    '/<input(?=[^>]*\bid="(?:[a-z]+_)?pago_fecha")([^>]*)\bclass="([^"]*)"/',
    '<input$1class="$2 js-fecha-en-palabras"', $t, -1, $nClase);

printf("  ids renombrados      %d\n", $totalIds);
printf("  <label for> ajustados %d\n", $totalFor);
printf("  selector JS           %d  (ahora por clase, alcanza las seis pestañas)\n", $nJs);
printf("  campos con la clase   %d\n", $nClase);

/* Comprobación: no puede quedar ningún id repetido. */
preg_match_all('/\bid\s*=\s*"([a-zA-Z][a-zA-Z0-9_.:-]*)"/', $t, $ids);
$repes = array_filter(array_count_values($ids[1]), fn($n) => $n > 1);

echo "\n";
if ($repes) {
    echo "  QUEDAN REPETIDOS:\n";
    foreach ($repes as $id => $n) { echo "     {$id} x{$n}\n"; }
} else {
    echo "  sin ids repetidos\n";
}

/* Y el número de name= no puede haber cambiado: son los que espera el
   servidor. */
preg_match_all('/\bname\s*=\s*"pago_[a-zA-Z0-9_]+"/', $t, $nombresDespues);
preg_match_all('/\bname\s*=\s*"pago_[a-zA-Z0-9_]+"/',
               (string)file_get_contents($f), $nombresAntes);

printf("  atributos name: antes %d, después %d  %s\n",
       count($nombresAntes[0]), count($nombresDespues[0]),
       count($nombresAntes[0]) === count($nombresDespues[0]) ? 'intactos' : 'CAMBIARON');

if ($aplicar && !$repes && count($nombresAntes[0]) === count($nombresDespues[0])) {
    file_put_contents($f, $t);
    echo "\n  APLICADO\n";
} else {
    echo "\n  " . ($aplicar ? 'NO SE APLICA: la comprobación falló' : 'simulado') . "\n";
}
