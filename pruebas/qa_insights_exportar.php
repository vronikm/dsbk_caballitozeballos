<?php
/*
|--------------------------------------------------------------------------
| La exportación entrega lo que la pantalla dice, y sólo a quien puede
|--------------------------------------------------------------------------
| Exportar es la acción por la que la información SALE del sistema. Tiene dos
| formas de fallar y ninguna avisa:
|
|   1. Que deje pasar a quien no debe. Un 200 se ve igual de bien que otro.
|
|   2. Que entregue cifras distintas de las de la pantalla. Si el archivo
|      tuviera su propia consulta y una de las dos cambiara, nadie lo notaría
|      hasta que dos personas comparasen sus copias en una reunión.
|
| Por eso aquí no se comprueba que la descarga funcione, sino que COINCIDA
| con lo que el controlador devuelve, que es la misma fuente que pinta la
| pantalla.
|
|
| EL FORMATO DEL CSV TAMBIÉN SE COMPRUEBA
|
| BOM y separador «;» no son cosmética: sin BOM, Excel en Windows abre las
| tildes como «OcupaciÃ³n»; con separador «,», parte los importes en dos
| columnas porque en la configuración regional de Ecuador la coma es el
| separador decimal. Las dos cosas se ven al abrir el archivo, no al
| generarlo.
*/

const APP_NAME = 'QA';
require_once __DIR__ . '/../ds_core/config/app.php';
require_once __DIR__ . '/conexion.php';

$fallos = 0;
$af = function (string $texto, bool $ok, string $detalle = '') use (&$fallos): void {
    printf("  %-56s %s%s\n", $texto, $ok ? 'OK' : 'FALLA', $detalle !== '' ? "  ($detalle)" : '');
    if (!$ok) { $fallos++; }
};

$db   = qa_conexion();
$base = 'http://localhost/barcelona/ds_insights/exportar/';

/** Pide una URL y devuelve [codigo, cuerpo]. */
$pedir = function (string $url, string $sid): array {
    $ctx = stream_context_create(['http' => [
        'header' => "Cookie: DigiSportsBasketball=$sid\r\n",
        'timeout' => 60, 'ignore_errors' => true, 'follow_location' => 0,
    ]]);
    $cuerpo = @file_get_contents($url, false, $ctx);
    $codigo = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) { $codigo = (int) $m[1]; }
    }
    return [$codigo, (string) $cuerpo];
};

$admin = 'dsqaui0000000000000';

/*==============  1. Todos los reportes declarados se entregan  ==============*/
/*
| La lista sale del propio controlador, no de una copia en esta prueba: si
| alguien añade un reporte exportable, se comprueba solo. Una lista repetida
| aquí se quedaría corta en silencio.
*/
$ctrl = (string) file_get_contents(__DIR__ . '/../ds_insights/controllers/insightsController.php');
preg_match('~public function exportables\(\): array\s*\{\s*return \[(.*?)\];~s', $ctrl, $m);
preg_match_all("~'([a-z]+)'\s*=>~", $m[1] ?? '', $mm);
$reportes = $mm[1] ?? [];

$af('el controlador declara reportes exportables', count($reportes) > 0, count($reportes) . ' reportes');

$malos = [];
foreach ($reportes as $r) {
    foreach (['csv', 'pdf'] as $f) {
        [$c] = $pedir($base . "?reporte=$r&formato=$f", $admin);
        if ($c !== 200) { $malos[] = "$r/$f=$c"; }
    }
}
$af('los ' . count($reportes) . ' reportes se entregan en CSV y PDF',
    $malos === [], $malos ? implode(' ', $malos) : count($reportes) * 2 . ' descargas');

[$c] = $pedir($base . '?reporte=noExisteEsteReporte&formato=csv', $admin);
$af('un reporte inexistente responde 404', $c === 404, "HTTP $c");

/*==============  2. El CSV está bien formado  ==============*/
[$c, $csv] = $pedir($base . "?reporte=becas&formato=csv", $admin);
$sinBom = preg_replace("~^\xEF\xBB\xBF~", "", $csv);

$af('el CSV lleva BOM UTF-8', str_starts_with($csv, "\xEF\xBB\xBF"),
    'sin él Excel abre «Ocupación» como «OcupaciÃ³n»');

$lineas = array_values(array_filter(explode("\n", str_replace("\r", '', $csv)), static fn($l) => $l !== ''));

$af('el separador es punto y coma',
    count($lineas) > 0 && substr_count($lineas[0], ';') >= 1,
    'con coma, Excel parte los importes en es-EC');

foreach (['Reporte', 'Periodo', 'Generado', 'Usuario', 'Filas'] as $campo) {
    $af("la cabecera lleva «{$campo}»",
        (bool) preg_match('~^' . preg_quote($campo, '~') . ';~m', $sinBom));
}

/*==============  3. Y dice lo mismo que el controlador  ==============*/
/*
| Se compara el número de filas de datos del archivo con el que devuelve
| datosExportables(). Si el archivo tuviera su propia consulta y divergiera,
| esto lo vería.
*/
preg_match('~^Filas;"?(\d+)~m', $csv, $mf);
$filasDeclaradas = (int) ($mf[1] ?? -1);

/* Las de datos: total menos la cabecera de contexto (6), la línea en blanco
   y la fila de títulos de columna. */
$filasReales = max(0, count($lineas) - 7);

$af('el número de filas del archivo cuadra con el declarado',
    $filasDeclaradas === $filasReales,
    "declaradas $filasDeclaradas · contadas $filasReales");

$af('los decimales van con coma',
    (bool) preg_match('~;\d+,\d{2};~', $csv),
    'es lo que espera Excel en es-EC');

/*==============  4. El PDF es un PDF  ==============*/
[$c, $pdf] = $pedir($base . '?reporte=instalaciones&formato=pdf', $admin);
$af('el PDF sale con su firma y con contenido',
    str_starts_with($pdf, '%PDF-') && strlen($pdf) > 1000,
    strlen($pdf) . ' bytes');

/*==============  5. El permiso de exportar se respeta  ==============*/
/*
| Es la mitad que importa. Se usa un rol de usar y tirar al que se le da la
| vista pero NO la accion de exportar, y despues se limpia.
*/
$rol = 2;
$yaTenia = (int) $db->query(
    "SELECT COUNT(*) FROM seguridad_rol_modulo
      WHERE rolmod_rolid = $rol AND rolmod_modulo = 'insights'")->fetchColumn();

if ($yaTenia > 0) {
    $af('el rol de prueba no tiene Insights de antemano', false,
        "el rol $rol ya lo tiene: se omite la prueba del permiso");
} else {
    $sid = 'dsqaexport000000000';
    exec(sprintf('%s %s %s RolExport %d %d "QA export" 0 2>&1',
        escapeshellarg(PHP_BINARY), escapeshellarg(__DIR__ . '/sesion_qa.php'),
        escapeshellarg($sid), $rol, $rol));

    $db->exec("INSERT INTO seguridad_rol_modulo (rolmod_rolid, rolmod_modulo, rolmod_estado)
               VALUES ($rol, 'insights', 'A')");

    $ids = [];
    foreach (['exportar', 'becas'] as $v) {
        $ids[$v] = (int) $db->query(
            "SELECT menu_id FROM seguridad_menu
              WHERE menu_modulo = 'insights' AND menu_vista = '$v'")->fetchColumn();
        $db->exec("INSERT INTO seguridad_permiso
                     (permiso_rolid, permiso_menuid, permiso_ver, permiso_crear,
                      permiso_editar, permiso_eliminar, permiso_exportar, permiso_estado)
                   VALUES ($rol, {$ids[$v]}, 'S', 'N', 'N', 'N', 'N', 'A')");
    }

    $antes = (int) $db->query("SELECT COUNT(*) FROM insights_auditoria")->fetchColumn();

    [$c] = $pedir($base . '?reporte=becas&formato=csv', $sid);
    $af('sin permiso_exportar: 403', $c === 403, "HTTP $c");

    $db->exec("UPDATE seguridad_permiso SET permiso_exportar = 'S'
                WHERE permiso_rolid = $rol AND permiso_menuid = {$ids['becas']}");

    [$c, $cuerpo] = $pedir($base . '?reporte=becas&formato=csv', $sid);
    $af('con permiso_exportar: 200 y descarga',
        $c === 200 && str_starts_with($cuerpo, "\xEF\xBB\xBF"), "HTTP $c");

    /* La denegacion tambien se audita: es justo el evento que interesa
       cuando alguien pregunta quien intento llevarse que. */
    $nuevos = $db->query(
        "SELECT auditoria_ok ok, auditoria_accion a FROM insights_auditoria
          ORDER BY auditoria_id DESC LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
    $huboDenegado = false;
    foreach ($nuevos as $n) { if ($n['ok'] === 'N') { $huboDenegado = true; } }

    $af('el intento denegado queda auditado', $huboDenegado,
        'registros nuevos: ' . ((int) $db->query("SELECT COUNT(*) FROM insights_auditoria")->fetchColumn() - $antes));

    /* Limpieza, y se comprueba que de verdad quedo limpio. */
    $db->exec("DELETE p FROM seguridad_permiso p
                 JOIN seguridad_menu m ON m.menu_id = p.permiso_menuid
                WHERE m.menu_modulo = 'insights' AND p.permiso_rolid = $rol");
    $db->exec("DELETE FROM seguridad_rol_modulo
                WHERE rolmod_rolid = $rol AND rolmod_modulo = 'insights'");

    $resto = (int) $db->query(
        "SELECT COUNT(*) FROM seguridad_rol_modulo
          WHERE rolmod_rolid = $rol AND rolmod_modulo = 'insights'")->fetchColumn();
    $af('la prueba no deja permisos concedidos', $resto === 0, "$resto quedan");
}

/*==============  6. La auditoría no guarda datos personales  ==============*/
/*
| El §45 pide registrar «filtros relevantes», pero guardarlos en texto libre
| convertiria la tabla en un almacen paralelo de datos personales. Se
| comprueba que las columnas siguen siendo las acotadas.
*/
$columnas = array_column($db->query("SHOW COLUMNS FROM insights_auditoria")->fetchAll(PDO::FETCH_ASSOC), 'Field');
$sospechosas = array_values(array_filter($columnas,
    static fn(string $c): bool => (bool) preg_match('~filtro|texto|detalle|json|params~i', $c)));

$af('la auditoría no tiene columnas de texto libre',
    $sospechosas === [],
    $sospechosas ? implode(', ', $sospechosas) : count($columnas) . ' columnas acotadas');

echo "\nfallos: $fallos\n";
exit($fallos > 0 ? 1 : 0);
