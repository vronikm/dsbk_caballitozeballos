<?php
/*
| Cuánto pesa cada pantalla, antes y después.
|
| POR QUÉ NO SE MIDE CON EL NAVEGADOR
|
| Se intentó, sumando las respuestas de las peticiones, y salió un disparate:
| la primera pantalla pedía 2,6 MB y las siguientes «0 KB». No es que fueran
| ligeras: es que sus librerías ya estaban en la caché de la sesión anterior
| y no generaban ni una petición. Medir así premia el orden de las pruebas.
|
| Aquí se lee el HTML QUE SIRVE EL SERVIDOR, se extraen las etiquetas de
| carga y se suman los tamaños en disco. Sale lo mismo la primera vez que la
| centésima.
|
| El «antes» sale de las copias guardadas en respaldo_js/, que no pasan por
| el servidor: se resuelven a mano las mismas rutas.
*/
$vistas = ['cumpleaniosList', 'dashboard', 'agenda', 'empleadoEntrada',
           'pagosDescuento', 'pagospendienteRecibo', 'buscarAsistencia'];

$RAIZ    = 'c:/wamp64/www/barcelona/';
$COOKIE  = 'DigiSportsBasketball=dsqaui0000000000000';

/** Convierte una URL del proyecto en la ruta del archivo, o null. */
function aDisco(string $url, string $raiz): ?string
{
    $url = preg_replace('/[?#].*$/', '', $url);
    if (!preg_match('#/barcelona/(.+)$#', $url, $m)) { return null; }
    $f = $raiz . $m[1];
    return is_file($f) ? $f : null;
}

/** Suma los bytes de los .js y .css que declara un HTML. */
function pesa(string $html, string $raiz): array
{
    $bytes = ['js' => 0, 'css' => 0];
    if (preg_match_all('/(?:src|href)="([^"]+\.(?:js|css)[^"]*)"/i', $html, $m)) {
        foreach (array_unique($m[1]) as $u) {
            $f = aDisco($u, $raiz);
            if ($f === null) { continue; }
            $bytes[str_ends_with(strtok($u, '?'), '.css') ? 'css' : 'js'] += filesize($f);
        }
    }
    return $bytes;
}

/* El «antes»: la copia guardada, con las rutas de PHP resueltas a mano. */
function comoSeSirve(string $php): string
{
    return str_replace(
        ['<?php echo APP_URL; ?>', '<?php echo DS_HUB_URL; ?>'],
        ['http://localhost/barcelona/ds_basketball/', 'http://localhost/barcelona/'],
        $php);
}

/* Tres de estas pantallas EXIGEN un identificador en la URL y sin el
   redirigen a otro sitio. Medir «pagosDescuento/» era medir pagosList, y
   dio por buenas dos pantallas que ni se habian visitado. */
$urls = [
    'buscarAsistencia'     => 'buscarAsistencia/2/',
    'pagosDescuento'       => 'pagosDescuento/2/',
    'pagospendienteRecibo' => 'pagospendienteRecibo/2/',
];

$totalAntes = 0; $totalAhora = 0;
printf("  %-22s %10s %10s %10s\n", 'pantalla', 'antes', 'ahora', 'ahorro');
echo "  " . str_repeat('─', 56) . "\n";

foreach ($vistas as $v) {
    $copia = __DIR__ . '/respaldo_js/' . $v . '-view.php';
    $antes = is_file($copia)
           ? pesa(comoSeSirve((string)file_get_contents($copia)), $RAIZ)
           : ['js' => 0, 'css' => 0];

    $url = 'http://localhost/barcelona/ds_basketball/' . ($urls[$v] ?? $v . '/');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => $COOKIE,
                            CURLOPT_FOLLOWLOCATION => false]);
    $html = (string)curl_exec($ch);
    curl_close($ch);

    $ahora = pesa($html, $RAIZ);
    $a = ($antes['js'] + $antes['css']) / 1024;
    $b = ($ahora['js'] + $ahora['css']) / 1024;
    $totalAntes += $a; $totalAhora += $b;

    printf("  %-22s %7d KB %7d KB %7d KB\n", $v, $a, $b, $a - $b);
}

echo "  " . str_repeat('─', 56) . "\n";
printf("  %-22s %7d KB %7d KB %7d KB\n", 'total', $totalAntes, $totalAhora,
       $totalAntes - $totalAhora);
