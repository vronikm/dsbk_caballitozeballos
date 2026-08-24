<?php
/*
| Siete pantallas revientan si el identificador de la URL no existe.
|
| EL FALLO
|
|     $x = $ctrl->Buscar($id);
|     if ($x->rowCount() == 1) { $x = $x->fetch(); }
|     ... mas abajo: $x['algo']
|
| Cuando no hay fila, $x sigue siendo el PDOStatement y la vista lo usa como
| array. PHP lanza un error fatal y, con display_errors encendido, imprime
| en la pagina la ruta absoluta del servidor y la pila de llamadas.
|
| Se llega sin hacer nada raro: un enlace viejo, un registro que alguien
| borro, o un numero cambiado en la barra de direcciones.
|
| EL ARREGLO
|
| El que ya usa el propio codigo. Cada una de estas vistas declara arriba a
| donde volver si el identificador no sirve:
|
|     $id = ds_id_de_url($url, 1, APP_URL . 'asistenciaListHorario/');
|
| Se reutiliza ESE destino para el caso de «existe el numero pero no el
| registro», que hoy no esta contemplado. Asi la vista se comporta igual en
| los dos casos y no hay que inventar ningun destino nuevo.
|
| NO SE TOCA NINGUNA CONSULTA NI NINGUNA REGLA DE NEGOCIO: solo se añade la
| rama que falta.
|
| Uso: guardar_id_inexistente.php [aplicar]
*/
$base    = 'c:/wamp64/www/barcelona/ds_basketball/app/views/content';
$aplicar = ($argv[1] ?? '') === 'aplicar';

/* Las siete, encontradas pidiendo cada pantalla con un id imposible. */
$vistas = ['asistenciaAlumno', 'asistenciaHorarioLista', 'asistenciaHorarioPDF',
           'asistenciaVerHorario', 'horarioListaPDF', 'jugadorListaPDF',
           'representanteVinc'];

$hechas = 0;

foreach ($vistas as $v) {
    $f = $base . '/' . $v . '-view.php';
    if (!is_file($f)) { printf("  %-24s no existe\n", $v); continue; }

    $t = (string)file_get_contents($f);
    $crlf = str_contains($t, "\r\n");
    $fin  = $crlf ? "\r\n" : "\n";

    /* A donde vuelve la vista cuando el identificador no sirve. */
    if (!preg_match("/ds_id_de_url\([^)]*APP_URL\s*\.\s*'([^']+)'/", $t, $m)) {
        printf("  %-24s no declara destino de vuelta\n", $v);
        continue;
    }
    $destino = $m[1];

    /* El bloque a completar. Se admite == 1 y > 0, que ambos aparecen. */
    $patron = '/if\s*\(\s*\$(\w+)->rowCount\(\)\s*(?:==|>)\s*[01]\s*\)\s*\{\s*\$\1\s*=\s*\$\1->fetch\(\)\s*;\s*\}/s';

    $n = 0;
    $t2 = preg_replace_callback($patron, function ($x) use ($destino, $fin, &$n) {
        $n++;
        $var = $x[1];
        $sangria = "\t\t";
        return $x[0] . " else {" . $fin
             . $sangria . "\t/* Sin registro, \$" . $var . " seguiria siendo el statement y la" . $fin
             . $sangria . "\t   vista lo usaria como array: error fatal en pantalla, con la" . $fin
             . $sangria . "\t   ruta del servidor dentro. Se vuelve a donde ya vuelve esta" . $fin
             . $sangria . "\t   misma vista cuando el identificador no sirve. */" . $fin
             . $sangria . "\theader(\"Location: \" . APP_URL . '" . $destino . "');" . $fin
             . $sangria . "\texit();" . $fin
             . $sangria . "}";
    }, $t, 1);

    if ($n !== 1) { printf("  %-24s no se encontro el bloque (%d)\n", $v, $n); continue; }

    printf("  %-24s vuelve a %s\n", $v, $destino);
    $hechas++;
    if ($aplicar) { file_put_contents($f, $t2); }
}

printf("\n  %d de %d vistas\n", $hechas, count($vistas));

if ($aplicar) {
    $malas = [];
    foreach ($vistas as $v) {
        $salida = [];
        exec('"c:/wamp64/bin/php/php8.3.28/php.exe" -l "' . $base . '/' . $v . '-view.php" 2>&1', $salida, $cod);
        if ($cod !== 0) { $malas[] = $v; }
    }
    echo $malas ? '  SINTAXIS ROTA EN: ' . implode(' ', $malas) . "\n" : "  APLICADO, sintaxis correcta\n";
} else {
    echo "  simulado (sin escribir)\n";
}
