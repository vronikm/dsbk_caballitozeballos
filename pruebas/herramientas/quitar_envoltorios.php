<?php
/*
| Retira los <div class="input-group-prepend|append"> de Bootstrap 4.
|
| En Bootstrap 5 el contenido del input-group va SUELTO: el <span
| class="input-group-text"> o el <button> son hijos directos. El <div>
| envolvente ya no aporta nada y, peor, mete un elemento de más en el
| flexbox que descuadra los bordes redondeados de los extremos.
|
| Se hace con un análisis de llaves, no con una expresión regular sobre
| todo el bloque: un .*? entre el <div> de apertura y su </div> se come el
| primer </div> que encuentre, que puede ser el de otro elemento anidado.
| Aquí se cuenta la anidación de verdad.
|
| Uso: quitar_envoltorios.php <carpeta> [aplicar]
*/
$carpeta = $argv[1] ?? '';
$aplicar = ($argv[2] ?? '') === 'aplicar';

if ($carpeta === '' || !is_dir($carpeta)) {
    fwrite(STDERR, "uso: quitar_envoltorios.php <carpeta> [aplicar]\n");
    exit(1);
}

/**
 * Devuelve el texto con el <div> de envoltorio retirado, conservando su
 * contenido. Recorre carácter a carácter para saber cuál de los </div>
 * cierra el que abrimos.
 */
function desenvolver(string $t, int &$cuantos): string
{
    $cuantos = 0;

    while (true) {
        if (!preg_match('/<div class="input-group-(?:prepend|append)"\s*>/', $t, $m,
                        PREG_OFFSET_CAPTURE)) {
            break;
        }

        $ini    = $m[0][1];
        $tras   = $ini + strlen($m[0][0]);
        $nivel  = 1;
        $pos    = $tras;
        $cierre = -1;

        while ($pos < strlen($t)) {
            $abre = stripos($t, '<div', $pos);
            $cier = stripos($t, '</div>', $pos);

            if ($cier === false) { break; }

            if ($abre !== false && $abre < $cier) {
                $nivel++;
                $pos = $abre + 4;
                continue;
            }

            $nivel--;
            if ($nivel === 0) { $cierre = $cier; break; }
            $pos = $cier + 6;
        }

        if ($cierre < 0) { break; }   /* Sin cierre: se deja como está. */

        $dentro = substr($t, $tras, $cierre - $tras);

        /* Se conserva la sangría razonable quitando un nivel. */
        $dentro = preg_replace('/^[ \t]{4}/m', '', $dentro);

        $t = substr($t, 0, $ini) . trim($dentro, "\r\n") . substr($t, $cierre + 6);
        $cuantos++;
    }

    return $t;
}

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($carpeta));
$total = 0;

foreach ($it as $f) {
    if ($f->getExtension() !== 'php') { continue; }
    $ruta = str_replace('\\', '/', $f->getPathname());
    if (str_contains($ruta, '/dist/') || str_contains($ruta, '/lib/')) { continue; }

    $t = (string)file_get_contents($ruta);
    if (!str_contains($t, 'input-group-prepend') && !str_contains($t, 'input-group-append')) {
        continue;
    }

    $antes = substr_count($t, '<div') - substr_count($t, '</div>');
    $n = 0;
    $nuevo = desenvolver($t, $n);
    $despues = substr_count($nuevo, '<div') - substr_count($nuevo, '</div>');

    /* Comprobación de seguridad: el desbalance de div tiene que ser el
       mismo antes y después. Si cambia, se ha comido una etiqueta que no
       era y NO se escribe.

       OJO, NO LO ATRAPA TODO: si la etiqueta encontrada estaba dentro de un
       COMENTARIO que la cita como ejemplo, el script la trata como código,
       borra un cierre real y el desbalance sale igual —quita uno de cada—.
       Pasó una vez. La regla práctica es no escribir marcado literal en los
       comentarios de las vistas. */
    if ($antes !== $despues) {
        printf("  %-34s DESCARTADO (el balance de <div> cambia: %d -> %d)\n",
               basename($ruta), $antes, $despues);
        continue;
    }

    printf("  %-34s %d envoltorios retirados\n", basename($ruta), $n);
    $total += $n;

    if ($aplicar) { file_put_contents($ruta, $nuevo); }
}

echo "\n  " . ($aplicar ? 'APLICADOS' : 'simulados') . ": {$total}\n";
