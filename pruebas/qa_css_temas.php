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

/*==============  Las hojas propias llevan versión  ==============*/
/*
| Los enlaces eran «core.css» a secas y Apache no envía Cache-Control, así que
| el navegador servía su copia guardada sin preguntar.
|
| Pasó de verdad: se corrigió el fondo del rótulo de la marca, el servidor
| entregaba la hoja nueva —comprobado pidiéndosela— y quien lo reportó seguía
| viendo el fallo. Dos veces. Un cambio de CSS que no llega no se distingue de
| un cambio que no se hizo.
|
| Con ?v=<fecha del archivo> la dirección cambia en cuanto el archivo cambia.
| Las librerías de terceros quedan fuera: cambian con una actualización, no
| con una edición, y su versión ya está en la ruta.
*/
$paginas = [
    'Basketball' => 'ds_basketball/dashboard/',
    'Hub'        => '',
    'Acceso'     => 'ds_basketball/login/',
    'League'     => 'ds_league/',
];
$sinVersion = [];
foreach ($paginas as $nombre => $ruta) {
    $ch = curl_init('http://localhost/barcelona/' . $ruta);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
                            CURLOPT_MAXREDIRS => 4, CURLOPT_TIMEOUT => 20,
                            CURLOPT_COOKIE => 'DigiSportsBasketball=dsqaui0000000000000']);
    $html = (string) curl_exec($ch);
    curl_close($ch);
    /* Hojas nuestras enlazadas SIN el parámetro de versión. */
    if (preg_match_all('~href="[^"]*ds_core/assets/css/([a-z]+\.css)"~', $html, $m)) {
        foreach ($m[1] as $h) { $sinVersion[] = "$nombre:$h"; }
    }
}
$af('las hojas propias se enlazan con versión', count($sinVersion) === 0,
    $sinVersion ? implode(' · ', array_slice($sinVersion, 0, 5)) : count($paginas) . ' páginas');

/*==============  Los controles de cabecera llegan al borde  ==============*/
/*
| AdminLTE trae un clearfix en .card-header::after —«display:block; clear:both;
| content:""»— pensado para maquetación con flotantes. En una cabecera declarada
| flex ese pseudoelemento cuenta como UN ELEMENTO MÁS, así que con
| justify-content: space-between el reparto era
|
|     título · grupo de controles · fantasma
|
| y el fantasma se quedaba el borde derecho. En ds_arena/instalacionList los
| selects y el botón «Nueva instalación» acababan a 318 px del borde.
|
| Se comprueba la POSICIÓN, no la regla: que el último hijo de cada cabecera
| flex termine en el borde interior. Una regla correcta que no llega al
| elemento no coloca nada, y en este proyecto ya ha pasado cuatro veces.
*/
$descolgadas = [];
foreach (['panel', 'instalacionList', 'horarioList', 'bloqueoList',
          'clienteList', 'reservaList', 'monederoList'] as $vista) {
    $ch = curl_init('http://localhost/barcelona/ds_arena/' . $vista . '/');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
                            CURLOPT_MAXREDIRS => 4, CURLOPT_TIMEOUT => 20,
                            CURLOPT_COOKIE => 'DigiSportsBasketball=dsqaui0000000000000']);
    $html = (string) curl_exec($ch);
    curl_close($ch);
    /* Si la vista declara cabecera flex, debe pedir el reparto a los extremos. */
    if (preg_match_all('~class="card-header[^"]*\bd-flex\b[^"]*"~', $html, $m)) {
        foreach ($m[0] as $clase) {
            if (!str_contains($clase, 'justify-content-between')) { $descolgadas[] = "$vista"; }
        }
    }
}
$af('las cabeceras flex de Arena reparten a los extremos',
    count($descolgadas) === 0,
    $descolgadas ? implode(' · ', array_unique($descolgadas)) : '7 vistas');

/* Y que el clearfix esté neutralizado, que es lo que lo hacía fallar. */
$css = (string) file_get_contents('c:/wamp64/www/barcelona/ds_core/assets/css/core.css');
$af('el clearfix no estorba en las cabeceras flex',
    (bool) preg_match('~\.card-header\.d-flex::after[^{]*\{[^}]*display:\s*none~s', $css),
    'regla presente en core.css');

/*==============  También los estilos EN LÍNEA  ==============*/
/*
| Esta suite leía reglas de hojas y de bloques <style>, pero no los atributos
| style= del marcado. Por ahí se coló «background:#fff» en las tarjetas de día
| de horarioList: en tema oscuro el texto hereda el claro del tema y quedaba
| blanco sobre blanco, con contraste 1.30. Y lo mismo en la tabla de moduloRol.
|
| Un fondo claro escrito a mano necesita o un color de texto a juego, o —mejor—
| un token del tema, que es lo que se hizo: var(--bs-body-bg).
|
| CASOS ACEPTADOS, con su motivo. No es una lista para acallar la prueba: es la
| diferencia entre «fondo claro con texto encima» y «fondo claro sin texto».
*/
$aceptadosEnLinea = [
    'organizacionForm-view.php' => 'fondo de la vista previa del logotipo: blanco a propósito, sin texto',
    'sedeForm-view.php'         => 'igual que organizacionForm',
    'estadisticas-view.php'     => 'línea divisoria de 1 px, sin texto',
];

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

$enLinea = [];
$revisados = 0;
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator('c:/wamp64/www/barcelona', FilesystemIterator::SKIP_DOTS));
foreach ($it as $arch) {
    if (!$arch->isFile()) { continue; }
    $ruta = strtr($arch->getPathname(), chr(92), '/');
    if (!preg_match('~\.php$~', $ruta))                              { continue; }
    if (preg_match('~/pruebas/|/borrar/|/vendor/~', $ruta))          { continue; }
    $revisados++;
    $txt = (string) file_get_contents($ruta);
    /* Se captura la ETIQUETA entera, no solo el atributo: el color puede
       venir de una clase (text-muted, text-dark) y no del style=. Mirar
       solo dentro del atributo daba falsos positivos. */
    if (!preg_match_all('~<[a-z]+[^>]*style\s*=\s*"([^"]*)"[^>]*>~i', $txt, $mm, PREG_SET_ORDER)) { continue; }
    foreach ($mm as $par) {
        $etiqueta = $par[0];
        $estilo   = $par[1];
        /* Una clase de color cuenta igual que color: en el estilo. */
        if (preg_match('~class="[^"]*\btext-[a-z]~', $etiqueta)) { continue; }
        /* Un elemento VACIO no puede fallar de contraste: no lleva texto.
           Los cuadraditos de leyenda de dashboard-operativo son <i></i>. */
        $tras = substr($txt, (int) strpos($txt, $etiqueta) + strlen($etiqueta), 40);
        if (preg_match('~^\s*</~', $tras)) { continue; }
        if (!preg_match('~background(?:-color)?\s*:\s*(#[0-9a-fA-F]{3,6})~i', $estilo, $bg)) { continue; }
        $L = $luminancia($bg[1]);
        if ($L === null || $L < 0.5)                     { continue; }
        if (preg_match('~(?<!-)color\s*:~i', $estilo))    { continue; }
        if (isset($aceptadosEnLinea[basename($ruta)]))    { continue; }
        $enLinea[] = basename($ruta) . ' (' . $bg[1] . ')';
    }
}

$af('ningún estilo en línea fija fondo claro sin color',
    count($enLinea) === 0,
    $enLinea ? implode(' · ', array_slice($enLinea, 0, 4)) : "$revisados archivos");

/*==============  Trampas de la PLANTILLA que usamos sin corregir  ==============*/
/*
| Tercer hueco, encontrado por el usuario en ds_league/panel: AdminLTE define
|
|     .callout { color: var(--lte-callout-color, inherit);
|                background-color: var(--lte-callout-bg, var(--bs-gray-100)) }
|
| La escala de grises de Bootstrap NO cambia con el tema —sólo cambian los
| tokens semánticos (--bs-body-bg, --bs-tertiary-bg…)—, así que ese fondo es
| claro SIEMPRE mientras el texto sigue al tema. Medido en oscuro: 1.24.
|
| Este rastreo lee el CSS de terceros (que las otras pasadas excluyen a
| propósito) buscando ESE patrón, y exige que toda clase así que usemos en
| nuestro marcado esté corregida en core.css. La corrección correcta es fijar
| las variables que la plantilla expone, no competir en especificidad.
*/
$hojaLte = 'c:/wamp64/www/barcelona/ds_core/assets/vendor/adminlte4/css/adminlte.min.css';
$nuestro = 'c:/wamp64/www/barcelona/ds_core/assets/css/core.css';

if (!is_file($hojaLte)) {
    $af('la hoja de AdminLTE está donde se espera', false, $hojaLte);
} else {
    $lte = (string) file_get_contents($hojaLte);
    $css = (string) file_get_contents($nuestro);

    /* Grises fijos: los que Bootstrap NO redefine bajo [data-bs-theme=dark]. */
    $fijos = '--bs-(?:gray-[1-9]00|white|light)';
    $trampas = [];
    preg_match_all('~\.([a-z][a-z0-9-]*)\s*\{([^}]*)\}~i', $lte, $reglas, PREG_SET_ORDER);
    foreach ($reglas as $r) {
        [$todo, $clase, $cuerpo] = $r;
        if (!preg_match('~background(?:-color)?\s*:[^;]*var\(\s*' . $fijos . '~i', $cuerpo)) { continue; }
        if (!preg_match('~(?<![a-z-])color\s*:[^;]*inherit~i', $cuerpo))                     { continue; }
        $trampas[$clase] = true;
    }

    /* ¿Cuáles usamos de verdad en nuestro marcado?
       Se recorre en PHP: shell_exec(grep) no existe en el PATH de PHP bajo
       Windows y devolvía cadena vacía, con lo que la prueba daba verde sin
       haber mirado nada. */
    $marcado = '';
    $it2 = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator('c:/wamp64/www/barcelona', FilesystemIterator::SKIP_DOTS));
    foreach ($it2 as $a2) {
        if (!$a2->isFile()) { continue; }
        $r2 = strtr($a2->getPathname(), chr(92), '/');
        if (!preg_match('~/ds_(league|arena|basketball|core)/.*\.php$~', $r2)) { continue; }
        if (preg_match('~/borrar/|/vendor/~', $r2))                            { continue; }
        $marcado .= (string) file_get_contents($r2);
    }
    $af('el recorrido del marcado encontró archivos',
        strlen($marcado) > 100000, strlen($marcado) . ' bytes');

    $usadas = [];
    foreach (array_keys($trampas) as $clase) {
        if (preg_match('~class="[^"]*\b' . preg_quote($clase, '~') . '\b~', $marcado)) {
            $usadas[] = $clase;
        }
    }

    /* Cada una usada tiene que estar corregida en core.css. */
    $sinCorregir = [];
    foreach ($usadas as $clase) {
        if (!preg_match('~\.' . preg_quote($clase, '~') . '\b[^{]*\{[^}]*--lte-' . preg_quote($clase, '~') . '-bg~s', $css)) {
            $sinCorregir[] = $clase;
        }
    }

    $af('las clases de AdminLTE con fondo fijo y texto heredado están corregidas',
        count($sinCorregir) === 0,
        $sinCorregir ? implode(', ', $sinCorregir)
                     : count($usadas) . ' usadas de ' . count($trampas) . ' detectadas');
}

/*==============  Una sola tarjeta de indicador  ==============*/
/*
| Habia TRES implementaciones de la misma tarjeta: .info-box (el componente de
| AdminLTE) en Basketball, un .ds-kpi propio en Arena y Core —con el valor AL
| LADO de la etiqueta, no encima—, y en League una card con estilos en linea.
| El usuario pidio que las de Arena se vieran como las de Basketball y que eso
| llegara al resto, asi que los cuatro modulos usan ahora el componente de la
| plantilla y .ds-kpi se retiro de core.css.
|
| Se comprueban las dos mitades: que el componente propio no reaparezca, y que
| los paneles sigan trayendo tarjetas. Sin la segunda, borrar los paneles
| enteros dejaria la prueba en verde.
*/
$paneles = [
    'ds_arena/views/panel-view.php',
    'ds_core/admin/views/panel-view.php',
    'ds_league/views/panel-view.php',
    'ds_basketball/app/views/content/dashboard-view.php',
];

$conKpiPropio = [];
$it3 = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator('c:/wamp64/www/barcelona', FilesystemIterator::SKIP_DOTS));
foreach ($it3 as $a3) {
    if (!$a3->isFile()) { continue; }
    $r3 = strtr($a3->getPathname(), chr(92), '/');
    if (!preg_match('~/ds_(league|arena|basketball|core)/.*\.(php|css)$~', $r3)) { continue; }
    if (preg_match('~/borrar/|/vendor/~', $r3))                                  { continue; }
    $t3 = (string) file_get_contents($r3);
    /* Fuera los comentarios: el de core.css que documenta la retirada
       nombra .ds-kpi a proposito y hacia fallar la prueba. */
    $t3 = preg_replace('~/\*.*?\*/~s', '', $t3) ?? $t3;
    /* Solo marcado y selectores reales: el comentario que documenta la retirada
       nombra .ds-kpi a proposito y no debe hacer fallar la prueba. */
    if (preg_match('~class="[^"]*\bds-kpi~', $t3) || preg_match('~^\s*\.[a-z .-]*\bds-kpi~m', $t3)) {
        $conKpiPropio[] = basename($r3);
    }
}
$af('no reaparece el componente propio de indicador',
    count($conKpiPropio) === 0,
    $conKpiPropio ? implode(', ', $conKpiPropio) : 'ds-kpi retirado');

$sinTarjetas = [];
foreach ($paneles as $rel) {
    $abs = 'c:/wamp64/www/barcelona/' . $rel;
    /* El CONTENEDOR, no la cadena: buscar 'info-box' a secas casaba con
       info-box-icon y aprobaba aunque el contenedor hubiera desaparecido. */
    if (!is_file($abs) || !preg_match('~class="[^"]*\binfo-box(?=[" ])~', (string) file_get_contents($abs))) {
        $sinTarjetas[] = basename(dirname(dirname($rel))) . '/' . basename($rel);
    }
}
$af('los cuatro paneles usan .info-box de la plantilla',
    count($sinTarjetas) === 0,
    $sinTarjetas ? implode(', ', $sinTarjetas) : count($paneles) . ' paneles');

printf("\nfallos: %d\n", $fallos);
exit($fallos === 0 ? 0 : 1);
