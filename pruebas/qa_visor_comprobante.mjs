/*
| El visor de comprobantes ABRE, y se ve.
|
| POR QUE EXISTE ESTA SUITE
|
| Porque este fallo se colo entero por la migracion a Bootstrap 5 sin dejar
| rastro. El visor anterior, ekko-lightbox, llamaba asi:
|
|     }).modal(this._config);            // ekko-lightbox.js:155
|
| En Bootstrap 4 eso inicializaba el modal Y LO ABRIA. En Bootstrap 5 la
| interfaz de jQuery solo ejecuta un metodo cuando recibe una CADENA; con un
| objeto se limita a construir la instancia.
|
| El resultado era el peor tipo de averia: el modal SE CREABA en el DOM, con
| opacity 0 y sin fondo. Ni un error de consola, ni un 404, ni un aviso de
| PHP. La pagina respondia 200 y el HTML contenia todo lo que se esperaba.
| Cualquier prueba que mirase el codigo de respuesta, la consola o la
| presencia del enlace habria dado verde. Al usuario «no le pasaba nada» al
| pulsar.
|
| De ahi lo que se mide aqui: no que el enlace exista, sino que tras pulsarlo
| haya un modal VISIBLE —opacity 1 y su fondo— con una imagen REALMENTE
| CARGADA, que es naturalWidth > 0 y no que el <img> este en el DOM.
|
|
| POR QUE SE PRUEBA CON EL MARCADOR DE POSICION
|
| En esta base ninguno de los 122 comprobantes esta en disco: se restauro el
| volcado sin las imagenes. media_url() lo detecta y devuelve la generica, que
| es el comportamiento correcto. Da igual para lo que se mide: una imagen que
| carga es una imagen que carga, y el visor no distingue.
*/

import { createRequire } from 'node:module'
import { readdirSync } from 'node:fs'
const EXT = 'C:/Users/dbafaces/.vscode/extensions/'
const RAIZ = EXT + readdirSync(EXT).filter(d => d.startsWith('danielsanmedium.dscodegpt-')).sort().pop() + '/standalone/'
const { chromium } = createRequire(RAIZ)('patchright')

const BASE = 'http://localhost/barcelona/ds_basketball/'
const GALLETA = { name: 'DigiSportsBasketball', value: 'dsqaui0000000000000',
                  domain: 'localhost', path: '/' }

let fallos = 0
const af = (texto, ok, detalle = '') => {
  console.log('  ' + texto.padEnd(52) + (ok ? 'OK' : 'FALLA') + (detalle ? '  (' + detalle + ')' : ''))
  if (!ok) fallos++
}

const nav = await chromium.launch({ headless: true, channel: 'chromium' })
const ctx = await nav.newContext()
await ctx.addCookies([GALLETA])
const page = await ctx.newPage()

const problemas = []
page.on('console', m => { if (m.type() === 'error') problemas.push(m.text().slice(0, 120)) })
page.on('pageerror', e => problemas.push('pageerror: ' + String(e).slice(0, 120)))

await page.goto(BASE + 'pagosNew/252/', { waitUntil: 'networkidle', timeout: 60000 })

/*----------  El complemento muerto ya no esta  ----------*/
const restos = await page.evaluate(() =>
  [...document.querySelectorAll('script[src]')].filter(s => s.src.includes('ekko')).length)
af('ekko-lightbox ya no se carga', restos === 0, restos + ' etiquetas')

const enlaces = await page.locator('[data-bs-toggle="lightbox"]').count()
af('la tabla de pagos trae enlaces al comprobante', enlaces > 0, enlaces + ' enlaces')

/*----------  Se pulsa, y tiene que verse  ----------*/
await page.locator('[data-bs-toggle="lightbox"]').first().click()
await page.waitForTimeout(900)

const abierto = await page.evaluate(() => {
  const m = document.querySelector('.modal.show')
  if (!m) { return { visible: false } }
  const img = m.querySelector('img[data-visor="imagen"]')
  return {
    visible: true,
    opacidad: getComputedStyle(m).opacity,
    fondos: document.querySelectorAll('.modal-backdrop').length,
    titulo: (m.querySelector('[data-visor="titulo"]') || {}).textContent || '',
    /* naturalWidth es la prueba de que el navegador DECODIFICO la imagen.
       Que el <img> exista no dice nada: existia tambien cuando fallaba. */
    ancho: img ? img.naturalWidth : 0,
  }
})

af('al pulsar se abre un modal visible',
   abierto.visible === true && abierto.opacidad === '1',
   abierto.visible ? 'opacidad ' + abierto.opacidad : 'no hay .modal.show')

af('con su fondo oscuro', abierto.fondos === 1, abierto.fondos + ' fondos')

af('y la imagen se cargo de verdad',
   abierto.ancho > 0, abierto.ancho + ' px de ancho natural')

af('el modal lleva titulo', (abierto.titulo || '').trim() !== '', abierto.titulo)

/*----------  Y se cierra sin dejar restos  ----------*/
/*
| Un fondo que se queda deja la pagina bloqueada: no se puede pulsar nada y
| parece que el sistema se colgo. Es el defecto clasico al manejar modales a
| mano, y por eso se comprueba.
*/
if (!abierto.visible) {
  af('se cierra y no deja el fondo puesto', false, 'omitido: nunca llego a abrirse')
} else {
await page.locator('.modal.show .btn-close').click()
await page.waitForTimeout(700)

const cerrado = await page.evaluate(() => ({
  abiertos: document.querySelectorAll('.modal.show').length,
  fondos: document.querySelectorAll('.modal-backdrop').length,
}))

af('se cierra y no deja el fondo puesto',
   cerrado.abiertos === 0 && cerrado.fondos === 0,
   cerrado.abiertos + ' abiertos · ' + cerrado.fondos + ' fondos')
}

/*----------  La galeria pasa de un comprobante a otro  ----------*/
if (enlaces > 1 && abierto.visible) {
  await page.locator('[data-bs-toggle="lightbox"]').first().click()
  await page.waitForTimeout(700)

  const antes = await page.evaluate(() =>
    (document.querySelector('[data-visor="cuenta"]') || {}).textContent || '')

  await page.locator('[data-visor="siguiente"]').click()
  await page.waitForTimeout(700)

  const despues = await page.evaluate(() =>
    (document.querySelector('[data-visor="cuenta"]') || {}).textContent || '')

  af('la galeria avanza al siguiente comprobante',
     antes !== '' && despues !== '' && antes !== despues,
     antes + ' -> ' + despues)
}

af('sin errores de consola en todo el recorrido',
   problemas.length === 0, problemas.slice(0, 2).join(' · '))

await nav.close()
console.log('\nfallos: ' + fallos)
process.exit(fallos === 0 ? 0 : 1)
