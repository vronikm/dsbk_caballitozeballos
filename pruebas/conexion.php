<?php
/*
| La conexión de las pruebas, en un solo sitio.
|
| POR QUE EXISTE
|
| Veintiocho archivos del arnés abrían la conexión escribiendo las
| credenciales a mano:
|
|     new PDO('mysql:host=localhost;dbname=digitech_barcelona', 'root', '')
|
| Y «root» con contraseña vacía NO era un valor de ejemplo: son las
| credenciales reales del ecosistema. Al ir a versionar pruebas/ —458
| archivos que nunca habían entrado en el repositorio— eso las habría
| publicado en texto plano, en un repositorio que además ha estado abierto.
|
| Aquí se leen de ds_core/config/app.php, que a su vez carga secrets.php,
| que está en .gitignore. La misma fuente que usa la aplicación: si un día
| cambian, las pruebas siguen funcionando sin tocar nada.
|
| LO QUE ESTO NO ARREGLA
|
| Que la base use «root» sin contraseña. Eso es un problema del servidor, no
| del repositorio, y en producción no puede quedarse así. Está anotado en
| DESPLIEGUE.md.
|
| USO
|
|     require_once __DIR__ . '/conexion.php';
|     $c = qa_conexion();
*/

require_once dirname(__DIR__) . '/ds_core/config/app.php';

function qa_conexion(): PDO
{
    static $con = null;
    if ($con instanceof PDO) { return $con; }

    $con = new PDO(
        'mysql:host=' . DB_SERVER . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            /* El mismo cotejamiento que la aplicación: sin esto, comparar un
               parámetro con una columna derivada de literal revienta con el
               error 1267. Ver qa_utf8mb4.php. */
            PDO::MYSQL_ATTR_INIT_COMMAND => defined('DS_DB_INIT_COMANDO')
                ? DS_DB_INIT_COMANDO
                : 'SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci',
        ]
    );
    return $con;
}
