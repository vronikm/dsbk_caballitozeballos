<?php
/*
| Ninguna vista puede quedarse sin DOCTYPE.
|
| POR QUE ESTA COMPROBACION EXISTE
|
| Veintidos vistas se dibujaban en modo quirks. Se descubrio persiguiendo un
| texto ilegible en el calendario: en tema oscuro las cabeceras de dia
| salian oscuras sobre fondo oscuro, 1.00 de contraste, y ninguna hoja de
| estilos lo explicaba. Preguntandole al motor que declaraciones aplicaban
| aparecio una regla de la hoja del NAVEGADOR:
|
|     table { color: -internal-quirk-inherit }
|
| Esa regla solo existe en modo quirks. Y el modo quirks se activa cuando
| falta el DOCTYPE.
|
| EN MODO QUIRKS CAMBIAN COSAS QUE BOOTSTRAP 5 DA POR SENTADAS
|
| El modelo de caja, la herencia de color en tablas, el manejo de
| line-height, los porcentajes de altura. La pagina puede parecer correcta y
| comportarse distinto en detalles que solo se notan al cambiar de tema o de
| tamaño de pantalla, que es exactamente lo que paso.
|
| SE COMPRUEBA SOBRE EL ARCHIVO, NO SOBRE LA PAGINA
|
| Es instantaneo y no depende de que la vista se pueda alcanzar por URL:
| varias exigen identificadores o llegan por POST. Ademas se confirma en el
| navegador una muestra, para que la comprobacion estatica no viva de una
| suposicion.
*/
$dirs = ['ds_basketball/app/views/content', 'ds_league/views', 'ds_arena/views',
         'ds_core/admin/views', 'ds_core/inc', 'ds_core/hub'];
$raiz = 'c:/wamp64/www/barcelona/';

$sin = []; $total = 0;

foreach ($dirs as $d) {
    foreach (glob($raiz . $d . '/*.php') ?: [] as $f) {
        $t = (string)file_get_contents($f);
        /* Solo las que producen un documento completo: los fragmentos que se
           incluyen dentro de otra vista no llevan DOCTYPE ni deben llevarlo. */
        if (!preg_match('/<html[\s>]/i', $t)) { continue; }
        $total++;
        if (!preg_match('/<!DOCTYPE\s+html/i', $t)) { $sin[] = basename($f, '-view.php'); }
    }
}

$fallos = 0;
$af = function (string $t, bool $ok, string $d = '') use (&$fallos) {
    printf("  %-48s %s%s\n", $t, $ok ? 'OK' : 'FALLA', $d ? "  ($d)" : '');
    if (!$ok) { $fallos++; }
};

$af("las $total vistas completas declaran DOCTYPE", empty($sin),
    $sin ? count($sin) . ' sin él: ' . implode(' ', array_slice($sin, 0, 4)) : '');

/*----------  Confirmacion en el navegador  ----------*/
/* Una muestra de las que estuvieron en quirks. Si la comprobacion estatica
   fuera suficiente por si sola no haria falta, pero da por hecho que el
   DOCTYPE es lo primero que sale, y eso conviene verificarlo de verdad. */
$muestra = ['agenda', 'carnetList', 'alumnoList', 'buscarAsistencia'];
$malas = [];

foreach ($muestra as $v) {
    $ch = curl_init('http://localhost/barcelona/ds_basketball/' . $v . '/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIE         => 'DigiSportsBasketball=dsqaui0000000000000',
        CURLOPT_TIMEOUT        => 15,
    ]);
    $html = (string)curl_exec($ch);
    $cod  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($cod !== 200) { continue; }

    /* El DOCTYPE tiene que ser lo primero que llega, sin nada delante
       salvo espacios en blanco. */
    if (!preg_match('/^\s*<!DOCTYPE\s+html/i', $html)) {
        $antes = trim(substr($html, 0, 40));
        $malas[] = $v . ' (empieza con «' . substr($antes, 0, 22) . '»)';
    }
}

$af('y lo sirven como primera cosa del documento', empty($malas),
    implode(' · ', $malas));

printf("\nfallos: %d\n", $fallos);
exit($fallos === 0 ? 0 : 1);
