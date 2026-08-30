# DigiSports Insights — Fase 2: Modelo analítico

**Diseño de fuentes, indicadores, tableros, reportes, filtros y permisos.**
Fecha: 2026-08-28 · Continúa [ANALISIS_ACTUAL.md](ANALISIS_ACTUAL.md)

> **Todas las fórmulas de este documento se ejecutaron contra la base real
> antes de escribirlas.** La columna «valor hoy» no es un ejemplo: es lo que
> devuelve la consulta. Un KPI sin ejecutar es una intención, no un diseño.

---

## 1. Los seis principios que gobiernan el resto

**1.1 Insights lee, no escribe.** Sobre las tablas de Basketball, Arena y
League sólo hay `SELECT`. Lo único que Insights escribirá son sus propias
tablas: configuración, favoritos, auditoría y —si se aprueba— los snapshots.
La garantía no es la disciplina del programador sino un usuario MySQL sin
permisos de escritura (riesgo R9).

**1.2 Ningún KPI sin fuente.** Si no se puede señalar la tabla y la columna,
el indicador no entra. Los que no tienen fuente están listados en §11 con su
motivo, en vez de calcularse mal.

**1.3 Lo histórico se congela; lo actual se deriva.** Es la distinción que
más problemas ha causado en este sistema:

| pregunta | naturaleza | de dónde sale |
|---|---|---|
| ¿Cuánto recaudó La Salle en marzo? | hecho pasado | `alumno_pago.pago_sedeid` — congelada |
| ¿Cuántos alumnos tiene La Salle? | estado presente | `sujeto_alumno.alumno_sedeid` — actual |

Confundirlas fue el defecto que corrigió la migración 044. Unificarlas sería
cometerlo al revés.

**1.4 Un número sin contexto no es información.** Todo KPI de la vista
ejecutiva lleva valor, comparación con el periodo anterior y sentido de la
variación. Los que **no pueden compararse** —la cartera, mientras sea una
proyección— se muestran sin variación y con una nota, no con un porcentaje
inventado.

**1.5 Los catálogos mandan.** Estados, rubros y formas de pago se leen de
`dsl_estado`, `general_tabla_catalogo` y `dsa_forma_ingreso`. Ningún literal
`'C'` ni `'FINALIZADO'` escrito a mano en una consulta de Insights.

**1.6 Cero antes que nada.** Un panel sin datos explica por qué, no muestra
un cero. La distinción importa: «no hay reservas en el periodo» y «el módulo
no está en operación» son cosas distintas para quien decide.

---

## 2. Modelo de datos: hechos y dimensiones

Insights no crea un almacén: consulta el modelo transaccional. Pero conviene
verlo con ojos dimensionales, porque eso decide qué se puede cruzar.

### 2.1 Tablas de hechos

| hecho | tabla | grano | medida | fecha |
|---|---|---|---|---|
| Cobro Basketball | `alumno_pago` | un pago | `pago_valor`, `pago_saldo` | `pago_fecha` |
| Descuento | `alumno_pago_descuento` | un descuento por alumno | `descuento_valor` | `descuento_fecha` |
| Asistencia | `asistencia_asistencia` | **alumno × mes**, pivotada | `D01…D31` | `asistencia_aniomes` |
| Reserva Arena | `dsa_reserva` | una reserva | `reserva_total`, `_abonado`, `_saldo`, `_horas` | `reserva_fecha` |
| Cobro Arena | `dsa_pago` | un pago | `pago_valor` | `pago_fecha` |
| Partido | `dsl_partido` | un partido | `partido_puntoslocal/visitante` | `partido_fecha` |
| Estadística | `dsl_partido_stat` | partido × persona × tipo | `stat_valor` | vía partido |
| Obligación League | `dsl_obligacion` | una deuda | `obligacion_valor` | `obligacion_fecha` |
| Cobro League | `dsl_abono` | un abono | `abono_valor` | `abono_fecha` |

### 2.2 Dimensiones y su alcance real

| dimensión | tabla | Basketball | Arena | League |
|---|---|---|---|---|
| **Tiempo** | derivada de las fechas | sí | sí | sí |
| **Sede** | `general_sede` | `pago_sedeid` (congelada) | `reserva_sedeid` | **no existe** (R5) |
| **Módulo** | constante del origen | sí | sí | sí |
| Instalación | `dsa_instalacion` | — | sí | `partido_instalacionid` |
| Categoría | — | **año de nacimiento** (R11) | — | `dsl_categoria` |
| Persona | tres tablas sin clave común (R3) | `sujeto_alumno` | `dsa_cliente` | `dsl_persona` |

**Consecuencia operativa:** los únicos ejes que cruzan los tres módulos son
**tiempo** y **módulo**. La **sede** cruza dos. La **persona** no cruza
ninguno. Todo cuadro consolidado del tablero ejecutivo se construye sobre
tiempo y módulo; la sede aparece en la vista financiera con League marcado
como «sin sede».

---

## 3. Matriz de indicadores

Ejecutadas el 2026-08-28 sobre el periodo 2026-01-01 → 2026-08-31.

### 3.1 Transversales — el Executive Overview

| KPI | Fórmula | Valor hoy |
|---|---|---:|
| **Ingresos cobrados** | suma de los tres orígenes, filtrando por estado efectivo | **$110 492,70** |
| — Basketball | `SUM(pago_valor)` de `alumno_pago` con `pago_estado='C'` | $13 726,92 · 12,4 % |
| — Arena | `SUM(pago_valor)` de `dsa_pago` con `pago_estado='A'` | $94 515,78 · 85,5 % |
| — League | `SUM(abono_valor)` de `dsl_abono` con `abono_anulado='N'` | $2 250,00 · 2,0 % |
| **Por cobrar** | saldo vivo, **no** proyección | **$26 954,47** |
| — Basketball | `SUM(pago_saldo)` con `pago_estado='P'` | $35,00 |
| — Arena | `SUM(reserva_saldo)` con `reserva_estado<>'X'` | $26 419,47 |
| — League | obligación − descuento + recargo − abonos no anulados | $500,00 |
| **Transacciones** | conteo en los tres orígenes | 5 099 |
| **Ticket promedio** | `SUM / COUNT` por origen | BK $21,39 · AR $21,05 · LG $132,35 |
| **Variación mensual** | mes actual vs anterior | julio $16 503,33 → agosto $13 521,69 · **−18,1 %** |

El ticket de League es alto y correcto: son inscripciones de $150, no cuotas.

**El reparto 85 / 12 / 2 no es un defecto de los datos: es lo que dicen.**
Arena factura siete veces más que Basketball con seis instalaciones al 33 %
de ocupación, mientras Basketball tiene 266 alumnos activos que sólo pagan
2,4 meses de 8. Un tablero que mostrara los dos módulos «equilibrados»
estaría escondiendo justo el problema que hay que ver. Ver §3.5.

> Las cifras son del **2026-08-28** y se mueven: durante la redacción de este
> documento entraron dos pagos reales de pensión registrados desde la
> aplicación. Ambos llegaron con `pago_sedeid` correctamente relleno, lo que
> confirma en uso real la migración 044.

### 3.2 Basketball

| KPI | Fórmula | Valor hoy |
|---|---|---:|
| Alumnos activos | `COUNT` con `alumno_estado='A'` | **266** |
| Alumnos inactivos | `alumno_estado='I'` | 41 |
| Tasa de abandono | `I / (A+I)` | **13,4 %** |
| Altas por mes | `COUNT` agrupado por `alumno_fechaingreso` | pico en julio: **84** |
| Ingresos por sede | `SUM(pago_valor)` agrupado por `pago_sedeid` | La Salle $11 061,92 · Cariamanga $1 960,00 |
| Cartera | `SUM(pago_saldo)` | $35,00 |
| **Cumplimiento de pago** | meses pagados / meses esperados | **ver §3.5** |
| % asistencia | requiere despivotar (R2) | pendiente de decisión |

### 3.3 Arena

| KPI | Fórmula | Valor hoy |
|---|---|---:|
| Reservas del periodo | `COUNT` de `dsa_reserva` | 5 851 |
| Reservas vigentes | `estado IN ('P','C') AND fecha >= CURDATE()` | 1 016 |
| **Ocupación** | horas reservadas / horas de apertura × 100 | **33,5 %** |
| Ocupación en hora punta | 18–21 h, lunes a viernes | **71,6 %** la Cancha Central |
| Ingreso por hora | `SUM(reserva_total) / SUM(reserva_horas)` | $15,98 medio |
| Cancelaciones | `reserva_estado='X'` | 431 · 7,4 % |
| Clientes | `COUNT` de `dsa_cliente` activos | 26 |

**La ocupación merece explicación, porque su denominador no existe en ninguna
tabla.** Hay que construirlo: `dsa_horario` guarda la apertura por instalación
y día de la semana; se cuenta cuántas veces cae cada día dentro del periodo y
se multiplica por su duración. Verificado: 6 455 h reservadas de 19 260
disponibles.

Ranking verificado, que es lo que responde «qué instalación rinde más»:

| instalación | ocupación | ingreso/hora |
|---|---:|---:|
| Residencia Bloque A | 42,1 % | $12,46 |
| Cancha Central | 35,4 % | **$28,44** |
| Cancha Norte | 33,1 % | $20,58 |
| Cancha Descubierta | 24,5 % | $13,74 |

La residencia ocupa más y rinde menos por hora: es exactamente el tipo de
lectura que justifica el tablero.

### 3.4 League

| KPI | Fórmula | Valor hoy |
|---|---|---:|
| Torneos | `COUNT` de `dsl_torneo` activos | 2 |
| Equipos inscritos | `COUNT` de `dsl_inscripcion` | 19 |
| Jugadores | `plantilla_rol='J'`, distintos | 181 |
| Partidos jugados | estado `FINALIZADO` o `WALKOVER` | 68 |
| Partidos pendientes | estados no finales | 22 |
| Ingresos por torneo | abonos de obligaciones de sus categorías | Copa QA Apertura **$2 250,00** |
| Tabla de posiciones | victorias por inscripción, con `dsl_categoria` puntuando | verificada |

La puntuación **no se codifica**: `dsl_categoria` guarda `ptsvictoria`,
`ptsderrota` y `ptswalkover`, y `dsl_estado.estado_efectivo` dice qué partido
cuenta. Un walkover suma; un cancelado no.

### 3.5 El indicador que la base pedía y el encargo no

Al verificar las cifras apareció esto, y es probablemente lo más útil que
Insights puede decirle a esta escuela:

```
266 alumnos activos × $28,57 de pensión × 8 meses  =  $60 800 esperado
recaudado por pensiones                            =  $12 411   (20,4 %)
```

No es un hueco de datos: **el 82,7 % de los alumnos activos tiene al menos un
pago**. Lo que ocurre es que la media es de **2,4 meses pagados de 8**, y 71
alumnos pagaron **una sola vez**. Ninguno supera los 5.

> **La matrícula se retiene; el pago no.** La tasa de abandono dice 13,4 %
> porque mide bajas administrativas. La de pago dice otra cosa muy distinta.

Propuesta de KPI, con su fórmula:

| KPI | Fórmula |
|---|---|
| **Cumplimiento de pago** | pagos de pensión registrados / meses transcurridos desde el ingreso del alumno |
| **Alumnos al día** | los que pagaron el mes en curso |
| **Alumnos en riesgo** | activos sin pago en los últimos 2 meses |

Va al centro de atención gerencial (§6), no al fondo de un reporte.

---

## 4. Tableros

### 4.1 Executive Overview

Cuatro tarjetas, un gráfico temporal, un reparto por módulo, tres resúmenes
por módulo y el centro de atención. En ese orden: lo que responde «¿cómo
vamos?» antes que lo que responde «¿por qué?».

```
┌──────────────┬──────────────┬──────────────┬──────────────┐
│ $110.492,70  │ $26.954,47   │ 5.099        │ 33,5 %       │
│ Ingresos     │ Por cobrar   │ Transacciones│ Ocupación    │
│ −18,1 % ↓    │ sin comparar │ −17,4 % ↓    │ −2,9 pts ↓   │
└──────────────┴──────────────┴──────────────┴──────────────┘
```

Las variaciones son las reales de julio a agosto, medidas: ingresos
$16 503,33 → $13 521,69; transacciones 786 → 649; ocupación 34,8 % → 31,9 %.
Agosto va bajando en los tres, de forma coherente entre sí — que es
justamente la señal que el tablero debe hacer visible.

La ocupación varía en **puntos porcentuales**, no en porcentaje sobre el
porcentaje: pasar de 34,8 % a 31,9 % es −2,9 puntos, no −8,3 %. Confundirlo
es un error clásico de tablero y aquí se evita en la unidad.

«Por cobrar» aparece **sin variación** a propósito: mientras la cartera de
Basketball sea una proyección desde hoy (R10), compararla entre periodos no
mide nada. La tarjeta lo dice en su tooltip en vez de fingir un porcentaje.

### 4.2 Financial Overview

Ingresos por módulo, por sede y por periodo; cobrado frente a pendiente;
ticket promedio; descuentos y becas. **League aparece sin sede** hasta que se
resuelva R5, y así se rotula: «sin sede asignada», no repartido a prorrateo.

### 4.3 Por módulo

Basketball —alumnos, altas y bajas, cumplimiento de pago, cartera,
asistencia si se resuelve R2—; Arena —ocupación, mapa de calor, ranking de
instalaciones, cancelaciones—; League —torneos, participación, calendario,
recaudación—.

---

## 5. Filtros y comparación

| filtro | fuente | aplica a |
|---|---|---|
| Periodo | rápidos + personalizado | todo |
| Sede | `general_sede` | Basketball y Arena; League deshabilitado con motivo |
| Módulo | constante | consolidados |
| Comparar con | periodo anterior, mismo periodo del año anterior | los KPI que lo admitan |

Se persisten en sesión, no en la URL, para que un enlace compartido no lleve
el ámbito de sede de otro usuario —lo que sería un escape de datos entre
sedes—. El filtro de sede **nunca amplía** el ámbito: se intersecta siempre
con `seguridad_usuario_sede`.

**«Comparar con» no está disponible en todos los KPI**, y eso se muestra. La
cartera y la ocupación proyectada no lo admiten; los ingresos sí.

---

## 6. Centro de atención gerencial

Reglas derivadas de datos reales, no de umbrales inventados. Cada una nombra
su fuente y su corte:

| regla | condición | valor hoy |
|---|---|---|
| Cobro estancado | alumnos activos sin pago en 2 meses | por medir al implementar |
| Cartera de Arena | saldo vivo sobre reservas no canceladas | **$26 419,47** |
| Ocupación en caída | instalación con caída > 15 % vs mes anterior | por medir |
| Partidos sin resultado | finalizados con puntuación nula | 0 |
| Inscripciones impagas | obligaciones vencidas sin abono | $500,00 |

Las reglas viven en una tabla propia de Insights con su umbral, para poder
añadir o ajustar sin tocar código. Eso es lo que pide el §26 del encargo.

---

## 7. Drill-down

Cuatro saltos, cada uno con su vista y su permiso:

```
Ingresos $110.492,70
   └→ por módulo          Arena 85,5 % · Basketball 12,4 % · League 2,0 %
        └→ Basketball     por sede: La Salle $11.061,92
             └→ La Salle  por rubro: Pensión $10.275,92 · Inscripción $270,00
                  └→ transacciones (lista, con permiso de detalle)
```

El último salto muestra pagos individuales, así que exige permiso propio: es
donde el dato deja de ser agregado.

---

## 8. Catálogo de reportes

Doce en la primera entrega, todos con fuente verificada. No se reimplementan
los **once reportes que Basketball ya tiene**: Insights enlaza a ellos.

| reporte | módulo | fuente |
|---|---|---|
| Ingresos por módulo | consolidado | los tres orígenes |
| Ingresos por sede | consolidado | `pago_sedeid` + `reserva_sedeid` |
| Evolución mensual | consolidado | serie por mes |
| Cartera consolidada | consolidado | saldos vivos |
| Alumnos por sede y año | Basketball | `sujeto_alumno` |
| Altas y bajas | Basketball | `alumno_fechaingreso`, `alumno_estado` |
| Cumplimiento de pago | Basketball | `alumno_pago` vs meses transcurridos |
| Ocupación por instalación | Arena | reservas / apertura |
| Mapa de calor | Arena | reservas por día y hora |
| Ranking de instalaciones | Arena | ingreso y ocupación |
| Participación por torneo | League | inscripciones y plantillas |
| Recaudación por torneo | League | obligaciones y abonos |

---

## 9. Permisos

Sobre el modelo real —módulo, vista, acción—, sin inventar un segundo
sistema. Cada vista de Insights se registra en `seguridad_menu` con
`menu_modulo='insights'`.

| vista | permiso del encargo | acción |
|---|---|---|
| `dashboard` | `INSIGHTS_DASHBOARD_GENERAL` | ver |
| `financiero` | `INSIGHTS_FINANCIERO`, `_VER_INGRESOS` | ver |
| `basketball` / `arena` / `league` | los tres homónimos | ver |
| `cartera` | `INSIGHTS_VER_CARTERA` | ver |
| `reporteList` | `INSIGHTS_REPORTES` | ver |
| `transacciones` | detalle del drill-down | ver |
| `configuracion` | `INSIGHTS_CONFIGURACION` | ver, editar |
| exportación | `INSIGHTS_EXPORTAR_*` | **exportar** — `puede_exportar($vista)` |

`DS_PERMISOS_ESTRICTOS = true`. En un módulo cuyas vistas son todas
información gerencial, olvidar registrar una no puede significar dejarla
abierta.

**El permiso se comprueba en el endpoint, no en la vista.** Ocultar una
tarjeta no protege el JSON que la alimenta.

---

## 10. Endpoints

```
GET  /ds_insights/ajax/resumen        Executive Overview
GET  /ds_insights/ajax/serie          serie temporal
GET  /ds_insights/ajax/modulo/{m}     detalle por módulo
GET  /ds_insights/ajax/ocupacion      ocupación y mapa de calor
GET  /ds_insights/ajax/atencion       centro de atención
```

Respuesta uniforme, con la metainformación que hace auditable el número:

```json
{ "success": true,
  "data": {},
  "meta": { "periodo": {"desde":"", "hasta":""},
            "comparado_con": {},
            "sedes_aplicadas": [],
            "generado": "" } }
```

`sedes_aplicadas` no es decorativo: deja constancia de que el ámbito del
usuario se aplicó, y permite que la auditoría lo registre.

---

## 11. Lo que NO se construye, y por qué

Decirlo es parte del diseño. Cada uno tiene su riesgo en el análisis:

| pedido | por qué no | desbloquea |
|---|---|---|
| Personas únicas del ecosistema | no hay clave común entre las tres tablas de persona | R3, y su sitio es Core |
| Asistencia por categoría | la tabla está pivotada y «categoría» no existe | R2 y R11 |
| Cartera comparada entre periodos | es una proyección desde hoy: comparar dos proyecciones no mide nada | R10 |
| Ingresos de League por sede | League no referencia `general_sede` | R5 |
| Rentabilidad | no hay costes por instalación ni por sede en ninguna tabla | requiere modelo de costes |

La última merece énfasis: el encargo pide `INSIGHTS_VER_RENTABILIDAD` y un
ranking de rentabilidad, pero **en la base no hay un solo coste** asociado a
una instalación o una sede. `balance_egreso` tiene 27 filas y sede propia:
es el punto de partida si se quiere, pero hoy no alcanza para hablar de
rentabilidad sin inventarla.

---

## 12. Criterios de aceptación medibles

Cada uno comprobable por el arnés, no por opinión:

1. Un usuario sin `insights` en `seguridad_rol_modulo` recibe 403 en la vista
   **y** en cada endpoint.
2. El total del Executive Overview coincide con la suma de los tres módulos,
   al céntimo.
3. Cambiar el periodo cambia todos los KPI comparables y ninguno de los no
   comparables muestra variación.
4. La ocupación calculada coincide con horas reservadas / horas de apertura
   verificadas a mano sobre una instalación.
5. Un usuario con ámbito de una sede no ve datos de otra, ni en pantalla ni en
   el JSON ni en la exportación. — *incumplido hasta la Fase 13; ver 12 bis y
   `qa_insights_ambito_sede.php`*
6. Ningún panel sin datos muestra un cero sin explicación.
7. Ninguna consulta de Insights escribe en tablas de otros módulos.
8. Los ingresos por sede no cambian al trasladar un alumno (ya cubierto por
   `qa_sede_historica.php`).

---

## 12 bis. El ámbito de sede, y cómo se nos escapó

El criterio 5 de la lista anterior —«un usuario con ámbito de una sede no ve
datos de otra»— **no se cumplía**. La Fase 13 lo encontró.

`sedesDelUsuario()` estaba escrito, con un comentario que explicaba por qué era
imprescindible («un coordinador que sólo ve su sede en Basketball no puede
verlas todas porque el informe sea consolidado»), y **no lo llamaba nadie**. No
era un descuido teórico: hay seis usuarios limitados en la base ahora mismo, y
los siete controladores de Basketball respetan el ámbito desde hace años. Sólo
Insights lo ignoraba.

Un método que existe se parece mucho a un método que funciona. Ahí se escondió.

### Cómo se aplica

Tres ayudantes, según por dónde llegue cada tabla a su sede:

| ayudante | para | resuelve con |
|---|---|---|
| `sede($col)` | la tabla tiene columna de sede | `col IN (...)` |
| `sedeReserva($col)` | `dsa_pago`, que la hereda de la reserva | subconsulta a `dsa_reserva` |
| `sedeAlumno($col)` | `insights_v_asistencia_dia` y `facturas_electronicas` | subconsulta a `sujeto_alumno` |

Los tres devuelven **cadena vacía** cuando el usuario no está limitado, que es
por qué el camino sin restricción quedó byte a byte idéntico tras acotar los
treinta métodos —se comprobó comparando el HTML completo de las siete vistas
antes y después—.

Las subconsultas no son un JOIN a propósito: varias de estas consultas son
agregados, y un JOIN de más cambiaría los conteos multiplicando filas. Es el
mismo error de *fan-out* que ya infló las marcas de asistencia 4,4 veces en
`alumnosPorEntrenador`.

### Por qué los ids van en línea y no como parámetros

La regla del proyecto es no concatenar **nunca** información recibida del
usuario. Estos ids no vienen del usuario: se leen de `seguridad_usuario_sede`
con la clave de la sesión y pasan por `array_map('intval', ...)`. No queda
ninguna cadena del exterior en el resultado.

Se hizo así porque el mismo fragmento se inserta en subconsultas que ya traen
sus propios marcadores, y arrastrar nombres únicos por cada una multiplicaría
las oportunidades de equivocarse justo en el código que protege el acceso.

### Lo que evita que vuelva a pasar

`qa_insights_ambito_sede.php` tiene dos mitades, y hacen falta las dos:

- **estática** — cada método cuya SQL toca una tabla con sede tiene que llamar
  a un ayudante. Es la que caza el próximo método que se escriba sin acotar.
  Encontró cinco que se habían quedado fuera en la primera pasada, entre ellos
  `ingresosPorSede`, que es precisamente la tabla por sede.
- **en ejecución** — un usuario limitado pide las mismas pantallas y
  exportaciones y no puede aparecer un dato ajeno. Antes comprueba que el
  superadministrador **sí** los ve, para no celebrar una pantalla vacía.

Se sometieron a un defecto provocado: con `sede()` devolviendo cadena vacía
—el defecto original exacto— las cuatro comprobaciones de ejecución se ponen
rojas y nombran lo que se filtró.

### Lo que queda por decidir: League

League **no tiene sede** por la decisión R5: sus torneos pueden organizarse
fuera de las canchas del club. Por eso sus consultas quedaron **sin acotar**, y
un usuario limitado a dos sedes ve las cifras de la liga entera.

Se consideró ocultárselas —un coordinador de dos sedes viendo la recaudación
completa de los torneos es discutible— pero eso sería **inventar una política**,
no cumplir el criterio 5: los datos de League no son «de otra sede», son de
ninguna. Queda como pregunta abierta para la escuela. Si se decide ocultarlas,
el cambio es un ayudante más y un `AND 1 = 0`; no hay que rehacer nada.

---
## 12 ter. Las tres pantallas que faltaban

La migración 048 registró once entradas de menú. Se escribieron ocho. Las
otras tres —**Cartera**, **Transacciones** e **Indicadores**— quedaron
declaradas en el menú y en el enrutador, y sin archivo de vista.

### Por qué no se notó

`ds_insights/index.php` responde 404 cuando el archivo no existe **y a
continuación pinta el tablero**. El código de estado es correcto y nadie lo
ve: el navegador muestra el Panel, y quien pulsa «Cartera» concluye que el
enlace no hace nada. Lo encontró el usuario, no el arnés.

El arnés comprobaba **menú ↔ enrutador** en los dos sentidos, y las tres
estaban bien declaradas en ambos. La cadena real es menú → enrutador →
**archivo**, y el tercer eslabón no se miraba. Ya se comprueba.

### Cartera

Saldo vivo, sin filtro de periodo: la deuda no es un flujo. La evolución
mensual se lee del snapshot y **no se recalcula** (decisión R10): preguntarle
hoy a la base cuánto se debía en marzo devuelve lo que se debe *hoy* de marzo,
que es sistemáticamente menor.

La antigüedad no se mide igual en los tres módulos, y la pantalla lo rotula:

| módulo | se cuenta desde | es un vencimiento real |
|---|---|---|
| League | `obligacion_vence` | **sí** |
| Arena | `reserva_fecha` | no |
| Basketball | `pago_fecha` | no — la tabla no guarda vencimiento |

Las reservas **futuras** con saldo van a su propia columna, «aún no vencida».
Un `DATEDIFF` a secas las metería en el tramo más reciente y abultaría la
deuda joven con dinero que todavía no es exigible: son $17.670,80 de $26.954,47,
así que no es un matiz.

Homogeneizarlo pide una fecha de vencimiento en `alumno_pago`, que es un
cambio de Basketball y no de Insights. Queda anotado.

### Transacciones

El último salto del drill-down (§7). Paginación **del servidor**: son 5.499
filas y volcarlas al navegador es lo que prohíbe el §51. Por eso tampoco lleva
el buscador de DataTables — buscaría sólo dentro de la página visible y daría
la impresión de haber mirado en todas.

Con el filtro de módulo puesto, la UNION se arma con una sola rama en vez de
tres. Medido: 649 transacciones del mes en 67 ms; el año entero, 5.499 en 110
páginas, 132 ms.

Es la única pantalla del módulo que muestra pagos de personas identificadas,
así que registra en la bitácora quién la consultó y con qué periodo. Del
alumno va el nombre corto y nada más.

### Indicadores

«Requiere tu atención» avisaba con la condición más simple que existe: mayor
que cero. Con 266 alumnos eso significa que avisa **siempre**, y un panel que
avisa siempre no avisa de nada.

Los umbrales viven en `insights_umbral` (migración 054) y se siembran con
valor 1, que reproduce exactamente el comportamiento anterior: primero se hace
configurable, después la escuela decide sus números.

Escribir aquí no contradice «Insights sólo lee»: es tabla propia, el candado
de `InsightsConexion` la admite, y guardar exige `permiso_editar`, CSRF y deja
registro de quién y cuándo.

Si un código de umbral desaparece de la tabla, el aviso **vuelve a avisar
siempre** en vez de callarse. Un umbral borrado por error no puede tener el
efecto de silenciar algo en silencio.

### Un tropiezo que conviene recordar

La migración 054 se aplicó con `mysql.exe` sin declarar el juego de
caracteres, y «pensión» se guardó como «pensi├│n». Lo peor no fue el fallo
sino la comprobación: `mb_check_encoding($texto, 'UTF-8')` devolvió **true**,
porque esos bytes son UTF-8 perfectamente válido — sólo que dicen otra cosa.
La función mira que los bytes estén bien formados, no que signifiquen lo que
deben.

Se vio en una captura de pantalla, no en una prueba. Las migraciones desde la
017 ya declaraban `SET NAMES utf8mb4`; la 054 no seguía esa convención y ahora
sí. Un barrido de las 93 tablas con texto confirmó que el daño se limitaba a
esa fila.

---
## 13. Decisiones tomadas

Resueltas el 2026-08-28. Cada una cambia el diseño, no sólo el papeleo.

### R5 · League no tiene sede, y no es un hueco

**Los torneos pueden organizarse fuera de las canchas y sedes del club.** No
se añade el campo: reflejaría mal el negocio. Consecuencias en el modelo:

- «Ingresos por sede» rotula la porción de League como **«fuera de sede»**, no
  la reparte a prorrateo ni la esconde.
- El filtro de sede **deshabilita** League y dice por qué, en vez de devolver
  cero sin explicación.
- Queda un análisis derivado que sí se puede hacer y que antes no se veía:
  `dsl_partido.partido_instalacionid` apunta a una instalación de Arena
  cuando el partido **sí** se juega en casa. De ahí sale «partidos en
  instalaciones propias frente a externas», que es información real y
  gratuita.

### R6 · ApexCharts, autoalojada

**Versión 3.54.1, licencia MIT**, en
`ds_core/assets/vendor/apexcharts3/` — 539 KB de JS y 13 KB de CSS. Sin CDN,
como el resto del vendor del proyecto: AdminLTE, Bootstrap, DataTables y
FontAwesome ya se sirven desde el aplicativo.

Verificada dibujando un gráfico de área real con la serie consolidada de
2026, servida por Apache con `text/javascript`, 0 errores de consola.

> Al probarla, `page.addScriptTag` y la inyección de `<script src>` desde el
> navegador automatizado **no** creaban el global: es un artefacto de
> aislamiento de Playwright, no de la librería. Con una etiqueta `<script>`
> real en una página servida por Apache funciona. Conviene saberlo antes de
> escribir la suite que la vigile.

### R10 · La cartera se fotografía cada mes

Tabla `insights_cartera_snapshot` (migración 046) y capturador
`ds_insights/cli/capturar_cartera.php`, idempotente.

Al diseñarlo apareció que en Basketball hay **dos carteras que se llaman
igual y difieren en un factor de 124**:

| tipo | qué es | valor |
|---|---|---:|
| `REGISTRADA` | lo que consta pendiente en documentos emitidos. Un **hecho** escrito en la fila | $35,00 |
| `PROYECTADA` | meses transcurridos × pensión actual. Una **estimación** que depende del precio de hoy | $4 340,02 |

Presentarlas mezcladas sería repetir el error que corrigió la migración 044:
confundir un hecho con una derivación del presente. La columna
`snapshot_tipo` las separa.

**El capturador se niega a retratar el pasado.** Todas sus consultas leen el
estado de hoy, así que guardarlas con fecha de marzo inventaría un histórico
indistinguible del verdadero. Sólo admite el mes en curso, y el anterior
únicamente durante los cinco primeros días. Se descubrió porque la primera
versión sí lo aceptaba y escribió diez filas falsas.

### R11 · Se agrupa por año de nacimiento

Es el dato real: `YEAR(alumno_fechanacimiento)`, que es además lo que la
aplicación ya llama categoría. No se inventan bandas U8/U10, que cambiarían
cada año y harían irreproducible cualquier serie.

**Los años sin alumnos inscritos aparecen igualmente**, con cero. El rango se
deriva del mínimo y el máximo presentes y se rellenan los huecos: una
categoría vacía es información —dice que no hay relevo en esa edad— y
omitirla la escondería.

### R12 · La deuda del retirado cuenta aparte

Ni se mezcla con la cartera viva ni se pierde. El capturador la guarda con
`snapshot_tipo = 'RETIRADOS'`, con su módulo y su sede.

Hoy vale **$0,00** porque ningún inactivo tiene saldo. Se comprobó que la
consulta funciona marcando temporalmente a un deudor como inactivo: la
recoge. Un cero verificado y un cero por consulta rota se ven igual en
pantalla.

### Convención de estructura

**La de Arena y League**, sin capa `models/`. No por ser «más segura» —los
dos patrones comprueban los mismos tres niveles— sino por dos diferencias
concretas del front controller:

| | Basketball | Arena / League |
|---|---|---|
| acceso denegado | página de denegación con **HTTP 200** | **HTTP 403** |
| modo estricto | no disponible | `DS_PERMISOS_ESTRICTOS` |

En un módulo donde toda vista es información gerencial, el modo estricto es
justo lo que hace falta: olvidar registrar una no puede significar dejarla
abierta.

### R9 · Los permisos de la aplicación en Core; la conexión, con candado propio

**Los usuarios y permisos se asignan en Core**, con el modelo que ya existe
—`Usuario → Rol → Permisos` sobre las tablas `seguridad_*`—. Insights lo usa
tal cual y no crea nada paralelo.

Eso resuelve la capa de la *aplicación*. Queda la del *motor*: con qué
usuario de MySQL se conecta el código. Como la respuesta apunta a no tocar
la administración de usuarios, la garantía se implementa en la conexión del
módulo, que no necesita nada del servidor:

`ds_insights/config/conexion.php` — `InsightsConexion extends PDO`, que
rechaza toda sentencia de escritura salvo sobre `insights_*`. Bloquea
`UPDATE`, `INSERT`, `DELETE`, `TRUNCATE`, `DROP` y `ALTER` sobre tablas
ajenas, y también los disfraces: un `UPDATE` precedido de `/* SELECT */`,
un `DELETE` tras un comentario de línea, y una CTE que acaba escribiendo.

**Su límite, dicho claramente:** inspecciona el TEXTO de la sentencia. Es una
barrera real —hace que un descuido falle en la primera prueba en vez de
corromper datos durante meses— pero no la impone el motor, así que no es
inviolable. La garantía fuerte sigue siendo un usuario de MySQL con `SELECT`
sobre las tablas ajenas: **una sentencia `GRANT` que no cambia una línea de
código**, porque esta clase seguiría funcionando igual. Queda recomendada
para producción.

Suite `pruebas/qa_insights_solo_lectura.php`, 21 comprobaciones. Vigila las
dos direcciones: que el candado no se afloje —aflojarlo hace fallar nueve— y
que no se apriete tanto que estorbe al trabajo legítimo, que es la mitad que
suele romperse primero. Un candado que molesta se acaba quitando.

> Se descubrió usándolo: el candado tomaba el `UPDATE` de un
> `ON DUPLICATE KEY UPDATE` por una tabla ajena y rechazaba la propia
> escritura de Insights. Apareció en el primer uso real del capturador, no
> en la prueba de laboratorio. Ese caso es ahora una comprobación fija.

### R2 · La asistencia, una vista y no una tabla

**El justificado cuenta como falta**: el alumno no fue. Que el representante
avisara es otra cosa y tiene indicador propio.

```
% asistencia = (P + A) / total marcas      68,3 % hoy
% avisadas   = J / (F + J)                 26,6 % hoy
```

Ese segundo número es el hallazgo: **de cada cuatro inasistencias, sólo una
se avisa**. Eso no se veía disuelto dentro del porcentaje de asistencia.

`insights_v_asistencia_dia` (migración 047) despivota las 31 columnas en una
fila por día, con la fecha reconstruida. La vista **entrega la marca cruda**;
la regla de negocio vive en quien consulta, para que cambiarla no obligue a
reconstruir la vista.

**Se eligió vista y no tabla, y la medición cambió la recomendación previa.**
En el análisis dije que el `UNION ALL` «no escala». Se midieron las tres
opciones con los datos reales:

| opción | consulta agregada | rango de fechas | tamaño SQL | duplica |
|---|---:|---:|---:|---|
| `UNION ALL` en cada consulta | 22,0 ms | ~22 ms | 4 434 car. | no |
| **vista** | 24,3 ms | 22,7 ms | 427 car. | **no** |
| tabla derivada | 24,1 ms | **1,4 ms** | 427 car. | sí, 1 243 filas |

**La tabla no es más rápida.** Su única ventaja aparece al filtrar por rango
de fechas, y eso sólo importará con volumen. Duplicar datos hoy —sin
justificación de rendimiento ni de histórico— iría contra la regla del
proyecto.

La vista da la simplicidad sin duplicar nada, y deja la puerta abierta: si el
rango de fechas se vuelve lento, se materializa con el **mismo nombre y las
mismas columnas**, sin tocar una sola consulta.

> Dato curioso y útil: al despivotar, los datos son **doce veces más
> pequeños** —1 243 filas frente a 15 159 celdas—, porque 13 916 celdas están
> vacías. La tabla pivotada no ahorra espacio: lo gasta.

Suite `pruebas/qa_asistencia_dia.php`. No comprueba que la vista funcione,
sino que **coincida con el origen marca por marca**: perder una rama del
`UNION` seguiría devolviendo cifras creíbles. Sometida al caso, quitar el
día 2 hace fallar cuatro comprobaciones.

*Supuesto pendiente de confirmar:* el **atraso** (`A`) cuenta como asistencia
—el alumno fue, aunque tarde—. Son 4 registros de 1 243, así que hoy no mueve
la cifra; si prefiere lo contrario, es un cambio en un solo sitio.

---

## 14. La Fase 3 puede empezar

**No queda ninguna decisión bloqueando.** Las nueve están tomadas y
aplicadas, cada una con su migración, su código y su suite.

Un supuesto menor sigue abierto y no bloquea: el atraso cuenta hoy como
asistencia (4 registros de 1 243).
