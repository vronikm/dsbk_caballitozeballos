<?php
/*
| La capa propia se cargaba ANTES del framework al que reviste.
|
| LO QUE SE VIO
|
| Al activar las ultimas reglas de identidad, el fondo de la barra superior
| no cambiaba pese a tener la clase puesta. Preguntandole al motor que reglas
| pintaban ese fondo, en orden de aplicacion:
|
|     .ds-core__navbar   var(--ds-bg)   !important
|     .bg-body           var(--bs-body-bg)  !important    ← gana esta
|
| Las dos son de una sola clase y las dos llevan !important, asi que decide
| el ORDEN DE CARGA. Y el orden era:
|
|     1. fuentes.css
|     2. core.css          ← la capa propia
|     3. all.min.css
|     4. overlayscrollbars.min.css
|     5. adminlte.min.css  ← el framework, con Bootstrap dentro
|
| La cabecera del propio core.css dice «No sustituye a AdminLTE: lo reviste».
| No podia revestir nada cargandose tres puestos antes.
|
| QUE CAMBIA Y QUE NO
|
| Las reglas de core.css con DOS clases —.ds-core .card y companía— ya
| ganaban por especificidad y no cambian. Las de una sola clase eran las que
| perdian en silencio, y son las que empiezan a aplicar.
|
| El riesgo es justo ese: reglas que llevaban tiempo sin hacer nada empiezan
| a hacerlo. Por eso despues hay que pasar el barrido entero —contraste en
| los dos temas, desbordes, maquetacion— y comparar capturas.
|
| Uso: orden_hojas.php [aplicar]
*/
$dirs = ['ds_basketball/app/views/content', 'ds_league/views', 'ds_arena/views',
         'ds_core/admin/views', 'ds_core/inc', 'ds_core/hub'];
$raiz    = 'c:/wamp64/www/barcelona/';
$aplicar = ($argv[1] ?? '') === 'aplicar';

$movidas = []; $sinAncla = [];

foreach ($dirs as $d) {
    foreach (glob($raiz . $d . '/*.php') ?: [] as $f) {
        $t = (string)file_get_contents($f);
        if (!preg_match('/<html[\s>]/i', $t)) { continue; }
        if (!str_contains($t, 'assets/css/core.css')) { continue; }

        $v = basename($f, '-view.php');

        /* La linea de la capa propia, con su salto.
           Se casa la LINEA ENTERA, no la etiqueta: el href lleva dentro
           «<?php echo DS_HUB_URL; ?>» y un [^>]* se corta en el «>» de ese
           propio PHP, no en el de la etiqueta. Ese fallo dejo el primer
           intento en cero de setenta. */
        if (!preg_match('#^[ \t]*<link[^\r\n]*assets/css/core\.css[^\r\n]*\R#mi', $t, $mc)) {
            $sinAncla[] = $v . ' (no se aisla la línea de core.css)';
            continue;
        }
        $linea = $mc[0];

        /* Y la del framework, que es donde tiene que ir detras. */
        if (!preg_match('#^[ \t]*<link[^\r\n]*adminlte4/css/adminlte\.min\.css[^\r\n]*\R#mi', $t, $ma)) {
            $sinAncla[] = $v . ' (no carga adminlte)';
            continue;
        }

        /* Si ya esta despues, no se toca. */
        if (strpos($t, $mc[0]) > strpos($t, $ma[0])) { continue; }

        $t2 = str_replace($linea, '', $t);            /* se quita de donde estaba */
        $t2 = str_replace($ma[0], $ma[0] . $linea, $t2); /* y se pone detras */

        $movidas[] = $f;
        if ($aplicar) { file_put_contents($f, $t2); }
    }
}

printf("  vistas con la capa propia movida detrás: %d\n", count($movidas));
if ($sinAncla) { printf("  sin poder mover: %d\n    %s\n", count($sinAncla), implode("\n    ", $sinAncla)); }

if ($aplicar) {
    $malas = [];
    foreach ($movidas as $f) {
        {
            $v = basename($f, '-view.php');
            $t = (string)file_get_contents($f);
            if (substr_count($t, 'assets/css/core.css') !== 1) { $malas[] = $v . ' (duplicada)'; }
            if (strpos($t, 'assets/css/core.css') < strpos($t, 'adminlte4/css/adminlte.min.css')) {
                $malas[] = $v . ' (sigue delante)';
            }
            $salida = [];
            exec('"c:/wamp64/bin/php/php8.3.28/php.exe" -l "' . $f . '" 2>&1', $salida, $cod);
            if ($cod !== 0) { $malas[] = $v . ' (sintaxis)'; }
        }
    }
    /* Que no diga APLICADO cuando no ha movido nada: la primera version lo
       hacia, y daba por bueno un anclaje que fallaba en las setenta. */
    if ($malas)        { echo "\n  REVISAR: " . implode(' ', array_unique($malas)) . "\n"; }
    elseif (!$movidas) { echo "\n  NO SE MOVIÓ NINGUNA: revisar el anclaje\n"; }
    else               { echo "\n  APLICADO: la capa propia va ahora detrás del framework\n"; }
} else {
    echo "\n  simulado (sin escribir)\n";
}
