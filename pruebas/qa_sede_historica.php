<?php
/*
|--------------------------------------------------------------------------
| La sede del pago no puede volver a derivarse del alumno
|--------------------------------------------------------------------------
| Hasta la migración 044, «ingresos por sede» se calculaba llegando a la sede
| por el alumno. Como alumno_sedeid es el PRESENTE del alumno y la suma de
| pagos es el PASADO, trasladar a alguien reescribía el historial de las dos
| sedes: medido sobre datos reales, un solo alumno movía 200,00 dólares.
|
| Esta suite vigila las tres formas de que el defecto vuelva:
|
|   1. Que alguien quite la columna, la clave foránea, o permita nulos.
|   2. Que un camino de escritura nuevo olvide rellenarla.
|   3. Que un informe de dinero vuelva a agrupar por la sede del alumno.
|
| Lo que NO vigila, a propósito: los listados de alumnos por sede siguen
| usando alumno_sedeid, y deben seguir haciéndolo. «¿Cuántos alumnos tiene
| La Salle?» pregunta por el presente; «¿cuánto recaudó en marzo?» no.
*/

require_once __DIR__ . '/conexion.php';

$fallos = 0;
$af = function (string $texto, bool $ok, string $detalle = '') use (&$fallos): void {
    printf("  %-52s %s%s\n", $texto, $ok ? 'OK' : 'FALLA', $detalle !== '' ? "  ($detalle)" : '');
    if (!$ok) { $fallos++; }
};

$db = qa_conexion();

/*==============  1. La columna y su blindaje  ==============*/

$col = null;
foreach ($db->query('SHOW COLUMNS FROM alumno_pago') as $c) {
    if ($c['Field'] === 'pago_sedeid') { $col = $c; }
}

$af('alumno_pago tiene la columna pago_sedeid', $col !== null);

/* NOT NULL no es cosmético: es lo que hace que un camino de escritura que
   olvide la sede FALLE en la primera prueba, en vez de insertar en silencio
   y corromper el informe durante meses. */
$af('la columna no admite nulos', $col !== null && $col['Null'] === 'NO',
    $col === null ? 'no existe' : 'Null=' . $col['Null']);

$fk = $db->query(
    "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumno_pago'
        AND COLUMN_NAME = 'pago_sedeid' AND REFERENCED_TABLE_NAME = 'general_sede'"
)->fetchColumn();
$af('apunta a general_sede con clave foránea', (int) $fk === 1);

$huerfanos = $db->query(
    'SELECT COUNT(*) FROM alumno_pago p
      LEFT JOIN general_sede s ON s.sede_id = p.pago_sedeid
      WHERE s.sede_id IS NULL'
)->fetchColumn();
$af('ningún pago apunta a una sede inexistente', (int) $huerfanos === 0, "$huerfanos pagos");

/*==============  2. Los caminos de escritura  ==============*/
/*
| Se recorre el código buscando quién crea pagos. Dos formas: el INSERT
| escrito a mano y el ayudante guardarDatos(). Cada una tiene que mencionar
| pago_sedeid cerca; si aparece un camino nuevo que no lo hace, esta prueba
| lo señala antes de que llegue a producción.
*/
$escrituras = [];
$sinSede    = [];

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator('c:/wamp64/www/barcelona', FilesystemIterator::SKIP_DOTS));
foreach ($it as $arch) {
    if (!$arch->isFile()) { continue; }
    $ruta = strtr($arch->getPathname(), chr(92), '/');
    if (!preg_match('~/ds_[a-z]+/.*\.php$~', $ruta))        { continue; }
    if (preg_match('~/borrar/|/vendor/|/pruebas/~', $ruta)) { continue; }

    $txt = (string) file_get_contents($ruta);

    /* INSERT escrito a mano. */
    if (preg_match_all('~INSERT\s+INTO\s+alumno_pago\s*\(([^)]*)\)~is', $txt, $m)) {
        foreach ($m[1] as $columnas) {
            $escrituras[] = basename($ruta);
            if (!str_contains($columnas, 'pago_sedeid')) {
                $sinSede[] = basename($ruta) . ' (INSERT)';
            }
        }
    }

    /* El ayudante. Se mira el bloque que precede a la llamada: ahí se arma
       el array de campos. 60 líneas cubren de sobra el payload. */
    if (preg_match_all('~guardarDatos\s*\(\s*["\']alumno_pago["\']~', $txt, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $hit) {
            $escrituras[] = basename($ruta);
            $desde  = max(0, $hit[1] - 4000);
            $previo = substr($txt, $desde, $hit[1] - $desde);
            if (!str_contains($previo, 'pago_sedeid')) {
                $sinSede[] = basename($ruta) . ' (guardarDatos)';
            }
        }
    }
}

/* Si el recorrido no encuentra NINGÚN camino, la prueba anterior aprobaría
   por vacío. Eso ya pasó una vez en este arnés y no vuelve a pasar. */
$af('el recorrido encuentra los caminos de escritura',
    count($escrituras) >= 4, count($escrituras) . ' encontrados');

$af('todo camino que crea pagos fija la sede',
    count($sinSede) === 0,
    $sinSede ? implode(', ', array_unique($sinSede)) : count($escrituras) . ' caminos');

/*==============  3. Los informes de dinero  ==============*/
/*
| Un informe que vuelva a agrupar dinero por alumno_sedeid deshace la
| migración sin tocar el esquema. Se buscan las consultas que suman pagos y
| se comprueba que ninguna llegue a la sede por el alumno.
*/
$informesMal = [];
foreach (glob('c:/wamp64/www/barcelona/ds_*/app/controllers/*.php') ?: [] as $ruta) {
    $txt = (string) file_get_contents($ruta);
    /* Consultas que mencionan a la vez una suma de pagos y la sede del
       alumno. La ventana es la propia sentencia SQL, no el archivo. */
    if (preg_match_all('~"[^"]*SUM\s*\(\s*[A-Za-z.]*pago_valor[^"]*"~is', $txt, $m)) {
        foreach ($m[0] as $consulta) {
            if (str_contains($consulta, 'alumno_sedeid')) {
                $informesMal[] = basename($ruta);
            }
        }
    }
}
$af('ningún informe suma dinero agrupando por la sede del alumno',
    count($informesMal) === 0,
    $informesMal ? implode(', ', array_unique($informesMal)) : 'revisados los controladores');

echo "\nfallos: $fallos\n";
exit($fallos > 0 ? 1 : 0);
