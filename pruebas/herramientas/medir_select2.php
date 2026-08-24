<?php
/*
| select2: quién la usa, quién la promete y quién la carga en balde.
|
| Tres casos, y cada uno pide una decisión distinta:
|
|   USA        tiene elementos .select2 Y carga la librería Y la invoca.
|              Se queda.
|
|   PROMETE    tiene elementos con class="select2" pero NO carga la
|              librería. El marcado dice que habrá un desplegable con
|              buscador y sale un <select> normal. No falla, pero engaña a
|              quien lea el código y al que espere el buscador.
|
|   SOBRA      carga la librería y no tiene ni un elemento. Son ~200 KB
|              que el navegador descarga para nada.
|
| Se cuenta además cuántas OPCIONES tiene cada desplegable, porque de eso
| depende la decisión: select2 aporta un buscador, y un buscador sobre
| cinco opciones es peor que el desplegable nativo —que en el móvil abre
| el selector del sistema, se maneja con el teclado y no necesita
| JavaScript—.
*/
$base = 'c:/wamp64/www/barcelona/ds_basketball/app/views/content';

$usa = []; $promete = []; $sobra = [];

foreach (glob($base . '/*.php') as $f) {
    $bruto = (string)file_get_contents($f);
    $vista = basename($f, '-view.php');

    /* Sin las etiquetas de carga, para que la ruta no cuente como uso. */
    $codigo = preg_replace('#<script[^>]*\bsrc=[^>]*>\s*</script>#i', '', $bruto);

    $elementos = preg_match_all('/class="[^"]*\bselect2\b[^"]*"/', $bruto);
    $carga     = str_contains($bruto, 'plugins/select2/js');
    $invoca    = (bool)preg_match('/\.select2\s*\(/', $codigo);

    if ($elementos === 0 && !$carga) { continue; }

    /* Cuántas opciones tiene cada <select class="...select2..."> */
    $opciones = [];
    if (preg_match_all('#<select[^>]*class="[^"]*\bselect2\b[^"]*"[^>]*>(.*?)</select>#s',
                       $bruto, $m)) {
        foreach ($m[1] as $cuerpo) {
            $fijas = preg_match_all('/<option/', $cuerpo);
            /* Un bucle PHP dentro significa lista de longitud desconocida:
               ahí el buscador sí puede hacer falta. */
            $dinamico = (bool)preg_match('/foreach|while|for\s*\(/', $cuerpo);
            $opciones[] = $dinamico ? 'variable' : $fijas;
        }
    }

    $ficha = ['vista' => $vista, 'elementos' => $elementos, 'opciones' => $opciones];

    if ($elementos > 0 && $carga)  { $usa[] = $ficha; }
    elseif ($elementos > 0)        { $promete[] = $ficha; }
    else                           { $sobra[] = $ficha + ['invoca' => $invoca]; }
}

$pinta = function (array $lista) {
    foreach ($lista as $x) {
        printf("  %-38s %2d elementos   opciones: %s\n",
               $x['vista'], $x['elementos'],
               $x['opciones'] ? implode(', ', $x['opciones']) : '—');
    }
};

echo "=== USA select2 (carga + elementos) ===\n";        $pinta($usa);
echo "\n=== LO PROMETE Y NO LA CARGA ===\n";             $pinta($promete);
echo "\n=== LA CARGA SIN NECESITARLA ===\n";
foreach ($sobra as $x) {
    printf("  %-38s %s\n", $x['vista'], $x['invoca'] ? '(la invoca sin elementos)' : '');
}

printf("\n  usa %d · promete %d · sobra %d\n", count($usa), count($promete), count($sobra));

/* Cuánto pesa la librería. */
$js  = 'c:/wamp64/www/barcelona/ds_basketball/app/views/dist/plugins/select2/js/select2.full.min.js';
$css = 'c:/wamp64/www/barcelona/ds_basketball/app/views/dist/plugins/select2/css/select2.min.css';
printf("  la librería pesa %d KB (JS %d + CSS %d)\n",
       (int)((filesize($js) + filesize($css)) / 1024),
       (int)(filesize($js) / 1024), (int)(filesize($css) / 1024));
