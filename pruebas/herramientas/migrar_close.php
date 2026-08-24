<?php
/*
| Botones de cierre: class="close" -> class="btn-close".
|
| En Bootstrap 5 btn-close DIBUJA EL ASPA con una imagen de fondo. Dejar
| dentro el &times; heredado de la versión 4 pinta dos aspas, una encima de
| otra. Por eso no basta con renombrar la clase: hay que vaciar el botón.
|
| Y se le pone aria-label: sin texto dentro, un lector de pantalla no
| tiene nada que anunciar. Antes lo decía el &times;, mal, pero algo decía.
|
| Hay dos variantes en el código —con y sin <span>, con y sin aria-label,
| para modal y para alert— y las cuatro se contemplan.
|
| Uso: migrar_close.php <carpeta> [aplicar]
*/
$carpeta = $argv[1] ?? '';
$aplicar = ($argv[2] ?? '') === 'aplicar';

if ($carpeta === '' || !is_dir($carpeta)) {
    fwrite(STDERR, "uso: migrar_close.php <carpeta> [aplicar]\n");
    exit(1);
}

$total = 0; $archivos = 0;

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($carpeta)) as $f) {
    if ($f->getExtension() !== 'php') { continue; }
    $ruta = str_replace('\\', '/', $f->getPathname());
    if (str_contains($ruta, '/dist/') || str_contains($ruta, '/lib/')) { continue; }

    $t = (string)file_get_contents($ruta);
    if (!str_contains($t, 'class="close"')) { continue; }

    /* Un solo patrón: el botón entero, con lo que lleve dentro. El
       .*? no se pasa de la raya porque está anclado al </button>. */
    $nuevo = preg_replace_callback(
        '#<button([^>]*?)class="close"([^>]*?)>.*?</button>#s',
        function ($m) {
            $attrs = $m[1] . $m[2];

            /* Se conserva a qué cierra: modal o alert. */
            $dismiss = preg_match('/data-bs-dismiss="([a-z]+)"/', $attrs, $d) ? $d[1] : 'modal';

            return '<button type="button" class="btn-close" data-bs-dismiss="' . $dismiss
                 . '" aria-label="Cerrar"></button>';
        },
        $t, -1, $n);

    if ($n > 0) {
        $archivos++;
        $total += $n;
        printf("  %-40s %d\n", basename($ruta), $n);
        if ($aplicar) { file_put_contents($ruta, $nuevo); }
    }
}

echo "\n  " . ($aplicar ? 'APLICADOS' : 'simulados') . ": {$total} en {$archivos} archivos\n";
