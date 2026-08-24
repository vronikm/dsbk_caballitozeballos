<?php
/*
| Los botones de plegar tarjeta no hacian NADA en todo el sistema.
|
| EL FALLO
|
| data-card-widget es la sintaxis de AdminLTE 3. La version 4 la ignora por
| completo: no hay error en consola, no hay aviso, el boton simplemente esta
| ahi y al pulsarlo no pasa nada. Medido en el navegador antes de tocar: la
| tarjeta seguia midiendo 187 px antes y despues de pulsar.
|
| Son 56 botones repartidos por los cuatro modulos, y todos con el mismo
| marcado, asi que la conversion es mecanica.
|
| LA VERSION 4 PIDE DOS ICONOS
|
| Uno para cada estado. Los oculta LOS DOS por CSS —[data-lte-icon=expand] y
| [data-lte-icon=collapse] llevan display:none— y muestra el que toca. Poner
| solo uno deja el boton vacio en la mitad de los estados, asi que hay que
| poner la pareja. Comprobado en el navegador: menos cuando esta desplegada,
| mas cuando esta plegada, y de vuelta.
|
| DE PASO, EL NOMBRE
|
| Son botones de solo icono: sin aria-label, quien navegue con lector de
| pantalla oye «boton» y nada mas.
|
| Uso: migrar_plegar.php [aplicar]
*/
$raiz    = 'c:/wamp64/www/barcelona/';
$aplicar = ($argv[1] ?? '') === 'aplicar';

$dirs = ['ds_basketball/app/views/content', 'ds_basketball/app/views/inc',
         'ds_league/views', 'ds_arena/views', 'ds_core/admin/views', 'ds_core/inc'];

$plegar = 0; $quitar = 0; $tocados = [];

foreach ($dirs as $d) {
    foreach (glob($raiz . $d . '/*.php') ?: [] as $f) {
        $t = (string)file_get_contents($f);
        if (!str_contains($t, 'data-card-widget')) { continue; }
        $orig = $t;

        /* Plegar: cambia el atributo Y el contenido, porque hacen falta los
           dos iconos. Se reescribe el boton entero. */
        $t = preg_replace_callback(
            '#<button([^>]*?)data-card-widget="collapse"([^>]*)>\s*<i class="fas fa-minus"></i>\s*</button>#s',
            function ($m) use (&$plegar) {
                $plegar++;
                /* Se conservan las clases que ya tuviera el boton. */
                $resto = $m[1] . $m[2];
                preg_match('/class="([^"]*)"/', $resto, $c);
                $clases = $c[1] ?? 'btn btn-tool';
                return '<button type="button" class="' . $clases . '"'
                     . ' data-lte-toggle="card-collapse"'
                     . ' title="Plegar o desplegar" aria-label="Plegar o desplegar">'
                     . '<i data-lte-icon="expand" class="fas fa-plus"></i>'
                     . '<i data-lte-icon="collapse" class="fas fa-minus"></i>'
                     . '</button>';
            }, $t);

        /* Quitar: aqui basta el atributo, el icono es siempre el mismo. */
        $t = preg_replace_callback(
            '#<button([^>]*?)data-card-widget="remove"([^>]*)>#s',
            function ($m) use (&$quitar) {
                $quitar++;
                $resto = $m[1] . $m[2];
                preg_match('/class="([^"]*)"/', $resto, $c);
                return '<button type="button" class="' . ($c[1] ?? 'btn btn-tool') . '"'
                     . ' data-lte-toggle="card-remove"'
                     . ' title="Quitar" aria-label="Quitar">';
            }, $t);

        if ($t !== $orig) {
            $tocados[] = basename($f, '-view.php');
            if ($aplicar) { file_put_contents($f, $t); }
        }
    }
}

printf("  botones de plegar   %d\n", $plegar);
printf("  botones de quitar   %d\n", $quitar);
printf("  vistas tocadas      %d\n", count($tocados));

/*----------  Comprobaciones  ----------*/
if ($aplicar) {
    $quedan = 0; $malformados = [];
    foreach ($dirs as $d) {
        foreach (glob($raiz . $d . '/*.php') ?: [] as $f) {
            $t = (string)file_get_contents($f);
            $quedan += substr_count($t, 'data-card-widget');
            /* Cada boton de plegar debe llevar SUS DOS iconos. */
            if (preg_match_all('#<button[^>]*card-collapse[^>]*>(.*?)</button>#s', $t, $m)) {
                foreach ($m[1] as $dentro) {
                    if (substr_count($dentro, 'data-lte-icon') !== 2) {
                        $malformados[] = basename($f);
                    }
                }
            }
            /* NO se cuentan las etiquetas de apertura y cierre para ver si
               cuadran. Se probo y dio dos falsos positivos: ui.php arma
               botones como cadenas de PHP y menciona la etiqueta de
               apertura sin cerrarla, y asistenciaHorarioJugador tiene un
               bloque comentado que acaba en «</button-->». Quien decide si
               esto quedo bien es el navegador, y eso lo comprueba
               qa_plegar.mjs pulsando cada boton. */
        }
    }
    printf("\n  sintaxis de la v3 que queda: %d\n", $quedan);
    printf("  botones mal formados:        %d %s\n", count($malformados),
           implode(' ', array_slice(array_unique($malformados), 0, 4)));
    echo '  ' . ($quedan === 0 && !$malformados ? "APLICADO y comprobado\n" : "REVISAR\n");
} else {
    echo "\n  simulado (sin escribir)\n";
}
