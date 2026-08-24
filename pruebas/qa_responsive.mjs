/*
| ¿Funciona en tablet y en movil? Nunca se habia mirado.
|
| Todo lo comprobado hasta ahora era a 1450 o 1500 px de ancho. Un sistema
| que se usa a pie de cancha —pasar lista, cobrar— se abre en el telefono, y
| ahi los fallos son de otra clase: la pagina se desborda a lo ancho, una
| tabla empuja el cuerpo, el menu tapa el contenido o los botones quedan
| demasiado pequeños para el dedo.
|
| QUE SE MIDE, Y POR QUE ESO
|
|   desborde     scrollWidth mayor que el ancho de la ventana. Es el sintoma
|                que delata casi todos los problemas de maquetacion, y es
|                objetivo: o desborda o no.
|
|   el menu      En movil el menu lateral debe estar recogido. Si arranca
|                abierto tapa la pantalla entera.
|
|   objetivos    Los botones y enlaces de accion deben medir al menos 24 px,
|                que es el minimo de la norma WCAG 2.2 para el tamaño del
|                objetivo. Por debajo se falla al pulsar.
|
| Los tres anchos son los de la propia rejilla de Bootstrap: movil por
| debajo de sm, tablet en md y escritorio en xl.
*/
import { createRequire } from 'node:module'
import { readdirSync } from 'node:fs'
const EXT = 'C:/Users/dbafaces/.vscode/extensions/'
const RAIZ = EXT + readdirSync(EXT).filter(d => d.startsWith('danielsanmedium.dscodegpt-')).sort().pop() + '/standalone/'
const { chromium } = createRequire(RAIZ)('patchright')

const BASE = 'http://localhost/barcelona/'
const ANCHOS = [
  { nombre: 'movil',      w: 390,  h: 844 },
  { nombre: 'tablet',     w: 768,  h: 1024 },
  { nombre: 'escritorio', w: 1440, h: 900 },
]

const PANTALLAS = [
  ['panel',        BASE + 'ds_basketball/dashboard/'],
  ['alumnos',      BASE + 'ds_basketball/alumnoList/'],
  ['alta alumno',  BASE + 'ds_basketball/alumnoNew/'],
  ['editar alumno', BASE + 'ds_basketball/alumnoUpdate/2/'],
  ['ficha alumno',  BASE + 'ds_basketball/alumnoProfile/2/'],
  ['pagos',        BASE + 'ds_basketball/pagosList/'],
  ['cobranza',     BASE + 'ds_league/cobranzaPanel/29/'],
  ['equipos',      BASE + 'ds_league/equipoList/'],
]

const nav = await chromium.launch({ headless: true, channel: 'chromium' })

let fallos = 0
const af = (t, ok, d = '') => {
  console.log('  ' + t.padEnd(46) + (ok ? 'OK' : 'FALLA') + (d ? '  (' + d + ')' : ''))
  if (!ok) fallos++
}

for (const medida of ANCHOS) {
  console.log('\n── ' + medida.nombre + ' (' + medida.w + 'px) ' + '─'.repeat(30))

  const ctx = await nav.newContext({
    viewport: { width: medida.w, height: medida.h },
    isMobile: medida.w < 768,
    hasTouch: medida.w < 768,
  })
  await ctx.addCookies([{ name: 'DigiSportsBasketball', value: 'dsqaui0000000000000',
                          domain: 'localhost', path: '/' }])
  const p = await ctx.newPage()

  for (const [nombre, url] of PANTALLAS) {
    await p.goto(url, { waitUntil: 'networkidle' })
    await p.waitForTimeout(500)

    const m = await p.evaluate(() => {
      const doc = document.documentElement
      /* Quien desborda: se busca el elemento mas ancho que su contenedor,
         que es lo que hay que arreglar. No basta con saber que desborda. */
      const culpables = []
      if (doc.scrollWidth > doc.clientWidth + 1) {
        document.querySelectorAll('body *').forEach(el => {
          const r = el.getBoundingClientRect()
          if (r.right > doc.clientWidth + 1 && r.width > 30) {
            const est = getComputedStyle(el)
            /* Si el propio elemento tiene su scroll, esta contenido y no
               es el culpable: es la solucion. */
            if (est.overflowX === 'auto' || est.overflowX === 'scroll') return
            culpables.push(el.tagName.toLowerCase()
              + (el.className && typeof el.className === 'string'
                 ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '')
              + ' ' + Math.round(r.width) + 'px')
          }
        })
      }

      /* Objetivos de pulsacion demasiado pequeños. */
      const pequenos = [...document.querySelectorAll('a.btn, button, .btn')]
        .filter(b => {
          const r = b.getBoundingClientRect()
          return r.width > 0 && (r.height < 24 || r.width < 24)
        })
        .map(b => (b.getAttribute('title') || b.innerText || b.className).trim().slice(0, 14))

      const menu = document.querySelector('.app-sidebar')
      return {
        desborda: doc.scrollWidth - doc.clientWidth,
        culpables: [...new Set(culpables)].slice(0, 2),
        pequenos: [...new Set(pequenos)].slice(0, 3),
        menuVisible: menu ? menu.getBoundingClientRect().left >= 0
                            && getComputedStyle(menu).visibility !== 'hidden' : null,
      }
    })

    af(nombre + ': no se desborda a lo ancho', m.desborda <= 1,
       m.desborda > 1 ? m.desborda + 'px de más · ' + (m.culpables.join(' · ') || '?') : '')

    if (medida.w < 768) {
      af(nombre + ': el menú arranca recogido', m.menuVisible === false,
         m.menuVisible ? 'tapa el contenido' : '')
    }

    af(nombre + ': los botones se pueden pulsar', m.pequenos.length === 0,
       m.pequenos.join(' · '))
  }

  await ctx.close()
}

console.log('\nfallos: ' + fallos)
await nav.close()
process.exit(fallos === 0 ? 0 : 1)
