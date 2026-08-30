<?php
/*
|--------------------------------------------------------------------------
| Criterio 5: quien está limitado a unas sedes no ve las otras
|--------------------------------------------------------------------------
| «Un usuario con ámbito de una sede no ve datos de otra, ni en pantalla ni
| en el JSON ni en la exportación.»
|
|
| POR QUÉ EXISTE ESTA SUITE
|
| Porque el criterio se incumplía. `sedesDelUsuario()` estaba escrito,
| documentado y con un comentario que explicaba por qué era imprescindible —y
| no lo llamaba nadie—. Seis usuarios están limitados hoy en la base; los
| siete controladores de Basketball lo respetan; Insights no lo hacía. Un
| método que existe se parece mucho a un método que funciona, y ahí es donde
| se escondió durante todo el desarrollo.
|
|
| LAS DOS MITADES
|
|   en ejecución   un usuario limitado a las sedes 4 y 5 pide las mismas
|                  pantallas y exportaciones, y no puede aparecer ni un dato
|                  de las otras cinco. Antes se comprueba que el
|                  superadministrador SÍ los ve: si no, la prueba estaría
|                  celebrando una pantalla vacía.
|
|   estática       cada método cuya SQL toca una tabla con sede tiene que
|                  llamar a uno de los ayudantes de ámbito. Esta es la que
|                  evita que la avería vuelva: el próximo método que se
|                  escriba sin acotar sale rojo en el barrido, no dentro de
|                  seis meses.
*/

const APP_NAME = 'QA';
require_once __DIR__ . '/../ds_core/config/app.php';
require_once __DIR__ . '/conexion.php';

$fallos = 0;
$af = function (string $texto, bool $ok, string $detalle = '') use (&$fallos): void {
    printf("  %-56s %s%s\n", $texto, $ok ? 'OK' : 'FALLA', $detalle !== '' ? "  ($detalle)" : '');
    if (!$ok) { $fallos++; }
};

$db = qa_conexion();

/*==================================================================
| 1. La comprobación estática
|==================================================================
| Se trocea el controlador por métodos y se mira, en cada uno que consulte
| una tabla con sede, si llama a algún ayudante de ámbito.
*/
$ctrl = (string) file_get_contents(__DIR__ . '/../ds_insights/controllers/insightsController.php');

/* Tablas cuyos datos son atribuibles a una sede. dsl_* queda fuera a
   propósito: League no tiene sede (decisión R5) y no se le puede asignar
   una sin inventarla. */
$conSede = ['sujeto_alumno', 'alumno_pago', 'alumno_pago_descuento',
            'dsa_reserva', 'dsa_pago', 'dsa_instalacion', 'dsa_horario',
            'insights_v_asistencia_dia', 'facturas_electronicas',
            'insights_cartera_snapshot'];

/* Métodos que consultan esas tablas pero NO necesitan acotarse, y por qué.
   La lista es corta a propósito: cada excepción es una puerta. */
$exentos = [
    'diagnostico'    => 'cuenta filas para comprobar que el módulo está instalado',
    'sede'           => 'es el propio ayudante',
    'sedeReserva'    => 'es el propio ayudante',
    'sedeAlumno'     => 'es el propio ayudante',
    'sedesDelUsuario' => 'lee el ámbito',
    'ambitoSedes'    => 'resuelve el ámbito',
    'sedeSnapshot'   => 'es el propio ayudante',
    'tramosCartera'  => 'devuelve los tramos de antigüedad, no consulta nada',
    'proximosPartidos' => 'es de League, que no tiene sede (R5); toca dsa_instalacion
                           sólo para poner el nombre del escenario',
];

preg_match_all('~\n    (?:public|protected|private) function (\w+)\(~', $ctrl, $m, PREG_OFFSET_CAPTURE);
$n = count($m[1]);
$sinAcotar = [];
$acotados  = 0;

for ($i = 0; $i < $n; $i++) {
    $nombre = $m[1][$i][0];
    $ini = $m[0][$i][1];
    $fin = $i + 1 < $n ? $m[0][$i + 1][1] : strlen($ctrl);
    $cuerpo = substr($ctrl, $ini, $fin - $ini);

    /*
    | Fuera los comentarios antes de mirar nada.
    |
    | El troceo va de una declaracion de metodo a la siguiente, asi que un
    | bloque de comentario escrito ENTRE dos metodos cae dentro del cuerpo
    | del anterior. Basto con documentar la cartera —la explicacion nombra
    | alumno_pago— para que leagueAnomalias saliera roja sin haber cambiado
    | ni una linea de su codigo.
    |
    | Ademas es lo correcto por si solo: una tabla nombrada en una
    | explicacion no es una consulta, y una llamada comentada no protege
    | nada.
    */
    $limpio = preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $cuerpo);

    if (!preg_match('~SELECT~i', $limpio)) { continue; }
    if (isset($exentos[$nombre]))          { continue; }

    $toca = false;
    foreach ($conSede as $t) { if (str_contains($limpio, $t)) { $toca = true; break; } }
    if (!$toca) { continue; }

    if (preg_match('~\$this->(sede|sedeReserva|sedeAlumno|sedeSnapshot)\(~', $limpio)) {
        $acotados++;
    } else {
        $sinAcotar[] = $nombre;
    }
}

$af('todo método con SQL de sede llama al ámbito',
    $sinAcotar === [],
    $sinAcotar ? implode(', ', $sinAcotar) : "$acotados métodos acotados");

/* Y que la lista de exentos no se haya quedado obsoleta. */
$vivos = array_filter(array_keys($exentos),
    static fn(string $x): bool => str_contains($ctrl, "function $x("));
$af('la lista de exentos no tiene métodos fantasma',
    count($vivos) === count($exentos),
    count($exentos) - count($vivos) . ' ya no existen');

/*==================================================================
| 2. La comprobación en ejecución
|==================================================================*/
$pedir = function (string $url, string $sid): array {
    $ctx = stream_context_create(['http' => [
        'header' => "Cookie: DigiSportsBasketball=$sid\r\n",
        'timeout' => 90, 'ignore_errors' => true,
    ]]);
    $cuerpo = @file_get_contents($url, false, $ctx);
    $codigo = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $mm)) { $codigo = (int) $mm[1]; }
    }
    return [$codigo, (string) $cuerpo];
};

$BASE  = 'http://localhost/barcelona/ds_insights/';
$admin = 'dsqaui0000000000000';

/* El usuario de prueba y su ámbito salen de la base, no de esta prueba: si
   mañana se le cambian las sedes, la prueba sigue midiendo lo correcto. */
$lim = $db->query(
    "SELECT u.usuario_id, u.usuario_rolid
       FROM seguridad_usuario u
       JOIN seguridad_usuario_sede us ON us.usuariosede_usuarioid = u.usuario_id
      WHERE u.usuario_rolid <> 1
      GROUP BY u.usuario_id, u.usuario_rolid
      ORDER BY COUNT(*) DESC, u.usuario_id DESC
      LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$lim) {
    $af('hay algún usuario limitado con el que probar', false,
        'sin filas en seguridad_usuario_sede');
    echo "\nfallos: $fallos\n";
    exit(1);
}

$uid = (int) $lim['usuario_id'];
$rol = (int) $lim['usuario_rolid'];

$suyas = array_map('intval', array_column($db->query(
    "SELECT usuariosede_sedeid FROM seguridad_usuario_sede
      WHERE usuariosede_usuarioid = $uid")->fetchAll(PDO::FETCH_ASSOC), 'usuariosede_sedeid'));

$todas = [];
foreach ($db->query("SELECT sede_id, sede_nombre FROM general_sede")->fetchAll(PDO::FETCH_ASSOC) as $s) {
    $todas[(int) $s['sede_id']] = $s['sede_nombre'];
}
$ajenas = array_diff_key($todas, array_flip($suyas));

$af('usuario limitado localizado en la base', true,
    "usuario $uid, rol $rol, sedes " . implode(',', $suyas));

/*----------  Permisos prestados, y devueltos al final  ----------*/
$teniaModulo = (int) $db->query(
    "SELECT COUNT(*) FROM seguridad_rol_modulo
      WHERE rolmod_rolid = $rol AND rolmod_modulo = 'insights'")->fetchColumn();

if ($teniaModulo === 0) {
    $db->exec("INSERT INTO seguridad_rol_modulo (rolmod_rolid, rolmod_modulo, rolmod_estado)
               VALUES ($rol, 'insights', 'A')");
}

$menus = $db->query(
    "SELECT menu_id FROM seguridad_menu WHERE menu_modulo = 'insights'")->fetchAll(PDO::FETCH_COLUMN);
$prestados = [];
foreach ($menus as $mid) {
    $ya = (int) $db->query(
        "SELECT COUNT(*) FROM seguridad_permiso
          WHERE permiso_rolid = $rol AND permiso_menuid = $mid")->fetchColumn();
    if ($ya === 0) {
        $db->exec("INSERT INTO seguridad_permiso
                     (permiso_rolid, permiso_menuid, permiso_ver, permiso_crear,
                      permiso_editar, permiso_eliminar, permiso_exportar, permiso_estado)
                   VALUES ($rol, $mid, 'S', 'N', 'N', 'N', 'S', 'A')");
        $prestados[] = (int) $mid;
    }
}

$sid = 'dsqasede00000000000';
exec(sprintf('%s %s %s Limitado %d %d "QA sede" 0 2>&1',
    escapeshellarg(PHP_BINARY), escapeshellarg(__DIR__ . '/sesion_qa.php'),
    escapeshellarg($sid), $uid, $rol));

/*----------  a) La exportación de «Ingresos por sede»  ----------*/
/*
| Primero con el superadministrador: si el informe no trae sedes ajenas, la
| comprobación siguiente no probaría nada.
*/
[$c1, $csvAdmin] = $pedir($BASE . 'exportar/?reporte=financiero&formato=csv', $admin);
[$c2, $csvLim]   = $pedir($BASE . 'exportar/?reporte=financiero&formato=csv', $sid);

$ajenasEnAdmin = array_values(array_filter($ajenas,
    static fn(string $nombre): bool => str_contains($csvAdmin, $nombre)));

$af('el informe sin límite SÍ trae sedes ajenas',
    $ajenasEnAdmin !== [],
    $ajenasEnAdmin ? implode(', ', $ajenasEnAdmin) : 'si no, la prueba siguiente no mide nada');

$ajenasEnLim = array_values(array_filter($ajenas,
    static fn(string $nombre): bool => str_contains($csvLim, $nombre)));

$af('el informe del usuario limitado NO trae sedes ajenas',
    $c2 === 200 && $ajenasEnLim === [],
    $ajenasEnLim ? 'se filtraron: ' . implode(', ', $ajenasEnLim) : "HTTP $c2");

/*----------  b) Y el importe cuadra con la suma de SUS sedes  ----------*/
$enLista = implode(',', $suyas);
$mes = date('Y-m-01');
$fin = date('Y-m-t');

$esperado = (float) $db->query(
    "SELECT IFNULL(SUM(pago_valor),0) FROM alumno_pago
      WHERE pago_estado = 'C' AND pago_fecha BETWEEN '$mes' AND '$fin'
        AND pago_sedeid IN ($enLista)")->fetchColumn();

/* La última columna de cada fila de datos es el total de la sede. */
$sumaCsv = 0.0;
foreach (explode("\n", str_replace("\r", '', $csvLim)) as $linea) {
    $cols = str_getcsv($linea, ';', '"', '\\');
    if (count($cols) < 3) { continue; }
    if (!isset($todas[array_search($cols[0], $todas, true)])
        && !in_array($cols[0], $todas, true)) { continue; }
    /* Basketball es la segunda columna del informe. */
    $sumaCsv += (float) str_replace(',', '.', (string) ($cols[1] ?? '0'));
}

$af('el importe del informe cuadra con la suma de sus sedes',
    abs($sumaCsv - $esperado) < 0.01,
    sprintf('informe %.2f · SQL %.2f', $sumaCsv, $esperado));

/*----------  c) Y en pantalla, no sólo en el archivo  ----------*/
[$c3, $htmlAdmin] = $pedir($BASE . 'financiero/', $admin);
[$c4, $htmlLim]   = $pedir($BASE . 'financiero/', $sid);

$enPantallaAdmin = array_values(array_filter($ajenas,
    static fn(string $nombre): bool => str_contains($htmlAdmin, $nombre)));
$enPantallaLim = array_values(array_filter($ajenas,
    static fn(string $nombre): bool => str_contains($htmlLim, $nombre)));

$af('la pantalla sin límite SÍ muestra sedes ajenas',
    $enPantallaAdmin !== [], implode(', ', array_slice($enPantallaAdmin, 0, 3)));

$af('la pantalla del usuario limitado NO las muestra',
    $c4 === 200 && $enPantallaLim === [],
    $enPantallaLim ? 'se filtraron: ' . implode(', ', $enPantallaLim) : "HTTP $c4");

/*----------  d) Y los alumnos son sólo los suyos  ----------*/
$alumnosSuyos = (int) $db->query(
    "SELECT COUNT(*) FROM sujeto_alumno
      WHERE alumno_estado = 'A' AND alumno_sedeid IN ($enLista)")->fetchColumn();
$alumnosTodos = (int) $db->query(
    "SELECT COUNT(*) FROM sujeto_alumno WHERE alumno_estado = 'A'")->fetchColumn();

[$c5, $htmlBk] = $pedir($BASE . 'basketball/', $sid);

$af('la vista de Basketball cuenta sólo sus alumnos',
    str_contains($htmlBk, (string) $alumnosSuyos) && !str_contains($htmlBk, (string) $alumnosTodos),
    "suyos $alumnosSuyos · todos $alumnosTodos");

/*----------  Se devuelve lo prestado  ----------*/
foreach ($prestados as $mid) {
    $db->exec("DELETE FROM seguridad_permiso
                WHERE permiso_rolid = $rol AND permiso_menuid = $mid");
}
if ($teniaModulo === 0) {
    $db->exec("DELETE FROM seguridad_rol_modulo
                WHERE rolmod_rolid = $rol AND rolmod_modulo = 'insights'");
}

$quedan = (int) $db->query(
    "SELECT COUNT(*) FROM seguridad_rol_modulo
      WHERE rolmod_rolid = $rol AND rolmod_modulo = 'insights'")->fetchColumn();

$af('la prueba devuelve los permisos que pidió prestados',
    $quedan === $teniaModulo, "módulo: $quedan (antes $teniaModulo)");

echo "\nfallos: $fallos\n";
exit($fallos > 0 ? 1 : 0);
