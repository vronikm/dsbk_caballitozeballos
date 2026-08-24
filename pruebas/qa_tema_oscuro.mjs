/*
| La capa de identidad sobrevive al tema oscuro.
|
| POR QUE SE COMPRUEBA ANTES DE QUE EXISTA EL INTERRUPTOR
|
| El modo oscuro de Bootstrap 5.3 se activa poniendo data-bs-theme="dark" en
| la raiz del documento. El interruptor todavia no esta hecho, pero el
| comportamiento SI se puede probar: se pone el atributo a mano y se mira si
| las superficies cambian.
|
| Hacerlo ahora, y no despues, evita extender por veinte vistas una capa que
| habria que revisar entera al llegar el tema oscuro.
|
| QUE SE MIDE
|
|   que cambie     Un fondo que sale igual en los dos temas es un color
|                  clavado que se ha escapado. Es el fallo que se busca.
|
|   que se lea     Cambiar de color no basta: hay que comprobar que el texto
|                  sigue contrastando con su fondo en el tema nuevo. Se usa
|                  el umbral AA de la norma, 4.5 para texto pequeño.
|
| LA BARRA LATERAL QUEDA FUERA A PROPOSITO
|
| Lleva data-bs-theme="dark" fijo: es oscura en los dos temas. Su texto
| claro es correcto siempre, y exigirle que cambie seria exigirle un fallo.
*/
import { createRequire } from 'node:module'
import { readdirSync } from 'node:fs'
import { sinAnimacion } from './sin_animacion.mjs'
const EXT = 'C:/Users/dbafaces/.vscode/extensions/'
const RAIZ = EXT + readdirSync(EXT).filter(d => d.startsWith('danielsanmedium.dscodegpt-')).sort().pop() + '/standalone/'
const { chromium } = createRequire(RAIZ)('patchright')

const nav = await chromium.launch({ headless: true, channel: 'chromium' })
const ctx = await nav.newContext({ viewport: { width: 1500, height: 1000 } })
await sinAnimacion(ctx)
await ctx.addCookies([{ name: 'DigiSportsBasketball', value: 'dsqaui0000000000000',
                        domain: 'localhost', path: '/' }])
const p = await ctx.newPage()

let fallos = 0
const af = (t, ok, d = '') => {
  console.log('  ' + t.padEnd(48) + (ok ? 'OK' : 'FALLA') + (d ? '  (' + d + ')' : ''))
  if (!ok) fallos++
}

await p.goto('http://localhost/barcelona/ds_basketball/dashboard/', { waitUntil: 'networkidle' })
await p.waitForTimeout(600)

/* Lo que tiene que cambiar de color al cambiar el tema. */
const SUPERFICIES = [
  ['el cuerpo',            'body'],
  ['la tarjeta',           '.app-content .card'],
  ['la cabecera de tarjeta', '.app-content .card .card-header'],
  ['el contenido',         '.app-main'],
]

const leer = () => p.evaluate((sels) => {
  const salida = {}
  for (const [nombre, sel] of sels) {
    const el = document.querySelector(sel)
    salida[nombre] = el ? getComputedStyle(el).backgroundColor : null
  }
  salida['__color_texto'] = getComputedStyle(document.body).color
  return salida
}, SUPERFICIES)

const claro = await leer()

await p.evaluate(() => document.documentElement.setAttribute('data-bs-theme', 'dark'))
await p.waitForTimeout(400)
const oscuro = await leer()

for (const [nombre] of SUPERFICIES) {
  const a = claro[nombre], b = oscuro[nombre]
  if (a === null) { af(nombre + ': existe en la página', false, 'no se encontró'); continue }
  /* Un fondo transparente hereda del padre: no es un color clavado. */
  const transparente = a === 'rgba(0, 0, 0, 0)'
  af(nombre + ': cambia con el tema', transparente || a !== b,
     transparente ? 'transparente, hereda' : a + ' → ' + b)
}

af('el texto del cuerpo cambia con el tema',
   claro['__color_texto'] !== oscuro['__color_texto'],
   claro['__color_texto'] + ' → ' + oscuro['__color_texto'])

/*==============  Que se siga leyendo  ==============*/
const flojos = await p.evaluate(() => {
  const aRgb = (s) => { const m = s.match(/[\d.]+/g)
                        return m ? { r:+m[0], g:+m[1], b:+m[2], a: m.length>3 ? +m[3] : 1 } : null }
  const lum = (c) => { const f = (v) => { v/=255
                         return v <= 0.03928 ? v/12.92 : Math.pow((v+0.055)/1.055, 2.4) }
                       return 0.2126*f(c.r) + 0.7152*f(c.g) + 0.0722*f(c.b) }
  /* Se componen las capas traslucidas sobre las de abajo: saltarselas da
     numeros que no corresponden a lo que se ve. */
  const fondoReal = (el) => {
    const capas = []
    for (let n = el; n; n = n.parentElement) {
      const c = aRgb(getComputedStyle(n).backgroundColor)
      if (!c || c.a === 0) continue
      capas.push(c)
      if (c.a >= 0.999) break
    }
    let base = capas.pop() || { r:255, g:255, b:255, a:1 }
    while (capas.length) {
      const e = capas.pop()
      base = { r: e.r*e.a + base.r*(1-e.a), g: e.g*e.a + base.g*(1-e.a),
               b: e.b*e.a + base.b*(1-e.a), a: 1 }
    }
    return base
  }
  const razon = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((p,q) => q-p)
                            return (x + 0.05) / (y + 0.05) }

  const malos = []
  /* Sin la barra lateral, que es oscura en los dos temas por diseño. */
  document.querySelectorAll('.app-main .card-title, .app-main .card-header, '
    + '.app-main p, .app-main td, .app-main th, .app-main h1, .app-main h3').forEach(el => {
    if (!el.innerText || !el.innerText.trim()) return
    const est = getComputedStyle(el)
    const t = aRgb(est.color)
    if (!t || t.a < 0.95) return
    const rz = razon(t, fondoReal(el))
    const px = parseFloat(est.fontSize)
    const grande = px >= 24 || (px >= 18.66 && parseInt(est.fontWeight, 10) >= 700)
    if (rz < (grande ? 3 : 4.5)) {
      malos.push(el.innerText.trim().slice(0, 16) + ' ' + rz.toFixed(2))
    }
  })
  return [...new Set(malos)]
})

af('el texto se sigue leyendo en oscuro', flojos.length === 0,
   flojos.slice(0, 3).join(' · ') || 'todo por encima del umbral')

console.log('\nfallos: ' + fallos)
await nav.close()
process.exit(fallos === 0 ? 0 : 1)
