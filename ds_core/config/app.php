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

/*----------  Transporte  ----------*/
// Fuera de la maquina local, el login viaja por HTTPS o no viaja. Sin
// cifrado, la contrasena va en claro por la red y la cookie de sesion se
// puede copiar tal cual desde cualquier punto intermedio.
//
// localhost y las redes privadas quedan exentas: el desarrollo sigue
// funcionando por HTTP sin tocar nada.
//
// Poner en false SOLO si el servidor publico aun no tiene certificado, y
// entendiendo que hasta entonces las credenciales circulan legibles.
const DS_FORZAR_HTTPS = true;

// Meses que el navegador recordara que este sitio es solo HTTPS (HSTS).
// Se empieza corto a proposito: si algo va mal, el efecto caduca pronto.
// Una vez comprobado que todo funciona, subirlo a 12.
const DS_HSTS_MESES = 1;
