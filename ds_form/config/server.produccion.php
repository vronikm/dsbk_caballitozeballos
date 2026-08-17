<?php
/* ============================================================
   BASE DE DATOS — Formulario publico de inscripcion
   ------------------------------------------------------------
   PLANTILLA. Este archivo ya NO declara credenciales.

   La version anterior traia usuario, contrasena y base de datos de
   PRODUCCION de otra escuela. Estaban en texto plano dentro del
   proyecto y no las usaba nadie: solo servian para filtrarlas.

   El formulario usa la MISMA base que el sistema administrativo, y esa
   configuracion vive en un unico sitio:

       ds_core/config/secrets.php     (fuera del control de versiones)

   Si el formulario se despliega en un servidor distinto al de la base,
   DB_SERVER no puede ser "localhost": debe ser el host o IP del servidor
   MySQL, y ese servidor debe permitir conexiones remotas para el usuario
   (en el panel del hosting suele llamarse "Remote MySQL").
   ============================================================ */

require_once __DIR__ . "/../../ds_core/config/app.php";
