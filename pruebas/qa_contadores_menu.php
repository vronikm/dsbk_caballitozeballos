<?php
/*
| Los numeritos del menu: que salgan cuando hay algo y que digan la verdad.
|
| POR QUE NO BASTA MIRAR LA PAGINA
|
| Ahora mismo no hay ninguna inscripcion a medias, asi que la insignia no
| aparece — y eso es lo correcto. Pero una insignia que nunca aparece y una
| insignia rota se ven exactamente igual. Por eso se prueba la construccion
| del menu directamente, dandole un numero inventado.
|
| Y LO MAS IMPORTANTE: QUE LA CIFRA COINCIDA
|
| El contador se apoya en la MISMA consulta que llena la pantalla de
| inscripciones pendientes. Si algun dia alguien escribe un COUNT aparte, el
| menu dira una cosa y la pantalla otra, y eso es peor que no tener contador.
| Aqui se comprueba que los dos numeros son el mismo.
|
| CUESTA LO QUE SE DIJO
|
| El menu se dibuja en CADA pagina, asi que el coste del contador lo paga el
| sistema entero. Se mide y se exige que siga siendo pequeño.
*/
require_once 'c:/wamp64/www/barcelona/ds_basketball/config/app.php';
require_once 'c:/wamp64/www/barcelona/ds_basketball/app/models/mainModel.php';
require_once 'c:/wamp64/www/barcelona/ds_basketball/app/controllers/inscripcionController.php';
require_once 'c:/wamp64/www/barcelona/ds_basketball/app/controllers/menuController.php';

$fallos = 0;
$af = function (string $t, bool $ok, string $d = '') use (&$fallos) {
    printf("  %-52s %s%s\n", $t, $ok ? 'OK' : 'FALLA', $d !== '' ? "  ($d)" : '');
    if (!$ok) { $fallos++; }
};

/*==============  1. La insignia se dibuja cuando hay algo  ==============*/
$menu = new \app\controllers\menuController();

/* Un menu de mentira con la entrada que nos interesa. */
$falso = [
    ['menu_padreid' => 0, 'menu_hijo' => 'N', 'menu_vista' => 'inscripcionPendientes',
     'menu_nombre' => 'Inscripciones pendientes', 'menu_icono' => 'fas fa-inbox', 'padre' => ''],
    ['menu_padreid' => 0, 'menu_hijo' => 'N', 'menu_vista' => 'alumnoList',
     'menu_nombre' => 'Alumnos', 'menu_icono' => 'fas fa-users', 'padre' => ''],
];

$conCero = $menu->ConstruirMenu($falso, '', ['inscripcionPendientes' => 0]);
$af('con cero pendientes no se dibuja ninguna insignia',
    !str_contains($conCero, 'nav-badge'));

$conSiete = $menu->ConstruirMenu($falso, '', ['inscripcionPendientes' => 7]);
$af('con siete pendientes sí se dibuja',
    substr_count($conSiete, 'nav-badge') === 1 && str_contains($conSiete, '>7</span>'),
    substr_count($conSiete, 'nav-badge') . ' insignias');

$af('y solo en la entrada que le toca',
    str_contains($conSiete, 'Inscripciones pendientes <span class="nav-badge'),
    'la insignia va pegada a su rótulo');

/* Un numero suelto no dice de que es: hace falta que lo diga en voz alta. */
$af('la insignia se anuncia para quien no la ve',
    str_contains($conSiete, 'aria-label="7 pendientes"'));

/*==============  2. La cifra coincide con la pantalla  ==============*/
$ins = new \app\controllers\inscripcionController();

$t0 = microtime(true);
$contador = $ins->contarPendientesInscripcion();
$ms = (microtime(true) - $t0) * 1000;

$filas = count($ins->listarPendientesInscripcion());

$af('el contador dice lo mismo que la pantalla',
    $contador === $filas, "menú: $contador · pantalla: $filas");

/*==============  3. Y no cuesta caro  ==============*/
/* Se mide varias veces: la primera incluye abrir la conexion. */
$t0 = microtime(true);
for ($i = 0; $i < 5; $i++) { $ins->contarPendientesInscripcion(); }
$medio = (microtime(true) - $t0) * 1000 / 5;

$af('el contador cuesta menos de 25 ms', $medio < 25,
    sprintf('%.1f ms de media, y se paga en cada página', $medio));

/*==============  4. Sin la entrada, no se calcula nada  ==============*/
/* El sidebar solo consulta si el usuario tiene esa vista en su menu. Se
   comprueba sobre el codigo, que es donde vive esa decision. */
$sidebar = (string) file_get_contents(
    'c:/wamp64/www/barcelona/ds_basketball/app/views/inc/main-sidebar.php');
$af('sin permiso sobre esa vista, no se consulta',
    str_contains($sidebar, '$tienePendientes')
      && strpos($sidebar, '$tienePendientes') < strpos($sidebar, 'contarPendientesInscripcion'),
    'la comprobación va antes que la consulta');

$af('un fallo del contador no deja sin menú',
    str_contains($sidebar, 'catch (\Throwable'));

printf("\nfallos: %d\n", $fallos);
exit($fallos === 0 ? 0 : 1);
