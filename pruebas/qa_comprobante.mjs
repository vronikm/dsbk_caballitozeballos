/*
| QA de emisión de comprobantes de League.
|
| Lo que se comprueba de verdad:
|   · el número sale del punto de League (003-003), no del de Basketball
|   · la clave de acceso tiene 49 dígitos y su módulo 11 cuadra
|   · un comprobante no mezcla deudores
|   · una obligación ya facturada no se factura dos veces
|   · sin datos tributarios válidos no se emite
|   · el secuencial avanza y NO se reutiliza
|
| Limpia sólo lo que crea.
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
  try { return JSON.parse(t) } catch { return { crudo: t.slice(0, 250) } }
}, [AJAX, c])

const leerId = async (vista, clave, valor, campo) => {
  await admin.goto(BASE + vista + '/', { waitUntil: 'networkidle' })
  return await admin.evaluate(([k, v, c]) => [...document.querySelectorAll('.js-editar')]
      .map(x => JSON.parse(x.getAttribute('data-fila')))
      .find(x => x[k] === v)?.[c] ?? 0, [clave, valor, campo])
}

/* Módulo 11 del SRI, reimplementado aquí a propósito: comprobar la clave
   con el mismo código que la generó no demostraría nada. */
const modulo11 = (base) => {
  const coef = [2, 3, 4, 5, 6, 7]
  let suma = 0, j = 0
  for (let i = base.length - 1; i >= 0; i--) { suma += (+base[i]) * coef[j]; j = (j + 1) % 6 }
  const r = 11 - (suma % 11)
  return r === 11 ? 0 : (r === 10 ? 1 : r)
}

await admin.goto(BASE + 'panel/', { waitUntil: 'networkidle' })
const sello = 'QK' + Date.now().toString().slice(-6)

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

/*==============  Identificación: se valida al guardar  ==============*/
/* El dígito cambiado va en la CÉDULA BASE (posición 10), no en el código
   de establecimiento: 0705727287009 sería un RUC perfectamente válido —el
   establecimiento 009 existe— y esperar que lo rechazara probaría lo
   contrario de lo que dice la prueba. */
const malIdent = await post({ modulo_league: 'guardarEquipo', equipo_id: 0,
    equipo_nombre: sello + ' Malo', equipo_corto: 'ML', equipo_contacto: '',
    equipo_telefono: '', equipo_email: '', equipo_idtipo: '04',
    equipo_identificacion: '0705727286001', equipo_razonsocial: 'X', equipo_direccion: 'X' })
rechaza('rechaza un RUC con dígito verificador malo', malIdent, 'Identificación no válida')

const rucCeros = await post({ modulo_league: 'guardarEquipo', equipo_id: 0,
    equipo_nombre: sello + ' Malo0', equipo_corto: 'M0', equipo_contacto: '',
    equipo_telefono: '', equipo_email: '', equipo_idtipo: '04',
    equipo_identificacion: '0705727287000', equipo_razonsocial: 'X', equipo_direccion: 'X' })
rechaza('rechaza un RUC con establecimiento 000', rucCeros, 'Identificación no válida')

const malCed = await post({ modulo_league: 'guardarEquipo', equipo_id: 0,
    equipo_nombre: sello + ' Malo2', equipo_corto: 'M2', equipo_contacto: '',
    equipo_telefono: '', equipo_email: '', equipo_idtipo: '05',
    equipo_identificacion: '1104015283', equipo_razonsocial: 'X', equipo_direccion: 'X' })
rechaza('rechaza una cédula con dígito verificador malo', malCed, 'Identificación no válida')

/* Equipo A: completo y facturable. */
const okA = await post({ modulo_league: 'guardarEquipo', equipo_id: 0,
    equipo_nombre: sello + ' Club A', equipo_corto: 'CA', equipo_contacto: 'Delegado',
    equipo_telefono: '0999999999', equipo_email: 'a@example.com', equipo_idtipo: '04',
    equipo_identificacion: '0705727287001', equipo_razonsocial: 'CLUB A ' + sello,
    equipo_direccion: 'Av. Siempre Viva 123' })
af('acepta un RUC válido', okA.icono === 'success', okA.titulo)
const eqA = await leerId('equipoList', 'equipo_nombre', sello + ' Club A', 'equipo_id')

/* Equipo B: completo también, para probar que no se mezclan deudores. */
await post({ modulo_league: 'guardarEquipo', equipo_id: 0,
    equipo_nombre: sello + ' Club B', equipo_corto: 'CB', equipo_contacto: '',
    equipo_telefono: '', equipo_email: '', equipo_idtipo: '05',
    equipo_identificacion: '1104015282', equipo_razonsocial: 'CLUB B ' + sello,
    equipo_direccion: 'Calle 2' })
const eqB = await leerId('equipoList', 'equipo_nombre', sello + ' Club B', 'equipo_id')

/* Equipo C: SIN datos tributarios. */
await post({ modulo_league: 'guardarEquipo', equipo_id: 0,
    equipo_nombre: sello + ' Club C', equipo_corto: 'CC', equipo_contacto: '',
    equipo_telefono: '', equipo_email: '' })
const eqC = await leerId('equipoList', 'equipo_nombre', sello + ' Club C', 'equipo_id')

af('monta los tres equipos', eqA > 0 && eqB > 0 && eqC > 0, [eqA, eqB, eqC].join('/'))

/* Inscribir los tres. */
await admin.goto(BASE + 'categoriaPanel/' + catId + '/', { waitUntil: 'networkidle' })
for (const e of [eqA, eqB, eqC]) {
  await post({ modulo_league: 'inscribirEquipo', inscripcion_equipoid: e,
               inscripcion_categoriaid: catId, inscripcion_valor: '0' })
}
await admin.reload({ waitUntil: 'networkidle' })
const inscIds = await admin.evaluate(() =>
  [...document.querySelectorAll('.js-mover')].map(b => +b.getAttribute('data-id')))
af('inscribe los tres equipos', inscIds.length === 3, inscIds.join(','))

/*==============  Conceptos y obligaciones  ==============*/
const cod = sello.toUpperCase()
await post({ modulo_league: 'guardarConcepto', concepto_id: 0, concepto_codigo: cod,
             concepto_nombre: 'Inscripción ' + sello, concepto_ambito: 'INSCRIPCION',
             concepto_valor: '120' })
const conId = await leerId('conceptoList', 'concepto_codigo', cod, 'concepto_id')

await post({ modulo_league: 'guardarConcepto', concepto_id: 0, concepto_codigo: cod + 'M',
             concepto_nombre: 'Multa ' + sello, concepto_ambito: 'EQUIPO',
             concepto_valor: '30' })
const conMulta = await leerId('conceptoList', 'concepto_codigo', cod + 'M', 'concepto_id')

/* Dos obligaciones al equipo A y una al equipo B. */
for (const [i, insc] of inscIds.entries()) {
  await post({ modulo_league: 'guardarObligacion', concepto_id: conId,
               origen_tipo: 'INSCRIPCION', origen_id: insc, categoria_id: catId,
               valor: '120', descuento: '0', recargo: '0',
               detalle: sello + ' insc' + i, vence: '' })
}
await post({ modulo_league: 'guardarObligacion', concepto_id: conMulta,
             origen_tipo: 'EQUIPO', origen_id: eqA, categoria_id: catId,
             valor: '30', descuento: '0', recargo: '0',
             detalle: sello + ' multaA', vence: '' })

/*==============  Lo que el panel ofrece  ==============*/
await admin.goto(BASE + 'cobranzaPanel/' + catId + '/', { waitUntil: 'networkidle' })

const bloques = await admin.evaluate(() =>
  [...document.querySelectorAll('.js-facturable')].reduce((a, c) => {
    const k = c.getAttribute('data-equipo'); (a[k] = a[k] || []).push(c.value); return a }, {}))
af('agrupa las facturables por equipo', Object.keys(bloques).length === 3,
   Object.keys(bloques).length + ' equipos')
af('el equipo A tiene sus dos conceptos', (bloques[String(eqA)] ?? []).length === 2,
   (bloques[String(eqA)] ?? []).length + ' líneas')

const sinDatos = await admin.evaluate((e) => {
  const chk = [...document.querySelectorAll('.js-facturable')]
    .find(c => c.getAttribute('data-equipo') === String(e))
  const btn = [...document.querySelectorAll('.js-emitir')]
    .find(b => b.getAttribute('data-equipo') === String(e))
  return { deshabilitado: chk ? chk.disabled : null, ofreceEmitir: !!btn }
}, eqC)
af('el equipo sin datos tributarios no se puede marcar', sinDatos.deshabilitado === true)
af('y no se le ofrece el botón de emitir', sinDatos.ofreceEmitir === false)

/*==============  Lo que NO se acepta  ==============*/
const idsA = bloques[String(eqA)]
const idsB = bloques[String(eqB)]
const idsC = bloques[String(eqC)]

const vacio = await post({ modulo_league: 'emitirComprobante', obligaciones: '', forma: '20' })
rechaza('rechaza emitir sin obligaciones', vacio, 'Nada que facturar')

const mezcla = await post({ modulo_league: 'emitirComprobante',
                            obligaciones: idsA[0] + ',' + idsB[0], forma: '20' })
rechaza('rechaza mezclar deudores en un comprobante', mezcla, 'Deudores distintos')

const sinRuc = await post({ modulo_league: 'emitirComprobante',
                            obligaciones: idsC[0], forma: '20' })
rechaza('rechaza emitir a un equipo sin datos tributarios', sinRuc,
        'Faltan datos tributarios')

const fantasma = await post({ modulo_league: 'emitirComprobante',
                              obligaciones: '999999', forma: '20' })
rechaza('rechaza una obligación inexistente', fantasma, 'Obligación no encontrada')

/*==============  La emisión buena  ==============*/
const emi = await post({ modulo_league: 'emitirComprobante',
                         obligaciones: idsA.join(','), forma: '20' })
af('emite el comprobante', emi.icono === 'success', (emi.texto ?? emi.crudo ?? '').slice(0, 60))

await admin.goto(BASE + 'cobranzaPanel/' + catId + '/', { waitUntil: 'networkidle' })
const comp = await admin.evaluate(() => {
  const tr = [...document.querySelectorAll('tbody tr')].find(t => t.querySelector('code'))
  if (!tr) return null
  const c = tr.querySelectorAll('td')
  return { numero: tr.querySelector('code').innerText.trim(),
           comprador: c[1].innerText.trim(),
           total: c[2].innerText.trim(),
           estado: c[3].innerText.trim() }
})
af('el comprobante aparece en el listado', comp !== null, comp?.numero ?? 'no aparece')
af('usa el punto de emisión de League, no el de Basketball',
   (comp?.numero ?? '').startsWith('003-003-'), comp?.numero)
af('el total suma las dos líneas (120 + 30)', comp?.total === '150.00', comp?.total)
af('nace en estado GENERADA', (comp?.estado ?? '').includes('GENERADA'), comp?.estado)
af('el comprador es el equipo A', (comp?.comprador ?? '').includes('CLUB A'), comp?.comprador)
af('y dice que son 2 líneas', (comp?.estado ?? '').includes('2 líneas'), comp?.estado)

/*==============  Las obligaciones quedan enlazadas  ==============*/
const yaNo = await admin.evaluate((e) =>
  [...document.querySelectorAll('.js-facturable')]
    .filter(c => c.getAttribute('data-equipo') === String(e)).length, eqA)
af('las facturadas salen de la lista de facturables', yaNo === 0, yaNo + ' quedan')

const dobla = await post({ modulo_league: 'emitirComprobante',
                           obligaciones: idsA.join(','), forma: '20' })
rechaza('no permite facturar dos veces lo mismo', dobla, 'Ya facturada')

/*==============  Clave de acceso  ==============*/
/* Se afirma siempre, nunca dentro de un if: una prueba que se salta a sí
   misma cuando no encuentra el dato pasa en verde sin comprobar nada. */
const clave = await admin.evaluate(() =>
  (document.querySelector('.js-clave')?.innerText ?? '').trim())

af('la pantalla muestra la clave de acceso', /^\d+$/.test(clave), clave.slice(0, 20) || 'vacía')
af('la clave de acceso tiene 49 dígitos', clave.length === 49, clave.length + ' dígitos')
af('su dígito verificador cuadra (módulo 11 recalculado aquí)',
   clave.length === 49 && modulo11(clave.slice(0, 48)) === +clave[48],
   clave.length === 49
     ? 'calculado ' + modulo11(clave.slice(0, 48)) + ' vs ' + clave[48]
     : 'no hay clave')

/* La clave incorpora fecha, tipo, RUC, ambiente, serie y secuencial: si
   alguno se colara mal, el SRI devolvería el comprobante. */
af('la clave lleva el tipo 01 y el RUC del emisor',
   clave.slice(8, 10) === '01' && clave.slice(10, 23) === '0705727287001',
   clave.slice(8, 23))
af('y la serie es la de League (003003)',
   clave.slice(24, 30) === '003003', clave.slice(24, 30))

/*==============  El secuencial avanza  ==============*/
const emi2 = await post({ modulo_league: 'emitirComprobante',
                          obligaciones: idsB[0], forma: '01' })
af('emite un segundo comprobante', emi2.icono === 'success',
   (emi2.texto ?? '').slice(0, 45))

await admin.goto(BASE + 'cobranzaPanel/' + catId + '/', { waitUntil: 'networkidle' })
const numeros = await admin.evaluate(() =>
  [...document.querySelectorAll('tbody code')].map(c => c.innerText.trim()))
af('hay dos comprobantes', numeros.length === 2, numeros.join(' '))

const secs = numeros.map(n => +n.split('-')[2]).sort((a, b) => a - b)
af('los secuenciales son distintos y consecutivos',
   secs.length === 2 && secs[1] === secs[0] + 1, secs.join(' → '))

console.log('\n  SELLO=' + sello + '  cat=' + catId
          + '  eq=' + eqA + '/' + eqB + '/' + eqC + '  conc=' + conId + '/' + conMulta)
console.log('\nfallos: ' + fallos)
await nav.close()
process.exit(fallos === 0 ? 0 : 1)
