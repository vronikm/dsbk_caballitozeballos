<?php
/*
| Limpieza de los datos de prueba de League.
|
| Borra SOLO lo que crearon las suites QA (sellos QF y QC) y lo hace
| resolviendo los ids primero, para poder enseñarlos antes de tocar nada.
|
| NO se toca dsl_auditoria: es un registro de lo que ocurrió, y borrar sus
| filas porque la entidad ya no existe es justo lo contrario de para lo
| que sirve una bitácora.
|
| Ejecutar con "listar" para ver qué se iría; con "borrar" para hacerlo.
*/
require "c:/wamp64/www/barcelona/ds_league/config/app.php";

$modo = $argv[1] ?? 'listar';

require_once __DIR__ . '/conexion.php';
$c = qa_conexion();

$sellos = "(temporada_nombre LIKE 'QF%' OR temporada_nombre LIKE 'QC%'
            OR temporada_nombre LIKE 'QK%')";

$ids = static function (PDO $c, string $sql): array {
    return array_map('intval', $c->query($sql)->fetchAll(PDO::FETCH_COLUMN));
};
$lista = static fn(array $a) => $a ? implode(',', $a) : '0';

$temporadas  = $ids($c, "SELECT temporada_id FROM dsl_temporada WHERE {$sellos}");
$torneos     = $ids($c, "SELECT torneo_id FROM dsl_torneo
                          WHERE torneo_temporadaid IN (" . $lista($temporadas) . ")");
$categorias  = $ids($c, "SELECT categoria_id FROM dsl_categoria
                          WHERE categoria_torneoid IN (" . $lista($torneos) . ")");
$inscrip     = $ids($c, "SELECT inscripcion_id FROM dsl_inscripcion
                          WHERE inscripcion_categoriaid IN (" . $lista($categorias) . ")");
$fases       = $ids($c, "SELECT fase_id FROM dsl_fase
                          WHERE fase_categoriaid IN (" . $lista($categorias) . ")");
$plantillas  = $ids($c, "SELECT plantilla_id FROM dsl_plantilla
                          WHERE plantilla_inscripcionid IN (" . $lista($inscrip) . ")");
$personas    = $ids($c, "SELECT DISTINCT plantilla_personaid FROM dsl_plantilla
                          WHERE plantilla_id IN (" . $lista($plantillas) . ")");
$equipos     = $ids($c, "SELECT equipo_id FROM dsl_equipo
                          WHERE equipo_nombre LIKE 'QF%' OR equipo_nombre LIKE 'QC%'
                             OR equipo_nombre LIKE 'QK%'");
$conceptos   = $ids($c, "SELECT concepto_id FROM dsl_concepto
                          WHERE concepto_codigo LIKE 'QF%' OR concepto_codigo LIKE 'QC%'
                             OR concepto_codigo LIKE 'QK%'");
$obligac     = $ids($c, "SELECT obligacion_id FROM dsl_obligacion
                          WHERE obligacion_conceptoid IN (" . $lista($conceptos) . ")
                             OR obligacion_categoriaid IN (" . $lista($categorias) . ")");

$facturasIds = $ids($c, "SELECT DISTINCT obligacion_facturaid FROM dsl_obligacion
                          WHERE obligacion_facturaid IS NOT NULL
                            AND obligacion_id IN (" . $lista($obligac) . ")");
$facturas = $lista($facturasIds);

printf("temporadas  %2d  %s\n", count($temporadas), $lista($temporadas));
printf("torneos     %2d  %s\n", count($torneos),    $lista($torneos));
printf("categorías  %2d  %s\n", count($categorias), $lista($categorias));
printf("fases       %2d  %s\n", count($fases),      $lista($fases));
printf("inscripc.   %2d  %s\n", count($inscrip),    $lista($inscrip));
printf("plantillas  %2d  %s\n", count($plantillas), $lista($plantillas));
printf("personas    %2d  %s\n", count($personas),   $lista($personas));
printf("equipos     %2d  %s\n", count($equipos),    $lista($equipos));
printf("conceptos   %2d  %s\n", count($conceptos),  $lista($conceptos));
printf("obligac.    %2d  %s\n", count($obligac),    $lista($obligac));
printf("facturas    %2d  %s\n", count($facturasIds), $facturas);

/* Comprobación explícita: lo del usuario NO puede estar en las listas. */
$intocables = ['temporada' => [18 => $temporadas], 'equipo' => [159 => $equipos],
               'persona'   => [11 => $personas]];
foreach ($intocables as $que => $par) {
    foreach ($par as $id => $en) {
        if (in_array($id, $en, true)) {
            fwrite(STDERR, "ABORTA: el {$que} {$id} es del usuario y está en la lista\n");
            exit(1);
        }
    }
}
echo "\nlos datos del usuario (temporada 18, equipo 159, persona 11) quedan fuera\n";

if ($modo !== 'borrar') { echo "\n(modo listar; nada se ha borrado)\n"; exit; }

$c->beginTransaction();
try {
    $borra = static function (PDO $c, string $sql) {
        $n = $c->exec($sql);
        echo "  " . str_pad((string)$n, 4, ' ', STR_PAD_LEFT) . "  " . $sql . "\n";
    };

    /* Los comprobantes de prueba, antes que las obligaciones: la FK
       obligacion_facturaid apunta a dsl_factura y borrar al revés aborta.
       Se sueltan primero los enlaces, luego se borran las facturas. */
    $borra($c, "UPDATE dsl_obligacion SET obligacion_facturaid = NULL
                 WHERE obligacion_id IN (" . $lista($obligac) . ")");
    $borra($c, "DELETE FROM dsl_factura_detalle WHERE detalle_facturaid IN ({$facturas})");
    $borra($c, "DELETE FROM dsl_factura_pago    WHERE pago_facturaid IN ({$facturas})");
    $borra($c, "DELETE FROM dsl_factura         WHERE factura_id IN ({$facturas})");

    $borra($c, "DELETE FROM dsl_abono      WHERE abono_obligacionid IN (" . $lista($obligac) . ")");
    $borra($c, "DELETE FROM dsl_obligacion WHERE obligacion_id IN (" . $lista($obligac) . ")");
    $borra($c, "DELETE FROM dsl_concepto   WHERE concepto_id IN (" . $lista($conceptos) . ")");

    /* Los partidos primero: cuelgan de ellos las estadísticas y las
       designaciones, y una FK sin CASCADE aborta la transacción entera si
       se intenta al revés. */
    $partidos = $lista($ids($c, "SELECT partido_id FROM dsl_partido
                                  WHERE partido_faseid IN (" . $lista($fases) . ")"));
    $borra($c, "DELETE FROM dsl_partido_stat WHERE stat_partidoid IN ({$partidos})");
    $borra($c, "DELETE FROM dsl_designacion  WHERE designacion_partidoid IN ({$partidos})");
    $borra($c, "DELETE FROM dsl_partido    WHERE partido_faseid IN (" . $lista($fases) . ")");

    $sorteos = $lista($ids($c, "SELECT sorteo_id FROM dsl_sorteo
                                 WHERE sorteo_faseid IN (" . $lista($fases) . ")"));
    $borra($c, "DELETE FROM dsl_sorteo_resultado   WHERE resultado_sorteoid IN ({$sorteos})");
    $borra($c, "DELETE FROM dsl_sorteo_restriccion WHERE restriccion_sorteoid IN ({$sorteos})");
    $borra($c, "DELETE FROM dsl_sorteo_bombo       WHERE bombo_sorteoid IN ({$sorteos})");
    $borra($c, "DELETE FROM dsl_sorteo     WHERE sorteo_faseid IN (" . $lista($fases) . ")");

    $borra($c, "DELETE FROM dsl_serie      WHERE serie_faseid IN (" . $lista($fases) . ")");
    $borra($c, "DELETE FROM dsl_jornada    WHERE jornada_faseid IN (" . $lista($fases) . ")");
    $borra($c, "DELETE FROM dsl_grupo_equipo WHERE ge_grupoid IN
                    (SELECT grupo_id FROM dsl_grupo WHERE grupo_faseid IN (" . $lista($fases) . "))");
    $borra($c, "DELETE FROM dsl_grupo      WHERE grupo_faseid IN (" . $lista($fases) . ")");
    $borra($c, "DELETE FROM dsl_fase       WHERE fase_id IN (" . $lista($fases) . ")");

    $borra($c, "DELETE FROM dsl_plantilla  WHERE plantilla_id IN (" . $lista($plantillas) . ")");
    $borra($c, "DELETE FROM dsl_persona    WHERE persona_id IN (" . $lista($personas) . ")");
    $borra($c, "DELETE FROM dsl_inscripcion WHERE inscripcion_id IN (" . $lista($inscrip) . ")");
    $borra($c, "DELETE FROM dsl_categoria  WHERE categoria_id IN (" . $lista($categorias) . ")");
    $borra($c, "DELETE FROM dsl_torneo     WHERE torneo_id IN (" . $lista($torneos) . ")");
    $borra($c, "DELETE FROM dsl_temporada  WHERE temporada_id IN (" . $lista($temporadas) . ")");
    $borra($c, "DELETE FROM dsl_equipo     WHERE equipo_id IN (" . $lista($equipos) . ")");

    $c->commit();
    echo "\nlimpio\n";
} catch (Throwable $e) {
    $c->rollBack();
    fwrite(STDERR, "ROLLBACK: " . $e->getMessage() . "\n");
    exit(1);
}
