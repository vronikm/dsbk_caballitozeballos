<?php
/*
| Los distintivos cuyo color se construye desde PHP.
|
| El convertidor de clases no puede verlos: en
|
|     class="badge badge-<?php echo $tono[...]; ?>"
|
| el nombre de la clase no existe en el archivo, se arma al ejecutar. Una
| expresión regular sobre «badge-success» no encuentra nada, y el
| distintivo se queda sin color en Bootstrap 5 —sin error, sin aviso, sólo
| gris—.
|
| Aquí se sustituye el prefijo dejando intacta la expresión PHP que elige
| el color.
|
| btn- y bg- NO se tocan: btn-primary y bg-success siguen existiendo en
| Bootstrap 5. Sólo badge-* pasó a text-bg-*.
|
| Uso: migrar_badges.php <carpeta> [aplicar]
*/
$carpeta = $argv[1] ?? '';
$aplicar = ($argv[2] ?? '') === 'aplicar';

if ($carpeta === '' || !is_dir($carpeta)) {
    fwrite(STDERR, "uso: migrar_badges.php <carpeta> [aplicar]\n");
    exit(1);
}

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($carpeta));
$total = 0; $archivos = 0;

foreach ($it as $f) {
    if ($f->getExtension() !== 'php') { continue; }
    $ruta = str_replace('\\', '/', $f->getPathname());
    if (str_contains($ruta, '/dist/') || str_contains($ruta, '/lib/')) { continue; }

    $t = (string)file_get_contents($ruta);

    /* Sólo cuando «badge-» va precedido de la clase «badge», que es como
       se usa siempre aquí. Así no se toca ninguna clase propia que
       casualmente empiece igual. */
    $nuevo = preg_replace(
        '/\bbadge\s+badge-(<\?php)/',
        'badge text-bg-$1',
        $t, -1, $n);

    if ($n > 0) {
        $archivos++;
        $total += $n;
        printf("  %-36s %2d distintivos\n", basename($ruta), $n);
        if ($aplicar) { file_put_contents($ruta, $nuevo); }
    }
}

echo "\n  " . ($aplicar ? 'APLICADOS' : 'simulados') . ": {$total} en {$archivos} archivos\n";

/* Lo que quede con badge- y no se haya podido convertir hay que verlo. */
echo "\n=== badge- que siguen sin convertir ===\n";
$queda = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($carpeta)) as $f) {
    if ($f->getExtension() !== 'php') { continue; }
    $ruta = str_replace('\\', '/', $f->getPathname());
    if (str_contains($ruta, '/dist/') || str_contains($ruta, '/lib/')) { continue; }
    $t = (string)file_get_contents($ruta);
    if (preg_match_all('/badge-(?!pill)[a-z<]/', $t, $m)) {
        printf("  %-36s %d\n", basename($ruta), count($m[0]));
        $queda += count($m[0]);
    }
}
if ($queda === 0) { echo "  ninguno\n"; }
