<?php
/*
| Crea un archivo de sesión de prueba SIN escribirlo a mano.
|
| Escribir «nombre|s:11:"QA Segundo";» con una longitud equivocada —son 10
| caracteres, no 11— hace que PHP no pueda decodificar la sesión y la
| descarte ENTERA, en silencio. El síntoma es una redirección al login sin
| ninguna pista, y se pierde un buen rato buscándolo en el sitio
| equivocado. Con serialize() la longitud no se puede equivocar.
|
| Uso: sesion_qa.php <sid> <usuario> <usuarioid> <rol> [nombre]
*/
[$_, $sid, $usuario, $usuarioid, $rol] = array_pad(array_slice($argv, 0, 5), 5, null);
$nombre   = $argv[5] ?? 'QA';
$empleado = (int)($argv[6] ?? 0);

if (!$sid || !$usuario) {
    fwrite(STDERR, "uso: sesion_qa.php <sid> <usuario> <usuarioid> <rol> [nombre] [empleadoid]\n");
    exit(1);
}

/*
| CUIDADO CON EL NOMBRE DE LA CLAVE
|
| «usuario_id» NO es el id del usuario: es el id de su ficha de EMPLEADO, y
| es lo que lee empleado_actual(). El id del usuario esta en «usuarioid», sin
| guion. Dejarlo en cero hace que el panel operativo del dashboard no se
| dibuje nunca y en su lugar salga el aviso de que no hay ficha vinculada —
| que fue exactamente lo que despisto al probarlo—.
*/
$datos = [
    'usuario'        => (string)$usuario,
    'usuarioid'      => (int)$usuarioid,
    'rol'            => (int)$rol,
    'usuario_id'     => $empleado,
    'sede'           => '',
    'nombre'         => (string)$nombre,
    'foto'           => '',
    'identificacion' => '',
];

/* El formato de PHP para sesiones es «clave|valorSerializado» concatenado,
   que NO es lo mismo que serialize() del array completo. */
$texto = '';
foreach ($datos as $k => $v) {
    $texto .= $k . '|' . serialize($v);
}

$ruta = 'c:/wamp64/tmp/sess_' . $sid;
file_put_contents($ruta, $texto);

echo "sesión {$sid} -> {$usuario} (id {$usuarioid}, rol {$rol}), "
   . strlen($texto) . " bytes\n";
