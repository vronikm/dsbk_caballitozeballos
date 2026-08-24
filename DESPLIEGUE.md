# Pase a producción — lista de comprobación

Verificado el 2026-08-21 contra el sistema, no de memoria. Este archivo no se
sirve por HTTP (la regla de `.md` en el `.htaccess` de la raíz lo bloquea).

## 1. Bloqueantes — resolver antes de desplegar

### El repositorio remoto es público y contiene datos de menores

`github.com/vronikm/dsbk_caballitozeballos` responde **HTTP 200 sin
autenticación**. `origin/main` está al día con el local (`9b6ed95`), y ese
commit incluye `ds_base_temporal/digitech_barcelona.sql`: 554.804 bytes con
**888 cédulas, 270 correos, 3295 fechas de nacimiento y 5 hashes de
contraseña**.

Mover el archivo a `borrar/` arregló el servidor local. **No toca el remoto ni
el histórico.** Hacen falta tres decisiones:

1. Poner el repositorio en privado.
2. Purgar los archivos del histórico y forzar el empuje. Son **tres** volcados,
   no uno, más dos fotos de personas: los comandos exactos, ya probados sobre
   un clon, están en `borrar/COMANDOS_GIT.md`.
3. Valorar si la LOPDP obliga a notificar, tratándose de menores.

Las credenciales, en cambio, **sí están bien**: `server.php` está versionado
pero no lleva valores, sólo carga `secrets.php`, que está en `.gitignore`.

### La base de datos usa `root` sin contraseña

Comprobado: `DB_USER` es `root` y `DB_PASS` está vacía. En una máquina de
desarrollo es la costumbre de WAMP; en producción no puede quedarse así,
sobre todo con un volcado de esa misma base ya circulando.

Hace falta un usuario propio de la aplicación, con contraseña, y con permisos
sólo sobre `digitech_barcelona` — no `root`, que puede leer y borrar
cualquier base del servidor. Se cambia en `ds_core/config/secrets.php`, que
no se versiona.

Efecto lateral que conviene saber: veintiocho archivos del arnés de pruebas
llevaban `root` y la contraseña vacía escritas a mano. Los tres que siguen
en uso ahora leen las credenciales de la configuración
(`pruebas/conexion.php`); los veinticinco diagnósticos históricos de
`pruebas/archivo/` quedaron fuera del repositorio.

### La factura devuelta bloquea la firma electrónica

Factura id 6, secuencial `000000017`, 45,00 USD del 28/07. El SRI responde
`45 - ERROR SECUENCIAL REGISTRADO`. El campo `xml_firmado` ocupa 112 bytes —
demasiado poco para una firma real, probablemente guardó un mensaje de error.
Bloquea también la facturación de League.

## 2. Configuración que cambia en producción

| dónde | ahora | producción |
|---|---|---|
| `facturas_electronicas_config.ambiente` | `1` (pruebas) | `2` |
| `dsl_concepto` — los 7 conceptos de League | `0.00` | sus valores reales |
| `facturas_electronicas_config.iva_tarifa_default` | `0.00` | decidir |
| Punto de emisión Arena (`002`) | `I` inactivo | decidir |
| `DS_FORZAR_HTTPS` en `ds_core/config/app.php` | `true` | dejar en `true` |
| `DS_HSTS_MESES` | `1` | subir a `12` cuando se compruebe |

Con los conceptos a cero, League cobra cero.

## 3. Lo que NO se sube al servidor

| carpeta | peso | por qué |
|---|---|---|
| `.git/` | 35 MB | el histórico completo; se servía con 200 antes de bloquearlo |
| `pruebas/` | 4 MB | scripts con credenciales que crean y borran usuarios |
| `borrar/` | 1 MB | cuarentena: volcado de la base y código retirado |

**Aplicación limpia: 29 MB.**

Las tres están bloqueadas por `.htaccess` aunque se suban por descuido, pero
el bloqueo depende de que Apache tenga `AllowOverride` activo. No subirlas es
la barrera que no depende de la configuración del servidor.

## 4. Lo que ya quedó cerrado

- **Errores.** Sólo `127.0.0.1` y la consola los ven. Antes, cualquier cliente
  recibía la traza de Xdebug **con los argumentos de cada llamada**, y el
  modelo conecta con `new PDO($dsn, $user, $pass)`: la contraseña de la base
  era visible. Ver `ds_core/inc/errores.php`.
- **Archivos servidos que no debían.** `.git`, `borrar/`, los ocultos y las
  extensiones de trabajo (`.sql`, `.log`, `.bak`, `.tgz`) devuelven 403.
  `.well-known/` queda fuera del bloqueo a propósito: la renovación del
  certificado HTTPS la necesita.
- **Cotejamiento.** `collation_connection` era `utf8mb4_general_ci` contra una
  base en `utf8mb4_0900_ai_ci`. Rompía `WHERE origen_modulo LIKE ?` sobre
  `v_comprobante_emitido` — la columna que separa las facturas de Basketball
  de las de League. Alineado en los cuatro puntos de conexión.
- **57 MB de librerías y archivos muertos** retirados, con respaldo.

## 5. Antes de dar el pase por bueno

```bash
cd pruebas && ./regresion.sh      # 39 suites · 0 con fallo
```

Y las tres cosas que sólo puede hacer una persona:

- Cambiar la contraseña de `AdminBCC` (la actual la generó un asistente).
- Activar el segundo factor: **0 de 5 usuarios** lo tienen.
- Rotar `TOKEN_SECRET` y la contraseña de base de `adfpedrolarrea` y
  `senderodecampeones_form`, que están en claro en cuatro archivos.
