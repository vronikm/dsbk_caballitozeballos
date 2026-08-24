# Pruebas automatizadas de DigiSports

Un comando comprueba que el ecosistema sigue funcionando:

```bash
cd /c/wamp64/www/barcelona/pruebas
./regresion.sh
```

Termina con `N suites · 0 con fallo`. Si algo falla, el detalle completo queda
en `%TEMP%\fallo_<suite>.log`.

Para incluir las suites que mueven dinero —dejan registros en la base y hay
que limpiarlos después con `limpiar_qa_finanzas.php`—:

```bash
./regresion.sh --dinero
```

## Esta carpeta no se sirve por HTTP, y es importante

Aquí dentro hay scripts que se conectan a la base con credenciales, crean y
borran usuarios y vacían tablas. El `.htaccess` de la raíz del proyecto
entrega directamente **cualquier archivo que exista en disco**, así que sin
bloqueo bastaría con acertar un nombre para ejecutar cualquiera de ellos
desde el navegador.

El `.htaccess` de esta carpeta lo impide, con el mismo criterio que
`app/controllers/` y las carpetas de datos personales. Y como el bloqueo
depende de que Apache tenga `AllowOverride` activo —algo que no se puede dar
por supuesto—, `qa_bloqueo.php` pide siete URL distintas y comprueba que
todas responden 403. Va dentro del barrido.

## Lo que NUNCA debe guardarse aquí

Capturas de pantalla, volcados de páginas y ficheros JSON con respuestas del
sistema. Se comprobó uno por uno: llevaban cédulas y nombres de alumnos
—menores de edad—. Meter eso en el directorio web, aunque esté bloqueado, es
multiplicar sin motivo los sitios donde vive un dato personal.

Por eso las dos suites que toman captura la escriben en el temporal del
sistema, y sólo cuando algo ha fallado, que es cuando sirve de algo.

Las copias de seguridad de la base y los tarballs del código están **fuera
del directorio web entero**, en `C:\wamp64\respaldos_barcelona\`. Una copia
de la base es la base: no puede estar donde Apache pueda servirla si un día
alguien toca un `.htaccess`.

## Qué cubre cada bloque

**Se envían los formularios.** Lo más importante y lo último en llegar. Hasta
que existió, todo lo comprobado era que las pantallas *se dibujan*: HTTP 200,
sin errores de consola, con la maquetación en su sitio. Nada de eso detecta
que un formulario haya dejado de guardar.

- `qa_crud_basket.mjs` — crear, leer, editar y borrar pulsando el botón en el
  navegador, de modo que la ruta sea la del usuario: formulario → `ajax.js` →
  endpoint → base de datos. Incluye el caso negativo: sin los campos
  obligatorios, el servidor tiene que rechazarlo.
- `qa_limpiar.mjs` — que la respuesta «limpiar» vacíe el formulario enviado y
  no otro.

**Se dibujan las pantallas.** Barrido de las 54 vistas, maquetación de los
cuatro módulos, plugins, menús, identificadores únicos, iconos, y que las
librerías retiradas sigan sin hacer falta.

Dos suites vigilan que no falte ningún archivo, y se complementan a
propósito:

- `qa_estaticos.php` — lee el marcado de las 98 vistas y comprueba que cada
  `src=` y `href=` resuelve a un archivo que existe. No abre el navegador,
  así que ve **todas** las vistas, incluidas las que sólo se alcanzan con un
  identificador. Mira también las rutas que viajan en cadenas, no sólo en
  atributos: la cabecera unificada construye las suyas concatenando, y
  mientras la suite sólo miraba atributos, las hojas que usan 67 vistas
  quedaban fuera sin que nada lo delatara.
- `qa_plugins_vivos.mjs` — abre las vistas de verdad y anota cualquier 404,
  que es lo único que atrapa lo que un script decide cargar en ejecución.
  Dice cuántas páginas alcanzó su destino y cuántas redirigieron, porque
  su alcance real es la mitad de lo que sugiere la lista de rutas.


**Seguridad.** El bloqueo de esta carpeta, las cabeceras CSP, el testigo CSRF
del acceso y el segundo factor —el TOTP se calcula en Node con `node:crypto`,
una implementación distinta de la de PHP: que dos implementaciones
independientes coincidan es lo que hace creíble que coincidan también con
Google Authenticator—.

**Base de datos.** Que la codificación siga unificada en `utf8mb4_0900_ai_ci`.

## Cinco trampas que ya dieron resultados falsos

Están documentadas dentro de cada archivo, pero conviene tenerlas presentes
al escribir una suite nueva:

1. **Contar apariciones no es contar usos.** En `ajax.js` se contaron cinco
   llamadas a jQuery y las cinco estaban dentro de un comentario, restos de
   la demo de AdminLTE con su «Lorem ipsum». Antes de contar hay que
   descartar comentarios y las etiquetas `script src`, donde el nombre de la
   librería aparece sin ser una llamada.

2. **Varias pantallas exigen un identificador en la URL** y sin él redirigen
   a otra. Medir `pagosDescuento/` era medir `pagosList/`, y daba por buenas
   dos pantallas que ni se habían visitado. Toda suite comprueba a dónde
   llegó antes de mirar nada más.

3. **El peso no se mide con el navegador.** La caché hace que la primera
   pantalla parezca pedir 2,6 MB y las siguientes «0 KB». `medir_peso.php`
   lee el HTML que sirve el servidor y suma los tamaños en disco: sale lo
   mismo la primera vez que la centésima.

4. **Para saber si algo sobra, tres fuentes dan tres respuestas y sólo una
   sirve.** Al retirar 51 carpetas de plugins: buscar el nombre en el
   código decía 12 en uso (contaba menciones dentro de ficheros que ya no
   carga nadie); el registro de Apache decía unas 30 (es histórico:
   encabezaba una carpeta borrada hacía dos horas); y abrir las vistas
   decía 7 (diecinueve redirigían). La respuesta buena —10— es «hay una
   etiqueta o una cadena que la carga, en un fichero que alguien incluye».
   Cuando dos métodos discrepan, ninguno es de fiar hasta entender por qué.

Y una quinta, que costó una versión entera de `qa_crud_basket.mjs`: las
pantallas tienen **muchos formularios de la misma clase** —el de cambiar
contraseña del navbar va primero en el documento, luego el de alta y luego
uno por fila—. Buscar «el primero» rellena el del navbar. Ese mismo error
estaba en `ajax.js` y dejaba sin limpiar el alta de alumno.

## Las contraseñas no están escritas aquí

Nueve scripts del archivo llevaban en claro las contraseñas de `AdminBCC` y
del superadministrador. Mientras vivían en una carpeta temporal pasaba
desapercibido; dentro del proyecto quedaban en un sitio que se versiona.
Ahora se leen del entorno:

```bash
DS_QA_CLAVE_ADMIN='...' DS_QA_CLAVE_SUPER='...' php archivo/qa_login_seguridad.php
```

La única contraseña que sigue escrita es la de `qa2fatester`, en
`qa2fa_usuario.php`: es una cuenta desechable que el propio barrido crea al
empezar y borra al terminar, y que nunca pertenece a una persona.

**Esas dos contraseñas conviene cambiarlas.** Estuvieron en claro en varios
archivos; quitarlas de aquí limpia el rastro de ahora en adelante, no el de
antes.

## Las otras carpetas

- `herramientas/` — scripts de medición y migración que se pueden volver a
  pasar: miden jQuery, pesan las páginas, retiran librerías. Documentan cómo
  se decidió cada cambio, que es la mitad de su valor. Casi todos aceptan un
  argumento `aplicar`; sin él sólo simulan.
- `archivo/` — diagnósticos de un rato concreto. No estorban y explican por
  qué algo está como está.

## Requisitos

- PHP 8.3 en `/c/wamp64/bin/php/php8.3.28/php.exe`
- Node, con `patchright` resuelto desde la extensión de VS Code
- Apache y MySQL en marcha, con la base `digitech_barcelona`
