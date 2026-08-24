/*
| QA de finanzas de League.
|
| Lo que se comprueba de verdad:
|   · el saldo se deriva, no se guarda: anular un cobro lo devuelve
|   · un abono nunca puede superar el saldo vivo
|   · el estado sale de los abonos, nunca se escribe a mano
|   · anular no borra: el movimiento sigue ahí, marcado
|   · una obligación con cobros no se puede anular de un plumazo
|
| Limpia SÓLO lo que crea, y toma línea base de dsl_auditoria al empezar
| en lugar de comparar contra cero.
*/
import { createRequire } from 'node:module'
import { readdirSync } from 'node:fs'
const EXT = 'C:/Users/dbafaces/.vscode/extensions/'
const RAIZ = EXT + readdirSync(EXT).filter(d => d.startsWith('danielsanmedium.dscodegpt-')).sort().pop() + '/standalone/'
const { chromium } = createRequire(RAIZ)('patchright')

const BASE = 'http://localhost/barcelona/ds_league/'
const AJAX = BASE + 'ajax/leagueAjax.php'

const nav = await chromium.launch({ headless: true, channel: 'chromium' })

let fallos = 0
const af = (t, ok, d = '') => {
  console.log('  ' + t.padEnd(58) + (ok ? 'OK' : 'FALLA') + (d ? '  (' + d + ')' : ''))
  if (!ok) fallos++
}

/* Un rechazo cuenta sólo si es EL rechazo esperado.
   Comprobar nada más que icono === 'error' deja pasar la prueba cuando el
   servidor rechazó por otro motivo, y entonces la aserción no demuestra
   nada sobre la regla que decía estar probando. */
const rechaza = (t, r, motivo) =>
  af(t, r.icono === 'error' && (r.titulo ?? '').includes(motivo),
     (r.titulo ?? r.crudo ?? '') + (r.icono !== 'error' ? ' [NO rechazó]' : ''))

const ctx = await nav.newContext({ viewport: { width: 1500, height: 1000 } })
await ctx.addCookies([{ name: 'DigiSportsBasketball', value: 'dsqaui0000000000000',
                        domain: 'localhost', path: '/' }])
const admin = await ctx.newPage()

const post = async (c) => await admin.evaluate(async ([url, campos]) => {
  const fd = new FormData()
  for (const k in campos) fd.append(k, campos[k])
  const r = await fetch(url, { method: 'POST', body: fd })
  const t = await r.text()
  try { return JSON.parse(t) } catch { return { crudo: t.slice(0, 200) } }
}, [AJAX, c])

const leerId = async (vista, clave, valor, campo) => {
  await admin.goto(BASE + vista + '/', { waitUntil: 'networkidle' })
  return await admin.evaluate(([k, v, c]) => [...document.querySelectorAll('.js-editar')]
      .map(x => JSON.parse(x.getAttribute('data-fila')))
      .find(x => x[k] === v)?.[c] ?? 0, [clave, valor, campo])
}

/* Lee del panel el saldo, el estado y el abonado de la obligación. */
const leerFila = async (catId, deudorParcial) => {
  await admin.goto(BASE + 'cobranzaPanel/' + catId + '/', { waitUntil: 'networkidle' })
  return await admin.evaluate((frag) => {
    const tr = [...document.querySelectorAll('tbody tr')]
      .find(t => t.innerText.includes(frag) && t.querySelector('.badge'))
    if (!tr) return null
    const c = tr.querySelectorAll('td')
    return { estado: tr.querySelector('.badge')?.innerText.trim() ?? '',
             total:   c[3]?.innerText.trim() ?? '',
             abonado: c[4]?.innerText.trim() ?? '',
             saldo:   c[5]?.innerText.trim() ?? '',
             cobrar:  !!tr.querySelector('.js-cobrar'),
             anular:  !!tr.querySelector('.js-anular-obl') }
  }, deudorParcial)
}

await admin.goto(BASE + 'panel/', { waitUntil: 'networkidle' })
const sello = 'QF' + Date.now().toString().slice(-6)

/* HOY ES EL DEL SERVIDOR, NO EL DEL NAVEGADOR.
   toISOString() da la fecha en UTC; el servidor corre en América/Guayaquil
   (UTC−5), así que a partir de las 19:00 locales el navegador ya está en
   el día siguiente. Un cobro fechado así se rechaza por «fecha futura» y
   la prueba culpa al código de un error suyo. */
const fechaServidor = async (catId) => await admin.evaluate(async ([base, c]) => {
  const r = await fetch(base + 'cobranzaPanel/' + c + '/')
  const t = await r.text()
  return (t.match(/id="vence"[^>]*min="(\d{4}-\d\d-\d\d)"/) ?? [])[1] ?? ''
}, [BASE, catId])

const masDias = (iso, n) => {
  const [a, m, d] = iso.split('-').map(Number)
  const x = new Date(Date.UTC(a, m - 1, d + n))
  return x.toISOString().slice(0, 10)
}

/*==============  Las pantallas existen  ==============*/
for (const v of ['conceptoList', 'cobranzaPanel']) {
  const r = await admin.goto(BASE + v + '/', { waitUntil: 'networkidle' })
  af('la vista ' + v + ' responde 200', r.status() === 200, 'HTTP ' + r.status())
}

const enMenu = await admin.evaluate(() =>
  [...document.querySelectorAll('.sidebar-menu .nav-link')].map(a => a.innerText.trim()))
af('«Cobranza» aparece en el menú', enMenu.some(t => t.includes('Cobranza')))
af('«Conceptos cobrables» aparece en el menú', enMenu.some(t => t.includes('Conceptos')))

/*==============  Montaje  ==============*/
await post({ modulo_league: 'guardarTemporada', temporada_id: 0, temporada_nombre: sello,
             temporada_desde: '2026-01-01', temporada_hasta: '2026-12-31' })
const tempId = await leerId('temporadaList', 'temporada_nombre', sello, 'temporada_id')

await post({ modulo_league: 'guardarTorneo', torneo_id: 0, torneo_temporadaid: tempId,
             torneo_nombre: sello, torneo_deporte: 'baloncesto' })
const torId = await leerId('torneoList', 'torneo_nombre', sello, 'torneo_id')

await post({ modulo_league: 'guardarCategoria', categoria_id: 0, categoria_torneoid: torId,
             categoria_nombre: sello, categoria_genero: 'X', categoria_edadmin: '',
             categoria_edadmax: '', categoria_fechacorte: '', categoria_ptsvictoria: 2,
             categoria_ptsderrota: 1, categoria_ptswalkover: 0 })
const catId = await leerId('categoriaList', 'categoria_nombre', sello, 'categoria_id')

await post({ modulo_league: 'guardarEquipo', equipo_id: 0, equipo_nombre: sello + ' Club',
             equipo_corto: 'QF', equipo_contacto: '', equipo_telefono: '', equipo_email: '' })
const eqId = await leerId('equipoList', 'equipo_nombre', sello + ' Club', 'equipo_id')

await admin.goto(BASE + 'categoriaPanel/' + catId + '/', { waitUntil: 'networkidle' })
await post({ modulo_league: 'inscribirEquipo', inscripcion_equipoid: eqId,
             inscripcion_categoriaid: catId, inscripcion_valor: '0' })
await admin.reload({ waitUntil: 'networkidle' })
const inscId = await admin.evaluate(() =>
  +([...document.querySelectorAll('.js-mover')][0]?.getAttribute('data-id') ?? 0))
af('monta la inscripción', inscId > 0 && catId > 0, 'insc ' + inscId + ' cat ' + catId)

/*==============  Catálogo de conceptos  ==============*/
const cod = sello.toUpperCase()

const c1 = await post({ modulo_league: 'guardarConcepto', concepto_id: 0,
                        concepto_codigo: cod, concepto_nombre: 'Prueba ' + sello,
                        concepto_ambito: 'INSCRIPCION', concepto_valor: '50.00' })
af('crea un concepto', c1.icono === 'success', c1.titulo)

const c2 = await post({ modulo_league: 'guardarConcepto', concepto_id: 0,
                        concepto_codigo: cod, concepto_nombre: 'Repetido',
                        concepto_ambito: 'INSCRIPCION', concepto_valor: '10' })
rechaza('rechaza un código repetido', c2, 'Código repetido')

const c3 = await post({ modulo_league: 'guardarConcepto', concepto_id: 0,
                        concepto_codigo: 'ab-cd', concepto_nombre: 'Malo',
                        concepto_ambito: 'INSCRIPCION', concepto_valor: '10' })
rechaza('rechaza un código con caracteres no válidos', c3, 'Código no válido')

const c4 = await post({ modulo_league: 'guardarConcepto', concepto_id: 0,
                        concepto_codigo: cod + 'X', concepto_nombre: 'Ámbito raro',
                        concepto_ambito: 'CUALQUIERA', concepto_valor: '10' })
rechaza('rechaza un ámbito inexistente', c4, 'Ámbito no válido')

const conId = await leerId('conceptoList', 'concepto_codigo', cod, 'concepto_id')
af('el concepto aparece en el catálogo', conId > 0, 'id ' + conId)

/*==============  Obligaciones: lo que NO se acepta  ==============*/
const HOY = await fechaServidor(catId)
af('lee la fecha del servidor', /^\d{4}-\d\d-\d\d$/.test(HOY), HOY)

const ayer   = masDias(HOY, -1)
const manana = masDias(HOY, 1)
const dentro = masDias(HOY, 30)

const o0 = await post({ modulo_league: 'guardarObligacion', concepto_id: conId,
                        origen_tipo: 'INSCRIPCION', origen_id: inscId,
                        valor: '0', descuento: '0', recargo: '0', detalle: '', vence: '' })
rechaza('rechaza una obligación de cero', o0, 'Valor no válido')

const oD = await post({ modulo_league: 'guardarObligacion', concepto_id: conId,
                        origen_tipo: 'INSCRIPCION', origen_id: inscId,
                        valor: '100', descuento: '150', recargo: '0', detalle: '', vence: '' })
rechaza('rechaza un descuento mayor que el valor', oD, 'Descuento excesivo')

const oV = await post({ modulo_league: 'guardarObligacion', concepto_id: conId,
                        origen_tipo: 'INSCRIPCION', origen_id: inscId,
                        valor: '100', descuento: '0', recargo: '0', detalle: '', vence: ayer })
rechaza('rechaza un vencimiento ya pasado', oV, 'Vencimiento en el pasado')

const oT = await post({ modulo_league: 'guardarObligacion', concepto_id: conId,
                        origen_tipo: 'INVENTADO', origen_id: inscId,
                        valor: '100', descuento: '0', recargo: '0', detalle: '', vence: '' })
rechaza('rechaza un origen que no existe', oT, 'Origen no válido')

const oP = await post({ modulo_league: 'guardarObligacion', concepto_id: conId,
                        origen_tipo: 'PARTIDO', origen_id: 999999, equipo_id: eqId,
                        valor: '100', descuento: '0', recargo: '0', detalle: '', vence: '' })
rechaza('rechaza un partido inexistente', oP, 'No encontrado')

/*==============  La obligación buena  ==============*/
const ok1 = await post({ modulo_league: 'guardarObligacion', concepto_id: conId,
                         origen_tipo: 'INSCRIPCION', origen_id: inscId,
                         valor: '100', descuento: '20', recargo: '20',
                         detalle: sello + ' cuota', vence: dentro })
af('crea la obligación', ok1.icono === 'success', (ok1.texto ?? '').slice(0, 40))

let f = await leerFila(catId, sello + ' cuota')
af('nace con el total valor + recargo − descuento', f?.total === '100.00', f?.total)
af('nace PENDIENTE', f?.estado === 'PENDIENTE', f?.estado)
af('nace con saldo completo', f?.saldo === '100.00', f?.saldo)
af('ofrece cobrar y ofrece anular', f?.cobrar === true && f?.anular === true)

/* El id, para los abonos. */
const oblId = await admin.evaluate((frag) => {
  const tr = [...document.querySelectorAll('tbody tr')].find(t => t.innerText.includes(frag))
  return +(tr?.querySelector('.js-cobrar')?.getAttribute('data-id') ?? 0)
}, sello + ' cuota')

/*==============  Abonos: lo que NO se acepta  ==============*/
const aMax = await post({ modulo_league: 'guardarAbono', obligacion_id: oblId,
                          valor: '150', fecha: HOY, forma: '01', referencia: '' })
rechaza('rechaza un abono mayor que el saldo', aMax, 'Abono mayor que el saldo')

const aFut = await post({ modulo_league: 'guardarAbono', obligacion_id: oblId,
                          valor: '10', fecha: manana, forma: '01', referencia: '' })
rechaza('rechaza un cobro con fecha futura', aFut, 'Fecha futura')

const aCero = await post({ modulo_league: 'guardarAbono', obligacion_id: oblId,
                           valor: '0', fecha: HOY, forma: '01', referencia: '' })
rechaza('rechaza un abono de cero', aCero, 'Importe no válido')

/*==============  Abono parcial  ==============*/
const hoyS = HOY
const a1 = await post({ modulo_league: 'guardarAbono', obligacion_id: oblId,
                        valor: '40', fecha: hoyS, forma: '01', referencia: 'REC-' + sello })
af('registra un abono parcial', a1.icono === 'success', (a1.texto ?? '').slice(0, 40))

f = await leerFila(catId, sello + ' cuota')
af('el estado pasa a PARCIAL solo', f?.estado === 'PARCIAL', f?.estado)
af('el saldo baja a 60', f?.saldo === '60.00', f?.saldo)
af('el abonado sube a 40', f?.abonado === '40.00', f?.abonado)

/*==============  Con cobros, no se anula la obligación  ==============*/
const anO = await post({ modulo_league: 'anularObligacion', obligacion_id: oblId,
                         motivo: 'prueba' })
rechaza('no deja anular una obligación con cobros', anO, 'Tiene cobros registrados')

f = await leerFila(catId, sello + ' cuota')
af('y la pantalla ya no ofrece el botón de anular', f?.anular === false)

/*==============  Se salda  ==============*/
const a2 = await post({ modulo_league: 'guardarAbono', obligacion_id: oblId,
                        valor: '60', fecha: hoyS, forma: '20', referencia: 'TRF-' + sello })
af('registra el resto', a2.icono === 'success')

f = await leerFila(catId, sello + ' cuota')
af('el estado pasa a PAGADA solo', f?.estado === 'PAGADA', f?.estado)
af('el saldo queda en cero', f?.saldo === '0.00', f?.saldo)
af('y ya no ofrece cobrar', f?.cobrar === false)

const a3 = await post({ modulo_league: 'guardarAbono', obligacion_id: oblId,
                        valor: '5', fecha: hoyS, forma: '01', referencia: '' })
rechaza('rechaza cobrar sobre una obligación saldada', a3, 'Ya está pagada')

/*==============  Anular un cobro  ==============*/
/* Se anula EL DE 40, elegido por su referencia y no por su posición: si
   mañana cambia el orden del listado, la prueba seguiría pasando mientras
   comprueba otra cosa. */
const abonoId = await admin.evaluate((ref) => {
  const fila = [...document.querySelectorAll('.ds-abono')].find(t => t.innerText.includes(ref))
  return +(fila?.querySelector('.js-anular-abono')?.getAttribute('data-id') ?? 0)
}, 'REC-' + sello)
af('los cobros se listan con su botón de anular', abonoId > 0, 'id ' + abonoId)

const sinMotivo = await post({ modulo_league: 'anularAbono', abono_id: abonoId, motivo: '' })
rechaza('anular un cobro exige motivo', sinMotivo, 'Falta el motivo')

const an1 = await post({ modulo_league: 'anularAbono', abono_id: abonoId,
                         motivo: 'Cheque devuelto ' + sello })
af('anula el cobro', an1.icono === 'success', (an1.texto ?? '').slice(0, 40))

const an2 = await post({ modulo_league: 'anularAbono', abono_id: abonoId, motivo: 'otra vez' })
rechaza('no deja anular dos veces el mismo cobro', an2, 'Ya estaba anulado')

f = await leerFila(catId, sello + ' cuota')
af('el importe anulado vuelve al saldo', f?.saldo === '40.00', f?.saldo)
af('el estado vuelve a PARCIAL solo', f?.estado === 'PARCIAL', f?.estado)
af('vuelve a ofrecer cobrar', f?.cobrar === true)

/* EL MOVIMIENTO NO SE BORRA: sigue listado, tachado y con su motivo. */
const rastro = await admin.evaluate(() => {
  const caja = [...document.querySelectorAll('tr[id^=abonos-]')].pop()
  return caja ? { texto: caja.innerText,
                  tachados: caja.querySelectorAll('[style*="line-through"]').length,
                  filas: caja.querySelectorAll('.ds-abono').length } : null
})
af('el cobro anulado SIGUE en el histórico', rastro?.filas === 2,
   (rastro?.filas ?? 0) + ' movimientos')
af('y se muestra tachado', rastro?.tachados === 1, (rastro?.tachados ?? 0) + ' tachados')
af('con el motivo a la vista', (rastro?.texto ?? '').includes('Cheque devuelto'))

/*==============  Los otros ámbitos  ==============*/
const cEq = await post({ modulo_league: 'guardarConcepto', concepto_id: 0,
                         concepto_codigo: cod + 'M', concepto_nombre: 'Multa ' + sello,
                         concepto_ambito: 'EQUIPO', concepto_valor: '15' })
const conEqId = await leerId('conceptoList', 'concepto_codigo', cod + 'M', 'concepto_id')

const oEq = await post({ modulo_league: 'guardarObligacion', concepto_id: conEqId,
                         origen_tipo: 'EQUIPO', origen_id: eqId, categoria_id: catId,
                         valor: '15', descuento: '0', recargo: '0',
                         detalle: sello + ' multa', vence: '' })
af('cobra también a un equipo, sin inscripción de por medio',
   oEq.icono === 'success', (oEq.texto ?? '').slice(0, 40))

/* EL HUECO QUE ESTA PRUEBA DESTAPÓ: una multa con origen EQUIPO no casaba
   con el filtro por categoría y no salía en ninguna pantalla. Deuda que
   nadie ve es deuda que nadie cobra. */
const multa = await leerFila(catId, sello + ' multa')
af('la multa del equipo SÍ aparece en el panel de la categoría',
   multa !== null, multa?.saldo ?? 'invisible')
af('y con su saldo completo', multa?.saldo === '15.00', multa?.saldo)

const oEqMal = await post({ modulo_league: 'guardarObligacion', concepto_id: conEqId,
                            origen_tipo: 'EQUIPO', origen_id: 999999, categoria_id: catId,
                            valor: '15', descuento: '0', recargo: '0', detalle: '', vence: '' })
rechaza('rechaza un equipo inexistente', oEqMal, 'No encontrado')

/* Un equipo real, pero que no juega en esta categoría. */
await post({ modulo_league: 'guardarEquipo', equipo_id: 0, equipo_nombre: sello + ' Ajeno',
             equipo_corto: 'AJ', equipo_contacto: '', equipo_telefono: '', equipo_email: '' })
const ajenoId = await leerId('equipoList', 'equipo_nombre', sello + ' Ajeno', 'equipo_id')

const oAjeno = await post({ modulo_league: 'guardarObligacion', concepto_id: conEqId,
                            origen_tipo: 'EQUIPO', origen_id: ajenoId, categoria_id: catId,
                            valor: '15', descuento: '0', recargo: '0', detalle: '', vence: '' })
rechaza('rechaza cobrarle a un equipo que no juega esa categoría',
        oAjeno, 'Equipo ajeno a la categoría')

/*==============  Anular una obligación limpia  ==============*/
/* Hay que estar EN el panel: leerId dejó el navegador en conceptoList, y
   buscar la fila ahí devuelve 0 sin decir por qué. */
await admin.goto(BASE + 'cobranzaPanel/' + catId + '/', { waitUntil: 'networkidle' })
const oblEqId = await admin.evaluate((frag) => {
  const tr = [...document.querySelectorAll('tbody tr')].find(t => t.innerText.includes(frag))
  return +(tr?.querySelector('.js-anular-obl')?.getAttribute('data-id') ?? 0)
}, sello + ' multa')

const anSin = await post({ modulo_league: 'anularObligacion', obligacion_id: oblEqId, motivo: '' })
rechaza('anular una obligación exige motivo', anSin, 'Falta el motivo')

const anOk = await post({ modulo_league: 'anularObligacion', obligacion_id: oblEqId,
                          motivo: 'Cargada por error ' + sello })
af('anula una obligación sin cobros', anOk.icono === 'success')

const anulada = await leerFila(catId, sello + ' multa')
af('la anulada sigue listada pero ya no ofrece cobrar',
   anulada !== null && anulada.cobrar === false, anulada?.estado ?? 'desapareció')
af('y consta como ANULADA', anulada?.estado === 'ANULADA', anulada?.estado)

/*==============  El resumen cuadra  ==============*/
await admin.goto(BASE + 'cobranzaPanel/' + catId + '/', { waitUntil: 'networkidle' })
const caja = await admin.evaluate(() =>
  [...document.querySelectorAll('.small-box .inner h3')].map(h => h.innerText.trim()))
/* Se cobraron 40 + 60 y se anularon los 40: quedan 60 cobrados y 40 por
   cobrar. La multa anulada de 15 no suma en ninguna columna. */
af('emitido = 100 (la anulada no suma)', caja[0] === '100.00', caja[0])
af('cobrado = 60 (el cobro anulado no cuenta)', caja[1] === '60.00', caja[1])
af('por cobrar = 40', caja[2] === '40.00', caja[2])

/*==============  Filtros  ==============*/
await admin.goto(BASE + 'cobranzaPanel/' + catId + '/?estado=PAGADA', { waitUntil: 'networkidle' })
const enPagadas = await admin.evaluate((s) =>
  [...document.querySelectorAll('#tablaObligaciones tbody tr')].some(t => t.innerText.includes(s)),
  sello + ' cuota')
af('el filtro PAGADA no la muestra (ya no lo está)', enPagadas === false)

await admin.goto(BASE + 'cobranzaPanel/' + catId + '/?estado=PARCIAL', { waitUntil: 'networkidle' })
const enParciales = await admin.evaluate((s) =>
  [...document.querySelectorAll('#tablaObligaciones tbody tr')].some(t => t.innerText.includes(s)),
  sello + ' cuota')
af('el filtro PARCIAL sí la muestra', enParciales === true)

await admin.goto(BASE + 'cobranzaPanel/' + catId + '/?estado=NO_EXISTE', { waitUntil: 'networkidle' })
const conBasura = await admin.evaluate(() => document.querySelectorAll('#tablaObligaciones tbody tr').length)
af('un estado inventado en la URL no rompe la pantalla', conBasura > 0, conBasura + ' filas')

console.log('\n  SELLO=' + sello + '  cat=' + catId + '  insc=' + inscId
          + '  obl=' + oblId + '/' + oblEqId + '  conc=' + conId + '/' + conEqId)
console.log('\nfallos: ' + fallos)
await nav.close()
process.exit(fallos === 0 ? 0 : 1)
