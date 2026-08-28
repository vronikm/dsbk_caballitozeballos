<?php
/*
|--------------------------------------------------------------------------
| «Exportar» es una acción con permiso propio, y tiene que seguir siéndolo
|--------------------------------------------------------------------------
| El vocabulario del sistema era ver / crear / editar / eliminar, y esos
| cuatro nombres estaban CABLEADOS en la consulta de permisos_de_la_sesion()
| y en el array que construye. Añadir una acción no fue cambiar un dato: fue
| tocar cinco archivos. Ver ds_core/database/045.
|
| Exportar merece permiso propio porque es la acción por la que la
| información SALE del sistema: ver la cartera en pantalla y llevársela en
| un Excel son dos decisiones distintas, y hasta ahora sólo se podía tomar
| una.
|
| Esta suite vigila que la cadena completa siga entera. Se rompe por
| cualquiera de sus eslabones y ninguno da error visible al romperse: si el
| controlador deja de guardar la columna, la pantalla sigue mostrando la
| casilla y el usuario cree que concedió algo.
|
| El último control —que el colspan de la fila de grupo coincida con el
| número de columnas— existe porque al añadir la columna se me pasó, y una
| tabla descuadrada no da error: sólo se ve torcida.
*/

require_once __DIR__ . '/conexion.php';

$fallos = 0;
$af = function (string $texto, bool $ok, string $detalle = '') use (&$fallos): void {
    printf("  %-54s %s%s\n", $texto, $ok ? 'OK' : 'FALLA', $detalle !== '' ? "  ($detalle)" : '');
    if (!$ok) { $fallos++; }
};

$raiz = 'c:/wamp64/www/barcelona/';
$db   = qa_conexion();

/*==============  1. La columna  ==============*/

$col = null;
foreach ($db->query('SHOW COLUMNS FROM seguridad_permiso') as $c) {
    if ($c['Field'] === 'permiso_exportar') { $col = $c; }
}
$af('seguridad_permiso tiene la columna permiso_exportar', $col !== null);
$af('no admite nulos y por omisión deniega',
    $col !== null && $col['Null'] === 'NO' && $col['Default'] === 'N',
    $col === null ? 'no existe' : "null={$col['Null']} default={$col['Default']}");

/*==============  2. La capa de seguridad  ==============*/

$seg = (string) file_get_contents($raiz . 'ds_core/inc/seguridad.php');

$af('la consulta de permisos pide permiso_exportar',
    str_contains($seg, 'p.permiso_exportar'));

$af('el array de acciones incluye exportar',
    (bool) preg_match("~'exportar'\s*=>\s*\\\$f\['permiso_exportar'\]~", $seg));

$af('existe el atajo puede_exportar()',
    str_contains($seg, 'function puede_exportar'));

/*==============  3. El controlador que la guarda  ==============*/
/*
| Cuatro puntos, y los cuatro hacen falta: leer la matriz para pintarla,
| leer el formulario, y persistir tanto al actualizar como al insertar.
| Faltando cualquiera, la casilla se marca y no se guarda nada.
*/
$ctrl = (string) file_get_contents($raiz . 'ds_core/admin/controllers/coreController.php');

foreach ([
    'lee la matriz para pintarla' => 'COALESCE(p.permiso_exportar',
    'lee el formulario'           => "\$exportar = !empty(\$a['exportar'])",
    'lo persiste al actualizar'   => 'permiso_exportar = :x',
    'lo persiste al insertar'     => 'permiso_exportar',
] as $texto => $aguja) {
    $af("el controlador $texto", str_contains($ctrl, $aguja));
}

/* Que el marcador viaje en ambas sentencias, no sólo en el SQL. */
$af('el parámetro :x se enlaza en las dos sentencias',
    substr_count($ctrl, "':x' => \$exportar") === 2,
    substr_count($ctrl, "':x' => \$exportar") . ' veces');

/*==============  4. La pantalla  ==============*/

$vista = (string) file_get_contents($raiz . 'ds_core/admin/views/permisoRol-view.php');

$af('la pantalla emite el campo [exportar]',
    str_contains($vista, "'exportar' => \$m['exportar']"));

$af('la cabecera permite alternar la columna entera',
    str_contains($vista, 'data-marcar-columna="exportar"'));

/*
| El colspan de la fila de grupo tiene que valer 1 (la columna de la vista)
| más una por cada acción. Al añadir «exportar» se me olvidó subirlo de 5 a
| 6, y una tabla descuadrada no da error: sólo se ve torcida.
*/
$columnas = preg_match_all('~<th class="accion">~', $vista);
preg_match('~<tr class="grupo"><td colspan="(\d+)"~', $vista, $m);
$colspan = isset($m[1]) ? (int) $m[1] : 0;
$af('el colspan de la fila de grupo cuadra con las columnas',
    $colspan === $columnas + 1,
    "colspan=$colspan · acciones=$columnas · esperado " . ($columnas + 1));

/*==============  5. Nadie tiene el permiso por accidente  ==============*/
/*
| Menor privilegio: la migración lo dejó en 'N' para las 100 filas
| existentes. Que alguien lo tenga no es un fallo —se concede a mano— pero
| que lo tenga SIN lectura sí lo es: no se puede exportar lo que no se ve.
*/
$incoherentes = (int) $db->query(
    "SELECT COUNT(*) FROM seguridad_permiso
      WHERE permiso_exportar = 'S' AND permiso_ver <> 'S'")->fetchColumn();
$af('nadie puede exportar lo que no puede ver', $incoherentes === 0, "$incoherentes filas");

echo "\nfallos: $fallos\n";
exit($fallos > 0 ? 1 : 0);
