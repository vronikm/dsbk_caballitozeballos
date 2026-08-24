<?php
/*
| El dashboard cargaba TRES librerías de iconos para dibujar ocho monigotes.
|
| LO QUE HABÍA
|
|   Font Awesome      local, y la usa el resto del sistema entero
|   ionicons 2.0.1    desde un CDN, sin comprobación de integridad, y sin
|                     una versión nueva desde 2015
|   bootstrap-icons   desde otro CDN, Y ADEMÁS una copia local de 84 KB a
|                     la que le faltan los archivos de fuente: cada carga
|                     del panel producía dos 404
|
| LO QUE APORTA UNIFICAR
|
|   - Desaparecen los dos 404 de fuentes.
|   - El navegador deja de pedir nada a dos terceros. En un sistema con
|     datos de menores, cada recurso externo es una dirección IP del
|     representante entregada a alguien que no pinta nada aquí.
|   - Se va una dependencia abandonada hace diez años.
|   - Y 84 KB de hoja de estilos que no dibujaba nada.
|
| DOS COMPROBACIONES QUE FALLARON EN LA PRIMERA VERSIÓN
|
|   Buscar el rastro «ion-» daba cinco resultados que no eran iconos:
|   text-decoration-none lleva «ion-» dentro. Y el enlace del CDN ocupa
|   CUATRO líneas, porque trae integrity y crossorigin, así que quitarlo
|   con una expresión regular de una línea no valía. Ahora se recorren
|   líneas, que se puede seguir con el dedo.
|
| Uso: unificar_iconos.php [aplicar]
*/
$archivo = 'c:/wamp64/www/barcelona/ds_basketball/app/views/content/dashboard-view.php';
$aplicar = ($argv[1] ?? '') === 'aplicar';

$orig = (string)file_get_contents($archivo);

/*----------  1. Los iconos  ----------*/
/* Las equivalencias son exactas: mismo concepto, mismo dibujo. */
$equivalencias = [
    'ion ion-person'          => 'fas fa-user',
    'ion ion-cash'            => 'fas fa-money-bill-wave',
    'ion ion-android-warning' => 'fas fa-exclamation-triangle',
    'bi bi-people-fill'       => 'fas fa-users',
];

$t = $orig;
foreach ($equivalencias as $de => $a) {
    /* Sólo dentro de un atributo class, y sólo la clase entera. */
    $n = 0;
    $t = str_replace('class="' . $de . '"', 'class="' . $a . '"', $t, $n1);
    $t = str_replace('class="' . $de . ' ', 'class="' . $a . ' ', $t, $n2);
    printf("  %-26s → %-30s %d\n", $de, $a, $n1 + $n2);
}

/*----------  2. Las hojas que ya no hacen falta  ----------*/
$lineas  = preg_split('/(\R)/', $t, -1, PREG_SPLIT_DELIM_CAPTURE);
$fuera   = [];
$saltando = false;
$quitadas = 0;

for ($i = 0; $i < count($lineas); $i += 2) {
    $linea = $lineas[$i];
    $fin   = $lineas[$i + 1] ?? '';

    /* Si venimos arrastrando un enlace de varias líneas, seguimos
       descartando hasta cerrarlo. */
    if ($saltando) {
        if (str_contains($linea, '>')) { $saltando = false; }
        continue;
    }

    $esEnlace = str_contains($linea, '<link');
    $sobra    = $esEnlace
             && (str_contains($linea, 'bootstrap-icons') || str_contains($linea, 'ionicons'));

    if ($sobra) {
        $quitadas++;
        /* ¿Se cierra en esta misma línea o sigue abajo? */
        if (!str_contains($linea, '>')) { $saltando = true; }
        continue;
    }

    /* El comentario que anunciaba la hoja se va con ella. */
    if (trim($linea) === '<!-- Ionicons -->') { continue; }

    $fuera[] = $linea . $fin;
}
$t = implode('', $fuera);

printf("  hojas de estilo retiradas   %d\n", $quitadas);

/*----------  3. Comprobar antes de escribir  ----------*/
$rastros = [];

/* Iconos: se busca la CLASE, no el trozo de texto. */
if (preg_match_all('/class="[^"]*\b(?:ion|bi)\b[^"]*"/', $t, $m)) {
    $rastros[] = count($m[0]) . ' iconos sin convertir: ' . implode(', ', array_slice($m[0], 0, 3));
}
foreach (['bootstrap-icons', 'ionicons'] as $lib) {
    $n = substr_count($t, $lib);
    if ($n > 0) { $rastros[] = "$lib sigue apareciendo ($n)"; }
}
if ($quitadas !== 3) { $rastros[] = "se esperaban 3 hojas, se quitaron $quitadas"; }

/* Y que no se haya perdido nada más por el camino: sólo deben faltar las
   líneas de las hojas, el comentario y las tres de integridad. */
$difLineas = substr_count($orig, "\n") - substr_count($t, "\n");
if ($difLineas < 0 || $difLineas > 8) {
    $rastros[] = "el archivo perdió $difLineas líneas, demasiadas";
}

if ($rastros) {
    echo "\n  NO SE ESCRIBE NADA:\n";
    foreach ($rastros as $r) { echo "    - $r\n"; }
    exit(1);
}

printf("\n  el archivo pasa de %d a %d líneas\n",
       substr_count($orig, "\n") + 1, substr_count($t, "\n") + 1);

if ($aplicar) {
    file_put_contents($archivo, $t);
    echo "  APLICADO\n";
} else {
    echo "  simulado (sin escribir)\n";
}
