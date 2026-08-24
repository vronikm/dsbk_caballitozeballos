<?php
/*
| Retira el bloque de demostración de AdminLTE de las vistas de Basketball.
|
| QUE SE RETIRA Y POR QUE
|
| Siete vistas llevan copiado íntegro el ejemplo de «formularios avanzados»
| de AdminLTE: inicializa tempusdominus, daterangepicker, duallistbox,
| colorpicker, bootstrap-switch y bs-stepper contra selectores
| —#reservationdate, .duallistbox, .my-colorpicker1…— que NO EXISTEN en
| ninguna de esas páginas. Se comprobó vista por vista y atributo por
| atributo antes de tocar nada.
|
| El bloque es byte a byte idéntico en las siete (mismo md5, 71 líneas),
| lo que confirma que es una copia y no código escrito para el caso.
|
| De ese bloque SÍ se conservan dos cosas, que son las únicas reales:
|
|   $('.select2').select2()     71 elementos con class="form-control select2"
|   $('[data-mask]').inputmask()  presente en 5 de las 7
|
| Se descarta también $('.select2bs4').select2({theme:'bootstrap4'}): la
| clase select2bs4 no aparece en NINGÚN elemento de las 75 vistas, lo que
| convierte a select2-bootstrap4-theme en peso muerto y elimina una de las
| supuestas barreras para migrar a Bootstrap 5.
|
| Uso: limpiar_demo.php [aplicar]
*/
$base    = 'c:/wamp64/www/barcelona/ds_basketball/app/views/content';
$aplicar = ($argv[1] ?? '') === 'aplicar';

$vistas = ['alumnoNew', 'alumnoProfile', 'alumnoUpdate', 'pagosNew',
           'pagosUniformeUpdate', 'pagosUpdate', 'representanteNew'];

/* Lo que sustituye al bloque entero. */
$reemplazo = <<<'JS'
		$(function () {
			/* Lo único del bloque de ejemplo de AdminLTE que esta página
			   usaba de verdad. El resto —tempusdominus, daterangepicker,
			   duallistbox, colorpicker, bootstrap-switch y bs-stepper—
			   apuntaba a selectores inexistentes: se descargaban seis
			   librerías para ejecutar código contra nada. */
			$('.select2').select2()
			$('[data-mask]').inputmask()
		})
JS;

$hechos = 0;

foreach ($vistas as $v) {
    $f = "{$base}/{$v}-view.php";
    $t = (string)file_get_contents($f);

    /* Del inicio del $(function () { que abre el bloque hasta el cierre
       del initializer de bs-stepper.
       El ancla va con expresión regular y no con texto literal: la
       sangría NO es la misma en las siete vistas —tabuladores en unas,
       espacios en otras— y anclar en literal sólo encontraba una. */
    if (!preg_match('/^[ \t]*\$\(function\s*\(\)\s*\{\s*\R[ \t]*\/\/\s*Initialize Select2 Elements/m',
                    $t, $m, PREG_OFFSET_CAPTURE)) {
        echo "  {$v}: no se encontró el arranque del bloque\n";
        continue;
    }
    $ini = $m[0][1];

    $marcaFin = "window.stepper = new Stepper(document.querySelector('.bs-stepper'))";
    $fin = strpos($t, $marcaFin, $ini);
    if ($fin === false) {
        echo "  {$v}: no se encontró el final del bloque\n";
        continue;
    }

    /* Se avanza hasta cerrar el addEventListener del stepper: «})» en su
       propia línea después de la marca. */
    $fin = strpos($t, "})", $fin);
    $fin = strpos($t, "\n", $fin) + 1;

    $antes  = strlen($t);
    $nuevo  = substr($t, 0, $ini) . $reemplazo . "\n" . substr($t, $fin);
    $quitado = $antes - strlen($nuevo);

    printf("  %-22s -%5d bytes\n", $v, $quitado);
    $hechos++;

    if ($aplicar) { file_put_contents($f, $nuevo); }
}

echo "\n  " . ($aplicar ? 'APLICADO' : 'simulado') . " en {$hechos} de "
   . count($vistas) . " vistas\n";
