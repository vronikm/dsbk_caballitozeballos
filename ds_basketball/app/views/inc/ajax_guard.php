<?php
/*
|--------------------------------------------------------------------------
| Guardia de los endpoints de app/ajax/
|--------------------------------------------------------------------------
| Debe incluirse en TODO archivo de app/ajax/, inmediatamente despues de
| session_start.php y antes de instanciar cualquier controlador.
|
| Un endpoint puede declarar la vista cuyo permiso exige, definiendo
| $GUARD_VISTA justo antes de incluir este archivo:
|
|     $GUARD_VISTA = "alumnoList";
|     require_once "../views/inc/ajax_guard.php";
|
| Si no se declara, el endpoint solo exige sesion activa y origen valido.
|
| Verifica en orden:
|   1. Sesion activa.
|   2. Origen de la peticion POST (anti-CSRF).
|   3. Permiso del rol sobre $GUARD_VISTA.
*/

require_once __DIR__ . "/seguridad.php";

if (!function_exists('guard_rechazar')) {
    /**
     * Corta la ejecucion devolviendo una alerta con el formato que espera
     * alertas_ajax() en app/views/dist/js/ajax.js.
     */
    function guard_rechazar(int $codigo, array $alerta): void
    {
        if (!headers_sent()) {
            http_response_code($codigo);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($alerta, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/*----------  1. Sesion activa  ----------*/
if (!usuario_autenticado()) {
    guard_rechazar(401, [
        'tipo'   => 'redireccionar',
        'icono'  => 'warning',
        'titulo' => 'Sesión finalizada',
        'texto'  => 'Su sesión expiró o no ha iniciado sesión. Vuelva a ingresar.',
        'url'    => APP_URL . 'login/',
    ]);
}

/*----------  2. Origen de la peticion (anti-CSRF)  ----------*/
if (!origen_es_valido()) {
    guard_rechazar(403, [
        'tipo'   => 'simple',
        'icono'  => 'error',
        'titulo' => 'Solicitud bloqueada',
        'texto'  => 'La solicitud no proviene del sistema. Recargue la página e inténtelo de nuevo.',
    ]);
}

/*----------  3. Acceso al modulo  ----------*/
if (!usuario_tiene_modulo(DS_MODULO)) {
    guard_rechazar(403, [
        'tipo'   => 'simple',
        'icono'  => 'error',
        'titulo' => 'Acceso denegado',
        'texto'  => 'Su rol no tiene acceso a este módulo del ecosistema.',
    ]);
}

/*----------  4. La vista declarada debe seguir perteneciendo al modulo  ----------*/
/* Si un endpoint declara una vista que el modulo ya no sirve, es que la
   pantalla se retiro y el endpoint quedo huerfano. Sin esta comprobacion
   pasaria a NO estar restringido, porque una vista ausente de
   seguridad_menu no se restringe: exactamente el agujero que dejo mover
   la configuracion a Core. Se rechaza en lugar de dejarlo abierto. */
if (isset($GUARD_VISTA) && $GUARD_VISTA !== '') {

    require_once __DIR__ . "/../../../../ds_core/vistas.php";

    if (!ds_vista_existe(DS_MODULO, $GUARD_VISTA)) {
        guard_rechazar(410, [
            'tipo'   => 'simple',
            'icono'  => 'error',
            'titulo' => 'Operación no disponible',
            'texto'  => 'Esta funcionalidad ya no pertenece a este módulo. '
                      . 'Consúltela en el módulo correspondiente del ecosistema.',
        ]);
    }
}

/*----------  5. Permiso de la accion sobre la vista  ----------*/
/* La operacion viaja en el campo POST modulo_* que despacha el endpoint.
   De su nombre se deduce si es lectura, alta, edicion o baja, de modo que
   el control por accion se aplica en un solo punto y no en los 29
   controladores. Ver accion_de_operacion() en ds_core/inc/seguridad.php */
$GUARD_OPERACION = operacion_de_peticion();

if (isset($GUARD_VISTA) && $GUARD_VISTA !== ''
    && $GUARD_OPERACION !== ''
    && !operacion_es_autoservicio($GUARD_OPERACION)) {

    $GUARD_ACCION = accion_de_operacion($GUARD_OPERACION);

    if (!puede($GUARD_ACCION, $GUARD_VISTA)) {

        $etiquetas = [
            'ver'      => 'consultar',
            'crear'    => 'registrar',
            'editar'   => 'modificar',
            'eliminar' => 'eliminar',
        ];

        guard_rechazar(403, [
            'tipo'   => 'simple',
            'icono'  => 'error',
            'titulo' => 'Acceso denegado',
            'texto'  => 'Su rol no tiene permiso para ' . ($etiquetas[$GUARD_ACCION] ?? 'realizar')
                      . ' en esta pantalla.',
        ]);
    }
}
