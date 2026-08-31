<?php
/*
|--------------------------------------------------------------------------
| El módulo Insights arranca y no deja entrar a quien no debe
|--------------------------------------------------------------------------
| Cuatro controles, los mismos que el resto del ecosistema, más el modo
| estricto que sólo tienen League e Insights:
|
|     sin sesión                   → redirige al login
|     sin el módulo                → 403
|     con módulo, sin permiso      → 403   por la matriz de permisos
|     vista FUERA del menú         → 403   ← esto es el modo estricto
|     con todo concedido           → 200
|
| El cuarto es el que hay que vigilar, y conviene no confundirlo con el
| tercero: una vista registrada sin permiso se deniega en cualquier módulo.
| Lo propio del modo estricto es denegar una vista que ni siquiera está
| registrada, que en el resto del ecosistema PASA. En el resto del ecosistema una vista
| no registrada en seguridad_menu NO se restringe: es una decisión deliberada
| para las vistas de apoyo de Basketball. Insights la invierte porque aquí
| toda vista es información gerencial, y olvidar registrar una no puede
| significar dejarla abierta.
|
| Si alguien quitara DS_PERMISOS_ESTRICTOS, nada fallaría a la vista: el
| módulo seguiría funcionando y las nueve vistas seguirían respondiendo. Sólo
| que a quien no debería. Por eso se comprueba.
*/

require_once __DIR__ . '/conexion.php';

$fallos = 0;
$af = function (string $texto, bool $ok, string $detalle = '') use (&$fallos): void {
    printf("  %-56s %s%s\n", $texto, $ok ? 'OK' : 'FALLA', $detalle !== '' ? "  ($detalle)" : '');
    if (!$ok) { $fallos++; }
};

$raiz = 'c:/wamp64/www/barcelona/';
$base = 'http://localhost/barcelona/ds_insights/';
$db   = qa_conexion();

/** Pide una URL y devuelve el codigo, sin seguir redirecciones. */
$http = function (string $url, ?string $sid = null): int {
    $ctx = stream_context_create(['http' => [
        'method'          => 'GET',
        'follow_location' => 0,
        'ignore_errors'   => true,
        'timeout'         => 20,
        'header'          => $sid !== null ? "Cookie: DigiSportsBasketball=$sid\r\n" : '',
    ]]);
    @file_get_contents($url, false, $ctx);
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) { return (int) $m[1]; }
    }
    return 0;
};

/*==============  1. Configuracion del modulo  ==============*/

$cfg = (string) file_get_contents($raiz . 'ds_insights/config/app.php');
$af("DS_MODULO es 'insights'", str_contains($cfg, "const DS_MODULO = \"insights\""));
$af('DS_PERMISOS_ESTRICTOS está activo',
    (bool) preg_match('~const\s+DS_PERMISOS_ESTRICTOS\s*=\s*true~', $cfg));

$reg = (string) file_get_contents($raiz . 'ds_core/modulos.php');
$af('el Hub tiene Insights activo',
    (bool) preg_match("~'insights'\s*=>\s*\[.*?'activo'\s*=>\s*true~s", $reg));

/*==============  2. Lista blanca y menú, sincronizados  ==============*/
/*
| Con permisos estrictos, una vista declarada en config/vistas.php pero NO
| registrada en seguridad_menu es INALCANZABLE: el front controller la
| acepta y usuario_tiene_permiso() la deniega. No da error, simplemente nadie
| puede entrar y nadie sabe por qué. Y al revés, una registrada que no exista
| como archivo aparece en el menú y lleva a un 404.
*/
$vistas = require $raiz . 'ds_insights/config/vistas.php';
$menu   = $db->query(
    "SELECT menu_vista FROM seguridad_menu WHERE menu_modulo = 'insights'")->fetchAll(PDO::FETCH_COLUMN);

$sinRegistrar = array_values(array_diff($vistas, $menu));
$sinDeclarar  = array_values(array_diff($menu, $vistas));

$af('toda vista declarada está registrada en el menú',
    $sinRegistrar === [], $sinRegistrar ? implode(', ', $sinRegistrar) : count($vistas) . ' vistas');
$af('todo menú apunta a una vista declarada',
    $sinDeclarar === [], $sinDeclarar ? implode(', ', $sinDeclarar) : count($menu) . ' entradas');

/*
| Y que el ARCHIVO exista. Este era el eslabon que faltaba: la cadena es
| menu -> enrutador -> archivo, y solo se comprobaban los dos primeros.
| Tres entradas del menu —Cartera, Transacciones e Indicadores— estaban
| declaradas en los dos sitios y sin vista escrita. El front controller
| responde 404 y a continuacion pinta el tablero, asi que al pulsar en el
| menu se abria el Panel sin decir nada: el 404 no se ve en ninguna parte.
*/
$sinArchivo = array_values(array_filter($vistas, static fn(string $v): bool =>
    !is_file(__DIR__ . '/../ds_insights/views/' . $v . '-view.php')));

$af('toda vista declarada tiene su archivo',
    $sinArchivo === [], $sinArchivo ? implode(', ', $sinArchivo) : count($vistas) . ' archivos');

/*==============  3. El código de servidor no se sirve por URL  ==============*/
/*
| docs/ entró en la lista después de comprobarlo: MODELO_INSIGHTS.md,
| ANALISIS_ACTUAL.md e INDICADORES.pdf se servían con HTTP 200.
|
| El .htaccess de la raíz bloquea .md, pero mod_rewrite NO hereda: un
| .htaccess con RewriteEngine propio REEMPLAZA las reglas del padre en vez de
| sumarse a ellas. Así que cada módulo con su .htaccess se queda sin las
| protecciones de arriba, y hay que repetirlas.
|
| Es un fallo silencioso por definición: nadie prueba una URL que no espera
| que exista, y el archivo se sirve con 200 durante meses.
*/
foreach (['config/app.php', 'config/conexion.php', 'controllers/insightsController.php',
          'views/dashboard-view.php', 'cli/capturar_cartera.php',
          'docs/MODELO_INSIGHTS.md', 'docs/INDICADORES.pdf'] as $ruta) {
    $af("bloqueado por URL: $ruta", $http($base . $ruta) === 403);
}

/*==============  4. Los cuatro controles de acceso  ==============*/

$af('sin sesión redirige al login', $http($base . 'dashboard/') === 302);

/* El superadministrador pasa todos los niveles por definición. */
$af('el superadministrador entra al panel',
    $http($base . 'dashboard/', 'dsqaui0000000000000') === 200);

$af('una vista inventada responde 404',
    $http($base . 'noExisteEstaVista/', 'dsqaui0000000000000') === 404);

/*
| El modo estricto, con un rol de usar y tirar. Se concede el módulo pero NO
| la vista, y se espera 403. Después se limpia todo lo creado.
|
| Se usa el rol 2 porque existe y no tiene Insights concedido; si algún día
| lo tuviera de verdad, la prueba lo detecta y no lo pisa.
*/
$rolPrueba = 2;
$yaTenia = (int) $db->query(
    "SELECT COUNT(*) FROM seguridad_rol_modulo
      WHERE rolmod_rolid = $rolPrueba AND rolmod_modulo = 'insights'")->fetchColumn();

if ($yaTenia > 0) {
    $af('el rol de prueba no tiene Insights concedido de antemano', false,
        "el rol $rolPrueba ya lo tiene: se omite la prueba del modo estricto");
} else {
    $sid = 'dsqastrict000000000';
    exec(sprintf('%s %s %s RolPrueba %d %d "QA estricto" 0 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(__DIR__ . '/sesion_qa.php'),
        escapeshellarg($sid), $rolPrueba, $rolPrueba));

    $af('sin el módulo concedido: 403', $http($base . 'dashboard/', $sid) === 403);

    $db->exec("INSERT INTO seguridad_rol_modulo (rolmod_rolid, rolmod_modulo, rolmod_estado)
               VALUES ($rolPrueba, 'insights', 'A')");

    /* Vista REGISTRADA en el menu y sin permiso: se deniega por la matriz,
       no por el modo estricto. Se comprueba igual porque es el caso comun. */
    $af('con el módulo pero sin permiso sobre la vista: 403',
        $http($base . 'dashboard/', $sid) === 403);

    /*
    | AHORA SI el modo estricto. Se desregistra la vista del menu: en el resto
    | del ecosistema eso la dejaria PASAR —una vista no registrada no se
    | restringe— y aqui debe seguir dando 403.
    |
    | La version anterior de esta prueba media el caso de arriba y lo llamaba
    | «modo estricto». Daba verde con DS_PERMISOS_ESTRICTOS desactivado, que
    | es exactamente lo que no debe pasar.
    */
    $db->exec("UPDATE seguridad_menu SET menu_estado = 'I'
                WHERE menu_modulo = 'insights' AND menu_vista = 'dashboard'");

    $af('vista fuera del menú: 403 (esto sí es el modo estricto)',
        $http($base . 'dashboard/', $sid) === 403);

    $db->exec("UPDATE seguridad_menu SET menu_estado = 'A'
                WHERE menu_modulo = 'insights' AND menu_vista = 'dashboard'");

    $menuId = (int) $db->query(
        "SELECT menu_id FROM seguridad_menu
          WHERE menu_modulo = 'insights' AND menu_vista = 'dashboard'")->fetchColumn();
    $db->exec("INSERT INTO seguridad_permiso
                 (permiso_rolid, permiso_menuid, permiso_ver, permiso_crear,
                  permiso_editar, permiso_eliminar, permiso_exportar, permiso_estado)
               VALUES ($rolPrueba, $menuId, 'S', 'N', 'N', 'N', 'N', 'A')");

    $af('con módulo y vista concedidos: 200', $http($base . 'dashboard/', $sid) === 200);

    /* Limpieza. Si esto fallara, el rol 2 se quedaria con acceso a
       Insights, asi que se comprueba que de verdad quedo limpio. */
    $db->exec("DELETE p FROM seguridad_permiso p
                 JOIN seguridad_menu m ON m.menu_id = p.permiso_menuid
                WHERE m.menu_modulo = 'insights' AND p.permiso_rolid = $rolPrueba");
    $db->exec("DELETE FROM seguridad_rol_modulo
                WHERE rolmod_rolid = $rolPrueba AND rolmod_modulo = 'insights'");

    $resto = (int) $db->query(
        "SELECT COUNT(*) FROM seguridad_rol_modulo
          WHERE rolmod_rolid = $rolPrueba AND rolmod_modulo = 'insights'")->fetchColumn();
    $af('la prueba no deja permisos concedidos', $resto === 0, "$resto quedan");
}

echo "\nfallos: $fallos\n";
exit($fallos > 0 ? 1 : 0);
