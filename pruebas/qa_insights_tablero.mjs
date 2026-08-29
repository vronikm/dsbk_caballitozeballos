/*
| El Executive Overview dice la verdad, y la dice de forma coherente.
|
| No basta con que el tablero pinte. Un tablero que pinta cifras equivocadas
| es peor que uno roto: el roto se arregla, el equivocado se cree.
|
| QUE SE MIRA
|
|   el consolidado    tiene que ser la SUMA de los tres modulos, al centimo.
|                     Si una consulta cambia y deja de cuadrar, el total
|                     sigue teniendo aspecto de total.
|
|   la coherencia     cuando el periodo comparable no tiene datos, las tres
|                     tarjetas comparables deben decir LO MISMO. La primera
|                     version mostraba «—» en tres y «+33,0 pts» en la de
|                     ocupacion, porque su guarda miraba las horas
|                     disponibles —que existen para cualquier fecha— en vez
|                     de las reservadas.
|
|   por cobrar        NUNCA muestra variacion. Es una proyeccion desde hoy:
|                     compararla entre periodos no mide nada, y un
|                     porcentaje ahi seria un dato inventado con aspecto de
|                     medicion.
|
| NO SE COMPRUEBAN LAS EXCEPCIONES DE JS AQUI
|
| El evento pageerror no es de fiar en este entorno: qa_datatables2.mjs lo
| probo con un error provocado y no lo detecto. Quien las comprueba de
| verdad es qa_errores_js.mjs, con Runtime.exceptionThrown. Aqui se miran
| solo los errores de consola y las peticiones caidas, que esos si llegan.
*/

import { createRequire } from 'node:module'
import { readdirSync } from 'node:fs'
const EXT = 'C:/Users/dbafaces/.vscode/extensions/'
const RAIZ = EXT + readdirSync(EXT).filter(d => d.startsWith('danielsanmedium.dscodegpt-')).sort().pop() + '/standalone/'
const { chromium } = createRequire(RAIZ)('patchright')

const BASE = 'http://localhost/barcelona/ds_insights/dashboard/'
const GALLETA = { name: 'DigiSportsBasketball', value: 'dsqaui0000000000000',
                  domain: 'localhost', path: '/' }

let fallos = 0
const af = (texto, ok, detalle = '') => {
  console.log('  ' + texto.padEnd(56) + (ok ? 'OK' : 'FALLA') + (detalle ? '  (' + detalle + ')' : ''))
  if (!ok) fallos++
}

const nav = await chromium.launch({ headless: true, channel: 'chromium' })
const ctx = await nav.newContext()
await ctx.addCookies([GALLETA])
const page = await ctx.newPage()

const problemas = []
page.on('console', m => { if (m.type() === 'error') problemas.push('consola: ' + m.text().slice(0, 120)) })
page.on('requestfailed', r => problemas.push('caido: ' + r.url().slice(-60)))
page.on('response', r => { if (r.status() >= 400) problemas.push(r.status() + ': ' + r.url().slice(-60)) })

/** Carga el tablero para un rango y devuelve lo medible. */
const leer = async (desde, hasta) => {
  await page.goto(BASE + '?desde=' + desde + '&hasta=' + hasta, { waitUntil: 'load', timeout: 45000 })
  await page.waitForTimeout(900)
  return page.evaluate(() => {
    const num = t => parseFloat(String(t).replace(/[^0-9.-]/g, '')) || 0
    const cajas = [...document.querySelectorAll('.info-box')].map(b => {
      const t = b.querySelectorAll('.info-box-text')
      return {
        etiqueta:  t[0]?.textContent.trim() || '',
        valor:     b.querySelector('.info-box-number')?.textContent.trim() || '',
        variacion: t[1]?.textContent.trim().replace(/\s+/g, ' ') || '',
      }
    })
    return {
      cajas,
      porcentajes: [...document.querySelectorAll('.progress')].map(p =>
        num(p.previousElementSibling?.querySelector('.fw-semibold')?.textContent)),
      importes: [...document.querySelectorAll('.progress + small')].map(s => num(s.textContent)),
      grafico: !!document.querySelector('#grafico-evolucion svg'),
      trazos:  document.querySelectorAll('#grafico-evolucion path').length,
    }
  })
}

/*==============  Periodo con comparable poblado  ==============*/
const conDatos = await leer('2026-08-01', '2026-08-31')

af('el tablero pinta las cuatro tarjetas', conDatos.cajas.length === 4,
   conDatos.cajas.length + ' tarjetas')

af('el gráfico de evolución se dibuja', conDatos.grafico && conDatos.trazos > 3,
   conDatos.trazos + ' trazos')

const total = parseFloat(conDatos.cajas[0].valor.replace(/[^0-9.]/g, '')) || 0
const suma  = conDatos.importes.reduce((a, b) => a + b, 0)
af('el consolidado es la suma de los tres módulos',
   Math.abs(total - suma) < 0.02,
   'total ' + total.toFixed(2) + ' · suma ' + suma.toFixed(2))

const pct = conDatos.porcentajes.reduce((a, b) => a + b, 0)
af('el reparto por módulo suma 100 %', Math.abs(pct - 100) < 0.3, pct.toFixed(1) + ' %')

af('«por cobrar» no muestra variación',
   /sin comparar/i.test(conDatos.cajas[1].variacion), conDatos.cajas[1].variacion)

af('con comparable, las variaciones se calculan',
   /[0-9]/.test(conDatos.cajas[0].variacion) && /[0-9]/.test(conDatos.cajas[3].variacion),
   'ingresos ' + conDatos.cajas[0].variacion + ' · ocupación ' + conDatos.cajas[3].variacion)

af('la ocupación se compara en puntos, no en porcentaje',
   /pts/.test(conDatos.cajas[3].variacion), conDatos.cajas[3].variacion)

/*==============  Comparable SIN datos  ==============*/
/*
| Enero-agosto de 2026 se compara con 2025, donde no hay nada. Las tres
| tarjetas comparables tienen que decir lo mismo. Si una muestra un
| porcentaje mientras las otras no, alguna guarda esta mal, y esa
| incoherencia entre tarjetas de la misma pantalla es el sintoma.
*/
const sinComparable = await leer('2026-01-01', '2026-08-31')
const comparables = [0, 2, 3].map(i => sinComparable.cajas[i].variacion)

af('sin comparable, las tres tarjetas dicen lo mismo',
   comparables.every(v => v.includes('—')), comparables.join(' | '))

af('sin comparable, ninguna inventa un porcentaje',
   !comparables.some(v => /[0-9]+(\.[0-9]+)?\s*(%|pts)/.test(v)), comparables.join(' | '))

/*==============  Los graficos sobreviven al cambio de tema  ==============*/
/*
| Un grafico se dibuja UNA VEZ con los colores que habia en ese momento. El
| resto de la interfaz reacciona sola porque su color vive en CSS; este no.
| Cambiar a claro dejaba la tinta del tema oscuro y nada fallaba: solo no se
| leia.
|
| Y una version del ayudante que actualizaba campo por campo se dejaba
| plotOptions, donde vive el color del total en el centro del donut: bajaba a
| 2,56 mientras el resto quedaba bien. Por eso se mide el MINIMO de todos los
| textos del grafico, no una muestra.
*/
const contrasteGrafico = () => page.evaluate(() => {
  const lum = c => { const p = (c.match(/[\d.]+/g) || []).map(Number)
    const [r, g, b] = p.slice(0, 3).map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4) })
    return 0.2126 * r + 0.7152 * g + 0.0722 * b }
  const opaco = el => { for (let n = el; n; n = n.parentElement) {
      const b = getComputedStyle(n).backgroundColor
      if (b && !/rgba\(.*,\s*0\)/.test(b) && b !== 'transparent') return b }
    return getComputedStyle(document.body).backgroundColor }
  const k = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05) }
  const textos = [...document.querySelectorAll(
    '.apexcharts-datalabel-label,.apexcharts-datalabel-value,.apexcharts-legend-text,' +
    '.apexcharts-xaxis text,.apexcharts-yaxis text')]
  const ks = textos.map(e => {
    const cs = getComputedStyle(e)
    const c = (cs.fill && cs.fill !== 'none' && e.namespaceURI && e.namespaceURI.includes('svg')) ? cs.fill : cs.color
    return k(c, opaco(e)) })
  return { n: ks.length, minimo: ks.length ? +Math.min(...ks).toFixed(2) : null }
})

await page.goto(BASE + '?desde=2026-01-01&hasta=2026-08-31', { waitUntil: 'load', timeout: 45000 })
await page.evaluate(() => document.documentElement.setAttribute('data-bs-theme', 'dark'))
await page.waitForTimeout(1000)
const enOscuro = await contrasteGrafico()

await page.evaluate(() => document.documentElement.setAttribute('data-bs-theme', 'light'))
await page.waitForTimeout(900)
const trasCambiar = await contrasteGrafico()

af('los textos del gráfico se leen en oscuro',
   enOscuro.minimo !== null && enOscuro.minimo >= 4.5,
   enOscuro.n + ' textos · mínimo ' + enOscuro.minimo)

af('y siguen leyéndose tras cambiar a claro',
   trasCambiar.minimo !== null && trasCambiar.minimo >= 4.5,
   trasCambiar.n + ' textos · mínimo ' + trasCambiar.minimo)

/*==============  Nada roto por el camino  ==============*/
const reales = problemas.filter(p => !/favicon|analytics/i.test(p))
af('sin errores de consola ni recursos caídos', reales.length === 0,
   reales.slice(0, 2).join(' | '))

console.log('\nfallos: ' + fallos)
await nav.close()
process.exit(fallos === 0 ? 0 : 1)
