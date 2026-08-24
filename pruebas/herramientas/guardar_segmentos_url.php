<?php
/*
| $url[N] leido sin comprobar que el segmento exista.
|
| Cuando la URL trae menos segmentos, PHP avisa «Undefined array key N» y
| —con display_errors encendido— imprime en la pagina la ruta absoluta del
| servidor y la pila de llamadas. Se vio al redirigir jugadorListaPDF a
| jugadorLista: el destino filtraba la ruta.
|
| Se toca SOLO la comparacion, que es donde se lee el segmento que puede
| faltar. La rama verdadera del ternario no necesita guarda: solo se evalua
| cuando la comparacion ya confirmo que existe y no esta vacio.
|
| No cambia ningun comportamiento: donde antes habia un aviso y el valor
| nulo, ahora hay cadena vacia y el mismo resultado.
*/
$base = 'c:/wamp64/www/barcelona/ds_basketball/app/views/content';
$aplicar = ($argv[1] ?? '') === 'aplicar';
$total = 0; $vistas = 0;

foreach (glob($base . '/*.php') as $f) {
    $t = (string)file_get_contents($f);
    $n = 0;
    /* ($url[N] != "")  →  (($url[N] ?? '') != "") */
    $t2 = preg_replace('/\(\s*\$url\[(\d)\]\s*!=\s*""\s*\)/',
                       '((\$url[$1] ?? "") != "")', $t, -1, $n);
    if ($n === 0) { continue; }
    $vistas++; $total += $n;
    if ($aplicar) { file_put_contents($f, $t2); }
}
printf("  %d comparaciones en %d vistas\n", $total, $vistas);
echo '  ' . ($aplicar ? "APLICADO\n" : "simulado\n");
