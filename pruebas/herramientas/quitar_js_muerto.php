<?php
/*
| Librerías que se descargan y no se usan.
|
| CÓMO SE DECIDIÓ CADA UNA
|
| No por olfato: contando llamadas sobre el código VIVO de cada vista, con
| los comentarios y las etiquetas de carga descartados antes de contar.
| Descartarlos importa —en ajax.js había cinco usos de jQuery y los cinco
| estaban dentro de un comentario—.
|
|   buscarAsistencia   No tiene ni una etiqueta de tabla: es un calendario
|                      de FullCalendar, que es JavaScript sin dependencias.
|                      Cargaba la pila entera de DataTables, jszip, pdfmake
|                      y las fuentes de pdfmake. 2,7 MB por pantalla.
|   dashboard          jquery-ui completo, 248 KB, sin invocar un solo
|                      widget. El archivo que sí lo usaba, pages/dashboard.js,
|                      no lo carga ninguna vista.
|   agenda             Igual, pero conserva jQuery: tiene dos llamadas
|                      propias.
|   los tres de pagos  inputmask cargado y nunca invocado. En pagosDescuento
|   y empleadoEntrada  la máscara se anunciaba con atributos sobre un campo
|                      de tipo date, que ya es un selector nativo del
|                      navegador: la máscara ni se aplicaba ni tenía sentido.
|   cumpleaniosList    jQuery a secas, sin nada que lo pida.
|
| LO QUE NO SE TOCA
|
| Ninguna vista donde algo lo use. Las 40 de DataTables, las 12 de
| inputmask, las 13 de dropzone y las 6 del visor de imágenes se quedan
| exactamente como están.
|
| Uso: quitar_js_muerto.php [aplicar]
*/
$base    = 'c:/wamp64/www/barcelona/ds_basketball/app/views/content';
$aplicar = ($argv[1] ?? '') === 'aplicar';

/* Qué se quita de dónde. Listas explícitas: una heurística que acierte hoy
   puede llevarse mañana algo que sí hace falta. */
$plan = [
    'cumpleaniosList' => ['plugins/jquery/'],
    /* El panel no dibuja ni una gráfica: ni una etiqueta canvas, ni una
       llamada a Chart, ni rastro de ApexCharts en ninguna vista. Cargaba
       las dos librerías de gráficos por herencia de la plantilla. */
    'dashboard'       => ['plugins/jquery/', 'plugins/jquery-ui/',
                          'plugins/chart.js/', 'css/apexcharts.css'],
    'agenda'          => ['plugins/jquery-ui/'],
    'empleadoEntrada' => ['plugins/jquery/', 'plugins/inputmask/'],
    'pagosDescuento'  => ['plugins/jquery/', 'plugins/inputmask/'],
    'pagospendienteRecibo' => ['plugins/jquery/', 'plugins/inputmask/'],
    'buscarAsistencia'=> ['plugins/jquery/', 'plugins/jquery-ui/',
                          'plugins/datatables/', 'plugins/datatables-bs5/',
                          'plugins/datatables-responsive/', 'plugins/datatables-buttons/',
                          'plugins/jszip/', 'plugins/pdfmake/'],
];

$totalLineas = 0;
$resumen = [];

foreach ($plan as $vista => $rutas) {
    $f = $base . '/' . $vista . '-view.php';
    if (!is_file($f)) { echo "  $vista: no existe\n"; continue; }

    $orig = (string)file_get_contents($f);

    /* Se recorre línea a línea: una expresión regular sobre todo el archivo
       ya se llevó por delante etiquetas que no debía en un intento
       anterior. */
    $trozos = preg_split('/(\R)/', $orig, -1, PREG_SPLIT_DELIM_CAPTURE);
    $quedan = [];
    $quitadas = [];

    for ($i = 0; $i < count($trozos); $i += 2) {
        $linea = $trozos[$i];
        $fin   = $trozos[$i + 1] ?? '';

        $esCarga = str_contains($linea, '<script') || str_contains($linea, '<link');
        $sobra   = false;

        if ($esCarga) {
            foreach ($rutas as $r) {
                if (str_contains($linea, $r)) { $sobra = true; break; }
            }
        }

        if ($sobra) {
            $quitadas[] = trim(basename(explode('"', explode('src="', $linea)[1]
                          ?? explode('href="', $linea)[1] ?? '')[0]));
            continue;
        }
        $quedan[] = $linea . $fin;
    }

    $t = implode('', $quedan);

    /* pagosDescuento anunciaba una máscara sobre un campo de tipo date.
       Sin la librería, esos atributos prometen algo que no ocurre. */
    if ($vista === 'pagosDescuento') {
        $t = preg_replace('/\s*data-inputmask-[a-z]+="[^"]*"/', '', $t);
        $t = preg_replace('/\s*data-mask(?=[\s>])/', '', $t);
    }

    /*----------  Comprobaciones antes de escribir  ----------*/
    $problemas = [];

    /* Que no quede ninguna referencia a lo retirado. */
    foreach ($rutas as $r) {
        if (str_contains($t, $r)) { $problemas[] = "sigue apareciendo $r"; }
    }
    /* Que FullCalendar siga en pie: su archivo se llama main.js igual que
       el del proyecto, y una retirada descuidada se lo lleva. */
    if ($vista === 'buscarAsistencia' && !str_contains($t, 'plugins/fullcalendar/main.js')) {
        $problemas[] = 'se perdió FullCalendar';
    }
    /* Que las etiquetas sigan cuadrando. */
    if (substr_count($t, '<script') !== substr_count($t, '</script>')) {
        $problemas[] = 'quedan etiquetas script sin cerrar';
    }

    if ($problemas) {
        printf("  %-22s NO SE TOCA: %s\n", $vista, implode('; ', $problemas));
        continue;
    }

    $n = count($quitadas);
    $totalLineas += $n;
    printf("  %-22s -%d  %s\n", $vista, $n, implode(' ', array_slice($quitadas, 0, 4))
           . ($n > 4 ? ' …' : ''));

    if ($aplicar) { file_put_contents($f, $t); }
    $resumen[$vista] = $n;
}

printf("\n  %d etiquetas retiradas en %d vistas\n", $totalLineas, count($resumen));
echo '  ' . ($aplicar ? "APLICADO\n" : "simulado (sin escribir)\n");
