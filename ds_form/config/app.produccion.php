<?php

/* ============================================================
   CONFIGURACIÓN DE PRODUCCIÓN — Formulario público de inscripción
   ------------------------------------------------------------
   Suba este contenido como config/app.php en
   https://adfpedrolarreainscripcion.digitech.com.ec/
   ============================================================ */

	const APP_URL="https://adfpedrolarreainscripcion.digitech.com.ec/";
	const APP_NAME="ADFPL_FORM";
	const APP_SESSION_NAME="ADFPL_FORM_SESSION";

	// Nombre visible de la escuela en el formulario y en los textos LOPDP
	const ESCUELA_NOMBRE="AF Pedro Larrea";

	/*----------  Enlace de inscripción  ----------*/
	// DEBE ser idéntica a la de adfpedrolarrea.digifutbol.com
	const TOKEN_SECRET = '0f7ea01e1b1db9f911768b4fe0265590a6eb6196e89d36cd';

	// Vigencia por defecto del enlace: 72 horas
	const TOKEN_EXPIRY = 259200;

	/*----------  Fotos de alumnos  ----------*/
	/*  Ruta ABSOLUTA en disco al directorio de fotos del sistema administrativo.
	 *
	 *  Los dos proyectos están en dominios distintos, así que esta ruta depende
	 *  de cómo estén ubicados en el servidor. Ejecute diagnostico.php para que
	 *  le diga si la ruta configurada existe y es escribible.
	 *
	 *  Casos típicos en hosting compartido (misma cuenta):
	 *    /home/USUARIO/public_html/adfpedrolarrea/app/views/imagenes/fotos/alumno/
	 *    /home/USUARIO/adfpedrolarrea.digifutbol.com/app/views/imagenes/fotos/alumno/
	 *
	 *  Si los dominios están en SERVIDORES distintos no hay ruta compartida
	 *  posible: deje la constante comentada y lea la nota de diagnostico.php.
	 */
	define('DIR_FOTOS_ALUMNO', '/home/digitech/adfpedrolarrea/app/views/imagenes/fotos/alumno/');

	/*----------  Zona horaria  ----------*/
	date_default_timezone_set("America/Guayaquil");