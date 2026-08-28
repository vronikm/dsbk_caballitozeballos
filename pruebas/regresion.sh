#!/bin/bash
# ============================================================================
#  El barrido de regresión de DigiSports.
#
#  De los ciento y pico scripts que se han ido acumulando, la mayoría fueron
#  diagnósticos de un rato concreto. Aquí están sólo los que SIGUEN VALIENDO:
#  se pueden repetir cuantas veces se quiera, no dejan rastro y comprueban
#  algo que hoy debe seguir siendo cierto.
#
#  POR QUÉ NO BASTA EL CÓDIGO DE SALIDA
#
#  Los scripts de PHP escriben «0 con problema» y terminan en 0 pase lo que
#  pase. Así que se mira el código de salida Y el texto: cualquiera de los
#  dos que acuse un fallo, cuenta como fallo. Es preferible un falso positivo
#  que enterarse tarde.
#
#  Uso:  ./regresion.sh          el conjunto seguro
#        ./regresion.sh --dinero incluye finanzas y comprobantes
# ============================================================================
cd "$(dirname "$0")" || exit 1
PHP=/c/wamp64/bin/php/php8.3.28/php.exe
# cygpath traduce la ruta de Windows a la que entiende bash. Sin esto el
# registro se intentaba escribir en «C:UsersdbafacesAppData…» y se perdia
# justo cuando hacia falta leerlo.
REGISTROS="$(cygpath -u "${TEMP:-/tmp}" 2>/dev/null || echo /tmp)"

# Se ejecuta cada uno y se guarda su salida, para poder enseñarla si falla.
declare -a NOMBRES=() ESTADOS=() DETALLES=()

corre() {
    local etiqueta="$1" comando="$2"
    local salida codigo
    salida=$(eval "$comando" 2>&1); codigo=$?

    # Sin -i A PROPOSITO. Con -i, «y falla por credenciales, que es lo
    # esperado» —el NOMBRE de una comprobación que pasa— daba por caída una
    # suite entera de CSRF con sus once resultados en verde.
    local veredicto="OK" detalle=""
    if [ $codigo -ne 0 ]; then
        veredicto="FALLA"; detalle="código $codigo"
    elif echo "$salida" | grep -qE "FALLA|[1-9][0-9]* con problema|fallos: [1-9]|Fatal error|Warning:"; then
        veredicto="FALLA"
        detalle=$(echo "$salida" | grep -E "FALLA|[1-9][0-9]* con problema|fallos: [1-9]|Fatal error" | head -2 | tr '\n' ' ')
    else
        detalle=$(echo "$salida" | grep -iE "vistas|fallos: 0|correcto|sin problema" | head -1)
    fi

    NOMBRES+=("$etiqueta"); ESTADOS+=("$veredicto"); DETALLES+=("$detalle")
    printf "  %-34s %s  %s\n" "$etiqueta" "$veredicto" "${detalle:0:60}"

    # El registro va al temporal del sistema, NO al proyecto: la salida de
    # una suite puede llevar nombres de alumnos dentro. Y el nombre se
    # limpia de barras — «Arena/League/Core» creaba una ruta inexistente y
    # el registro se perdía justo cuando hacía falta.
    if [ "$veredicto" = "FALLA" ]; then
        local limpio="${etiqueta//[^a-zA-Z0-9]/_}"
        echo "$salida" > "$REGISTROS/fallo_${limpio}.log"
    fi
    return 0
}

# La sesión que usan las suites caduca sola: PHP recoge el archivo tras
# ~24 minutos sin actividad. Cuando eso pasaba a mitad de trabajo, el
# barrido entero fallaba con «37 vistas, 37 con problema» y «HTTP 200 sin
# app-main» — que se lee como armazón roto y en realidad era el login.
# Se renueva aquí para que el arnés no dependa de cuándo se creó.
# Argumentos: <sid> <usuario> <usuarioid> <rol> [nombre] [empleadoid].
# AdminBCC no tiene ficha de empleado, de ahí el 0 final: ver el aviso
# dentro de sesion_qa.php sobre usuario_id vs usuarioid.
$PHP sesion_qa.php dsqaui0000000000000 AdminBCC 1 1 "QA" 0 > /dev/null 2>&1 \n  || { echo "  no se pudo preparar la sesión de pruebas"; exit 1; }
echo

echo "── Se envían los formularios ──────────────────────────────"
corre "CRUD completo"        "node qa_crud_basket.mjs"
corre "limpiar el form justo" "node qa_limpiar.mjs"

echo
echo "── Se dibujan las pantallas ───────────────────────────────"
corre "barrido de 54 vistas"  "$PHP qa_barrido_todo.php"
corre "maquetación Basketball" "node qa_basket4.mjs"
corre "maquetación Arena/League/Core" "node qa_layout4.mjs"
corre "plugins cargados"      "node qa_plugins_bk.mjs"
corre "menú de Basketball"    "node qa_menu_bk.mjs"
corre "botones de salir"      "node qa_salir.mjs"
corre "pantalla de pagos"     "node qa_pagosnew.mjs"
corre "ids únicos"            "node qa_ids_todo.mjs"
corre "select2 donde toca"    "node qa_select2.mjs"

# El panel tiene dos caras y sólo se ve una por sesión: la gerencial y la
# del profesor. Esta segunda necesita un usuario con ficha de empleado y
# horarios detrás, así que se prepara la sesión antes de mirar.
$PHP sesion_qa.php dsqaoper000000000000 SCECAO02 16 3 "QA Operativo" 2 > /dev/null 2>&1
corre "panel de Basketball"   "node qa_dashboard.mjs"
corre "iconos del panel"      "node qa_iconos_dash.mjs"
corre "iconos del sistema"   "node qa_iconos.mjs"
corre "Arena/League/Core sin jQuery" "node qa_sin_jquery.mjs"
corre "librerias retiradas"  "node qa_js_muerto.mjs"
corre "movil y tablet"      "node qa_responsive.mjs"
corre "nada sobremontado"   "node qa_sobremontado.mjs"
corre "controles de formulario" "node qa_radios.mjs"
corre "selector de foto"     "node qa_selector_foto.mjs"
corre "asistente del alta"   "node qa_wizard.mjs"
corre "contadores del menu"   "$PHP qa_contadores_menu.php"
corre "tablas DataTables 2"  "node qa_datatables2.mjs"
corre "exportar a PDF y Excel" "node qa_exportar.mjs"
corre "excepciones de JS"     "node qa_errores_js.mjs"
corre "plegar y separacion"  "node qa_plegar.mjs"
corre "doctype y modo estandar" "$PHP qa_doctype.php"
corre "id inexistente"      "node qa_id_inexistente.mjs"
corre "identidad DigiSports" "node qa_identidad.mjs"
corre "tema oscuro"         "node qa_tema_oscuro.mjs"
corre "interruptor de tema"  "node qa_interruptor_tema.mjs"
corre "referencias a archivos" "$PHP qa_estaticos.php"
corre "plugins vivos"        "node qa_plugins_vivos.mjs"

echo
echo "── Seguridad ──────────────────────────────────────────────"
corre "esta carpeta no se sirve" "$PHP qa_bloqueo.php"
corre "no se filtran errores"  "$PHP qa_errores_fuga.php"
corre "cabeceras CSP"         "node qa_csp.mjs"
corre "CSRF en el acceso"     "$PHP qa_csrf_login.php"
corre "segundo factor (TOTP)" "$PHP qa_totp.php"

# El segundo factor necesita una cuenta que se pueda activar y desactivar
# varias veces. Se crea aquí y se borra al salir, para que la suite no
# dependa de que alguien recuerde exportar dos variables.
$PHP qa2fa_usuario.php crear > /dev/null 2>&1
corre "segundo factor (web)" \
      "QA2FA_USER=qa2fatester QA2FA_PASS='Qa2fa!Prueba#2026' node qa_2fa.mjs"
$PHP qa2fa_usuario.php borrar > /dev/null 2>&1

echo
echo "── Base de datos ──────────────────────────────────────────"
corre "raíces de módulo"     "$PHP qa_raices_modulos.php"
corre "CSS y temas"          "$PHP qa_css_temas.php"
corre "codificación unificada" "$PHP qa_utf8mb4.php"
corre "sede histórica del pago" "$PHP qa_sede_historica.php"
corre "permiso de exportar"    "$PHP qa_permiso_exportar.php"
corre "Insights solo lee"     "$PHP qa_insights_solo_lectura.php"
corre "asistencia por día"    "$PHP qa_asistencia_dia.php"
corre "módulo Insights"      "$PHP qa_insights_modulo.php"
corre "tablero de Insights"  "node qa_insights_tablero.mjs"

if [ "$1" = "--dinero" ]; then
    echo
    echo "── Dinero (deja registros; limpiar después) ───────────────"
    corre "finanzas de League"  "node qa_finanzas.mjs"
    corre "comprobantes"        "node qa_comprobante.mjs"
fi

echo
malos=0
for e in "${ESTADOS[@]}"; do [ "$e" = "FALLA" ] && malos=$((malos+1)); done
echo "══════════════════════════════════════════════════════════"
printf "  %d suites · %d con fallo\n" "${#NOMBRES[@]}" "$malos"
[ $malos -gt 0 ] && echo "  El detalle de cada fallo está en $REGISTROS/fallo_*.log"
exit $((malos > 0))
