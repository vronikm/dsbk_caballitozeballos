/*
| Los ocho iconos del panel se DIBUJAN.
|
| Un icono mal escrito no da ningún error: el navegador pinta un hueco y
| sigue. Por eso no vale mirar el HTML —ahí la clase está siempre—; hay que
| preguntarle al navegador si esa clase acabó produciendo un glifo.
|
| Se comprueban las dos cosas que tienen que cumplirse a la vez: que la
| familia tipográfica sea la de Font Awesome (si la hoja no cargara, sería
| la de por defecto) y que el pseudoelemento tenga un carácter dentro (si la
| clase no existiera, no habría ninguno).
*/
import { createRequire } from 'node:module'
import { readdirSync } from 'node:fs'
const EXT = 'C:/Users/dbafaces/.vscode/extensions/'
const RAIZ = EXT + readdirSync(EXT).filter(d => d.startsWith('danielsanmedium.dscodegpt-')).sort().pop() + '/standalone/'
const { chromium } = createRequire(RAIZ)('patchright')

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

const externas = []
p.on('request', r => {
  const u = r.url()
  if (!u.startsWith('http://localhost/') && !u.startsWith('data:')) externas.push(u)
})
const caidas = []
p.on('response', r => { if (r.status() >= 400) caidas.push(r.status() + ' ' + r.url()) })

await p.goto('http://localhost/barcelona/ds_basketball/dashboard/', { waitUntil: 'networkidle' })

const iconos = await p.evaluate(() =>
  [...document.querySelectorAll('.info-box-icon i, .small-box i')].map(el => {
    const est = getComputedStyle(el, '::before')
    const caja = el.getBoundingClientRect()
    return {
      clase:   el.className,
      familia: est.fontFamily,
      glifo:   est.content,
      ancho:   Math.round(caja.width)
    }
  }))

af('el panel tiene iconos', iconos.length >= 8, iconos.length + ' encontrados')

/* Se resumen: son sesenta, uno por bloque y por sede. Interesa cuántos
   fallan y cuáles, no repetir sesenta veces que todo va bien. */
const dibuja = (i) => /Font Awesome/i.test(i.familia)
                   && i.glifo !== 'none' && i.glifo !== '""'
                   && i.ancho > 0

const mudos = iconos.filter(i => !dibuja(i))
af('los ' + iconos.length + ' iconos dibujan un glifo', mudos.length === 0,
   mudos.length
     ? mudos.length + ' mudos: ' + [...new Set(mudos.map(i => i.clase))].join(', ')
     : [...new Set(iconos.map(i => i.clase))].length + ' clases distintas')

af('no quedan iconos de otras librerías',
   iconos.every(i => !/\bion\b|\bbi\b/.test(i.clase)),
   iconos.filter(i => /\bion\b|\bbi\b/.test(i.clase)).map(i => i.clase).join(', '))

af('el navegador no pide nada a terceros',
   externas.length === 0, [...new Set(externas)].slice(0, 2).join(' '))

af('ningún recurso falla', caidas.length === 0, caidas.slice(0, 2).join(' '))

console.log('\nfallos: ' + fallos)
await nav.close()
process.exit(fallos === 0 ? 0 : 1)
