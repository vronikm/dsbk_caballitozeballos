<?php
/*
| Una regla que fija el fondo tiene que fijar también el color del texto.
|
| POR QUÉ ESTA SUITE EXISTE
|
| Dos fallos reales se colaron por aquí, y los dos igual:
|
|   .enlace-info      background: #e8f5e9  ← verde claro fijo, sin color
|   cumpleaniosList   color: var(--azul)   ← #054017 sobre el fondo del tema
|
| El fondo es un valor escrito a mano y el texto se hereda del tema. En claro
| se lee; en oscuro el texto es casi blanco sobre un fondo casi blanco. Nadie
| lo ve al escribirlo porque quien lo escribe trabaja en un solo tema.
|
| POR QUÉ NO LO PILLA LA SONDA DE CONTRASTE
|
| qa_identidad recorre 66 vistas midiendo lo que está PINTADO. Estos dos
| bloques están OCULTOS hasta que algo los revela: uno aparece al generar un
| enlace, el otro sólo el día que no cumple años nadie. Lo que no se dibuja
| no se puede medir.
|
| Esta suite ataca el mismo problema desde el otro lado: lee las REGLAS, no
| los píxeles. No necesita que el bloque se muestre.
|
| LO QUE NO COMPRUEBA
|
| No mide contraste: no puede, porque no sabe qué texto acabará dentro. Sólo
| señala la combinación que lo provoca —fondo fijo sin color— para que quien
| la escriba decida el color a conciencia.
*/
$RAIZ = 'c:/wamp64/www/barcelona';
$fallos = 0;
$af = function (string $t, bool $ok, string $d = '') use (&$fallos) {
    printf("  %-54s %s%s\n", $t, $ok ? 'OK' : 'FALLA', $d !== '' ? "  ($d)" : '');
    if (!$ok) { $fallos++; }
};

/*
| CASOS ACEPTADOS, cada uno con su motivo.
|
| No es una lista para acallar la prueba: es la diferencia entre «fondo claro
| sin texto encima» y «fondo claro con texto que hereda del tema». Si aparece
| uno nuevo, hay que mirarlo y decidir, no añadirlo aquí por costumbre.
*/
$aceptados = [
    '.ds-core .switch span'  => 'la pista del interruptor de tema: no lleva texto',
    '.carnet'                => 'el carné fija el color en sus hijos; comprobado: #000 y #333 sobre blanco',
    '.sg-qr'                 => 'contiene sólo el SVG del QR; el fondo blanco es requisito para que se pueda escanear',
];
$hojasAjenas = ['sweetalert2', 'adminlte', 'bootstrap', 'select2', 'datatables', 'overlayscrollbars', 'all.min'];

$fuentes = [];
foreach (['ds_basketball/app/views/content', 'ds_league/views', 'ds_arena/views',
          'ds_core/admin/views', 'ds_core/hub', 'ds_core/assets/css',
          'ds_basketball/app/views/dist/css'] as $d) {
    foreach (glob("$RAIZ/$d/*.{php,css}", GLOB_BRACE) as $f) { $fuentes[] = strtr($f, chr(92), '/'); }
}

$luminancia = function (string $hex): ?float {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) { $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2]; }
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) { return null; }
    $c = [];
    foreach ([0, 2, 4] as $i) {
        $v = hexdec(substr($hex, $i, 2)) / 255;
        $c[] = $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
    }
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
};

$nuevos = [];
$reglas = 0;
foreach ($fuentes as $f) {
    $base = basename($f);
    foreach ($hojasAjenas as $ajena) {
        if (str_contains(strtolower($base), $ajena)) { continue 2; }   /* librerías de terceros */
    }
    $t = (string) file_get_contents($f);
    /* Los comentarios se quitan ANTES de trocear: si no, un bloque de
       texto comentado acaba dentro del «selector» y el aviso sale
       ilegible. Paso al añadir el armazón claro. */
    $t = preg_replace('~/\*.*?\*/~s', '', $t);
    if (!preg_match_all('~([.#][a-zA-Z][\w-]*(?:[^{};]*)?)\{([^}]*)\}~', $t, $m, PREG_SET_ORDER)) { continue; }
    foreach ($m as $r) {
        $reglas++;
        $sel = trim(preg_replace('~\s+~', ' ', $r[1]));
        $cuerpo = $r[2];
        if (!preg_match('~background(-color)?\s*:\s*(#[0-9a-fA-F]{3,6})~', $cuerpo, $bg)) { continue; }
        $L = $luminancia($bg[2]);
        if ($L === null || $L < 0.5)                    { continue; }  /* sólo fondos claros */
        if (preg_match('~(?<!-)color\s*:~', $cuerpo))    { continue; }  /* ya fija el texto */

        $perdonado = false;
        foreach ($aceptados as $patron => $motivo) {
            if (str_starts_with($sel, $patron)) { $perdonado = true; break; }
        }
        /*
        | Un contenedor puede fijar el fondo y dejar el color a una regla
        | sobre sus hijos, que es un patrón legítimo y muy común en el
        | armazón: .ds-core__navbar pone el fondo y
        | .ds-core__navbar .nav-link pone el texto, dos líneas más abajo.
        | Si en el MISMO archivo hay una regla que desciende de este
        | selector y fija color, el caso está atendido.
        */
        if (!$perdonado) {
            $base_sel = preg_quote(trim(explode(',', $sel)[0]), '~');
            $hijoConColor = preg_match('~' . $base_sel . '\s+[^{,]+\{[^}]*(?<!-)color\s*:~', $t);
            if ($hijoConColor) { continue; }
        }
        if (!$perdonado) { $nuevos[] = "$base → $sel ({$bg[2]})"; }
    }
}

printf("  reglas CSS revisadas: %d en %d archivos propios\n\n", $reglas, count($fuentes));

$af('ninguna regla fija un fondo claro sin fijar el texto',
    count($nuevos) === 0,
    $nuevos ? implode(' · ', array_slice($nuevos, 0, 4)) : 'sin casos nuevos');

/* La prueba tiene que poder fallar: si no lee reglas, no comprueba nada. */
$af('la sonda está leyendo CSS de verdad', $reglas > 200, "$reglas reglas");

printf("\nfallos: %d\n", $fallos);
exit($fallos === 0 ? 0 : 1);
