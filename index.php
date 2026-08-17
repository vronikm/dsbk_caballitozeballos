<?php
/*
|--------------------------------------------------------------------------
| DigiSports — Hub de aplicaciones
|--------------------------------------------------------------------------
| Puerta de entrada al ecosistema. Presenta los modulos como un lanzador de
| aplicaciones y delega en cada ds_* la gestion de su dominio.
|
| La sesion es unica en todo DigiSports (mismo nombre de cookie y path "/"),
| asi que iniciar sesion en cualquier modulo vale tambien aqui.
*/

/*----------  Nucleo del ecosistema  ----------*/
require_once __DIR__ . "/ds_core/config/app.php";

/*----------  Identidad de este contexto  ----------*/
/* La capa de seguridad compartida trabaja con APP_URL / APP_NAME; en el Hub
   esas constantes apuntan a la raiz del ecosistema. */
const APP_URL          = DS_HUB_URL;
const APP_NAME         = DS_HUB_NAME;
const APP_SESSION_NAME = DS_SESSION_NAME;

require_once __DIR__ . "/ds_core/autoload.php";
require_once __DIR__ . "/ds_core/modulos.php";
require_once __DIR__ . "/ds_core/inc/session_start.php";

/*----------  Solo usuarios autenticados  ----------*/
/* El inicio de sesion vive todavia en el modulo Basketball; al ser la
   sesion comun, basta con enviar alli a quien no la tenga. */
if (!usuario_autenticado()) {
    header("Location: " . DS_BASKETBALL_URL . "login/");
    exit();
}

require_once __DIR__ . "/ds_core/hub/hub-view.php";
