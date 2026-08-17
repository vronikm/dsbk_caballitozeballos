<?php

	const APP_URL="http://localhost/adfpedrolarrea_form/";
	const APP_NAME="ADFPL_FORM";
	const APP_SESSION_NAME="ADFPL_FORM_SESSION";

	// Nombre visible de la escuela en el formulario público
	const ESCUELA_NOMBRE="AF Pedro Larrea";

	/*----------  Enlace de inscripción  ----------*/
	// Clave para validar los tokens HMAC del enlace de inscripción.
	// DEBE coincidir con la del proyecto adfpedrolarrea.
	const TOKEN_SECRET = '6831800a7814e9352ed2755c5ce5e9935c4957ab6c4398ef';

	// Vigencia por defecto del enlace: 72 horas
	const TOKEN_EXPIRY = 259200;

	/*----------  Fotos de alumnos  ----------*/
	// Ruta ABSOLUTA en disco al directorio de fotos del sistema administrativo,
	// para que la imagen subida en la inscripción se vea desde el panel.
	// Se declara acá y no se deduce de "../.." porque depende de dónde quede
	// instalado este proyecto: al lado del principal o dentro de él.
	//   proyectos hermanos : __DIR__."/../../adfpedrolarrea/app/views/imagenes/fotos/alumno/"
	//   anidado dentro     : __DIR__."/../../app/views/imagenes/fotos/alumno/"
	define('DIR_FOTOS_ALUMNO', __DIR__ . "/../../adfpedrolarrea/app/views/imagenes/fotos/alumno/");

	/*----------  Zona horaria  ----------*/
	date_default_timezone_set("America/Guayaquil");