# Pase a producción — lista de comprobación

Verificado el 2026-08-31 contra el sistema, no de memoria. Este archivo no se
sirve por HTTP (la regla de `.md` en el `.htaccess` de la raíz lo bloquea).

Estado del código: `5537bd0` en `main`, **52 suites del arnés en verde**.

---

## 1. Bloqueantes — resolver antes de desplegar

### El repositorio remoto es público y contiene datos de menores

`github.com/vronikm/dsbk_caballitozeballos` responde **HTTP 200 sin
autenticación**, y el histórico incluye `ds_base_temporal/digitech_barcelona.sql`:
554.804 bytes con **888 cédulas, 270 correos, 3295 fechas de nacimiento y 5
hashes de contraseña**.

Mover el archivo a `borrar/` arregló el servidor local. **No toca el remoto ni
el histórico.** Hacen falta tres decisiones:

1. Poner el repositorio en privado.
2. Purgar los archivos del histórico y forzar el empuje. Son **tres** volcados,
   no uno, más dos fotos de personas: los comandos exactos, ya probados sobre
   un clon, están en `borrar/COMANDOS_GIT.md`.
3. Valorar si la LOPDP obliga a notificar, tratándose de menores.

Las credenciales, en cambio, **sí están bien**: `server.php` está versionado
pero no lleva valores, sólo carga `secrets.php`, que está en `.gitignore` y
**nunca estuvo en el histórico** (comprobado con `git log --all`).

### La base de datos usa `root` sin contraseña

Comprobado hoy: `DB_USER` es `root` y `DB_PASS` sigue vacía. En una máquina de
desarrollo es la costumbre de WAMP; en producción no puede quedarse así, sobre
todo con un volcado de esa misma base ya circulando.

Hace falta un usuario propio de la aplicación, con contraseña, y con permisos
sólo sobre `digitech_barcelona` — no `root`, que puede leer y borrar cualquier
base del servidor. Se cambia en `ds_core/config/secrets.php`, que no se
versiona.

### El segundo factor: 0 de 5 usuarios

Las columnas existen (`usuario_2fa_estado`, `_secreto`, `_activado`) y el
mecanismo funciona, pero **ningún usuario lo tiene activado** y todos los
secretos están vacíos. Con el volcado de la base circulando en un repositorio
público, la contraseña sola no basta.

### La factura devuelta bloquea la firma electrónica

Factura id 6, secuencial `000000017`, 45,00 USD del 28/07. El SRI responde
`45 - ERROR SECUENCIAL REGISTRADO`. El campo `xml_firmado` ocupa 112 bytes —
demasiado poco para una firma real, probablemente guardó un mensaje de error.
Bloquea también la facturación de League.

---

## 2. La base de datos: migraciones

**Este es el paso que más fácil sale mal, y hasta ahora no estaba escrito.**

Hay **54 migraciones** en `ds_core/database/` y **no existe tabla que registre
cuáles se aplicaron**. En desarrollo da igual: se sabe. En una base de
producción no, y ahí importa mucho, porque no todas se pueden reejecutar.

### Antes de tocar nada, preguntar en qué estado está

```bash
php ds_core/database/estado_migraciones.php
```

**Sólo lee.** No aplica nada. Deduce del propio esquema qué migraciones dejaron
su rastro —una tabla, una columna, un índice, una vista— y dice cuáles faltan,
en qué orden aplicarlas y cuáles no se pueden repetir.

Sobre la base actual responde: **31 aplicadas, 0 pendientes, 23 no
determinables**.

### Las cuatro que NO se pueden repetir

| migración | por qué |
|---|---|
| `008_claves_foraneas.sql` | `ADD CONSTRAINT` (se protege sola, pero conviene mirarla) |
| `048_insights_menu.sql` | `INSERT` sin proteger — repetirla duplica el menú |
| `049_insights_menu_becas.sql` | igual |
| `052_insights_menu_exportar.sql` | igual |

Las demás llevan `IF NOT EXISTS`, `ON DUPLICATE KEY` o `INSERT IGNORE` y se
pueden lanzar sin miedo.

### Cómo aplicarlas

```bash
mysql -u USUARIO -p --default-character-set=utf8mb4 BASE \
      < ds_core/database/NOMBRE.sql
```

**`--default-character-set=utf8mb4` no es opcional.** Sin él, el cliente de
MySQL en Windows lee el archivo con la página de códigos de la consola y las
tildes se guardan mal: «pensión» queda como «pensi├│n». Ocurrió de verdad con
la migración 054. Y no se detecta con `mb_check_encoding()`, porque esos bytes
son UTF-8 perfectamente válido — sólo que dicen otra cosa.

Las migraciones desde la 017 declaran `SET NAMES utf8mb4` dentro del archivo,
que es la protección buena. Las anteriores no.

### Lo que conviene hacer, aunque no sea urgente

Crear una tabla de migraciones y registrar en ella lo aplicado. El diagnóstico
de arriba es un indicio muy bueno, no un registro: no distingue una migración
aplicada de una cuyo efecto se consiguió por otro camino, y no puede decir nada
de las 23 que sólo insertan filas.

---

## 3. Configuración que cambia en producción

| dónde | ahora | producción |
|---|---|---|
| `ds_core/config/secrets.php` | `root` sin contraseña | usuario propio con clave |
| `DS_HUB_URL` en `ds_core/config/app.php` | `http://localhost/barcelona/` | el dominio real, con `https://` |
| `facturas_electronicas_config.ambiente` | `1` (pruebas) | `2` |
| `dsl_concepto` — conceptos de League | **6 de 7 en `0.00`** | sus valores reales |
| `facturas_electronicas_config.iva_tarifa_default` | `0.00` | decidir |
| Punto de emisión Arena (`002`) | `I` inactivo | decidir |
| `DS_FORZAR_HTTPS` | `true` | dejar en `true` |
| `DS_HSTS_MESES` | `1` | subir a `12` cuando se compruebe |

Con los conceptos a cero, **League cobra cero**.

---

## 4. Insights: nadie puede entrar todavía

El módulo está instalado y sus once vistas registradas, pero
`seguridad_rol_modulo` **no lo tiene concedido a ningún rol**. Tal como está,
sólo el superadministrador lo ve.

Hay que decidir, en Core, quién entra y a qué:

- **el módulo**, en `seguridad_rol_modulo` — sin esto no se ve nada;
- **cada vista**, en `seguridad_permiso` — Insights corre en modo estricto: una
  vista no concedida devuelve 403, no se limita a ocultar el menú;
- **`transacciones` aparte**. Es la única pantalla que muestra pagos de personas
  identificadas. Ver que una sede recaudó $11.000 y ver quién pagó cada cuota
  son dos decisiones distintas;
- **`exportar`**, que es una acción propia (`permiso_exportar`): sacar la
  información del sistema no es lo mismo que verla;
- **`configuracion`** necesita además `permiso_editar` para mover umbrales.

Y comprobar el **ámbito de sede**: si un coordinador está limitado en
`seguridad_usuario_sede`, Insights lo respeta en las 36 consultas que tocan
datos con sede. League queda fuera del ámbito porque no tiene sede.

---

## 5. Tareas programadas

Una, y es imprescindible:

```bash
php ds_insights/cli/capturar_cartera.php
```

**Una vez al mes**, entre el día 1 y el 5. Fotografía la deuda del mes que
cierra en `insights_cartera_snapshot`.

No es un adorno: **la deuda pasada no se puede reconstruir**. Los saldos de
marzo que ya se cobraron valen cero hoy, así que preguntarle a la base cuánto
se debía en marzo devuelve una cifra sistemáticamente menor. Sin esta tarea, la
evolución de la cartera se queda en blanco para siempre.

El script se niega a retratar periodos pasados: sólo acepta el mes en curso, y
el anterior únicamente durante los cinco primeros días. Un retrato tomado tarde
sería una cifra falsa con fecha de otro mes.

---

## 6. Lo que NO se sube al servidor

| carpeta | peso | por qué |
|---|---|---|
| `.git/` | 35 MB | el histórico completo; se servía con 200 antes de bloquearlo |
| `pruebas/` | 4 MB | scripts que crean y borran usuarios y permisos |
| `borrar/` | 1 MB | cuarentena: volcado de la base y código retirado |

**Aplicación limpia: 29 MB.**

Las tres están bloqueadas por `.htaccess` aunque se suban por descuido, pero el
bloqueo depende de que Apache tenga `AllowOverride` activo. No subirlas es la
barrera que no depende de la configuración del servidor.

### Datos de prueba que conviene limpiar

| tabla | filas | qué es |
|---|---|---|
| `insights_auditoria` | 175 | bitácora generada por las pruebas |
| `insights_cartera_snapshot` | 10 | fotografías de una base de ensayo |

Ninguna es peligrosa, pero arrancar producción con una bitácora falsa estorba
la primera vez que haya que consultarla de verdad.

También conviene revisar `dsa_cliente`: una de sus filas contiene datos
personales reales (nombre, cédula, correo, teléfono, dirección) cargados
durante las pruebas.

---

## 7. Carpetas que deben existir y ser escribibles

Bajo `ds_basketball/app/views/`:

```
imagenes/fotos/alumno/     imagenes/cedulas/
imagenes/fotos/empleado/   fotos/usuario/
imagenes/pagos/            imagenes/ingresos/
imagenes/fotos/ingresos/   imagenes/fotos/egresos/
```

Se sirven a través de `app/media.php`, que exige sesión activa. Si una carpeta
falta o no es escribible, la subida falla; si el archivo falta pero su nombre
está en la base, `media_url()` devuelve la imagen genérica en vez de un icono
roto.

---

## 8. Lo que ya quedó cerrado

- **Errores.** Sólo `127.0.0.1` y la consola los ven. Antes, cualquier cliente
  recibía la traza de Xdebug **con los argumentos de cada llamada**, y el
  modelo conecta con `new PDO($dsn, $user, $pass)`: la contraseña de la base
  era visible.
- **Archivos servidos que no debían.** `.git`, `borrar/`, los ocultos y las
  extensiones de trabajo (`.sql`, `.log`, `.bak`, `.tgz`) devuelven 403.
  `.well-known/` queda fuera del bloqueo a propósito: la renovación del
  certificado HTTPS la necesita.
- **Cotejamiento.** `collation_connection` era `utf8mb4_general_ci` contra una
  base en `utf8mb4_0900_ai_ci`. Rompía `WHERE origen_modulo LIKE ?` sobre
  `v_comprobante_emitido`. Alineado en los cuatro puntos de conexión.
- **La sede de un pago.** `alumno_pago.pago_sedeid` congela la sede del cobro.
  Antes se llegaba por el alumno, así que trasladar a uno reescribía el
  histórico de ingresos de dos sedes a la vez.
- **El ámbito de sede en Insights.** `sedesDelUsuario()` existía y no lo llamaba
  nadie: un coordinador limitado a dos sedes veía las siete. Acotadas las 36
  consultas que tocan datos con sede.
- **El visor de comprobantes.** ekko-lightbox dejó de funcionar al migrar a
  Bootstrap 5 sin dar un solo error: creaba el modal con `opacity 0`.
  Sustituido por un visor nativo, sin jQuery.
- **La documentación de Insights se servía por HTTP.** `MODELO_INSIGHTS.md`,
  `ANALISIS_ACTUAL.md` e `INDICADORES.pdf` respondían **200**. El `.htaccess` de
  la raíz sí bloquea `.md`, pero **mod_rewrite no hereda**: un `.htaccess` con
  `RewriteEngine` propio *reemplaza* las reglas del padre en lugar de sumarse a
  ellas, así que cada módulo con `.htaccess` propio se queda sin las
  protecciones de arriba. Cerrado en `ds_insights/.htaccess` y comprobado por el
  arnés.
- **57 MB de librerías y archivos muertos** retirados, con respaldo.

> **Al desplegar, repetir esta comprobación.** Es un fallo silencioso por
> definición: nadie prueba una URL que no espera que exista, y el archivo se
> sirve con 200 durante meses. Pedir por HTTP unos cuantos archivos que
> *deberían* dar 403 —un `.md`, un `.sql`, un controlador— cuesta un minuto.

---

## 9. Antes de dar el pase por bueno

```bash
cd pruebas && ./regresion.sh          # 52 suites · 0 con fallo
php ds_core/database/estado_migraciones.php   # 0 pendientes
```

Y a mano, sobre el servidor ya desplegado:

- Entrar al Hub y a los cinco módulos con un usuario que **no** sea el
  superadministrador, para ver que los permisos están bien concedidos.
- Abrir una vista de Insights y comprobar que el total del Panel cuadra con la
  suma de las tres tarjetas de módulo.
- Pulsar el importe de un pago en «Pagos realizados» y ver que el comprobante
  se abre.
- Emitir una factura de prueba **antes** de pasar `ambiente` a `2`.

### Las cuatro cosas que sólo puede hacer una persona

- Poner el repositorio en privado y purgar el histórico.
- Cambiar la contraseña de `AdminBCC` (la actual la generó un asistente).
- Crear el usuario de base de datos y retirar `root`.
- Activar el segundo factor: **0 de 5 usuarios** lo tienen.
