<?php
/*
| Que utf8mb4 funcione de verdad, no sólo en el esquema.
|
| Se escribe y se relee por la MISMA vía que usa la aplicación —la
| conexión de seguridad— y se comparan los BYTES, no el texto: una doble
| codificación se ve idéntica en pantalla y sólo el HEX la delata.
|
| Limpia lo que crea.
*/
$_SERVER['HTTP_HOST'] = 'localhost';
require 'c:/wamp64/www/barcelona/ds_league/config/app.php';
require 'c:/wamp64/www/barcelona/ds_core/autoload.php';
require 'c:/wamp64/www/barcelona/ds_core/modulos.php';
require 'c:/wamp64/www/barcelona/ds_core/inc/session_start.php';

$fallos = 0;
function af(string $t, bool $ok, string $d = ''): void {
    global $fallos;
    echo '  ' . str_pad($t, 56) . ($ok ? 'OK' : 'FALLA') . ($d ? "  ({$d})" : '') . "\n";
    if (!$ok) { $fallos++; }
}

$con = seguridad_conexion();
af('la conexión de seguridad responde', $con !== null);

/* 1. Lo que la conexión declara. */
$juego = $con->query("SELECT @@character_set_client c, @@character_set_connection n,
                             @@character_set_results r, @@collation_connection l")
             ->fetch(PDO::FETCH_ASSOC);
af('character_set_client es utf8mb4',     $juego['c'] === 'utf8mb4', $juego['c']);
af('character_set_connection es utf8mb4', $juego['n'] === 'utf8mb4', $juego['n']);
af('character_set_results es utf8mb4',    $juego['r'] === 'utf8mb4', $juego['r']);

/* 2. Ida y vuelta de caracteres de 4 bytes y de acentos. */
$sello = 'QU' . substr((string)microtime(true), -6);

$casos = [
    'acento'     => 'Fundación Peñalosa · Añoranza',
    'emoji'      => 'Campeón 🏀🥇 2026',
    'tipografia' => '«Copa» — “final” … ½',
    'mixto'      => 'Muñoz–García 🇪🇨',
];

$con->exec("CREATE TEMPORARY TABLE qa_mb4 (
                k VARCHAR(20) NOT NULL PRIMARY KEY,
                v VARCHAR(200) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");

$ins = $con->prepare("INSERT INTO qa_mb4 (k, v) VALUES (:k, :v)");
foreach ($casos as $k => $v) { $ins->execute([':k' => $k, ':v' => $v]); }

$sel = $con->prepare("SELECT v, HEX(v) h FROM qa_mb4 WHERE k = :k");
foreach ($casos as $k => $esperado) {
    $sel->execute([':k' => $k]);
    $f = $sel->fetch(PDO::FETCH_ASSOC);
    $mismoTexto = $f && $f['v'] === $esperado;
    $mismosBytes = $f && strtoupper(bin2hex($esperado)) === $f['h'];
    af("«{$k}» vuelve idéntico byte a byte", $mismoTexto && $mismosBytes,
       $mismosBytes ? '' : 'esperado ' . strtoupper(bin2hex($esperado))
                         . ' obtenido ' . ($f['h'] ?? 'nada'));
}

/* 3. El emoji ocupa 4 bytes: es lo que utf8mb3 no podía guardar. */
$sel->execute([':k' => 'emoji']);
$f = $sel->fetch(PDO::FETCH_ASSOC);
af('el emoji conserva sus 4 bytes por carácter',
   str_contains($f['h'] ?? '', 'F09F'), substr($f['h'] ?? '', 0, 40));

/* 4. Una unión entre tablas de familias distintas ya no revienta.
      Antes, seguridad_menu (utf8mb3) contra dsl_* (utf8mb4) daba
      «Illegal mix of collations» y el helper devolvía [] en silencio. */
try {
    $n = $con->query(
        "SELECT COUNT(*) FROM seguridad_menu M
           JOIN facturas_electronicas_punto_emision P ON P.punto_modulo = M.menu_modulo")
        ->fetchColumn();
    af('une seguridad_menu con los puntos de emisión', true, $n . ' filas');
} catch (Throwable $e) {
    af('une seguridad_menu con los puntos de emisión', false, $e->getMessage());
}

try {
    $n = $con->query(
        "SELECT COUNT(*) FROM seguridad_usuario U
           JOIN sujeto_empleado E ON E.empleado_identificacion = U.usuario_usuario")
        ->fetchColumn();
    af('une seguridad_usuario con sujeto_empleado por texto', true, $n . ' filas');
} catch (Throwable $e) {
    af('une seguridad_usuario con sujeto_empleado por texto', false, $e->getMessage());
}

/* 5. La comparación de textos con ñ dentro de la misma consulta. */
try {
    $r = $con->query("SELECT 'Peña' = 'Pena' AS igual")->fetchColumn();
    af('utf8mb4_0900_ai_ci considera «Peña» = «Pena»', (int)$r === 1,
       'devolvió ' . var_export($r, true));
} catch (Throwable $e) {
    af('utf8mb4_0900_ai_ci considera «Peña» = «Pena»', false, $e->getMessage());
}

/*
| 6. EL COTEJAMIENTO DE LA CONEXION, no solo el de las tablas.
|
| Esta suite daba verde mientras existia un desajuste real: las 93 tablas y
| las 474 columnas estaban en utf8mb4_0900_ai_ci, pero collation_connection
| era utf8mb4_general_ci. Miraba el esquema y no la conexion, que es con lo
| que PHP compara.
|
| No era cosmetico. Esta consulta reventaba:
|
|     SELECT ... FROM v_comprobante_emitido WHERE origen_modulo LIKE ?
|     ERROR 1267: Illegal mix of collations
|
| origen_modulo sale de un literal dentro de la vista, asi que es COERCIBLE
| igual que un parametro enlazado, y dos COERCIBLE con cotejamientos
| distintos no se comparan. Se corrigio con DS_DB_INIT_COMANDO.
*/
try {
    $cc = $con->query('SELECT @@collation_connection')->fetchColumn();
    $cd = $con->query('SELECT @@collation_database')->fetchColumn();
    af('la conexión usa el cotejamiento de la base', $cc === $cd, "conexión $cc · base $cd");
} catch (Throwable $e) {
    af('la conexión usa el cotejamiento de la base', false, $e->getMessage());
}

/* Y la consulta concreta que lo destapó, por si vuelve por otra via. */
try {
    $st = $con->prepare('SELECT COUNT(*) FROM v_comprobante_emitido WHERE origen_modulo LIKE ?');
    $st->execute(['%basket%']);
    af('comparar un parámetro con una columna derivada de literal', true,
       $st->fetchColumn() . ' filas');
} catch (Throwable $e) {
    af('comparar un parámetro con una columna derivada de literal', false,
       substr($e->getMessage(), 0, 70));
}
echo "\nfallos: {$fallos}\n";
exit($fallos === 0 ? 0 : 1);
