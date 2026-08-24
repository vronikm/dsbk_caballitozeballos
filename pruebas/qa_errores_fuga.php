<?php
/*
| Un error no le cuenta secretos al navegador de otra persona.
|
| LO QUE SE ENCONTRO Y POR QUE ESTA PRUEBA EXISTE
|
| Con display_errors activo y Xdebug cargado, una excepcion no capturada
| devolvia la traza completa CON LOS ARGUMENTOS de cada llamada:
|
|     conectar( $usuario = 'root', $clave = 'CLAVE-SECRETA-DE-PRUEBA' )
|
| El modelo conecta con new PDO($dsn, $this->user, $this->pass), asi que la
| clave de la base es un argumento. Y cualquier funcion que reciba una
| cedula o un telefono los imprimiria igual.
|
| QUE SE COMPRUEBA, Y DESDE DONDE
|
| Desde 127.0.0.1 los errores TIENEN que seguir viendose: si esta prueba
| pasara tambien en local, significaria que se rompio la forma de trabajar
| de quien desarrolla. Desde la IP de red no puede salir nada.
|
| Y LA TERCERA, QUE ES LA QUE PILLO UN FALLO REAL
|
| El JSON de error tiene que llevar un «tipo» que alertas_ajax reconozca. La
| primera version mandaba tipo «error», que no existe en la interfaz: el
| dialogo parseaba el JSON correctamente y no dibujaba nada. El formulario
| se quedaba mudo, que para el usuario es peor que un mensaje feo. La lista
| de tipos validos se lee de ajax.js, no se copia aqui: si alguien la
| cambia, esta prueba se entera.
*/
$RAIZ   = 'c:/wamp64/www/barcelona';
$fallos = 0;
$af = function (string $t, bool $ok, string $d = '') use (&$fallos) {
    printf("  %-52s %s%s\n", $t, $ok ? 'OK' : 'FALLA', $d !== '' ? "  ($d)" : '');
    if (!$ok) { $fallos++; }
};

/* La IP de red se averigua; escribirla aqui la deja obsoleta al primer
   cambio de red. */
$ipRed = '';
foreach (gethostbynamel(gethostname()) ?: [] as $ip) {
    if (!str_starts_with($ip, '127.')) { $ipRed = $ip; break; }
}
if ($ipRed === '') {
    echo "  no hay IP de red: sin ella no se puede probar el caso remoto\n";
    echo "\nfallos: 1\n";
    exit(1);
}

/* Sonda temporal en la raiz web. Se borra pase lo que pase. */
$sonda = "$RAIZ/sonda_qa_errores.php";
register_shutdown_function(function () use ($sonda) { @unlink($sonda); });
file_put_contents($sonda, <<<'CODE'
<?php
require_once __DIR__ . "/ds_core/config/app.php";
function ds_qa_conectar(string $usuario, string $clave) {
    throw new RuntimeException("fallo simulado por la prueba");
}
ds_qa_conectar("root", "CLAVE-QA-NO-DEBE-SALIR");
CODE);

$pedir = function (string $host, array $cab = []) use ($sonda): array {
    $ch = curl_init("http://$host/barcelona/sonda_qa_errores.php");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
                            CURLOPT_HTTPHEADER => $cab]);
    $b = (string) curl_exec($ch);
    $c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$c, $b];
};

/*==============  1. En local no cambia nada  ==============*/
[, $local] = $pedir('127.0.0.1');
$af('desde la propia máquina los errores se siguen viendo',
    str_contains($local, 'CLAVE-QA-NO-DEBE-SALIR') || str_contains($local, 'RuntimeException'),
    'si esto falla, se rompió el trabajo de quien desarrolla');

/*==============  2. Desde la red no sale nada  ==============*/
[$codigo, $remoto] = $pedir($ipRed);

$af('desde la red no aparece la contraseña',
    !str_contains($remoto, 'CLAVE-QA-NO-DEBE-SALIR'));
$af('ni la ruta del disco',
    !preg_match('~[Cc]:\\wamp64|c:/wamp64~', $remoto));
$af('ni el nombre de la excepción',
    !str_contains($remoto, 'RuntimeException'));
$af('ni el número de línea',
    !preg_match('~on line~i', $remoto));
$af('responde 500, no 200',
    $codigo === 500, "HTTP $codigo");
$af('y da una referencia para buscar en el registro',
    (bool) preg_match('~[0-9A-F]{8}~', $remoto));

/*==============  3. El JSON usa un tipo que la interfaz entiende  ==============*/
[, $json] = $pedir($ipRed, ['X-Requested-With: XMLHttpRequest']);
$datos = json_decode($json, true);
$af('la vía AJAX responde JSON válido', is_array($datos), substr($json, 0, 60));

$ajax = (string) file_get_contents("$RAIZ/ds_basketball/app/views/dist/js/ajax.js");
preg_match_all('~alerta\.tipo\s*==\s*"([^"]+)"~', $ajax, $m);
$tiposUI = $m[1];
$af('la interfaz declara sus tipos', count($tiposUI) >= 5, implode(' ', $tiposUI));
$af('el tipo enviado es uno que la interfaz dibuja',
    in_array($datos['tipo'] ?? '', $tiposUI, true),
    'envía «' . ($datos['tipo'] ?? '—') . '»');
$af('y trae los campos que ese tipo necesita',
    isset($datos['icono'], $datos['titulo'], $datos['texto']));
$af('sin filtrar nada en el JSON',
    !str_contains($json, 'CLAVE-QA-NO-DEBE-SALIR') && !str_contains($json, 'wamp64'));

/*==============  4. El detalle sí queda registrado  ==============*/
if (preg_match('~([0-9A-F]{8})~', $remoto, $r)) {
    $log = @file_get_contents('c:/wamp64/logs/php_error.log');
    $af('el detalle real está en el registro del servidor',
        $log !== false && str_contains($log, '[DS ' . $r[1] . ']'),
        'referencia ' . $r[1]);
}

printf("\nfallos: %d\n", $fallos);
exit($fallos === 0 ? 0 : 1);
