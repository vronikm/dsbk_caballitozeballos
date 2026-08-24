<?php
/*
| Las 73 cabeceras repetidas pasan a una sola.
|
| POR QUE
|
| Cada vista de Basketball llevaba su propio bloque <head> con los mismos
| seis enlaces. Cada cambio de plantilla de esta migracion hubo que
| repetirlo setenta y tres veces, y una vez se quedaron tres vistas atras:
| siguieron con el CSS de DataTables para Bootstrap 4 durante toda la
| migracion porque el script que lo cambiaba solo miraba las que
| inicializaban una tabla. Ese es el coste real de la duplicacion.
|
| QUE SE CONSERVA TAL CUAL
|
|   el titulo             se saca del <title> que ya tenia
|   los extras            se deducen de las hojas que carga: datatables,
|                         dropzone, lightbox, select2
|   sweetalert            se conserva EN LA CABECERA solo si ya estaba ahi:
|                         moverlo cambiaria cuando se ejecuta
|   los <style> propios   pasan enteros a $cabeceraExtra
|
| LAS QUE NO SE TOCAN
|
| Seis vistas cargan hojas que no entran en ningun extra —el carnet, la
| agenda, cumpleanos, el acceso—. Se dejan como estan: forzarlas al molde
| comun es justo el tipo de atajo que rompe una pantalla en silencio.
|
| Uso: unificar_cabecera.php <patron|todas> [aplicar]
*/
$base    = 'c:/wamp64/www/barcelona/ds_basketball/app/views/content';
$cual    = $argv[1] ?? 'todas';
$aplicar = ($argv[2] ?? '') === 'aplicar';

/* Lo que la cabecera comun ya pone. */
$comunes = ['fuentes.css', 'all.min.css', 'overlayscrollbars.min.css', 'adminlte.min.css',
            'core.css', 'sweetalert2.min.css', 'logo_bsc.png', 'tema.js',
            'sweetalert2.all.min.js'];

/* Que hoja delata a que extra. */
$delatan = [
    'dataTables.bootstrap5.min.css' => 'datatables',
    'responsive.bootstrap5.min.css' => 'datatables',
    'buttons.bootstrap5.min.css'    => 'datatables',
    'dropzone.min.css'              => 'dropzone',
    'ekko-lightbox.css'             => 'lightbox',
    'select2.min.css'               => 'select2',
    'select2-bootstrap4.min.css'    => 'select2',
];

$archivos = ($cual === 'todas') ? glob($base . '/*.php') : glob($base . '/' . $cual . '-view.php');
$hechas = []; $saltadas = [];

foreach ($archivos as $f) {
    $t = (string)file_get_contents($f);
    $v = basename($f, '-view.php');

    if (str_contains($t, 'inc/cabecera.php')) { continue; }        /* ya unificada */
    if (!preg_match('#<head>(.*?)</head>#s', $t, $m)) { continue; }
    $cabeza = $m[1];

    /* Hojas y scripts que carga. */
    preg_match_all('#(?:href|src)="[^"]*/([^/"]+\.(?:css|js))#', $cabeza, $x);
    $cargadas = array_unique($x[1]);
    $extranas = array_values(array_diff($cargadas, $comunes, array_keys($delatan)));

    if ($extranas) { $saltadas[$v] = $extranas; continue; }

    /* El titulo. */
    $titulo = '';
    if (preg_match('#<title>\s*<\?php echo APP_NAME; \?>\s*\|?\s*([^<]*)</title>#', $cabeza, $ti)) {
        $titulo = trim($ti[1]);
    }

    /* Los extras. */
    $extras = [];
    foreach ($cargadas as $c) {
        if (isset($delatan[$c])) { $extras[$delatan[$c]] = true; }
    }
    if (in_array('sweetalert2.all.min.js', $cargadas, true)) { $extras['swal'] = true; }
    $extras = array_keys($extras);

    /* Los <style> propios, enteros. */
    $estilos = '';
    if (preg_match_all('#[ \t]*<style\b.*?</style>#s', $cabeza, $st)) {
        $estilos = "\n" . implode("\n", $st[0]) . "\n";
    }

    /* La llamada que sustituye a todo el bloque. */
    $llamada = "<?php\n"
             . "\t/* La cabecera es comun a todas las vistas: ds_basketball/app/views/inc/cabecera.php */\n"
             . "\t\$tituloVista = " . var_export($titulo, true) . ";\n";
    if ($extras)  { $llamada .= "\t\$extras      = " . str_replace(["\n", '  '], ['', ''], var_export($extras, true)) . ";\n"; }
    if ($estilos) { $llamada .= "\t\$cabeceraExtra = <<<'CSS'" . $estilos . "CSS;\n"; }
    $llamada .= "\trequire_once \"app/views/inc/cabecera.php\";\n?>\n";

    /* Se sustituye desde el DOCTYPE (o el <html>) hasta cerrar la cabecera. */
    $n = 0;
    $t2 = preg_replace('#<!DOCTYPE html>.*?</head>#s', rtrim($llamada), $t, 1, $n);
    if ($n !== 1) { $saltadas[$v] = ['no se pudo aislar el bloque']; continue; }

    $hechas[$v] = ['titulo' => $titulo, 'extras' => $extras, 'estilo' => $estilos !== ''];
    if ($aplicar) { file_put_contents($f, $t2); }
}

printf("  unificadas: %d\n", count($hechas));
foreach (array_slice($hechas, 0, 8, true) as $v => $d) {
    printf("    %-28s «%s» %s%s\n", $v, $d['titulo'],
           $d['extras'] ? implode('+', $d['extras']) : '—',
           $d['estilo'] ? ' +estilo' : '');
}
if (count($hechas) > 8) { printf("    … y %d más\n", count($hechas) - 8); }

if ($saltadas) {
    printf("\n  se dejan como estaban: %d\n", count($saltadas));
    foreach ($saltadas as $v => $o) { printf("    %-28s %s\n", $v, implode(' ', $o)); }
}

if ($aplicar) {
    $malas = [];
    foreach (array_keys($hechas) as $v) {
        $s = [];
        exec('"c:/wamp64/bin/php/php8.3.28/php.exe" -l "' . $base . '/' . $v . '-view.php" 2>&1', $s, $cod);
        if ($cod !== 0) { $malas[] = $v; }
    }
    echo $malas ? "\n  SINTAXIS ROTA: " . implode(' ', $malas) . "\n" : "\n  APLICADO\n";
} else {
    echo "\n  simulado (sin escribir)\n";
}
