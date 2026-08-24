/*
| El selector de foto funciona: elegir, ver y quitar.
|
| QUE ESTABA ROTO
|
| Trece vistas usan el marcado de un plugin —Jasny fileinput, de Bootstrap
| 3— que NO se cargaba en ninguna. Sin el, sus dos rotulos salian a la vez
| («Seleccionar Foto Cambiar»), «Remover» no hacia nada y no habia forma de
| ver la imagen antes de guardarla.
|
| POR QUE SE PRUEBA ELIGIENDO UN ARCHIVO DE VERDAD
|
| Mirar el marcado solo diria que las etiquetas estan. Lo que importa es el
| efecto: que al elegir una imagen aparezca, que el rotulo cambie a
| «Cambiar», y que al quitarla se vuelva al principio. Eso solo se sabe
| haciendolo.
|
| Se usa una imagen que ya esta en el proyecto, para no fabricar archivos
| ni dejar rastro.
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
import { readdirSync, existsSync } from 'node:fs'
const EXT = 'C:/Users/dbafaces/.vscode/extensions/'
const RAIZ = EXT + readdirSync(EXT).filter(d => d.startsWith('danielsanmedium.dscodegpt-')).sort().pop() + '/standalone/'
const { chromium } = createRequire(RAIZ)('patchright')

const BASE = 'http://localhost/barcelona/ds_basketball/'
const IMAGEN = 'c:/wamp64/www/barcelona/ds_basketball/app/views/dist/img/foto.jpg'

let fallos = 0
const af = (t, ok, d = '') => {
  console.log('  ' + t.padEnd(48) + (ok ? 'OK' : 'FALLA') + (d ? '  (' + d + ')' : ''))
  if (!ok) fallos++
}

af('la imagen de prueba existe', existsSync(IMAGEN), IMAGEN.split('/').pop())
if (!existsSync(IMAGEN)) { console.log('\nfallos: ' + fallos); process.exit(1) }

const nav = await chromium.launch({ headless: true, channel: 'chromium' })
const ctx = await nav.newContext({ viewport: { width: 1400, height: 900 } })
await ctx.addCookies([{ name: 'DigiSportsBasketball', value: 'dsqaui0000000000000',
                        domain: 'localhost', path: '/' }])
const p = await ctx.newPage()

const errores = []
p.on('pageerror', e => errores.push(e.message.slice(0, 80)))

await p.goto(BASE + 'torneoList/', { waitUntil: 'networkidle' })
await p.waitForTimeout(500)

/*==============  1. De entrada, un solo rótulo  ==============*/
const inicio = await p.evaluate(() => {
  const caja = document.querySelector('.fileinput')
  if (!caja) return null
  const visibles = [...caja.querySelectorAll('.fileinput-new, .fileinput-exists')]
    .filter(e => e.offsetParent !== null && e.tagName === 'SPAN')
    .map(e => e.innerText.trim())
  return { visibles, estado: caja.className }
})
af('el widget existe', inicio !== null)
af('sólo se ve un rótulo, no los dos',
   inicio && inicio.visibles.length === 1,
   inicio ? 'se ven: ' + inicio.visibles.join(' + ') : '')

/*==============  2. Al elegir una imagen, se ve  ==============*/
await p.setInputFiles('.fileinput input[type="file"]', IMAGEN)
await p.waitForTimeout(500)

const tras = await p.evaluate(() => {
  const caja = document.querySelector('.fileinput')
  const vista = caja.querySelector('.fileinput-preview img')
  const visibles = [...caja.querySelectorAll('.fileinput-new, .fileinput-exists')]
    .filter(e => e.offsetParent !== null && e.tagName === 'SPAN')
    .map(e => e.innerText.trim())
  return {
    estado: caja.classList.contains('fileinput-exists'),
    hayPrevia: !!vista,
    anchoPrevia: vista ? Math.round(vista.getBoundingClientRect().width) : 0,
    visibles,
  }
})

af('aparece la vista previa de la imagen elegida',
   tras.hayPrevia && tras.anchoPrevia > 20, tras.anchoPrevia + 'px de ancho')
af('el rótulo pasa a «Cambiar»',
   tras.visibles.length === 1 && /cambiar/i.test(tras.visibles[0] || ''),
   tras.visibles.join(' + '))

/*==============  3. Al quitarla, se vuelve al principio  ==============*/
await p.evaluate(() => {
  const q = document.querySelector('.fileinput [data-bs-dismiss="fileinput"], .fileinput [data-dismiss="fileinput"]')
  if (q) q.click()
})
await p.waitForTimeout(400)

const limpio = await p.evaluate(() => {
  const caja = document.querySelector('.fileinput')
  const campo = caja.querySelector('input[type="file"]')
  return {
    vaciado: campo.value === '',
    sinPrevia: !caja.querySelector('.fileinput-preview img'),
    visibles: [...caja.querySelectorAll('.fileinput-new, .fileinput-exists')]
      .filter(e => e.offsetParent !== null && e.tagName === 'SPAN')
      .map(e => e.innerText.trim()),
  }
})

af('«Remover» vacía el campo y quita la vista previa',
   limpio.vaciado && limpio.sinPrevia,
   'campo ' + (limpio.vaciado ? 'vacío' : 'CON valor')
     + ', previa ' + (limpio.sinPrevia ? 'quitada' : 'SIGUE'))
af('y el rótulo vuelve a «Seleccionar»',
   limpio.visibles.length === 1 && /seleccionar/i.test(limpio.visibles[0] || ''),
   limpio.visibles.join(' + '))

af('sin errores de JavaScript', errores.length === 0, errores[0] ?? '')

console.log('\nfallos: ' + fallos)
await nav.close()
process.exit(fallos === 0 ? 0 : 1)
