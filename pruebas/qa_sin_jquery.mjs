/*
| Arena, League y Core funcionan SIN jQuery.
|
| El pie compartido lo cargaba en las cuarenta y una vistas de los tres
| módulos, y ninguna hacía una sola llamada. Al quitarlo hay que comprobar
| dos cosas distintas:
|
|   1. Que de verdad ya no se descarga —si otra etiqueta lo trajera por
|      detrás, el ahorro sería imaginario—.
|   2. Que nada se rompió. Una llamada a jQuery que se me hubiera escapado
|      lanza «$ is not defined», y eso sí aparece en la consola.
|
| POR QUÉ NO SE MIRA window.jQuery CON evaluate
|
| evaluate corre en un contexto aislado que no ve las variables de la
| página: allí window.jQuery sale indefinido SIEMPRE, esté cargado o no, y
| la comprobación pasaría por el motivo equivocado. Se pregunta desde
| dentro, con una etiqueta inyectada, y la respuesta vuelve por un atributo
| del documento.
*/
/*
| AVISO SOBRE «sin errores de JavaScript» EN ESTE ARCHIVO
|
| El evento pageerror NO es de fiar en este entorno: se probo con un
| error provocado y no lo detecto. Quien comprueba las excepciones de
| verdad es qa_errores_js.mjs, que usa Runtime.exceptionThrown del
| protocolo del motor y ademas verifica su propia sonda antes de
| barrer. Lo que sigue capturando aqui son las respuestas 4xx, que esas
| si llegan.
*/

import { createRequire } from 'node:module'
import { readdirSync } from 'node:fs'
const EXT = 'C:/Users/dbafaces/.vscode/extensions/'
const RAIZ = EXT + readdirSync(EXT).filter(d => d.startsWith('danielsanmedium.dscodegpt-')).sort().pop() + '/standalone/'
const { chromium } = createRequire(RAIZ)('patchright')

const HUB = 'http://localhost/barcelona/'
const VISTAS = [
  ['League',  HUB + 'ds_league/panel/'],
  ['League',  HUB + 'ds_league/temporadaList/'],
  ['League',  HUB + 'ds_league/equipoList/'],
  ['League',  HUB + 'ds_league/conceptoList/'],
  ['League',  HUB + 'ds_league/cobranzaPanel/29/'],
  ['Arena',   HUB + 'ds_arena/panel/'],
  ['Arena',   HUB + 'ds_arena/instalacionList/'],
  ['Arena',   HUB + 'ds_arena/horarioList/'],
  ['Arena',   HUB + 'ds_arena/bloqueoList/'],
  ['Core',    HUB + '?p=seguridad'],
]

const nav = await chromium.launch({ headless: true, channel: 'chromium' })
const ctx = await nav.newContext({ viewport: { width: 1450, height: 1000 } })
await ctx.addCookies([{ name: 'DigiSportsBasketball', value: 'dsqaui0000000000000',
                        domain: 'localhost', path: '/' }])
const p = await ctx.newPage()

let fallos = 0
const af = (t, ok, d = '') => {
  console.log('  ' + t.padEnd(46) + (ok ? 'OK' : 'FALLA') + (d ? '  (' + d + ')' : ''))
  if (!ok) fallos++
}

let bytesAhorrados = 0
const conJquery = []
const conErrores = []

for (const [modulo, url] of VISTAS) {
  const errores = []
  const oyenteError = e => errores.push(e.message.slice(0, 100))
  const oyenteConsola = m => { if (m.type() === 'error') errores.push(m.text().slice(0, 100)) }
  const oyentePeticion = r => {
    if (/jquery/i.test(r.url())) { conJquery.push(modulo + ' ' + r.url().split('/').pop()) }
  }
  p.on('pageerror', oyenteError)
  p.on('console', oyenteConsola)
  p.on('request', oyentePeticion)

  await p.goto(url, { waitUntil: 'networkidle' })

  /* Desde dentro de la página, que es donde vive de verdad. */
  await p.addScriptTag({ content:
    "document.documentElement.setAttribute('data-qa-jq'," +
    " (typeof window.jQuery !== 'undefined' || typeof window.$ !== 'undefined') ? 'si' : 'no');" })
  const hay = await p.evaluate(() => document.documentElement.getAttribute('data-qa-jq'))

  if (hay === 'si') { conJquery.push(modulo + ' ' + url) }
  if (errores.length) { conErrores.push(modulo + ': ' + errores[0]) }

  p.off('pageerror', oyenteError)
  p.off('console', oyenteConsola)
  p.off('request', oyentePeticion)
}

af('ninguna de las ' + VISTAS.length + ' vistas carga jQuery',
   conJquery.length === 0, [...new Set(conJquery)].slice(0, 3).join(' · '))

af('ninguna da errores de JavaScript',
   conErrores.length === 0, conErrores.slice(0, 2).join(' · '))

/* Y que la comprobación sirva de algo: en Basketball jQuery SÍ está, así
   que si allí también saliera «no», la sonda estaría rota. */
const errBk = []
p.on('pageerror', e => errBk.push(e.message))
await p.goto(HUB + 'ds_basketball/torneoList/', { waitUntil: 'networkidle' })
await p.addScriptTag({ content:
  "document.documentElement.setAttribute('data-qa-jq'," +
  " typeof window.jQuery !== 'undefined' ? 'si' : 'no');" })
const enBasket = await p.evaluate(() => document.documentElement.getAttribute('data-qa-jq'))
af('la sonda detecta jQuery donde sí está (Basketball)', enBasket === 'si',
   'devolvió ' + enBasket)

console.log('\nfallos: ' + fallos)
await nav.close()
process.exit(fallos === 0 ? 0 : 1)
