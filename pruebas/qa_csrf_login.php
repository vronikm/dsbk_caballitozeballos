<?php
/*
| CSRF del formulario de acceso.
|
| NO se usan credenciales reales. Para probar que el testigo válido deja
| pasar basta con enviar uno correcto y una clave equivocada: si la
| respuesta es «usuario o contraseña incorrectos» en vez del error de
| testigo, el control quedó atrás y la ejecución siguió. Eso es justo lo
| que hay que demostrar, y sin escribir una contraseña en ningún sitio.
|
| OJO CON CÓMO SE LEE EL ERROR: showError() guarda el mensaje en sesión y
| REDIRIGE, así que el POST responde 302 con cuerpo vacío. El aviso sale
| en la petición siguiente. Mirar sólo la respuesta del POST no prueba
| nada, y de hecho hacía fallar la primera versión de esta prueba.
*/
$BASE = 'http://localhost/barcelona/ds_basketball/login/';

$fallos = 0;
function af(string $t, bool $ok, string $d = ''): void {
    global $fallos;
    echo '  ' . str_pad($t, 58) . ($ok ? 'OK' : 'FALLA') . ($d ? "  ({$d})" : '') . "\n";
    if (!$ok) { $fallos++; }
}

/** Petición con su propio tarro de cookies. */
function pedir(string $url, ?array $post, string $galletas): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $galletas,
        CURLOPT_COOKIEFILE     => $galletas,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $cuerpo = (string)curl_exec($ch);
    $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$codigo, $cuerpo];
}

/** Testigo presente en el HTML. */
function testigo(string $html): string {
    return preg_match('/name="ds_csrf"\s+value="([a-f0-9]{64})"/', $html, $m) ? $m[1] : '';
}

/** Texto del SweetAlert de error. */
function aviso(string $html): string {
    return preg_match('/text:\s*"((?:[^"\\\\]|\\\\.)*)"/', $html, $m)
        ? stripcslashes($m[1]) : '';
}

/** Envía el formulario y devuelve el aviso que aparece después. */
function entrar(string $url, array $post, string $galletas): string {
    pedir($url, $post, $galletas);
    [, $html] = pedir($url, null, $galletas);
    return aviso($html);
}

$galletas  = sys_get_temp_dir() . '/qa_csrf_'  . getmypid() . '.txt';
$galletas2 = sys_get_temp_dir() . '/qa_csrf2_' . getmypid() . '.txt';
@unlink($galletas); @unlink($galletas2);

$credenciales = ['login_usuario' => 'usuarioqa', 'login_clave' => 'noimporta1234'];

/*==============  1. La página trae testigo  ==============*/
[$c, $html] = pedir($BASE, null, $galletas);
af('la página de acceso responde 200', $c === 200, "HTTP {$c}");

$t1 = testigo($html);
af('el formulario incluye un testigo de 64 hex', strlen($t1) === 64,
   $t1 === '' ? 'no hay campo ds_csrf' : substr($t1, 0, 16) . '…');

/*==============  2. Estable entre recargas  ==============*/
[, $html2] = pedir($BASE, null, $galletas);
af('no cambia al recargar (dos pestañas no se estorban)',
   testigo($html2) === $t1 && $t1 !== '');

/*==============  3. Sin testigo  ==============*/
$msg = entrar($BASE, $credenciales, $galletas);
af('sin testigo, el acceso se rechaza', str_contains($msg, 'caduc'), $msg ?: '(sin aviso)');

/*==============  4. Testigo inventado  ==============*/
$msg = entrar($BASE, $credenciales + ['ds_csrf' => str_repeat('a', 64)], $galletas);
af('con un testigo inventado, se rechaza', str_contains($msg, 'caduc'), $msg ?: '(sin aviso)');

/*==============  5. Testigo truncado  ==============*/
$msg = entrar($BASE, $credenciales + ['ds_csrf' => 'abc'], $galletas);
af('un testigo truncado se rechaza', str_contains($msg, 'caduc'), $msg ?: '(sin aviso)');

/*==============  6. Testigo de otra sesión  ==============*/
[, $html3] = pedir($BASE, null, $galletas2);
$tOtra = testigo($html3);
af('otra sesión recibe un testigo distinto', $tOtra !== '' && $tOtra !== $t1,
   $tOtra === $t1 ? 'MISMO VALOR EN DOS SESIONES' : '');

$msg = entrar($BASE, $credenciales + ['ds_csrf' => $tOtra], $galletas);
af('el testigo de otra sesión se rechaza', str_contains($msg, 'caduc'), $msg ?: '(sin aviso)');

/*==============  7. Con el testigo BUENO el control queda atrás  ==============*/
/* El testigo sigue siendo el mismo pese a los intentos fallidos: no se
   renueva al fallar, justo para que el usuario no tenga que recargar. */
[, $htmlAhora] = pedir($BASE, null, $galletas);
$tAhora = testigo($htmlAhora);
af('el testigo sobrevive a los intentos fallidos', $tAhora === $t1,
   $tAhora === $t1 ? '' : 'cambió');

$msg = entrar($BASE, $credenciales + ['ds_csrf' => $tAhora], $galletas);
af('con el testigo correcto, la validación continúa',
   $msg !== '' && !str_contains($msg, 'caduc'), $msg ?: '(sin aviso)');
af('y falla por credenciales, que es lo esperado',
   str_contains(mb_strtolower($msg), 'incorrect')
   || str_contains(mb_strtolower($msg), 'intentos'), $msg);

@unlink($galletas); @unlink($galletas2);

echo "\nfallos: {$fallos}\n";
exit($fallos === 0 ? 0 : 1);
