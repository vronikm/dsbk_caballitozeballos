# DigiSports Insights — Fase 1: Discovery

**Análisis del ecosistema existente, previo a cualquier línea de código.**
Fecha: 2026-08-28 · Base analizada: `digitech_barcelona` (MySQL 8.4.7, PHP 8.3.28)

> Este documento no propone nada que no se haya verificado contra el código y la
> base de datos reales. Donde algo no se pudo comprobar, se dice.

---

## 0. Resumen para quien decide

Tres conclusiones condicionan todo el proyecto:

1. **El ecosistema ya está preparado para Insights.** No hay que inventar el
   enganche: la constante `DS_INSIGHTS_URL`, el color `--ds-insights`, la
   entrada en el registro de módulos del Hub (`activo => false`) y la clave
   `'insights'` en `ds_modulos_conocidos()` **ya existen**. La carpeta
   `ds_insights/` existe y está vacía.

2. **Dos de los tres módulos a analizar no tienen datos.** Basketball tiene
   ocho meses de operación real. Arena tiene 0 reservas y 0 pagos. League
   tiene 0 partidos y 0 facturas. Un BI construido hoy sobre los tres mostraría
   ceros en dos tercios de la pantalla.

3. **El modelo de permisos que pide el prompt no es el que el sistema tiene.**
   El prompt pide permisos con nombre (`INSIGHTS_VER_INGRESOS`). El sistema
   usa *módulo → vista → acción* en tablas. Son equivalentes en potencia;
   construir el primero encima del segundo crearía dos sistemas de permisos.

El detalle y las alternativas están abajo.

---

## 1. Arquitectura actual encontrada

### 1.1 Forma general

MVC propio, sin framework. Cada módulo es una carpeta `ds_*` con su **front
controller** y su **lista blanca** de vistas. El núcleo compartido es
`ds_core/`, que no se sirve por URL (bloqueado por `.htaccess` salvo `assets/`
y `admin/`).

```
barcelona/
├── index.php            Hub de aplicaciones (lanzador)
├── ds_core/             núcleo compartido: seguridad, config, assets, admin
├── ds_basketball/       app/{controllers,models,views,ajax,services,lib}
├── ds_arena/            {controllers,views,ajax,config}
├── ds_league/           {controllers,views,ajax,config,publico}
├── ds_form/             formularios públicos de inscripción
├── ds_insights/         VACÍA
└── pruebas/             arnés de regresión (45 suites)
```

Nótese que **la estructura interna no es uniforme**: Basketball tiene
`app/models/` y `app/services/`; Arena y League no tienen carpeta `models/` —
los controladores hablan con PDO directamente. El prompt propone una estructura
con `models/`; conviene decidir cuál de las dos convenciones sigue Insights
(ver §5).

### 1.2 Enrutado

Front controller + lista blanca, idéntico en los tres módulos. Ejemplo real de
`ds_arena/index.php`, que es el patrón a copiar:

```php
$listaBlanca = require __DIR__ . "/config/vistas.php";
$vista = $url[0] !== '' ? $url[0] : 'panel';

if (!usuario_autenticado())            → redirige al login
if (!usuario_tiene_modulo(DS_MODULO))  → 403 + accesoDenegado-view.php
if (!in_array($vista, $listaBlanca))   → 404, cae a 'panel'
if (!usuario_tiene_permiso($vista))    → 403 + accesoDenegado-view.php
```

**Los cuatro controles que pide el §8 del prompt ya están implementados aquí.**
Insights no necesita un mecanismo nuevo: necesita replicar este archivo.

### 1.3 Sesión

Sesión única para todo el ecosistema: mismo nombre de cookie
(`DS_SESSION_NAME = "DigiSportsBasketball"`) y `path "/"`. Entrar en cualquier
módulo vale para todos. El login vive en Basketball
(`DS_BASKETBALL_URL . "login/"`).

### 1.4 Capa de presentación

| pieza | versión / ubicación | nota |
|---|---|---|
| AdminLTE | 4.8.5 — `ds_core/assets/vendor/adminlte4/` | autoalojado |
| Bootstrap | 5.3.8 — `ds_core/assets/vendor/bootstrap5/` | |
| DataTables | 2.x — `ds_core/assets/vendor/datatables2/` | |
| FontAwesome | 6 | |
| OverlayScrollbars | — | |
| **SweetAlert2** | `ds_basketball/app/views/dist/` | **no está en el vendor común** |
| **Gráficos** | **no existe ninguna librería** | ApexCharts/Chart.js habría que incorporarlos |

Layout compartido: `ds_core/inc/layout-modulo.php` (+ `-pie.php`). El tema lo
gobierna el mecanismo oficial de AdminLTE (`lte-theme`, `data-bs-theme`), con
una regla del ecosistema: **la barra, el menú y el pie quedan siempre oscuros;
sólo el área de contenido sigue la elección del usuario.**

Los recursos propios se enlazan con `ds_recurso()`, que añade `?v=filemtime`.

---

## 2. Tablas relevantes encontradas

**93 tablas, todas InnoDB.** Recuentos reales (`COUNT(*)`, no estimaciones de
`information_schema`, que en este esquema difieren).

### 2.1 SEGURIDAD — 9 tablas

| tabla | filas | papel |
|---|---:|---|
| `seguridad_usuario` | 5 | usuarios |
| `seguridad_rol` | 8 | roles |
| `seguridad_rol_modulo` | 13 | **nivel 1**: qué módulos ve cada rol |
| `seguridad_menu` | 82 | **nivel 2**: vistas registradas por módulo |
| `seguridad_permiso` | 100 | **nivel 3**: ver/crear/editar/eliminar por rol y vista |
| `seguridad_usuario_sede` | 6 | ámbito de sede por usuario |
| `seguridad_intento_acceso` | 105 | frenado de fuerza bruta |
| `seguridad_2fa_evento` / `_recuperacion` | 0 / 0 | 2FA implementado, **sin usar** |

### 2.2 BASKETBALL / CORE — 36 tablas (las que importan al BI)

| tabla | filas | uso en Insights |
|---|---:|---|
| `sujeto_alumno` | 308 | dimensión alumno; `alumno_sedeid`, `alumno_fechaingreso`, `alumno_estado` |
| `alumno_pago` | 669 | **hecho económico principal** |
| `alumno_pago_descuento` | 128 | descuentos y becas |
| `alumno_pago_transaccion` | 6 | apenas usada |
| `asistencia_asistencia` | 489 | asistencia — **tabla pivotada, ver §10** |
| `asistencia_asignahorario` | 294 | alumno ↔ horario |
| `asistencia_horario` / `_detalle` | 50 / 250 | horarios |
| `alumno_representante` | 264 | representantes |
| `general_sede` | 7 | **dimensión sede — la única compartida** |
| `general_tabla` / `_catalogo` | 19 / 78 | **catálogo central**: rubros, formas de pago, descuentos |
| `sujeto_empleado` | 8 | entrenadores |
| `torneo_torneo` / `_equipo` / `_jugador` | 86 / 23 / 246 | torneos **internos de Basketball**, distintos de League |
| `balance_ingreso` / `_egreso` | 0 / 27 | contabilidad ligera; ingresos sin usar |

### 2.3 ARENA — 11 tablas

| tabla | filas |
|---|---:|
| `dsa_instalacion` | 1 |
| `dsa_cliente` | 1 |
| `dsa_monedero` / `_movimiento` | 1 / 1 |
| `dsa_forma_ingreso`, `dsa_tipo_piso` | 4 / 4 (catálogos) |
| **`dsa_reserva`** | **0** |
| **`dsa_pago`** | **0** |
| **`dsa_tarifa`** | **0** |
| **`dsa_horario`** | **0** |
| **`dsa_bloqueo`** | **0** |

`dsa_reserva` tiene la forma correcta para el BI —`reserva_sedeid`,
`reserva_fecha`, `reserva_valorhora`, `reserva_total`, `reserva_abonado`,
`reserva_saldo`, `reserva_estado`— pero **no hay ni una fila**.

### 2.4 LEAGUE — 29 tablas

Pobladas sólo las de catálogo y una fila de arranque:

| tabla | filas |
|---|---:|
| `dsl_estado` / `_transicion` | 15 / 24 |
| `dsl_estadistica_tipo` | 17 |
| `dsl_concepto` | 7 (**seis a 0.00**; `INSC_EQUIPO` a 150.00) |
| `dsl_auditoria` | 308 |
| `dsl_torneo`, `_temporada`, `_categoria`, `_equipo`, `_plantilla`, `_persona`, `_inscripcion`, `_fase` | 1 cada una |
| **`dsl_partido`, `_stat`, `dsl_factura`, `_detalle`, `_pago`, `dsl_abono`, `dsl_obligacion`, `dsl_jornada`, `dsl_grupo`, `dsl_serie`, `dsl_sorteo*`, `dsl_designacion`** | **0** |

### 2.5 FACTURACIÓN — 8 tablas

`facturas_electronicas` (1 fila) + detalle, pagos, certificado, config, puntos
de emisión (3) y secuenciales. Integración con el SRI de Ecuador.

---

## 3. Relaciones entre Basketball, Arena y League

**85 claves foráneas en total. Sólo 3 cruzan las fronteras de módulo:**

```
dsa_instalacion.instalacion_sedeid  → general_sede
dsa_reserva.reserva_sedeid          → general_sede
dsl_factura.factura_puntoid         → facturas_electronicas_punto_emision
```

Consecuencias, que son las que gobiernan qué se puede consolidar:

- **`general_sede` es la única dimensión compartida**, y sólo entre Basketball
  y Arena.
- **League no se relaciona con `general_sede`.** Consolidar ingresos por sede
  incluyendo League exige decidir cómo se le asigna sede: por punto de emisión,
  por escenario, o añadiendo el campo.
- **No existe una dimensión «persona» común.** `sujeto_alumno` (Basketball),
  `dsa_cliente` (Arena) y `dsl_persona` (League) son tres tablas independientes
  sin ninguna clave que las una. **Hoy es imposible saber que un alumno y un
  cliente de Arena son la misma persona.** Cualquier KPI de tipo «clientes
  únicos del ecosistema» no es calculable sin resolver esto antes.
- **El eje temporal sí es común**: las tres tienen fechas propias, así que la
  consolidación por periodo es viable de inmediato.
- **Ojo con la homonimia**: `torneo_torneo` (86 filas) pertenece a *Basketball*,
  no a League. Son cosas distintas y no deben sumarse.

---

## 4. Sistema actual de usuarios, roles y permisos

`ds_core/inc/seguridad.php`, 1048 líneas. Es una capa madura, no un esqueleto.

### 4.1 Los tres niveles

```
Usuario ──> Rol ──> ┌ nivel 1  seguridad_rol_modulo   ¿ve el módulo?
                    ├ nivel 2  seguridad_menu         ¿existe la vista?
                    └ nivel 3  seguridad_permiso      ¿ver/crear/editar/eliminar?
```

Funciones públicas: `usuario_tiene_modulo()`, `usuario_tiene_permiso()`,
`puede($accion, $vista, $modulo)` y los atajos `puede_crear/editar/eliminar()`.

### 4.2 Piezas que el prompt pide y **ya existen**

- 403 sin revelar información interna, con vista propia `accesoDenegado`.
- Bloqueo de acceso directo por URL (lista blanca).
- CSRF: `csrf_token()`, `csrf_campo()`, `csrf_valido()`, `csrf_renovar()`.
- Frenado de fuerza bruta por usuario y por IP, con purga y retención de 90 días.
- 2FA completo (`dosfactores.php`), implementado y sin usar por nadie.
- `es_superadministrador()`: el rol 1 pasa todos los controles.
- Ámbito por sede: `seguridad_usuario_sede`.

### 4.3 Modo estricto

`permisos_estrictos()` — por omisión, una vista **no registrada** en
`seguridad_menu` **no se restringe** (decisión deliberada para las vistas de
apoyo de Basketball: formularios, PDF, recibos). Un módulo puede invertirlo
declarando `DS_PERMISOS_ESTRICTOS = true` en su config.

**Sólo League lo hace hoy.** Insights **debe** hacerlo: un módulo cuyas vistas
son todas información gerencial no puede tener vistas abiertas por olvido.

### 4.4 El choque con el §9 del prompt

El prompt pide quince permisos con nombre (`INSIGHTS_ACCESO`,
`INSIGHTS_VER_INGRESOS`, `INSIGHTS_EXPORTAR_EXCEL`…). El sistema no tiene
permisos con nombre: tiene filas.

La traducción es directa y **no requiere un segundo sistema**:

| permiso del prompt | dónde vive realmente |
|---|---|
| `INSIGHTS_ACCESO` | fila en `seguridad_rol_modulo` con `rolmod_modulo='insights'` |
| `INSIGHTS_DASHBOARD_GENERAL` | `seguridad_menu` vista `dashboard` + `permiso_ver` |
| `INSIGHTS_BASKETBALL` / `_ARENA` / `_LEAGUE` / `_FINANCIERO` | una vista registrada cada uno |
| `INSIGHTS_VER_INGRESOS` / `_CARTERA` / `_COSTOS` / `_RENTABILIDAD` | vistas propias, o el mismo mecanismo a nivel de endpoint |
| `INSIGHTS_REPORTES` | vista `reporteList` |
| `INSIGHTS_CONFIGURACION` | vista `configuracion` |
| **`INSIGHTS_EXPORTAR_EXCEL` / `_PDF`** | `permiso_exportar`, **añadido en la migración 045** |

---

## 5. Propuesta de arquitectura para Insights

Adaptada a lo que el proyecto **es**, no a la plantilla del prompt.

```
ds_insights/
├── index.php                 front controller: copia del patrón de Arena
├── config/
│   ├── app.php               DS_MODULO='insights', DS_PERMISOS_ESTRICTOS=true
│   └── vistas.php            lista blanca
├── controllers/
│   └── insightsController.php    consultas analíticas (solo lectura)
├── views/
│   ├── dashboard-view.php        Executive Overview
│   ├── basketball-view.php
│   ├── arena-view.php
│   ├── league-view.php
│   ├── financiero-view.php
│   ├── reporteList-view.php      catálogo de reportes
│   ├── accesoDenegado-view.php
│   └── inc/                      layout-top, filtros globales
├── ajax/                         endpoints JSON
└── assets/{css,js}
```

Decisiones que propongo, cada una con su porqué:

1. **Seguir la convención de Arena/League** (controlador con PDO, sin capa
   `models/`) en vez de la de Basketball. Motivo: es la convención de los dos
   módulos recientes y la que el equipo mantiene ahora. *Esto contradice la
   estructura del §7 del prompt, que propone `models/`; el propio §7 dice que
   no se aplique ciegamente.*

2. **Una conexión PDO en modo solo lectura.** El §4 del prompt exige que
   Insights no modifique datos transaccionales. La forma robusta de garantizarlo
   no es la disciplina del programador, sino **un usuario MySQL con `SELECT`
   únicamente** sobre las tablas de los otros módulos, y permisos de escritura
   sólo sobre las tablas propias de Insights. Requiere decisión del usuario.

3. **Sin capa de agregación al principio.** El §41 del prompt lo pide sólo si
   hace falta. Con 669 pagos y 489 filas de asistencia, cualquier consulta
   directa es instantánea. Introducir `insights_daily_summary` ahora sería
   duplicar datos sin justificación —y el propio prompt lo prohíbe.
   **La excepción es la asistencia** (§10, riesgo R2).

4. **Caché ligero en disco o APCu.** No existe ninguna capa de caché en el
   proyecto. Recomiendo posponerlo hasta tener medición real: con este volumen
   no aporta.

---

## 6. Matriz inicial de KPI

La columna **«datos hoy»** es la que hace útil esta tabla: dice qué se puede
construir ya y qué mostraría cero.

### 6.1 Transversales

| KPI | Fuente | Fórmula | Filtros | Datos hoy |
|---|---|---|---|---|
| Ingresos consolidados | `alumno_pago` + `dsa_pago` + `dsl_factura_pago` | `SUM(valor)` donde estado = cobrado | fecha, sede, módulo | **Sólo Basketball** |
| Por cobrar | `alumno_pago.pago_saldo` + `dsa_reserva.reserva_saldo` + `dsl_obligacion` | `SUM(saldo)` | fecha, sede | Basketball: **$35,00** |
| Transacciones | las tres tablas de pago | `COUNT(*)` | fecha, módulo | Basketball: 669 |
| Ticket promedio | idem | `SUM(valor)/COUNT(*)` | fecha, módulo | Basketball: **$21,39** sobre cobrados |
| Ingresos por módulo | idem | `SUM` agrupado | fecha | **Basketball 100 %** |
| Ingresos por sede | `alumno_pago.pago_sedeid` y `dsa_reserva.reserva_sedeid` | `SUM` agrupado | fecha | Basketball sí; League **no tiene sede** |

> **Corregido el 2026-08-28 (migración 044).** Hasta esa fecha «ingresos por
> sede» se derivaba de `sujeto_alumno.alumno_sedeid`, que es el *presente* del
> alumno. Trasladar a alguien reescribía el historial de las dos sedes: medido
> sobre datos reales, un solo alumno movía **200,00 dólares**, y 442,00
> pertenecían a alumnos inactivos. La sede se congela ahora en el pago, como ya
> hacían `balance_egreso` y `dsa_reserva`. Los listados de *alumnos* por sede
> siguen usando la sede actual, que es lo correcto para esa pregunta.

### 6.2 Basketball — **construible hoy**

| KPI | Fuente | Fórmula | Datos hoy |
|---|---|---|---|
| Alumnos activos | `sujeto_alumno` | `COUNT WHERE alumno_estado='A'` | **266** |
| Alumnos inactivos | idem | `estado='I'` | **41** |
| Nuevos por mes | `alumno_fechaingreso` | `COUNT` agrupado por mes | sí |
| Tasa de abandono | `I / (A+I)` | | **13,4 %** |
| Alumnos por sede | `alumno_sedeid` → `general_sede` | `COUNT` agrupado | 7 sedes |
| Alumnos por edad | `alumno_fechanacimiento` | `TIMESTAMPDIFF` en rangos | sí |
| Recaudación | `alumno_pago` estado `C` | `SUM(pago_valor)` | **$13.666,92** |
| Cartera pendiente | estado `P` | `SUM(pago_saldo)` | **$35,00** |
| Descuentos y becas | `alumno_pago_descuento` | `SUM` | 128 filas |
| **% asistencia** | `asistencia_asistencia` | **requiere despivotar 31 columnas** | 489 filas — ver R2 |

### 6.3 Arena — **schema listo, sin datos**

| KPI | Fórmula | Datos hoy |
|---|---|---|
| Reservas del periodo | `COUNT(dsa_reserva)` | **0** |
| Ocupación | horas reservadas / horas disponibles × 100 | **no calculable**: `dsa_horario` y `dsa_tarifa` vacías |
| Ingresos | `SUM(dsa_pago.pago_valor)` | **0** |
| Ranking de instalaciones | `SUM` agrupado por instalación | 1 instalación |
| Mapa de calor | `dsa_reserva` por día y hora | **0** |

### 6.4 League — **schema listo, sin datos**

| KPI | Fórmula | Datos hoy |
|---|---|---|
| Torneos activos | `COUNT(dsl_torneo)` por estado | **1** |
| Equipos inscritos | `COUNT(dsl_inscripcion)` | **1** |
| Partidos jugados / pendientes | `COUNT(dsl_partido)` por estado | **0** |
| Ingresos por torneo | `dsl_factura_pago` | **0** — y los 7 `dsl_concepto` están a 0.00 |

---

## 7. Scripts SQL que serían necesarios

Ninguno crea ni altera tablas de otros módulos, salvo el punto 3, que es una
decisión del usuario.

1. **Alta del módulo en el menú** — filas en `seguridad_menu` con
   `menu_modulo='insights'`, una por vista.
2. **Concesión al rol** — filas en `seguridad_rol_modulo` y `seguridad_permiso`.
   *(El rol 1 no las necesita: `es_superadministrador()` pasa por encima.)*
3. ~~`ALTER TABLE seguridad_permiso ADD permiso_exportar`~~ — **hecho**, en
   `ds_core/database/045_permiso_exportar.sql`.
4. **Tabla de auditoría de Insights** — el §45 pide registrar
   `VIEW_DASHBOARD`, `EXPORT_EXCEL`, `PERMISSION_DENIED`… No existe auditoría
   transversal; sólo `dsl_auditoria`, que es de League. Propongo
   `insights_auditoria` siguiendo su patrón, sin datos personales.
5. **Usuario MySQL de sólo lectura** — si se acepta la decisión 2 del §5.
6. **Índices** — **ninguno todavía.** El §40 del prompt es explícito y estoy de
   acuerdo: primero medir con `EXPLAIN` sobre consultas reales.

---

## 8. Archivos nuevos a crear

Todos dentro de `ds_insights/`, más nada fuera salvo lo del §9.

```
ds_insights/index.php
ds_insights/config/app.php
ds_insights/config/vistas.php
ds_insights/controllers/insightsController.php
ds_insights/views/dashboard-view.php
ds_insights/views/basketball-view.php
ds_insights/views/arena-view.php
ds_insights/views/league-view.php
ds_insights/views/financiero-view.php
ds_insights/views/reporteList-view.php
ds_insights/views/accesoDenegado-view.php
ds_insights/views/inc/layout-top.php
ds_insights/views/inc/filtros.php
ds_insights/ajax/*.php
ds_insights/assets/css/insights.css
ds_insights/assets/js/insights.js
ds_core/assets/vendor/<librería de gráficos>/     ← dependencia nueva
```

## 9. Archivos existentes que requieren modificación

Deliberadamente pocos. El §51 del prompt prohíbe tocar código estable sin
necesidad.

| archivo | cambio | por qué |
|---|---|---|
| `ds_core/modulos.php` | `'activo' => false` → `true` | para que aparezca en el Hub |
| `ds_core/inc/seguridad.php` | **hecho**: `exportar` en la consulta, en el array y el atajo `puede_exportar()` | los cuatro verbos estaban cableados |
| `ds_core/admin/views/permisoRol-view.php` | **hecho**: columna de exportar y `colspan` corregido | |
| `ds_core/admin/controllers/coreController.php` | **hecho**: lee y persiste la acción nueva | |
| `pruebas/` | suites nuevas | el arnés debe cubrir Insights |

**No requieren cambios** `ds_basketball`, `ds_arena` ni `ds_league`: Insights
sólo lee de sus tablas.

---

## 10. Riesgos técnicos encontrados

### R1 — Dos de los tres módulos no tenían datos · **resuelto el 2026-08-28**

> **Sembrado.** A petición del usuario se generaron datos de prueba para Arena
> y League con `pruebas/semilla_arena.php` y `pruebas/semilla_league.php`.
> Ambos aceptan `--limpiar` y borran **sólo** lo que crearon.
>
> | | Arena | League |
> |---|---:|---:|
> | filas principales | 790 reservas, 634 pagos | 90 partidos, 4 000 estadísticas |
> | apoyo | 6 instalaciones, 42 horarios, 12 tarifas, 25 clientes | 18 equipos, 198 personas y fichas, 30 jornadas |
> | dinero | $16 837,93 cobrado · $5 206,37 por cobrar | $2 250,00 cobrado · $500,00 pendiente |
>
> Cuatro propiedades que la hacen utilizable como base de pruebas:
>
> - **Determinista.** `mt_srand` con semilla fija: dos ejecuciones dan las
>   mismas cifras, así que una prueba puede afirmar $16 837,93 y no
>   «mayor que cero».
> - **Marcada.** Códigos con prefijo `QA-` e identificaciones que empiezan por
>   99 —código de provincia inexistente en Ecuador, así que no puede coincidir
>   con una persona real—. Nombres de fantasía y dominio `example.com`.
>   Importa: esta base ya estuvo volcada en un repositorio público.
> - **Con forma.** Las reservas se concentran de 18:00 a 21:00 entre semana y
>   por la mañana en fin de semana. Una ocupación plana no probaría el mapa de
>   calor. El calendario de League es de ida y vuelta y cruza el día de hoy,
>   para que existan a la vez partidos jugados y por jugar.
> - **Coherente.** Las estadísticas de los 66 partidos finalizados suman
>   **exactamente** el marcador. Los estados siguen el calendario: lo pasado
>   está cumplido o cancelado, lo futuro pendiente o confirmado.
>
> Dos efectos secundarios documentados: la semilla de Arena pone en `STM`
> —formativa y alquiler— las sedes 4 y 5, porque eran `STF` y la aplicación no
> permitiría canchas en ellas; `--limpiar` las devuelve a `STF` si no queda
> ninguna instalación real. Y el texto del panel de League sigue diciendo
> «todavía no hay temporadas, torneos ni equipos», que ya no es cierto: es un
> literal de la vista de fase 0 y no se tocó.

**El diagnóstico original, que sigue explicando por qué se sembró:**

Arena: 0 reservas, 0 pagos, 0 tarifas, 0 horarios. League: 0 partidos, 0
facturas. El resultado esperado del §54 —«Ingresos $18.425, Ocupación 72 %,
Torneos 4»— no es alcanzable: los datos reales de todo el sistema son
**$13.666,92 en ocho meses, íntegramente de Basketball**.

Además el §48 prohíbe expresamente mostrar ceros sin contexto.

**Recomendación:** construir en el orden Basketball → Financiero → Arena →
League (no el del §52, que pone el consolidado antes que el único módulo con
datos), y que cada panel sin datos muestre su estado vacío explicando que el
módulo aún no está en operación, no un cero.

### R2 — La asistencia está pivotada · **alto**

`asistencia_asistencia` guarda **una fila por alumno y mes** con 31 columnas
`asistencia_D01 … asistencia_D31` de `char(1)`. No hay columna de fecha.

Todos los KPI de asistencia del §16 —porcentaje general, por categoría, por
entrenador, por sede, tendencia mensual— exigen **despivotar 31 columnas**. En
SQL directo eso es un `UNION ALL` de 31 ramas por consulta, que no escala y no
permite filtrar por rango de fechas con un índice.

**Recomendación:** es el único caso donde la capa analítica del §41 se justifica
desde el principio. Una vista o tabla derivada
`insights_asistencia_dia(alumno_id, fecha, marca)` construida una vez y
refrescada por mes convierte todos esos KPI en agregaciones triviales. Es
**derivada**, no duplicada: se puede reconstruir entera desde el origen.

### R3 — No hay dimensión «persona» común · **medio**

`sujeto_alumno`, `dsa_cliente` y `dsl_persona` no comparten ninguna clave. No se
puede responder «cuántas personas distintas usan DigiSports» ni cruzar el
comportamiento de alguien entre módulos.

**Recomendación:** no resolverlo dentro de Insights. Es un problema del modelo
del ecosistema y su sitio natural es Core. Documentarlo y dejar los KPI de
persona única fuera del alcance inicial, en vez de calcularlos mal.

### R4 — «Exportar» no existía como acción · **resuelto el 2026-08-28**

> **Aprobada la opción (a) y aplicada** en `ds_core/database/045`.
> `seguridad_permiso.permiso_exportar CHAR(1) NOT NULL DEFAULT 'N'`, más el
> atajo `puede_exportar()`. Cinco archivos: la migración, la capa de
> seguridad —consulta y array, donde los cuatro nombres estaban cableados—,
> el controlador que guarda la matriz y la pantalla de permisos.
|
> Dos mecanismos recogieron la acción nueva **sin tocar una línea de JS**:
> el que alterna una columna entera y el que deshabilita todo lo demás
> cuando se quita la lectura. Verificado en el navegador: `exportar` queda
> deshabilitado en las filas sin «ver».
|
> Por omisión **nadie lo tiene**: las 100 filas existentes quedaron en
> `N`. Nada se rompe porque ninguna pantalla comprobaba esta acción todavía;
> la estrena Insights. El rol 1 no la necesita: `es_superadministrador()`
> pasa por encima.
|
> Suite `pruebas/qa_permiso_exportar.php`, 14 comprobaciones, sometida a
> tres fallos conocidos. Una de ellas existe porque al añadir la columna se
> me olvidó subir el `colspan` de la fila de grupo de 5 a 6, y una tabla
> descuadrada no da error: sólo se ve torcida.

**El diagnóstico original:**


El vocabulario es `ver/crear/editar/eliminar`, y esos cuatro nombres están
**cableados** en la consulta y en la construcción del array de
`permisos_de_la_sesion()`. Los permisos `INSIGHTS_EXPORTAR_EXCEL` y `_PDF` del
§9 no tienen dónde vivir.

Tres salidas:

- **(a) Añadir `permiso_exportar`** — una columna, dos ediciones en
  `seguridad.php`, una casilla en la vista de permisos. `puede('exportar', …)`
  ya funciona genéricamente. Toca un módulo estable, pero de forma aditiva y
  retrocompatible. **Es la que recomiendo.**
- **(b) Reutilizar `permiso_crear`** como «puede generar un archivo». Cero
  cambios, pero mezcla semánticas y confundirá a quien administre permisos.
- **(c) Registrar cada exportación como vista propia.** Sin cambios de esquema,
  pero infla `seguridad_menu` con entradas que no son menú.

**Requiere su decisión antes de la Fase 4.**

### R5 — League no tiene sede · **medio**

`dsl_*` no referencia `general_sede`. «Ingresos por sede» consolidado no puede
incluir League hoy. Salidas: derivarla del punto de emisión
(`dsl_factura.factura_puntoid`), del escenario, o añadir el campo a
`dsl_torneo`. **Requiere su decisión antes de la Fase 6.**

### R6 — Falta librería de gráficos · **bajo**

No hay ninguna. Habrá que incorporar ApexCharts o Chart.js a
`ds_core/assets/vendor/`, autoalojada como el resto (el proyecto no usa CDN).
ApexCharts cubre mejor el mapa de calor del §20; Chart.js es más ligero.

### R7 — SweetAlert2 sólo vive en Basketball · **bajo**

Está en `ds_basketball/app/views/dist/`, no en el vendor común. Si Insights lo
usa, hay que promoverlo a `ds_core/assets/vendor/` — cambio que afecta también
a los enlaces de Basketball.

### R8 — No hay auditoría transversal · **bajo**

Sólo `dsl_auditoria` (League). El §45 pide registrar accesos y exportaciones.
Hay que crearla, y con cuidado: registrar filtros puede capturar datos
personales, lo que choca con la minimización que exige la LOPDP.

### R9 — El §4 del prompt no se garantiza solo · **medio**

«Insights no debe modificar datos transaccionales» es hoy una intención. Con la
conexión PDO actual, un `UPDATE` mal puesto funcionaría. La garantía real es un
usuario MySQL sin permisos de escritura sobre las tablas ajenas. **Requiere su
decisión.**

### R10 — La cartera es una proyección desde hoy, no un hecho · **alto**

Descubierto al revisar si el defecto de la sede se repetía en otro sitio. La
deuda no está almacenada: se calcula así, en `cobranzaController`,
`dashboardController` y `reporteController`:

```
deuda = meses_desde_el_último_pago × (sede_pension − descuento_valor)
```

Los tres factores son **del presente**: el precio actual de la sede actual, el
descuento actualmente activo y `CURDATE()`. Consecuencias medidas:

| escenario | cartera proyectada |
|---|---:|
| hoy | **$4 400,02** |
| si la pensión sube $1 | **$4 617,02** |
| si la pensión sube $5 | **$5 485,02** |

Subir la pensión un dólar **infla la cartera en $217 sin que nadie pague ni
deje de pagar**, porque los 238 alumnos con pensión recalculan toda su deuda
histórica al precio nuevo.

Para el uso operativo del día a día esto es defendible: lo que se debe hoy, al
precio de hoy. **Lo que no se puede hacer es compararla entre periodos**, y el
encargo lo pide expresamente («comparación mensual», «valores por cobrar»).
Comparar la cartera de marzo con la de agosto sería comparar dos proyecciones
hechas desde el mismo instante: no mide nada.

**Recomendación:** en Insights, o se presenta la cartera **sólo como cifra del
momento**, sin serie histórica ni variación porcentual, o se guarda un
snapshot mensual. Lo segundo es la excepción que el propio encargo admite en
su §41: existe justificación histórica. **Requiere su decisión.**

Nota menor del mismo sitio: la consulta usa `MAX(descuento_valor)` agrupando
por alumno. Si alguien tiene dos descuentos activos, se aplica el mayor. Es una
regla de negocio que hoy no está escrita en ninguna parte.

### R11 — «Categoría» no existe como dato · **medio**

El encargo pide alumnos y asistencia por categoría, con el ejemplo
`U8 94 % · U10 91 % · U12 89 %`. Esas bandas **no están en la base**: no hay
columna de categoría en `sujeto_alumno` ni en las tablas de horario, y el
catálogo central tampoco las tiene.

Lo que la aplicación llama categoría es el **año de nacimiento**:

```php
alumnoController.php:812 →  and YEAR(alumno_fechanacimiento) = :categoria
```

El año de nacimiento es estable y sirve perfectamente para agrupar. Pero
convertirlo en una banda U8/U10 exige una fecha de referencia, **y la banda
cambia cada año**: el mismo niño es U10 este año y U12 el siguiente. Una serie
de «asistencia por categoría» a lo largo del tiempo no es reproducible salvo
que se fije la referencia por periodo.

Hay además `torneo_equipo.equipo_categoria`, texto libre de los torneos
internos de Basketball, con valores heterogéneos —`2012`, `2009`,
`2008-2009-2010`—, que no sirve como dimensión.

**Recomendación:** agrupar por año de nacimiento, que es el dato real, y no
inventar bandas. Si se quieren bandas, son un catálogo nuevo con su regla de
pertenencia y su fecha de corte. **Requiere su decisión.**

### R12 — La deuda de un alumno inactivo desaparece · **bajo, latente**

La consulta de cartera filtra `alumno_estado <> 'I'`. Un alumno que se retira
debiendo dinero deja de aparecer en el informe: la deuda no se cancela, se
vuelve invisible.

Hoy no hay pérdida real —los alumnos inactivos suman **0,00** en saldos—, así
que es un riesgo latente y no un problema en curso. Pero conviene decidir si
esa deuda debe seguir contándose, porque el día que ocurra nadie lo notará.

---

## 11. Lo que hace falta que usted decida antes de la Fase 2

1. ~~**R4** — resuelto: columna `permiso_exportar` aplicada.~~
2. **R5** — ¿cómo se le asigna sede a League?
3. **R9** — ¿se crea el usuario MySQL de sólo lectura?
4. **R1** — ¿se reordenan las fases para empezar por Basketball?
5. **R6** — ¿ApexCharts o Chart.js?
6. ¿Insights sigue la convención de Arena/League (sin `models/`) o la de
   Basketball?
7. **R10** — ¿la cartera se muestra sólo como cifra del momento, o se guarda un
   snapshot mensual para poder compararla?
8. **R11** — ¿se agrupa por año de nacimiento, o se crea un catálogo de bandas
   de edad con su fecha de corte?
9. **R12** — ¿la deuda de un alumno retirado sigue contando en la cartera?
