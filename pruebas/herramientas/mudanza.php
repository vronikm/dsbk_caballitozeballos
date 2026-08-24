<?php
/*
| Mudanza de las pruebas al proyecto.
|
| QUÉ VA A CADA SITIO, Y POR QUÉ
|
|   pruebas/                Las suites que ejecuta el lanzador y sus apoyos.
|                           Es lo que hay que poder repetir mañana.
|
|   pruebas/herramientas/   Scripts de medición y de migración que se pueden
|                           volver a pasar: miden jQuery, pesan las páginas,
|                           retiran librerías. Documentan cómo se decidió
|                           cada cambio, que es la mitad de su valor.
|
|   pruebas/archivo/        Diagnósticos de un rato concreto. No estorban y
|                           explican por qué algo está como está.
|
| QUÉ NO ENTRA AL PROYECTO
|
|   Capturas, volcados JSON y HTML de páginas: llevan cédulas y nombres de
|   alumnos —se comprobó, no se supone—. Meter eso en el directorio web,
|   aunque esté bloqueado, es multiplicar sin motivo los sitios donde vive
|   un dato personal. Minimización.
|
|   Copias de la base y tarballs del código: fuera del directorio web
|   entero, en C:\wamp64\respaldos_barcelona\. Una copia de la base es la
|   base: no puede estar donde Apache pueda servirla si un día alguien
|   toca un .htaccess.
|
| Uso: mudanza.php [aplicar]
*/
$origen  = __DIR__;
$destino = 'c:/wamp64/www/barcelona/pruebas';
$fuera   = 'c:/wamp64/respaldos_barcelona';
$aplicar = ($argv[1] ?? '') === 'aplicar';

/*----------  1. Qué usa el lanzador  ----------*/
/* Se leen del propio regresion.sh en vez de escribirlas a mano: si mañana
   se añade una suite, esta lista se entera sola. */
$sh = (string)file_get_contents($origen . '/regresion.sh');
preg_match_all('/\b(qa[\w]*\.(?:mjs|php))/', $sh, $m);
$activas = array_unique($m[1]);

/* El lanzador y los apoyos que las suites necesitan y él no nombra. */
$activas = array_merge($activas,
    ['regresion.sh', 'sesion_qa.php', 'limpiar_qa_finanzas.php']);

/*----------  2. Herramientas que se pueden volver a pasar  ----------*/
$herramientas = [
    'medir_jquery.php', 'medir_peso.php', 'medir_select2.php', 'medir_uso_real2.php',
    'quitar_js_muerto.php', 'quitar_select2.php', 'unificar_iconos.php',
    'quitar_plugins_muertos.php', 'quitar_envoltorios.php',
    'migrar_bs5.php', 'migrar_badges.php', 'migrar_close.php',
    'arreglar_ids_pagosnew.php', 'limpiar_demo.php', 'mudanza.php',
];

/*----------  3. Reparto  ----------*/
/* Sólo código. Las extensiones que guardan datos capturados se quedan. */
$codigo = ['php', 'mjs', 'sh', 'js'];
$copias = ['sql', 'tgz'];

$cuenta = ['pruebas' => 0, 'herramientas' => 0, 'archivo' => 0,
           'respaldos' => 0, 'se queda' => 0];
$detalle = [];

foreach (scandir($origen) as $f) {
    if ($f === '.' || $f === '..') { continue; }
    $ruta = $origen . '/' . $f;
    if (is_dir($ruta)) {
        /* respaldo_js/ lo lee medir_peso.php: viaja con él. */
        $detalle[$f] = ($f === 'respaldo_js') ? 'herramientas' : 'se queda';
        $cuenta[$detalle[$f]]++;
        continue;
    }

    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));

    if (in_array($ext, $copias, true))          { $d = 'respaldos'; }
    elseif (!in_array($ext, $codigo, true))     { $d = 'se queda'; }
    elseif (in_array($f, $activas, true))       { $d = 'pruebas'; }
    elseif (in_array($f, $herramientas, true))  { $d = 'herramientas'; }
    else                                        { $d = 'archivo'; }

    $detalle[$f] = $d;
    $cuenta[$d]++;
}

foreach ($cuenta as $k => $v) { printf("  %-14s %3d\n", $k, $v); }

echo "\n  a pruebas/ (las que ejecuta el lanzador):\n";
foreach ($detalle as $f => $d) {
    if ($d === 'pruebas') { echo "    $f\n"; }
}

if (!$aplicar) { echo "\n  simulado (sin mover)\n"; exit; }

/*----------  4. Mover  ----------*/
@mkdir($fuera, 0777, true);
$rutas = ['pruebas' => $destino,
          'herramientas' => $destino . '/herramientas',
          'archivo' => $destino . '/archivo',
          'respaldos' => $fuera];

$movidos = 0;
foreach ($detalle as $f => $d) {
    if ($d === 'se queda') { continue; }
    $de = $origen . '/' . $f;
    $a  = $rutas[$d] . '/' . $f;

    if (is_dir($de)) {
        /* Sólo hay una carpeta que viaja, y es plana. */
        @mkdir($a, 0777, true);
        foreach (scandir($de) as $g) {
            if ($g === '.' || $g === '..') { continue; }
            copy($de . '/' . $g, $a . '/' . $g);
        }
        $movidos++;
        continue;
    }
    if (copy($de, $a)) { $movidos++; }
}

printf("\n  %d elementos copiados\n", $movidos);
echo "  APLICADO (los originales siguen en el temporal hasta comprobar)\n";
