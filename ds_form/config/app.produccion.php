<?php
/* ============================================================
   CONFIGURACION DE PRODUCCION — Formulario publico de inscripcion
   ------------------------------------------------------------
   PLANTILLA. Copie su contenido a config/app.php en el servidor y
   ajuste unicamente la raiz del ecosistema.

   Este archivo NO contiene secretos a proposito. La version anterior
   traia el TOKEN_SECRET y el dominio de produccion de otra escuela,
   heredados al copiar el formulario: cualquiera con acceso al
   repositorio podia firmar enlaces validos de aquel sistema.

   Los secretos viven en ds_core/config/secrets.php, fuera del control
   de versiones, y llegan aqui a traves de ds_core/config/app.php.
   ============================================================ */

/*----------  Nucleo del ecosistema  ----------*/
/* En produccion basta con que DS_HUB_URL apunte al dominio real; de ahi
   se derivan la URL de este formulario y la de cada modulo. */
require_once __DIR__ . "/../../ds_core/config/app.php";

/*----------  Identidad de este formulario  ----------*/
const APP_URL  = DS_HUB_URL . "ds_form/";
const APP_NAME = "DigiSports - Inscripción";

const APP_SESSION_NAME = "DigiSportsInscripcion";

/*----------  Fotos de alumnos  ----------*/
/* Ruta ABSOLUTA en disco a la carpeta de fotos del modulo de la escuela.
   Si el formulario y el panel comparten alojamiento, la ruta relativa de
   abajo sirve tal cual. Si estan en servidores distintos no hay carpeta
   compartida posible y habra que sincronizarlas por otro medio. */
define('DIR_FOTOS_ALUMNO', __DIR__ . "/../../ds_basketball/app/views/imagenes/fotos/alumno/");

/*----------  Nombre visible de la escuela  ----------*/
/* Se toma de la organizacion configurada en Core, para que coincida con
   el que aparece en recibos y reportes. */
require_once __DIR__ . "/../../ds_core/inc/seguridad.php";
require_once __DIR__ . "/../../ds_core/inc/organizacion.php";

define('ESCUELA_NOMBRE', ds_nombre_organizacion() ?: DS_HUB_NAME);
