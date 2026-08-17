<?php
/*
|--------------------------------------------------------------------------
| Configuracion comun del ecosistema DigiSports
|--------------------------------------------------------------------------
| Todo lo que comparten el Hub y los modulos: URLs del ecosistema, zona
| horaria y credenciales. Cada modulo define aparte su propia identidad
| (APP_URL, APP_NAME) en su config/app.php.
|
| Este archivo NO define APP_URL ni APP_NAME a proposito: esas constantes
| pertenecen al contexto que se este ejecutando (el Hub o un modulo), y
| definirlas aqui provocaria una redefinicion.
*/

/*----------  Raiz del ecosistema  ----------*/
const DS_HUB_URL  = "http://localhost/barcelona/";
const DS_HUB_NAME = "DigiSports";
const DS_TAGLINE  = "Sports Management Ecosystem";

/*----------  URL de cada modulo  ----------*/
const DS_BASKETBALL_URL = DS_HUB_URL . "ds_basketball/";
const DS_ARENA_URL      = DS_HUB_URL . "ds_arena/";
const DS_LEAGUE_URL     = DS_HUB_URL . "ds_league/";
const DS_INSIGHTS_URL   = DS_HUB_URL . "ds_insights/";

/*----------  Sesion unica del ecosistema  ----------*/
// El mismo nombre de cookie en Hub y modulos, con path "/", hace que
// iniciar sesion una vez valga para todo DigiSports.
const DS_SESSION_NAME = "DigiSportsBasketball";

/*----------  Secretos  ----------*/
// Credenciales de BD y TOKEN_SECRET. Fuente unica para todo el ecosistema.
require_once __DIR__ . "/server.php";

/*----------  Zona horaria  ----------*/
date_default_timezone_set("America/Guayaquil");
