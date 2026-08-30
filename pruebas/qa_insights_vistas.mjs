/*
| Las siete vistas de Insights dibujan, y dibujan tambien en un movil.
|
| El barrido del tablero que ya existe mira una vista al detalle: que el
| consolidado sume, que las variaciones sean coherentes. Esta suite mira lo
| contrario —poco de cada vista, pero de TODAS— y busca las tres averias que
| se cuelan cuando una pagina «se ve bien» de un vistazo:
|
|
|   1. EL AVISO DE PHP QUE SE LEE COMO CONTENIDO
|
|      Un «Warning: Undefined array key» se imprime en medio de la pagina, en
|      texto negro sobre blanco, y no genera error de consola ni codigo 500.
|      La pagina responde 200 y el navegador esta contento. Sale a produccion
|      y lo ve el usuario antes que nadie.
|
|
|   2. EL GRAFICO QUE OCUPA SITIO PERO NO PINTA
|
|      ApexCharts crea el contenedor aunque la serie venga vacia o falle: el
|      hueco existe, tiene su altura reservada, y esta en blanco. Comprobar
|      que el div existe no prueba nada. Aqui se cuentan los <path> del SVG,
|      que es lo unico que solo aparece si de verdad dibujo.
|
|
|   3. LA PAGINA QUE SE DESBORDA A LO ANCHO
|
|      El §49 pide que funcione en movil. Una tabla ancha sin envoltorio hace
|      que TODA la pagina se arrastre en horizontal: el titulo se sale, el
|      menu se descoloca y hay que hacer scroll lateral para leer una frase.
|      Se mide con scrollWidth del documento a 390 px, que es el ancho de un
|      telefono corriente. Y cuando falla, se nombra al culpable: buscarlo a
|      mano en una pagina llena de tarjetas cuesta mas que la propia prueba.
|
|
| POR QUE NO SE MIRAN LAS EXCEPCIONES DE JS
|
| pageerror no es de fiar en este entorno —qa_datatables2.mjs lo probo con un
| error provocado y no lo vio—. De eso se encarga qa_errores_js.mjs con
| Runtime.exceptionThrown. Aqui van errores de consola y peticiones caidas,
| que esos si llegan.
*/

import { createRequire } from 'node:module'
import { readdirSync } from 'node:fs'
const EXT = 'C:/Users/dbafaces/.vscode/extensions/'
const RAIZ = EXT + readdirSync(EXT).filter(d => d.startsWith('danielsanmedium.dscodegpt-')).sort().pop() + '/standalone/'
const { chromium } = createRequire(RAIZ)('patchright')

const BASE = 'http://localhost/barcelona/ds_insights/'
const GALLETA = { name: 'DigiSportsBasketball', value: 'dsqaui0000000000000',
                  domain: 'localhost', path: '/' }

/* Cuantos graficos lleva cada vista. Verificado contando las llamadas
   reales a dsGrafico() en cada -view.php: son cuatro en total, uno por
   vista analitica. Ojo, esa palabra aparece tambien dentro de un
   comentario de financiero y no cuenta: contarla dio 3 donde hay 1, y
   esta suite se puso roja por un defecto que no existia.
   Se exige el numero EXACTO, no un minimo: asi se ve tanto el grafico
   que desaparece como el que se cuela. */
const VISTAS = [
  { v: 'dashboard',   graficos: 1 },
  { v: 'financiero',  graficos: 1 },
  { v: 'becas',       graficos: 1 },
  { v: 'basketball',  graficos: 1 },
  { v: 'arena',       graficos: 0 },   /* su visual es el mapa de calor, que es HTML */
  { v: 'league',      graficos: 0 },   /* League es tablas: no lleva grafico */
  { v: 'reporteList', graficos: 0 },
  { v: 'transacciones', graficos: 0 },
  { v: 'configuracion', graficos: 0 },
  /*
  | Cartera es el caso raro: su grafico solo existe cuando hay DOS o mas
  | fotografias mensuales de la deuda, y hoy hay una. Un numero fijo aqui
  | se romperia solo el mes que viene, asi que se comprueba la REGLA: o
  | esta el grafico, o esta el texto que explica por que no. Nunca las dos
  | ni ninguna.
  */
  { v: 'cartera',     graficos: null },
]

/* Lo que PHP imprime cuando algo va mal. Se busca en el TEXTO VISIBLE, no en
   el HTML: en el HTML, «Warning» aparece dentro de comentarios y de nombres
   de clase, y daria falso positivo. */
const AVISOS = ['Warning:', 'Notice:', 'Deprecated:', 'Fatal error:',
                'Undefined variable', 'Undefined array key',
                'Uncaught', 'Stack trace']

let fallos = 0
const af = (texto, ok, detalle = '') => {
  console.log('  ' + texto.padEnd(52) + (ok ? 'OK' : 'FALLA') + (detalle ? '  (' + detalle + ')' : ''))
  if (!ok) fallos++
}

const nav = await chromium.launch({ headless: true, channel: 'chromium' })
const ctx = await nav.newContext()
await ctx.addCookies([GALLETA])
const page = await ctx.newPage()

let problemas = []
page.on('console', m => { if (m.type() === 'error') problemas.push('consola: ' + m.text().slice(0, 90)) })
page.on('requestfailed', r => problemas.push('caido: ' + r.url().slice(-50)))
page.on('response', r => { if (r.status() >= 400) problemas.push(r.status() + ': ' + r.url().slice(-50)) })

/*==================  Escritorio  ==================*/
console.log('\n  ── a 1366 px ──')
await page.setViewportSize({ width: 1366, height: 900 })

const medido = {}

for (const { v, graficos } of VISTAS) {
  problemas = []
  const resp = await page.goto(BASE + v + '/', { waitUntil: 'networkidle', timeout: 60000 })

  const d = await page.evaluate(() => {
    /* Sin el menu ni el pie: ahi no hay contenido de la vista y sus textos
       propios darian ruido. */
    const main = document.querySelector('.app-main') || document.body
    const lienzos = [...document.querySelectorAll('.apexcharts-canvas')]
    return {
      texto:    main.innerText,
      chars:    main.innerText.trim().length,
      lienzos:  lienzos.length,
      /* Un <path d> o un <rect width> solo existen si ApexCharts dibujo. */
      pintados: lienzos.filter(s => s.querySelectorAll('path[d],rect[width]').length > 0).length,
      tablas:   document.querySelectorAll('table').length,
    }
  })
  medido[v] = d

  const avisos = AVISOS.filter(a => d.texto.includes(a))

  af(v.padEnd(12) + ' responde 200 y trae contenido',
     resp.status() === 200 && d.chars > 200, 'HTTP ' + resp.status() + ' · ' + d.chars + ' car')

  af(v.padEnd(12) + ' sin avisos de PHP en pantalla',
     avisos.length === 0, avisos.join(' '))

  af(v.padEnd(12) + ' sin errores de consola ni peticiones caidas',
     problemas.length === 0, problemas.slice(0, 2).join(' · '))

  if (graficos === null) {
    /* O grafico, o explicacion. Las dos cosas o ninguna es un defecto. */
    const explica = d.texto.includes('fotografías mensuales')
    af(v.padEnd(12) + ' o dibuja su grafico o explica por que no',
       (d.pintados === 1) !== explica,
       d.pintados + ' graficos · explicacion: ' + explica)
  } else {
    af(v.padEnd(12) + ' sus ' + graficos + ' grafico(s) DIBUJARON',
       d.lienzos === graficos && d.pintados === graficos, d.pintados + ' pintados de ' + d.lienzos + ' lienzos')
  }
}

/*==================  Movil  ==================*/
/*
| Se vuelve a CARGAR cada vista en vez de solo redimensionar: ApexCharts
| calcula el ancho al construirse, asi que una vista redimensionada puede
| parecer correcta sin serlo cuando se abre de verdad en el telefono.
*/
console.log('\n  ── a 390 px, que es un movil ──')
await page.setViewportSize({ width: 390, height: 844 })

for (const { v } of VISTAS) {
  await page.goto(BASE + v + '/', { waitUntil: 'networkidle', timeout: 60000 })

  const d = await page.evaluate(() => {
    const doc = document.documentElement
    let culpable = ''
    if (doc.scrollWidth > window.innerWidth + 1) {
      for (const el of document.querySelectorAll('.app-main *')) {
        const r = el.getBoundingClientRect()
        if (r.right > window.innerWidth + 1 && r.width > 40) {
          culpable = el.tagName.toLowerCase() +
                     (typeof el.className === 'string' && el.className.trim()
                        ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '')
          break
        }
      }
    }
    return {
      ancho: doc.scrollWidth, ventana: window.innerWidth, culpable,
      /* Una tabla ancha esta bien SIEMPRE QUE se arrastre ella sola. */
      tablasSueltas: [...document.querySelectorAll('table')].filter(t => {
        const env = t.closest('.table-responsive,.dataTables_wrapper,[style*="overflow"]')
        return !env && t.scrollWidth > 390
      }).length,
    }
  })

  af(v.padEnd(12) + ' no arrastra la pagina en horizontal',
     d.ancho <= d.ventana + 1,
     d.ancho + 'px en ventana de ' + d.ventana + (d.culpable ? ' · ' + d.culpable : ''))

  if (medido[v].tablas > 0) {
    af(v.padEnd(12) + ' sus tablas se arrastran solas',
       d.tablasSueltas === 0, d.tablasSueltas + ' sueltas de ' + medido[v].tablas)
  }
}

await nav.close()
console.log('\nfallos: ' + fallos)
process.exit(fallos === 0 ? 0 : 1)
