<?php
/*
| core.css deja de tener colores clavados y pasa a seguir el tema.
|
| POR QUE AHORA Y NO DESPUES
|
| La capa de identidad se esta activando vista por vista. Cada vista que se
| active hereda lo que haya en este archivo, asi que si primero se extiende
| y luego se corrige el color hay que revisar dos veces todo lo activado. Se
| convierte antes.
|
| COMO FUNCIONA EL TEMA EN BOOTSTRAP 5.3
|
| No se invierten colores con CSS propio. Bootstrap define un juego de
| variables en :root y lo REDEFINE dentro de [data-bs-theme=dark]. Todo lo
| que se escriba en terminos de esas variables cambia solo:
|
|     --bs-body-bg          #fff  →  #212529
|     --bs-body-color       #212529  →  #dee2e6
|     --bs-border-color     gris claro  →  gris oscuro
|     --bs-secondary-color  gris medio  →  gris claro translucido
|
| Se comprobo que las cuatro existen en el AdminLTE cargado y que el bloque
| oscuro esta presente.
|
| LOS CUATRO TOKENS DEL ARCHIVO SON LA CLAVE
|
| --core-borde, --core-suave, --core-texto y --core-tenue los usan 20
| declaraciones. Apuntandolos a las variables de Bootstrap, esas veinte
| siguen el tema sin tocarlas una por una.
|
| LO QUE NO SE TOCA, Y POR QUE
|
| Los colores del navbar y del menu lateral (#cbd5e1 sobre fondo oscuro).
| Esa barra es oscura en los DOS temas —lleva data-bs-theme=dark fijo—, asi
| que su texto claro es correcto siempre. Cambiarlo a variables de tema lo
| romperia en modo claro.
|
| Uso: core_a_temas.php [aplicar]
*/
$archivo = 'c:/wamp64/www/barcelona/ds_core/assets/css/core.css';
$aplicar = ($argv[1] ?? '') === 'aplicar';

$t = (string)file_get_contents($archivo);
$orig = $t;

$paso = function (string $de, string $a, int $esperado) use (&$t) {
    $n = 0;
    $t = str_replace($de, $a, $t, $n);
    if ($n !== $esperado) {
        exit("FALLA en «" . substr(trim($de), 0, 46) . "»: $n cambios, se esperaban $esperado\n");
    }
};

/*----------  1. Los cuatro tokens  ----------*/
$paso(
"    --core-borde:   #e6e9ef;
    --core-suave:   #f6f8fb;
    --core-texto:   #1f2937;
    --core-tenue:   #6b7280;",

"    /* Apuntan a las variables de Bootstrap 5.3, que se redefinen solas
       dentro de [data-bs-theme=dark]. Asi las veinte declaraciones que
       usan estos tokens siguen el tema sin tocar ninguna. */
    --core-borde:   var(--bs-border-color);
    --core-suave:   var(--bs-tertiary-bg);
    --core-texto:   var(--bs-body-color);
    --core-tenue:   var(--bs-secondary-color);", 1);

/*----------  2. Las dos superficies blancas  ----------*/
$paso("    border-bottom: 1px solid var(--core-borde);\n    background: #fff;",
      "    border-bottom: 1px solid var(--core-borde);\n    background: var(--bs-body-bg);", 1);

$paso("    padding: 18px;\n    background: #fff;",
      "    padding: 18px;\n    background: var(--bs-body-bg);", 1);

/*----------  3. Las insignias de modulo  ----------*/
/* Son identidad de marca: su color no puede salir de la paleta de
   Bootstrap. Se declaran como pareja clara/oscura y la regla comun las
   consume, que es el patron que recomienda la propia 5.3. */
$paso(
'.ds-core .badge-modulo--basketball { border-color: #ffd0a3; color: #b45309; background: #fff7ed; }
.ds-core .badge-modulo--core       { border-color: #c7d2fe; color: #3730a3; background: #eef2ff; }
.ds-core .badge-modulo--arena      { border-color: #a5f3fc; color: #0e7490; background: #ecfeff; }
.ds-core .badge-modulo--league     { border-color: #ddd6fe; color: #5b21b6; background: #f5f3ff; }
.ds-core .badge-modulo--insights   { border-color: #bbf7d0; color: #15803d; background: #f0fdf4; }',

'/* Cada modulo declara su pareja de colores; la regla de abajo los usa.
   En claro, fondo palido y texto oscuro. En oscuro se invierte el papel:
   fondo del mismo tono pero translucido sobre el fondo oscuro, y texto
   claro del mismo color. Asi la insignia sigue diciendo «naranja es
   Basketball» en los dos temas. */
.ds-core .badge-modulo--basketball { --bm-borde: #ffd0a3; --bm-texto: #b45309; --bm-fondo: #fff7ed; }
.ds-core .badge-modulo--core       { --bm-borde: #c7d2fe; --bm-texto: #3730a3; --bm-fondo: #eef2ff; }
.ds-core .badge-modulo--arena      { --bm-borde: #a5f3fc; --bm-texto: #0e7490; --bm-fondo: #ecfeff; }
.ds-core .badge-modulo--league     { --bm-borde: #ddd6fe; --bm-texto: #5b21b6; --bm-fondo: #f5f3ff; }
.ds-core .badge-modulo--insights   { --bm-borde: #bbf7d0; --bm-texto: #15803d; --bm-fondo: #f0fdf4; }

[data-bs-theme="dark"] .ds-core .badge-modulo--basketball { --bm-borde: rgba(255, 208, 163, .30); --bm-texto: #fdba74; --bm-fondo: rgba(180, 83, 9, .20); }
[data-bs-theme="dark"] .ds-core .badge-modulo--core       { --bm-borde: rgba(199, 210, 254, .30); --bm-texto: #a5b4fc; --bm-fondo: rgba(55, 48, 163, .25); }
[data-bs-theme="dark"] .ds-core .badge-modulo--arena      { --bm-borde: rgba(165, 243, 252, .30); --bm-texto: #67e8f9; --bm-fondo: rgba(14, 116, 144, .25); }
[data-bs-theme="dark"] .ds-core .badge-modulo--league     { --bm-borde: rgba(221, 214, 254, .30); --bm-texto: #c4b5fd; --bm-fondo: rgba(91, 33, 182, .28); }
[data-bs-theme="dark"] .ds-core .badge-modulo--insights   { --bm-borde: rgba(187, 247, 208, .30); --bm-texto: #86efac; --bm-fondo: rgba(21, 128, 61, .25); }

.ds-core .badge-modulo--basketball,
.ds-core .badge-modulo--core,
.ds-core .badge-modulo--arena,
.ds-core .badge-modulo--league,
.ds-core .badge-modulo--insights {
    border-color: var(--bm-borde);
    color: var(--bm-texto);
    background: var(--bm-fondo);
}', 1);

/*----------  4. El aviso de superadministrador  ----------*/
$paso("    background: #fff7ed;\n    border: 1px solid #fed7aa;\n    color: #9a3412;",
      "    /* Misma pareja clara/oscura que las insignias. */\n"
    . "    background: var(--aviso-fondo, #fff7ed);\n"
    . "    border: 1px solid var(--aviso-borde, #fed7aa);\n"
    . "    color: var(--aviso-texto, #9a3412);", 1);

/* Y su variante oscura, al final del bloque. */
$t .= "\n/* El aviso de superadministrador, en tema oscuro. */\n"
    . "[data-bs-theme=\"dark\"] .ds-core .aviso-superadmin {\n"
    . "    --aviso-fondo: rgba(154, 52, 18, .22);\n"
    . "    --aviso-borde: rgba(254, 215, 170, .30);\n"
    . "    --aviso-texto: #fdba74;\n}\n";

/*----------  Comprobaciones  ----------*/
$fallos = [];
/* Debe quedar UNO: la bolita del interruptor, que es blanca sobre una pista
   de color en los dos temas y por tanto no depende del tema. */
$blancos = substr_count($t, 'background: #fff;');
if ($blancos !== 1) { $fallos[] = "quedan $blancos fondos blancos fijos, se esperaba 1 (la bolita del interruptor)"; }
if (substr_count($t, '--core-borde:   var(--bs-border-color)') !== 1) { $fallos[] = 'los tokens no quedaron'; }
if (substr_count($t, '[data-bs-theme="dark"]') !== 6) {
    $fallos[] = 'se esperaban 6 bloques oscuros, hay ' . substr_count($t, '[data-bs-theme="dark"]');
}
if (substr_count($t, '{') !== substr_count($t, '}')) { $fallos[] = 'llaves descuadradas'; }

if ($fallos) {
    echo "  NO SE ESCRIBE NADA:\n";
    foreach ($fallos as $f) { echo "    - $f\n"; }
    exit(1);
}

printf("  el archivo pasa de %d a %d lineas\n",
       substr_count($orig, "\n") + 1, substr_count($t, "\n") + 1);

if ($aplicar) { file_put_contents($archivo, $t); echo "  APLICADO\n"; }
else          { echo "  simulado (sin escribir)\n"; }
