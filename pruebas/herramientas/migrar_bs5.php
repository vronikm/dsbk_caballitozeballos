<?php
/*
| Bootstrap 4 -> 5 en las vistas de un módulo.
|
| SÓLO RENOMBRA UTILIDADES Y ATRIBUTOS data-*.
|
| No toca la estructura: quitar los <div class="input-group-append"> o
| convertir .form-row a .row g-2 son cambios de árbol DOM, no de nombre, y
| hacerlos a ciegas con expresiones regulares es como se rompe una
| pantalla sin enterarse. Lo que necesite estructura se marca al final
| para revisarlo a mano.
|
| Las sustituciones van con \b para que .mr-2 no toque a .attr-2 ni
| .text-right a .text-right-custom.
|
| Uso: migrar_bs5.php <carpeta> [aplicar]
*/
$carpeta = $argv[1] ?? '';
$aplicar = ($argv[2] ?? '') === 'aplicar';

if ($carpeta === '' || !is_dir($carpeta)) {
    fwrite(STDERR, "uso: migrar_bs5.php <carpeta> [aplicar]\n");
    exit(1);
}

/* Renombres seguros: mismo elemento, otro nombre de clase. */
$renombres = [
    /* Márgenes y relleno: la dirección pasa de left/right a start/end,
       porque Bootstrap 5 soporta idiomas de derecha a izquierda. */
    '/\bml-(auto|[0-5])\b/'  => 'ms-$1',
    '/\bmr-(auto|[0-5])\b/'  => 'me-$1',
    '/\bpl-([0-5])\b/'       => 'ps-$1',
    '/\bpr-([0-5])\b/'       => 'pe-$1',
    '/\bml-(sm|md|lg|xl)-(auto|[0-5])\b/' => 'ms-$1-$2',
    '/\bmr-(sm|md|lg|xl)-(auto|[0-5])\b/' => 'me-$1-$2',

    /* Alineación y flotado. */
    '/\bfloat-left\b/'       => 'float-start',
    '/\bfloat-right\b/'      => 'float-end',
    '/\bfloat-(sm|md|lg|xl)-left\b/'  => 'float-$1-start',
    '/\bfloat-(sm|md|lg|xl)-right\b/' => 'float-$1-end',
    '/\btext-left\b/'        => 'text-start',
    '/\btext-right\b/'       => 'text-end',
    '/\btext-(sm|md|lg|xl)-left\b/'  => 'text-$1-start',
    '/\btext-(sm|md|lg|xl)-right\b/' => 'text-$1-end',

    /* Distintivos: en Bootstrap 5 el color va con text-bg-*, que además
       elige el color de texto que contrasta. */
    '/\bbadge-(primary|secondary|success|danger|warning|info|light|dark)\b/'
                             => 'text-bg-$1',
    '/\bbadge-pill\b/'       => 'rounded-pill',

    /* Formularios. */
    '/\bform-control-file\b/' => 'form-control',
    '/\bcustom-select\b/'     => 'form-select',
    '/\bcustom-file-input\b/' => 'form-control',
    '/\bbtn-block\b/'         => 'w-100',
    '/\bsr-only\b/'           => 'visually-hidden',

    /* Atributos de los componentes: todos llevan prefijo bs. */
    '/\bdata-toggle=/'       => 'data-bs-toggle=',
    '/\bdata-target=/'       => 'data-bs-target=',
    '/\bdata-dismiss=/'      => 'data-bs-dismiss=',
    '/\bdata-parent=/'       => 'data-bs-parent=',
    '/\bdata-ride=/'         => 'data-bs-ride=',
    '/\bdata-slide=/'        => 'data-bs-slide=',
    '/\bdata-placement=/'    => 'data-bs-placement=',

    /* AdminLTE 3 -> 4. */
    '/data-widget="pushmenu"/' => 'data-lte-toggle="sidebar"',
    '/data-widget="treeview"/' => 'data-lte-toggle="treeview"',

    /* form-group desapareció; su único efecto era el margen inferior. */
    '/\bform-group\b/'       => 'mb-3',
    /* form-row era una fila con menos separación. */
    '/\bform-row\b/'         => 'row g-2',
];

/* Lo que NO se toca automáticamente, sólo se cuenta para revisar. */
$revisar = [
    'input-group-append'  => 'el <div> envolvente sobra en BS5; el botón va suelto',
    'input-group-prepend' => 'el <div> envolvente sobra en BS5',
    'custom-control'      => 'estructura distinta: form-check',
    'custom-checkbox'     => 'estructura distinta: form-check',
    'custom-switch'       => 'estructura distinta: form-check form-switch',
    'class="close"'       => 'ahora es btn-close, y sin el &times; dentro',
    'user-panel'          => 'no existe en AdminLTE 4',
];

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($carpeta));
$tocados = 0; $totalCambios = 0; $pendientes = [];

foreach ($it as $f) {
    if ($f->getExtension() !== 'php') { continue; }
    $ruta = str_replace('\\', '/', $f->getPathname());
    if (str_contains($ruta, '/dist/') || str_contains($ruta, '/lib/')) { continue; }

    /* Los layouts se reescriben a mano para AdminLTE 4 y además llevan en
       su cabecera la TABLA DE RENOMBRES como documentación. Pasarles el
       convertidor la reescribiría dejando «.float-end -> .float-end», que
       es exactamente perder la explicación de por qué existe el cambio. */
    if (preg_match('#/inc/layout-(top|bottom)\.php$#', $ruta)) { continue; }

    $orig = (string)file_get_contents($ruta);
    $t = $orig;
    $cambios = 0;

    foreach ($renombres as $de => $a) {
        $t = preg_replace($de, $a, $t, -1, $n);
        $cambios += $n;
    }

    foreach ($revisar as $marca => $porQue) {
        $n = substr_count($t, $marca);
        if ($n > 0) { $pendientes[basename($ruta)][$marca] = $n; }
    }

    if ($cambios > 0) {
        $tocados++;
        $totalCambios += $cambios;
        printf("  %-34s %4d cambios\n", basename($ruta), $cambios);
        if ($aplicar) { file_put_contents($ruta, $t); }
    }
}

echo "\n  " . ($aplicar ? 'APLICADOS' : 'simulados') . ": {$totalCambios} cambios en {$tocados} archivos\n";

if ($pendientes) {
    echo "\n=== A REVISAR A MANO (cambian estructura, no nombre) ===\n";
    foreach ($pendientes as $arch => $marcas) {
        foreach ($marcas as $m => $n) {
            printf("  %-34s %-22s x%d  %s\n", $arch, $m, $n, $revisar[$m]);
        }
    }
} else {
    echo "\n  nada que revisar a mano\n";
}
