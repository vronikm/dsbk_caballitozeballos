<?php
/*
| Todo lo que las vistas cargan apunta a un archivo que existe.
|
| POR QUE ESTA PRUEBA Y NO UN PASEO CON EL NAVEGADOR
|
| Se intento comprobarlo abriendo las 70 vistas navegables y anotando los
| 404. Salio limpio, y era enganoso: la mitad de esas vistas necesitan un
| identificador en la URL y sin el REDIRIGEN a su listado. Abrir pagosNew/
| lleva a pagosList/, asi que lo que se reviso fue pagosList catorce veces.
| Un barrido que dice «70 de 70» y en realidad mira treinta paginas es peor
| que no tener barrido, porque tranquiliza.
|
| Leyendo el marcado no hay redirecciones ni sesion ni parametros.
|
| TRES TRAMPAS QUE ESTA PRUEBA YA PISO
|
| 1. MARCADO COMENTADO. Once vistas parecian cargar un main.js inexistente.
|    Estaba dentro de <!--script ...-->: comentado desde hace anios.
|
| 2. RUTAS QUE NO SON ATRIBUTOS. La cabecera unificada no escribe href="...";
|    construye las rutas concatenando (APP_URL . 'app/...'), y exportar.js
|    las lleva en cadenas de JavaScript. Mirando solo atributos, las hojas
|    que usan 67 vistas quedaban FUERA. Se descubrio escondiendo
|    select2.min.css: la prueba siguio diciendo que todo estaba bien.
|
| 3. RUTAS SIN PREFIJO. src="app/views/..." sin APP_URL no cuelga de la raiz
|    del modulo: el navegador la resuelve contra la URL de la PAGINA.
|
| LIMITE, DICHO EN VOZ ALTA
|
| Esto no ve lo que un script decide cargar segun el caso. De eso se ocupan
| qa_exportar.mjs y qa_plugins_vivos.mjs. Ninguna sola basta.
*/
$RAIZ = 'c:/wamp64/www/barcelona';
$fallos = 0;

$mapa = [
    'APP_URL'    => "$RAIZ/ds_basketball/",
    'DS_HUB_URL' => "$RAIZ/",
];

$vistas = array_merge(
    glob("$RAIZ/ds_basketball/app/views/content/*.php"),
    glob("$RAIZ/ds_basketball/app/views/inc/*.php")
);

$rotos = $relativas = [];
$revisadas = $refs = $comentadas = $cadenas = 0;

/*==============  PASADA 1 · atributos src= y href=  ==============*/
foreach ($vistas as $v) {
    $txt = (string) file_get_contents($v);
    $revisadas++;

    $limpio = preg_replace('~<!--.*?-->~s', '', $txt, -1, $n);
    $comentadas += $n;

    if (!preg_match_all('~(?:src|href)\s*=\s*\x22([^\x22]+)\x22~i', $limpio, $m)) { continue; }

    foreach ($m[1] as $url) {
        if (preg_match('~^(https?:|//|\#|mailto:|data:|javascript:)~i', $url)) { continue; }
        if (!preg_match('~\.(css|js|png|jpe?g|gif|svg|woff2?|ttf|ico)(\?|$)~i', $url)) { continue; }

        $tienePrefijo = false;
        $ruta = $url;
        foreach ($mapa as $const => $dir) {
            $ruta = preg_replace('~<\?php\s+echo\s+' . $const . '\s*;?\s*\?>~i', $dir, $ruta, -1, $c);
            if ($c) { $tienePrefijo = true; }
        }
        if (str_contains($ruta, '<?php')) { continue; }
        $ruta = preg_replace('~\?.*$~', '', $ruta);

        if (!$tienePrefijo) { $relativas[] = basename($v) . '  →  ' . $url; continue; }

        $refs++;
        if (!is_file($ruta)) {
            $rotos[] = sprintf('%-32s %s', basename($v), str_replace("$RAIZ/", '', $ruta));
        }
    }
}

/*==============  PASADA 2 · rutas que viajan en cadenas  ==============*/
/* \x22 es la comilla doble y \x27 la simple: escritas asi, el patron no
   tiene que pelearse con las comillas de PHP. */
$fuentes = array_merge(
    glob("$RAIZ/ds_basketball/app/views/inc/*.php"),
    glob("$RAIZ/ds_core/assets/js/*.js"),
    glob("$RAIZ/ds_core/inc/*.php")
);
$pat = '~[\x22\x27]([^\x22\x27]*(?:app/views/dist|ds_core/assets)/[^\x22\x27]+'
     . '\.(?:css|js|png|jpe?g|gif|svg|woff2?|ttf|ico))[\x22\x27]~i';

foreach ($fuentes as $f) {
    $txt = (string) file_get_contents($f);
    if (!preg_match_all($pat, $txt, $mm)) { continue; }
    foreach ($mm[1] as $rel) {
        if (str_contains($rel, '<?php')) { continue; }
        $rel = ltrim($rel, '/');
        $cadenas++;
        $existe = false;
        foreach (["$RAIZ/ds_basketball/$rel", "$RAIZ/$rel"] as $c) {
            if (is_file($c)) { $existe = true; break; }
        }
        if (!$existe) { $rotos[] = sprintf('%-32s %s', basename($f), $rel); }
    }
}
$refs += $cadenas;

printf("  vistas revisadas: %d  ·  referencias: %d  (%d en cadenas)  ·  comentarios omitidos: %d\n\n",
       $revisadas, $refs, $cadenas, $comentadas);

if ($rotos) {
    echo "  APUNTAN A UN ARCHIVO QUE NO EXISTE:\n";
    foreach ($rotos as $r) { echo "    $r\n"; }
    $fallos++;
} else {
    echo "  todas resuelven a un archivo existente\n";
}

if ($relativas) {
    echo "\n  SIN PREFIJO (se resolverían contra la URL de la página):\n";
    foreach ($relativas as $r) { echo "    $r\n"; }
    $fallos++;
}

/* La prueba tiene que poder fallar. Si no resuelve nada, no prueba nada. */
if ($refs < 200 || $cadenas < 5) {
    printf("\n  FALLA: %d referencias y %d en cadenas; el patrón no ve el marcado\n", $refs, $cadenas);
    $fallos++;
}

printf("\nfallos: %d\n", $fallos);
exit($fallos === 0 ? 0 : 1);
