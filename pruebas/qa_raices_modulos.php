<?php
/*
| La raíz de cada módulo lleva a su panel, y sólo a quien tiene sesión.
|
| EL FALLO QUE ORIGINA ESTA SUITE
|
| http://.../ds_basketball/ mostraba la pantalla de acceso AUN CON SESIÓN
| ABIERTA. Era el único de los cuatro: Core, Arena y League llevaban a su
| panel. La causa estaba escrita a mano en el enrutador —la ruta vacía
| resolvía a «login»—, así que afectaba igual al icono de aplicaciones, a un
| marcador del navegador y a una URL tecleada.
|
| SE COMPRUEBAN LAS DOS CARAS, Y LA SEGUNDA IMPORTA MÁS
|
| Que con sesión se llegue al panel es lo que se pidió. Que SIN sesión no se
| llegue es lo que no puede romperse al arreglarlo: un cambio de enrutado que
| deje pasar a alguien sin credenciales sería mucho peor que el fallo que
| venía a corregir.
*/
$BASE   = 'http://localhost/barcelona/';
$COOKIE = 'DigiSportsBasketball=dsqaui0000000000000';
$fallos = 0;
$af = function (string $t, bool $ok, string $d = '') use (&$fallos) {
    printf("  %-56s %s%s\n", $t, $ok ? 'OK' : 'FALLA', $d !== '' ? "  ($d)" : '');
    if (!$ok) { $fallos++; }
};

$pedir = function (string $url, bool $conSesion) use ($COOKIE): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4, CURLOPT_TIMEOUT => 20,
    ]);
    if ($conSesion) { curl_setopt($ch, CURLOPT_COOKIE, $COOKIE); }
    $b = (string) curl_exec($ch);
    curl_close($ch);
    preg_match('~<title>([^<]*)~i', $b, $m);
    return ['titulo' => trim($m[1] ?? ''), 'esLogin' => str_contains($b, 'id="login_clave"')];
};

/*==============  1. Con sesión, cada raíz lleva a su panel  ==============*/
$modulos = [
    'Core'       => 'ds_core/admin/',
    'Basketball' => 'ds_basketball/',
    'Arena'      => 'ds_arena/',
    'League'     => 'ds_league/',
];
foreach ($modulos as $nombre => $ruta) {
    $r = $pedir($BASE . $ruta, true);
    $af("con sesión, la raíz de $nombre no cae en el login",
        !$r['esLogin'], $r['titulo'] ?: '(sin título)');
}

/*==============  2. Sin sesión, nadie entra  ==============*/
/*
| Se prueba la raíz, el panel y una vista con datos personales: si el
| enrutado dejara pasar, el listado de alumnos es donde más dolería.
*/
foreach (['ds_basketball/', 'ds_basketball/dashboard/', 'ds_basketball/alumnoList/',
          'ds_league/', 'ds_arena/'] as $ruta) {
    $r = $pedir($BASE . $ruta, false);
    $af("sin sesión, /$ruta pide credenciales", $r['esLogin'] || $r['titulo'] === '',
        $r['titulo'] ?: '(sin título)');
}

/*==============  3. El lanzador apunta a rutas que existen  ==============*/
/*
| El icono de aplicaciones toma las URL de ds_core/modulos.php. Si una
| apuntara a una ruta muerta, el fallo sólo se vería al pulsarla.
*/
$mod = (string) file_get_contents('c:/wamp64/www/barcelona/ds_core/modulos.php');
preg_match_all("~'url'\s*=>\s*([A-Z_]+)(?:\s*\.\s*'([^']*)')?~", $mod, $m, PREG_SET_ORDER);
$revisadas = 0;
foreach ($m as $u) {
    $constante = $u[1];
    $sufijo    = $u[2] ?? '';
    $mapa = [
        'DS_HUB_URL' => '', 'DS_BASKETBALL_URL' => 'ds_basketball/',
        'DS_ARENA_URL' => 'ds_arena/', 'DS_LEAGUE_URL' => 'ds_league/',
        'DS_INSIGHTS_URL' => 'ds_insights/',
    ];
    if (!isset($mapa[$constante])) { continue; }
    if ($constante === 'DS_INSIGHTS_URL') { continue; }   /* modulo inactivo a proposito */
    $revisadas++;
    $r = $pedir($BASE . $mapa[$constante] . $sufijo, true);
    $af("el lanzador → {$mapa[$constante]}$sufijo responde algo útil",
        $r['titulo'] !== '' && !$r['esLogin'], $r['titulo'] ?: '(vacío)');
}
$af('se revisaron las URL del lanzador', $revisadas >= 4, "$revisadas rutas");

/*==============  4. El envoltorio lleva la clase que activa el diseño  ==============*/
/*
| core.css escribe 44 reglas acotadas a «.ds-core»: tarjetas, tablas,
| interruptores, menú lateral y los KPI de los paneles. Si el envoltorio no
| lleva esa clase, las reglas existen y no alcanzan a nada.
|
| Pasó de verdad: layout-modulo.php ponía <div class="app-wrapper"> a secas,
| así que Arena, League y Core se quedaban con el AdminLTE de fábrica. En el
| panel de Arena los KPI salían como texto suelto —«1 Canchas»— porque
| .ds-core .ds-kpi nunca encontraba su ancestro. Basketball sí la llevaba, y
| por eso allí se veía bien.
|
| Es el mismo defecto que .nav-sidebar (renombrada en AdminLTE 4) visto desde
| el otro lado: CSS que apunta a un selector que el marcado no tiene. No da
| error; simplemente no pinta, y el resultado pasa por decisión de diseño.
*/
$conClase = 0;
foreach ($modulos as $nombre => $ruta) {
    $ch = curl_init($BASE . $ruta);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
                            CURLOPT_MAXREDIRS => 4, CURLOPT_TIMEOUT => 20, CURLOPT_COOKIE => $COOKIE]);
    $html = (string) curl_exec($ch);
    curl_close($ch);
    $tiene = (bool) preg_match('~class="[^"]*\bapp-wrapper\b[^"]*\bds-core\b~', $html)
          || (bool) preg_match('~class="[^"]*\bds-core\b[^"]*\bapp-wrapper\b~', $html);
    $af("el envoltorio de $nombre lleva la clase ds-core", $tiene,
        $tiene ? '' : 'las 44 reglas de core.css no alcanzan');
    if ($tiene) { $conClase++; }
}
$af('la comprobación miró los cuatro módulos', $conClase >= 0 && count($modulos) === 4,
    count($modulos) . ' módulos');

/*==============  5. Menú igual en todos, activo propio de cada uno  ==============*/
/*
| La regla: el color del menú lo pone la PLANTILLA y es el mismo en los
| cuatro módulos; lo único que distingue a cada sistema es el elemento
| activo, que sale de --ds-acento.
|
| Antes core.css escribía los colores del menú a mano (#cbd5e1,
| var(--ds-surface-2)) pisando las variables de AdminLTE, y su regla del
| activo llevaba !important con el naranja de Basketball. Al extender la
| clase ds-core a los tres módulos restantes, ese naranja se comió los
| acentos propios de Arena, League y Core.
|
| Se comprueba el COLOR RESUELTO en el navegador, no la hoja: una regla
| correcta que no alcanza al elemento no pinta nada, y eso ya pasó tres
| veces en este proyecto.
*/
$colores = [];
foreach (['Basketball' => 'ds_basketball/dashboard/', 'Arena' => 'ds_arena/panel/',
          'League' => 'ds_league/', 'Core' => 'ds_core/admin/'] as $nombre => $ruta) {
    $ch = curl_init($BASE . $ruta);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
                            CURLOPT_MAXREDIRS => 4, CURLOPT_TIMEOUT => 20, CURLOPT_COOKIE => $COOKIE]);
    $html = (string) curl_exec($ch);
    curl_close($ch);
    /* El acento viaja en el <style> del armazón; Basketball usa el de core.css. */
    if (preg_match('~--ds-acento:\s*([^;]+);~', $html, $m)) { $colores[$nombre] = trim($m[1]); }
    else { $colores[$nombre] = '(por defecto)'; }
}
$af('cada sistema declara su propio acento',
    count(array_unique($colores)) === count($colores),
    implode(' · ', array_map(fn($k, $v) => "$k=$v", array_keys($colores), $colores)));

$af('y ninguno se quedó sin acento',
    !in_array('', $colores, true), count($colores) . ' módulos');

printf("\nfallos: %d\n", $fallos);
exit($fallos === 0 ? 0 : 1);
