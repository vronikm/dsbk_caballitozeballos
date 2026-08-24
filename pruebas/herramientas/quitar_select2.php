<?php
/*
| select2 se queda SÓLO donde aporta algo.
|
| LA MEDICIÓN QUE LO DECIDE
|
| Se contaron las opciones de cada desplegable marcado como select2, sobre
| la página renderizada —en el archivo salen cero, porque las genera PHP—.
| El resultado, sin una sola excepción salvo una:
|
|     sedes                3 a 8 opciones
|     formas de pago       7
|     parentesco           6
|     nacionalidad         5
|     tipo de documento    3
|     tallas               9
|     ── y aparte ──
|     horarios            51 en el alta de alumno, 11 en la edición
|
| Lo único que aporta select2 es un buscador. Sobre cinco opciones un
| buscador ESTORBA: el desplegable nativo abre el selector del sistema en
| el móvil, se maneja con el teclado, lo lee cualquier ayuda técnica y no
| necesita JavaScript. Sobre cincuenta y uno, sí hace falta.
|
| Así que la librería —91 KB más una dependencia de jQuery— se queda en
| las dos vistas del desplegable de horarios y se retira de las demás.
|
| SE TOCA SÓLO DENTRO DE class="…"
|
| Un \bselect2\b suelto sobre el archivo casa TAMBIÉN con la ruta
| plugins/select2/js/select2.full.min.js. La primera versión de este
| script contó 200 sustituciones —cuatro veces los elementos que hay— y
| habría dejado los <script src> apuntando a ninguna parte. El recuento
| absurdo fue lo que lo delató.
|
| Uso: quitar_select2.php [aplicar]
*/
$base    = 'c:/wamp64/www/barcelona/ds_basketball/app/views/content';
$aplicar = ($argv[1] ?? '') === 'aplicar';

/* Las dos que conservan la librería, y sólo para el desplegable largo. */
$conservan = ['alumnoNew', 'alumnoUpdate'];

/** Quita o renombra la clase select2 DENTRO de los atributos class. */
function tocarClases(string $t, ?string $porCual, int &$n): string
{
    return preg_replace_callback(
        '/\bclass="([^"]*)"/',
        function ($m) use ($porCual, &$n) {
            $clases = preg_split('/\s+/', trim($m[1]));
            $fuera  = [];
            $hubo   = false;

            foreach ($clases as $c) {
                /* Exactamente «select2». custom-select2 es una clase
                   propia del proyecto y no se toca. */
                if ($c === 'select2') {
                    $hubo = true;
                    if ($porCual !== null) { $fuera[] = $porCual; }
                    continue;
                }
                if ($c !== '') { $fuera[] = $c; }
            }

            if (!$hubo) { return $m[0]; }
            $n++;

            return $fuera ? 'class="' . implode(' ', $fuera) . '"' : 'class=""';
        },
        $t);
}

$quitClase = 0; $quitCarga = 0; $quitInit = 0; $renombrados = 0;
$tocados = [];

foreach (glob($base . '/*.php') as $f) {
    $vista = basename($f, '-view.php');
    $t = (string)file_get_contents($f);
    $orig = $t;

    if (in_array($vista, $conservan, true)) {
        /* El desplegable de horarios conserva el buscador, con una clase
           que dice para qué está. Se localiza por su id y se cambia sólo
           el suyo. */
        $t = preg_replace_callback(
            '#<select\b[^>]*>#',
            function ($m) use (&$renombrados) {
                if (!str_contains($m[0], 'id="horarioid"')) { return $m[0]; }
                if (!preg_match('/\bclass="[^"]*\bselect2\b/', $m[0])) { return $m[0]; }
                $renombrados++;
                return preg_replace('/\bselect2\b/', 'js-buscador', $m[0]);
            }, $t);

        /* El resto de select2 de esas vistas se van igual. */
        $t = tocarClases($t, null, $quitClase);

        /* El inicializador pasa a apuntar al buscador. */
        $t = preg_replace(
            "/\\\$\('\.select2'\)\.select2\([^)]*\)/",
            "\$('.js-buscador').select2({ width: '100%' })", $t, -1, $c);
        $quitInit += $c;

    } else {
        $t = tocarClases($t, null, $quitClase);

        /* La carga de la librería. */
        $t = preg_replace(
            '#^[ \t]*<(?:script[^>]*\bsrc|link[^>]*\bhref)="[^"]*plugins/select2[^"]*"[^>]*>(?:\s*</script>)?[ \t]*\R#mi',
            '', $t, -1, $c2);
        $quitCarga += $c2;

        /* Y la línea que lo inicializaba. */
        $t = preg_replace("#^[ \t]*\\\$\('\.select2'\)\.select2\([^;]*?\)\s*\R#m", '', $t, -1, $c3);
        $quitInit += $c3;
    }

    /* Restos de espaciado en los atributos que quedaron tocados. */
    $t = preg_replace('/\sclass=""/', '', $t);

    if ($t !== $orig) {
        $tocados[] = $vista;
        if ($aplicar) { file_put_contents($f, $t); }
    }
}

printf("  clases select2 retiradas   %d\n", $quitClase);
printf("  cargas de la librería      %d\n", $quitCarga);
printf("  inicializadores            %d\n", $quitInit);
printf("  desplegable de horarios    %d  (pasa a .js-buscador)\n", $renombrados);
printf("  vistas tocadas             %d\n", count($tocados));

/* Comprobación: las rutas de los scripts NO pueden haberse tocado. */
$rutasAntes = 0; $rutasDespues = 0;
foreach (glob($base . '/*.php') as $f) {
    $rutasDespues += substr_count((string)file_get_contents($f), 'plugins/select2/');
}
printf("\n  rutas plugins/select2/ que quedan: %d  (deben ser sólo las de %s)\n",
       $rutasDespues, implode(' y ', $conservan));

echo "\n  " . ($aplicar ? 'APLICADO' : 'simulado') . "\n";
