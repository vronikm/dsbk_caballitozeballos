<?php
/*
| Usuario desechable para probar el segundo factor.
|
| NO se usa la cuenta real: la prueba activa y desactiva el segundo factor
| varias veces, y si se interrumpiera a media faena dejaría al usuario con
| una verificación cuyo secreto sólo conocería el script.
|
| Uso: qa2fa_usuario.php crear|borrar
*/
require_once __DIR__ . '/conexion.php';
$c = qa_conexion();

$USUARIO = 'qa2fatester';
$CLAVE   = 'Qa2fa!Prueba#2026';

$modo = $argv[1] ?? 'crear';

if ($modo === 'crear') {
    $c->prepare("DELETE FROM seguridad_usuario WHERE usuario_usuario = :u")
      ->execute([':u' => $USUARIO]);

    $c->prepare(
        "INSERT INTO seguridad_usuario
                (usuario_empleadoid, usuario_usuario, usuario_rolid, usuario_clave,
                 usuario_fechacreacion, usuario_cambiaclave, usuario_tienebloqueo,
                 usuario_estado)
         VALUES (NULL, :u, 1, :c, NOW(), 'N', 'N', 'A')")
      ->execute([':u' => $USUARIO, ':c' => password_hash($CLAVE, PASSWORD_BCRYPT)]);

    $id = (int)$c->lastInsertId();
    echo "USUARIO={$USUARIO}\n";
    echo "CLAVE={$CLAVE}\n";
    echo "ID={$id}\n";
    exit;
}

if ($modo === 'borrar') {
    $id = (int)$c->query("SELECT usuario_id FROM seguridad_usuario
                           WHERE usuario_usuario = " . $c->quote($USUARIO))->fetchColumn();

    if ($id > 0) {
        /* Las tablas del segundo factor van en CASCADE, pero se borran
           explícitamente para que el recuento diga la verdad. */
        $r = $c->prepare("DELETE FROM seguridad_2fa_recuperacion WHERE rec_usuarioid = :i");
        $r->execute([':i' => $id]);
        $nRec = $r->rowCount();

        $e = $c->prepare("DELETE FROM seguridad_2fa_evento WHERE ev_usuarioid = :i");
        $e->execute([':i' => $id]);
        $nEv = $e->rowCount();

        $c->prepare("DELETE FROM seguridad_usuario WHERE usuario_id = :i")->execute([':i' => $id]);

        echo "borrado usuario {$id}: {$nRec} códigos, {$nEv} eventos\n";
    } else {
        echo "no existía\n";
    }

    $a = $c->prepare("DELETE FROM seguridad_intento_acceso WHERE intento_usuario = :u");
    $a->execute([':u' => $USUARIO]);
    echo "intentos de acceso borrados: " . $a->rowCount() . "\n";

    /* Lo del usuario real no se toca. */
    echo "usuarios que quedan: "
       . $c->query("SELECT COUNT(*) FROM seguridad_usuario")->fetchColumn() . "\n";
    exit;
}

fwrite(STDERR, "modo desconocido\n");
exit(1);
