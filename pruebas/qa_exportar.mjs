/*
| Exportar a PDF y a Excel sigue funcionando con la carga bajo demanda.
|
| QUE SE CAMBIO Y QUE RIESGO TIENE
|
| Cada pantalla de listado descargaba 2.685 KB de JavaScript, de los que
| 2.204 KB —el 82%— eran pdfmake, sus tipografias y jszip: tres librerias que
| solo hacen falta si alguien pulsa PDF o Excel. Ahora se traen al pulsar.
|
| El riesgo es evidente: si el mecanismo falla, el boton no hace nada y nadie
| se entera hasta que alguien necesita un informe. Por eso esta suite PULSA
| los botones y espera la descarga de verdad, en vez de comprobar que el
| codigo esta escrito.
|
| SE COMPRUEBA TAMBIEN QUE NO SE TRAIGA DE ENTRADA
|
| Si por un descuido volviera a cargarse en el arranque, todo seguiria
| funcionando y el ahorro se habria perdido en silencio. Eso no se nota
| usando la aplicacion; hay que medirlo.
*/
import { createRequire } from 'node:module'
import { readdirSync } from 'node:fs'
const EXT = 'C:/Users/dbafaces/.vscode/extensions/'
const RAIZ = EXT + readdirSync(EXT).filter(d => d.startsWith('danielsanmedium.dscodegpt-')).sort().pop() + '/standalone/'
const { chromium } = createRequire(RAIZ)('patchright')

const BASE = 'http://localhost/barcelona/ds_basketball/'
const nav = await chromium.launch({ headless: true, channel: 'chromium' })
const ctx = await nav.newContext({ viewport: { width: 1500, height: 950 },
                                   acceptDownloads: true })
await ctx.addCookies([{ name: 'DigiSportsBasketball', value: 'dsqaui0000000000000',
                        domain: 'localhost', path: '/' }])
const p = await ctx.newPage()

let fallos = 0
const af = (t, ok, d = '') => {
  console.log('  ' + t.padEnd(50) + (ok ? 'OK' : 'FALLA') + (d ? '  (' + d + ')' : ''))
  if (!ok) fallos++
}

/*==============  1. De entrada, las pesadas NO viajan  ==============*/
const pedidos = []
p.on('request', r => {
  const n = r.url().split('/').pop()
  if (/pdfmake|vfs_fonts|jszip/i.test(n)) { pedidos.push(n) }
})

await p.goto(BASE + 'alumnoList/', { waitUntil: 'networkidle' })
await p.waitForTimeout(900)

af('al cargar la pantalla no se traen las pesadas',
   pedidos.length === 0, pedidos.join(' ') || 'ninguna')

const bytes = await p.evaluate(() =>
  performance.getEntriesByType('resource')
    .filter(r => /\.js(\?|$)/.test(r.name))
    .reduce((a, r) => a + (r.transferSize || r.encodedBodySize || 0), 0))
af('el JavaScript de la pantalla baja de 900 KB',
   bytes < 900 * 1024, Math.round(bytes / 1024) + ' KB')

/*==============  2. Los botones están  ==============*/
const botones = await p.evaluate(() =>
  [...document.querySelectorAll('.dt-buttons button')].map(b => b.innerText.trim()))
af('la tabla ofrece sus botones de exportación',
   botones.some(b => /pdf/i.test(b)) && botones.some(b => /excel/i.test(b)),
   botones.join(' '))

/*==============  3. Al pulsar PDF, se trae y descarga  ==============*/
async function exportar(etiqueta) {
  pedidos.length = 0
  const espera = p.waitForEvent('download', { timeout: 25000 }).catch(() => null)
  await p.evaluate((t) => {
    const b = [...document.querySelectorAll('.dt-buttons button')]
      .find(x => new RegExp(t, 'i').test(x.innerText))
    b.click()
  }, etiqueta)
  const descarga = await espera
  return { descarga, traidas: [...pedidos] }
}

const pdf = await exportar('pdf')
af('al pulsar PDF se descarga el archivo',
   pdf.descarga !== null,
   pdf.descarga ? pdf.descarga.suggestedFilename() : 'no llegó ninguna descarga')
af('y se trajo pdfmake en ese momento',
   pdf.traidas.some(n => /pdfmake/i.test(n)),
   pdf.traidas.join(' ') || 'ninguna')

/*==============  4. La segunda vez no vuelve a traerla  ==============*/
const pdf2 = await exportar('pdf')
af('la segunda exportación no vuelve a descargar la librería',
   pdf2.descarga !== null && pdf2.traidas.length === 0,
   'descarga: ' + (pdf2.descarga ? 'sí' : 'no') + ', librerías: ' + (pdf2.traidas.join(' ') || 'ninguna'))

/*==============  5. Y Excel  ==============*/
const excel = await exportar('excel')
af('al pulsar Excel se descarga el archivo',
   excel.descarga !== null,
   excel.descarga ? excel.descarga.suggestedFilename() : 'no llegó ninguna descarga')
af('y se trajo jszip en ese momento',
   excel.traidas.some(n => /jszip/i.test(n)),
   excel.traidas.join(' ') || 'ninguna')

console.log('\nfallos: ' + fallos)
await nav.close()
process.exit(fallos === 0 ? 0 : 1)
