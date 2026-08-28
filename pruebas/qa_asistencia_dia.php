<?php
/*
|--------------------------------------------------------------------------
| La vista de asistencia dice exactamente lo mismo que la tabla pivotada
|--------------------------------------------------------------------------
| asistencia_asistencia guarda una fila por alumno y MES con 31 columnas
| D01..D31 y ninguna fecha. insights_v_asistencia_dia la despivota: un día
| por fila, con la fecha reconstruida.
|
| Una vista que despivota tiene una forma silenciosa de fallar: perder
| marcas o inventarlas, y seguir devolviendo cifras de aspecto razonable.
| Nadie lo notaría hasta que un informe de asistencia no cuadrase con el
| calendario de un alumno, meses después.
|
| Por eso esta suite no comprueba que la vista funcione: comprueba que
| **coincida con el origen, marca por marca**.
|
|
| LA REGLA DE NEGOCIO NO ESTA EN LA VISTA, Y ES DELIBERADO
|
| La vista entrega la marca cruda. Quien consulta decide:
|
|     % asistencia = (P + A) / total       el justificado es falta
|     % avisadas   = J / (F + J)           indicador propio
|
| El justificado cuenta como inasistencia porque el alumno no fue. Que el
| representante avisara es otra cosa —y con los datos de hoy, sólo el
| 26,6 % de las inasistencias se avisaron—, así que merece su propio
| indicador en vez de disolverse dentro del porcentaje de asistencia.
*/

require_once __DIR__ . '/conexion.php';

$fallos = 0;
$af = function (string $texto, bool $ok, string $detalle = '') use (&$fallos): void {
    printf("  %-56s %s%s\n", $texto, $ok ? 'OK' : 'FALLA', $detalle !== '' ? "  ($detalle)" : '');
    if (!$ok) { $fallos++; }
};

$db = qa_conexion();

/*==============  Existe  ==============*/
$hay = (int) $db->query(
    "SELECT COUNT(*) FROM information_schema.VIEWS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'insights_v_asistencia_dia'")->fetchColumn();
$af('existe la vista insights_v_asistencia_dia', $hay === 1);
if ($hay !== 1) { echo "\nfallos: $fallos\n"; exit(1); }

/*==============  Fidelidad: ni una marca de mas ni de menos  ==============*/
/*
| Se cuenta directamente sobre las 31 columnas del origen y se compara con
| la vista. Es la comprobacion que importa: si la vista perdiera una rama
| del UNION —olvidar el dia 31, por ejemplo— seguiria devolviendo cifras
| creibles y nadie lo notaria.
*/
$ramas = [];
for ($d = 1; $d <= 31; $d++) {
    $ramas[] = sprintf(
        "SELECT asistencia_D%02d v,
                STR_TO_DATE(CONCAT(asistencia_aniomes, '%02d'), '%%Y%%m%%d') f
           FROM asistencia_asistencia WHERE asistencia_D%02d IS NOT NULL", $d, $d, $d);
}
$origen = $db->query(
    "SELECT COUNT(*) t, SUM(v='P') p, SUM(v='F') f, SUM(v='J') j, SUM(v='A') a
       FROM (" . implode(' UNION ALL ', $ramas) . ") x
      WHERE x.f IS NOT NULL")->fetch(PDO::FETCH_ASSOC);

$vista = $db->query(
    "SELECT COUNT(*) t, SUM(dia_marca='P') p, SUM(dia_marca='F') f,
            SUM(dia_marca='J') j, SUM(dia_marca='A') a
       FROM insights_v_asistencia_dia")->fetch(PDO::FETCH_ASSOC);

$af('el total de marcas coincide con el origen',
    (int) $origen['t'] === (int) $vista['t'],
    "origen {$origen['t']} · vista {$vista['t']}");

foreach (['p' => 'presentes', 'f' => 'faltas', 'j' => 'justificadas', 'a' => 'atrasos'] as $k => $nombre) {
    $af("coinciden las $nombre",
        (int) $origen[$k] === (int) $vista[$k],
        "origen {$origen[$k]} · vista {$vista[$k]}");
}

/*==============  Ninguna marca sin fecha  ==============*/
/*
| Todas las filas tienen las 31 columnas, tenga el mes 28 dias o 31.
| STR_TO_DATE('20260230','%Y%m%d') devuelve NULL, no un error, asi que sin
| el filtro final esas marcas entrarian sin fecha y descuadrarian cualquier
| conteo por periodo.
|
| Hoy no hay ninguna: es una precaucion por la forma de la tabla, no por un
| caso activo. Que hoy valga cero no la hace innecesaria; la hace barata.
*/
$sinFecha = (int) $db->query(
    "SELECT COUNT(*) FROM insights_v_asistencia_dia WHERE dia_fecha IS NULL")->fetchColumn();
$af('ninguna marca queda sin fecha', $sinFecha === 0, "$sinFecha filas");

/*==============  La fecha se reconstruye bien  ==============*/
/*
| Se toma una fila real del origen, se busca su primera columna con marca y
| se comprueba que la vista la coloca en el dia que toca. Sin esto, un
| desfase de un dia —empezar en D00, o usar el indice del bucle en vez del
| numero de columna— pasaria inadvertido: los totales seguirian cuadrando.
*/
/* El dia se BUSCA, no se supone: fijar el 15 hacia fallar la prueba
   cuando ese dia no tenia clase, que es justo lo que paso al escribirla. */
$fila = null; $dia = null;
for ($d = 1; $d <= 31 && $fila === null; $d++) {
    $q = $db->query(sprintf(
        "SELECT asistencia_alumnoid a, asistencia_aniomes m, asistencia_D%02d v
           FROM asistencia_asistencia WHERE asistencia_D%02d IS NOT NULL LIMIT 1", $d, $d));
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if ($r) { $fila = $r; $dia = $d; }
}

if ($fila) {
    $esperada = sprintf('%s-%s-%02d',
        substr((string) $fila['m'], 0, 4), substr((string) $fila['m'], 4, 2), $dia);
    $sql = $db->prepare(
        "SELECT dia_marca FROM insights_v_asistencia_dia
          WHERE dia_alumnoid = :a AND dia_fecha = :f");
    $sql->execute([':a' => $fila['a'], ':f' => $esperada]);
    $marca = $sql->fetchColumn();
    $af("el dia $dia aterriza en el dia $dia del mes correcto",
        $marca === $fila['v'],
        "esperado {$fila['v']} en $esperada, hallado " . ($marca === false ? 'nada' : $marca));
} else {
    $af('hay datos para comprobar la reconstruccion de la fecha', false, 'ninguna columna con marcas');
}

/*==============  Las dos formulas del negocio  ==============*/
/* No se afirma un valor concreto —los datos cambian— sino que las dos
   formulas son calculables y coherentes entre si. */
$r = $db->query(
    "SELECT COUNT(*) t, SUM(dia_marca IN ('P','A')) asis,
            SUM(dia_marca IN ('F','J')) inas, SUM(dia_marca='J') avis
       FROM insights_v_asistencia_dia")->fetch(PDO::FETCH_ASSOC);

$af('asistencias e inasistencias suman el total',
    (int) $r['asis'] + (int) $r['inas'] === (int) $r['t'],
    "{$r['asis']} + {$r['inas']} = {$r['t']}");

$af('las avisadas no superan a las inasistencias',
    (int) $r['avis'] <= (int) $r['inas'],
    "{$r['avis']} de {$r['inas']}");

if ((int) $r['t'] > 0) {
    printf("\n  asistencia %.1f %% · inasistencias avisadas %.1f %%\n",
        $r['asis'] / $r['t'] * 100,
        (int) $r['inas'] > 0 ? $r['avis'] / $r['inas'] * 100 : 0);
}

echo "\nfallos: $fallos\n";
exit($fallos > 0 ? 1 : 0);
