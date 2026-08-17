<?php
/*
|--------------------------------------------------------------------------
| PLANTILLA DE SECRETOS
|--------------------------------------------------------------------------
| Copie este archivo como  config/secrets.php  y complete los valores
| reales del entorno. secrets.php NUNCA se versiona (ver .gitignore).
|
|   cp config/secrets.example.php config/secrets.php
*/

/*----------  Base de datos  ----------*/
const DB_SERVER = "localhost";
const DB_NAME   = "nombre_de_la_base";
const DB_USER   = "usuario_de_la_base";
const DB_PASS   = 'clave_de_la_base';

/*----------  Firma de los enlaces de inscripcion (HMAC)  ----------*/
// DEBE coincidir con la del proyecto del formulario publico.
// Generar uno nuevo con:  php -r "echo bin2hex(random_bytes(32));"
const TOKEN_SECRET = 'reemplazar_por_un_secreto_aleatorio_largo';
