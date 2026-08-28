<?php
/*
|--------------------------------------------------------------------------
| Insights no puede escribir fuera de sus propias tablas
|--------------------------------------------------------------------------
| El encargo pide que Insights consulte y no modifique datos de Basketball,
| Arena ni League. Con la conexión compartida eso era sólo una intención: el
| usuario de MySQL tiene escritura sobre las 93 tablas.
|
| InsightsConexion lo convierte en mecanismo, rechazando por el texto de la
| sentencia. Esta suite vigila que el candado siga cerrado Y que no estorbe
| al trabajo legítimo, que es la mitad que suele romperse: un candado
| demasiado apretado se acaba quitando.
|
| LO QUE ESTA SUITE NO PUEDE AFIRMAR
|
| Que Insights sea incapaz de escribir. Esto inspecciona texto, no lo impone
| el motor. La garantía fuerte sigue siendo un usuario de MySQL con SELECT
| sobre las tablas ajenas: una sentencia GRANT que no cambia una línea de
| código. Mientras no exista, esto es una barrera real pero no inviolable, y
| conviene no confundir las dos cosas.
*/

const APP_NAME = 'QA';
require_once __DIR__ . '/../ds_core/config/app.php';
require_once __DIR__ . '/../ds_insights/config/conexion.php';

$fallos = 0;
$af = function (string $texto, bool $ok, string $detalle = '') use (&$fallos): void {
    printf("  %-58s %s%s\n", $texto, $ok ? 'OK' : 'FALLA', $detalle !== '' ? "  ($detalle)" : '');
    if (!$ok) { $fallos++; }
};

$db = InsightsConexion::abrir();

/*
| Cada caso dice si la sentencia DEBE pasar. Las que deben pasar importan
| tanto como las que no: sin ellas, apretar el candado hasta romper el
| módulo pasaría por mejora.
*/
$casos = [
    /* ---- Lectura: siempre pasa ---- */
    ['lee de Basketball',            'SELECT COUNT(*) FROM alumno_pago', true],
    ['lee de Arena',                 'SELECT SUM(reserva_total) FROM dsa_reserva', true],
    ['lee de League',                'SELECT COUNT(*) FROM dsl_partido', true],
    ['describe una tabla',           'SHOW COLUMNS FROM dsl_partido', true],
    ['analiza un plan',              'EXPLAIN SELECT 1', true],
    ['usa una CTE de lectura',       'WITH t AS (SELECT 1 n) SELECT n FROM t', true],

    /* ---- Escritura en tablas propias: pasa ---- */
    ['escribe en su propia tabla',
     "INSERT INTO insights_cartera_snapshot (snapshot_periodo) VALUES ('x')", true],
    ['actualiza su propia tabla',
     'UPDATE insights_cartera_snapshot SET snapshot_valor = 1', true],
    /*
    | Este caso existe por un fallo real. El candado tomaba «UPDATE
    | snapshot_valor» del ON DUPLICATE KEY por una tabla ajena y rechazaba
    | la escritura legítima de Insights. Apareció en el primer uso real del
    | capturador, no en la prueba de laboratorio.
    */
    ['admite ON DUPLICATE KEY UPDATE',
     "INSERT INTO insights_cartera_snapshot (snapshot_periodo) VALUES ('x')
      ON DUPLICATE KEY UPDATE snapshot_valor = VALUES(snapshot_valor)", true],

    /* ---- Escritura en tablas ajenas: se rechaza ---- */
    ['no actualiza pagos',           'UPDATE alumno_pago SET pago_valor = 0', false],
    ['no inserta reservas',          "INSERT INTO dsa_reserva (reserva_codigo) VALUES ('x')", false],
    ['no borra alumnos',             'DELETE FROM sujeto_alumno', false],
    ['no vacía partidos',            'TRUNCATE TABLE dsl_partido', false],
    ['no elimina tablas',            'DROP TABLE general_sede', false],
    ['no altera el esquema',         'ALTER TABLE alumno_pago ADD COLUMN x INT', false],

    /* ---- Disfraces ---- */
    ['no se engaña con un comentario de bloque',
     '/* SELECT */ UPDATE alumno_pago SET pago_valor = 0', false],
    ['no se engaña con un comentario de línea',
     "-- SELECT\nDELETE FROM alumno_pago", false],
    ['no se engaña con una CTE que acaba escribiendo',
     'WITH t AS (SELECT 1) DELETE FROM alumno_pago', false],
    /* Y el reverso: una cadena literal no convierte una lectura en escritura. */
    ['no confunde un literal con una sentencia',
     "SELECT 'DELETE FROM alumno_pago' AS texto", true],
];

foreach ($casos as [$etiqueta, $sql, $debePasar]) {
    $paso = true;
    try {
        $db->prepare($sql);
    } catch (InsightsSoloLectura $e) {
        $paso = false;
    } catch (Throwable $e) {
        /* Error de SQL, no del candado: para esta suite es una pasada. */
    }
    $af($etiqueta, $paso === $debePasar, $paso ? 'pasa' : 'rechazada');
}

/*==============  El capturador usa esta conexión  ==============*/
/*
| Sin esto, alguien podría dejar el candado intacto y darle la vuelta
| abriendo un PDO corriente en el único script que hoy escribe.
*/
$cli = (string) @file_get_contents(__DIR__ . '/../ds_insights/cli/capturar_cartera.php');
$af('el capturador de cartera usa InsightsConexion',
    str_contains($cli, 'InsightsConexion::abrir()'));
$af('el capturador no abre un PDO por su cuenta',
    !preg_match('~new\s+PDO\s*\(~', $cli));

echo "\nfallos: $fallos\n";
exit($fallos > 0 ? 1 : 0);
