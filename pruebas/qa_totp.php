<?php
/*
| TOTP contra los vectores de prueba del RFC 6238.
|
| Es la única forma de saber que la implementación es correcta: si sólo se
| comprueba que «genera un código de 6 dígitos y luego lo acepta», una
| implementación equivocada pasa la prueba con las dos mitades igual de
| mal, y el fallo aparece el día que alguien use Google Authenticator.
|
| Los vectores del RFC vienen con secreto en ASCII («12345678901234567890»),
| así que se codifica a base32 para pasarlo por la misma puerta que usa la
| aplicación.
*/
require 'c:/wamp64/www/barcelona/ds_core/inc/dosfactores.php';

$fallos = 0;
function af(string $t, bool $ok, string $d = ''): void {
    global $fallos;
    echo '  ' . str_pad($t, 54) . ($ok ? 'OK' : 'FALLA') . ($d ? "  ({$d})" : '') . "\n";
    if (!$ok) { $fallos++; }
}

/*==============  1. Base32 de ida y vuelta  ==============*/
$secretoAscii = '12345678901234567890';
$b32 = base32_codificar($secretoAscii);
af('base32 del secreto del RFC es GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
   $b32 === 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $b32);
af('decodificar deshace exactamente lo codificado',
   base32_decodificar($b32) === $secretoAscii);

foreach (['', 'a', 'ab', 'abc', 'abcd', 'abcde', 'abcdef', random_bytes(20)] as $i => $bruto) {
    $ok = base32_decodificar(base32_codificar($bruto)) === $bruto;
    if (!$ok) { af("ida y vuelta de {$i} bytes", false, bin2hex($bruto)); }
}
af('ida y vuelta con longitudes sueltas y 20 bytes al azar', true);

/*==============  2. Vectores del RFC 6238 (SHA1, 8 dígitos)  ==============*/
/* El RFC los publica con 8 dígitos; aquí se usan 6, así que se comparan
   los seis últimos, que es lo que produce el mismo truncamiento. */
$vectores = [
    [59,          '94287082'],
    [1111111109,  '07081804'],
    [1111111111,  '14050471'],
    [1234567890,  '89005924'],
    [2000000000,  '69279037'],
    [20000000000, '65353130'],
];

foreach ($vectores as [$t, $esperado8]) {
    $esperado6 = substr($esperado8, -6);
    $obtenido  = totp_codigo($b32, $t);
    af("RFC 6238  t={$t}  ->  {$esperado6}", $obtenido === $esperado6, $obtenido);
}

/*==============  3. La ventana de tolerancia  ==============*/
$s = totp_secreto_nuevo();
$ahora = time();

af('acepta el código del momento', totp_valido($s, totp_codigo($s, $ahora), $ahora));
af('acepta el código anterior (reloj atrasado)',
   totp_valido($s, totp_codigo($s, $ahora, -1), $ahora));
af('acepta el código siguiente (reloj adelantado)',
   totp_valido($s, totp_codigo($s, $ahora, 1), $ahora));
af('RECHAZA dos pasos atrás (60 s ya es demasiado)',
   !totp_valido($s, totp_codigo($s, $ahora, -2), $ahora));
af('RECHAZA dos pasos adelante',
   !totp_valido($s, totp_codigo($s, $ahora, 2), $ahora));

/*==============  4. Lo que no debe pasar  ==============*/
af('rechaza un código de 5 dígitos',  !totp_valido($s, '12345', $ahora));
af('rechaza un código de 7 dígitos',  !totp_valido($s, '1234567', $ahora));
af('rechaza vacío',                   !totp_valido($s, '', $ahora));
af('rechaza letras',                  !totp_valido($s, 'ABCDEF', $ahora));

$otro = totp_secreto_nuevo();
af('el código de otro secreto no vale',
   !totp_valido($s, totp_codigo($otro, $ahora), $ahora));

/* Un secreto distinto debe dar un código distinto casi siempre; con 6
   dígitos hay 1 entre un millón de coincidir, así que se prueba varias
   veces y basta con que no coincidan todas. */
$coinciden = 0;
for ($i = 0; $i < 20; $i++) {
    $a = totp_secreto_nuevo();
    $b = totp_secreto_nuevo();
    if (totp_codigo($a, $ahora) === totp_codigo($b, $ahora)) { $coinciden++; }
}
af('dos secretos distintos no producen el mismo código', $coinciden === 0,
   $coinciden . ' coincidencias de 20');

/*==============  5. Secretos  ==============*/
$s1 = totp_secreto_nuevo();
$s2 = totp_secreto_nuevo();
af('el secreto tiene 32 caracteres base32', strlen($s1) === 32, strlen($s1) . '');
af('sólo usa el alfabeto base32', preg_match('/^[A-Z2-7]+$/', $s1) === 1, $s1);
af('dos secretos seguidos son distintos', $s1 !== $s2);
af('se muestra en grupos de cuatro',
   totp_secreto_legible('ABCDEFGH') === 'ABCD EFGH', totp_secreto_legible('ABCDEFGH'));

/* Se tolera cómo lo pegue el usuario. */
af('acepta el secreto con espacios',
   totp_valido(totp_secreto_legible($s1), totp_codigo($s1, $ahora), $ahora));
af('acepta el secreto en minúsculas',
   totp_valido(strtolower($s1), totp_codigo($s1, $ahora), $ahora));

/*==============  6. La URI del QR  ==============*/
$uri = totp_uri($s1, 'AdminBCC', 'DigiSports');
af('la URI empieza por otpauth://totp/', str_starts_with($uri, 'otpauth://totp/'), '');
af('lleva el emisor en la etiqueta y como parámetro',
   str_contains($uri, 'DigiSports:AdminBCC') && str_contains($uri, 'issuer=DigiSports'));
af('lleva el secreto', str_contains($uri, 'secret=' . $s1));
af('declara algoritmo, dígitos y periodo',
   str_contains($uri, 'algorithm=SHA1') && str_contains($uri, 'digits=6')
   && str_contains($uri, 'period=30'));

$uriRaro = totp_uri($s1, 'a b@c.ec', 'Escuela Barcelona');
af('escapa espacios y arrobas del usuario y del emisor',
   !str_contains(explode('?', $uriRaro)[0], ' ')
   && str_contains($uriRaro, 'Escuela%20Barcelona'), explode('?', $uriRaro)[0]);

/*==============  7. Códigos de recuperación  ==============*/
$codigos = recuperacion_generar();
af('genera diez códigos', count($codigos) === 10, count($codigos) . '');
af('todos con formato XXXX-XXXX',
   count(array_filter($codigos, fn($c) => preg_match('/^[2-9A-HJ-NP-Z]{4}-[2-9A-HJ-NP-Z]{4}$/', $c))) === 10,
   $codigos[0]);
af('sin caracteres que se confundan al leer (I O 0 1)',
   !preg_match('/[IO01]/', implode('', $codigos)));
af('los diez son distintos', count(array_unique($codigos)) === 10);

$hash = recuperacion_hash($codigos[0]);
af('el hash guardado no contiene el código',
   !str_contains($hash, str_replace('-', '', $codigos[0])));
af('verifica el código tal cual', recuperacion_verificar($codigos[0], $hash));
af('verifica sin el guión',
   recuperacion_verificar(str_replace('-', '', $codigos[0]), $hash));
af('verifica en minúsculas', recuperacion_verificar(strtolower($codigos[0]), $hash));
af('no verifica otro código', !recuperacion_verificar($codigos[1], $hash));

echo "\nfallos: {$fallos}\n";
exit($fallos === 0 ? 0 : 1);
