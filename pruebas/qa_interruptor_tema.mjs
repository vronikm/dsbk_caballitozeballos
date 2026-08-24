/*
| El interruptor de tema: que cambie, que se recuerde y que NO parpadee.
|
| LO QUE DE VERDAD SE ROMPE EN ESTA FUNCIONALIDAD
|
| No es el cambio de color —eso se ve enseguida—, sino tres cosas que solo
| se notan usandola:
|
|   1. Que la eleccion sobreviva a recargar y a navegar a otra pantalla. Un
|      tema que hay que volver a poner en cada pagina es peor que no tenerlo.
|
|   2. Que «automatico» siga al sistema DE VERDAD, y no solo al arrancar: el
|      sistema puede cambiar solo al anochecer con la aplicacion abierta.
|
|   3. Que no parpadee. Si el script corriera al final del cuerpo, la pagina
|      se pinta clara y salta a oscura. Se comprueba interceptando el HTML
|      antes de que el navegador lo interprete: la etiqueta del script tiene
|      que estar DENTRO de la cabecera y sin defer.
|
| El parpadeo no se puede medir mirando la pagina ya cargada —ahi ya esta
| oscura—, asi que se comprueba sobre el documento que llega del servidor.
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
import { sinAnimacion } from './sin_animacion.mjs'
const EXT = 'C:/Users/dbafaces/.vscode/extensions/'
const RAIZ = EXT + readdirSync(EXT).filter(d => d.startsWith('danielsanmedium.dscodegpt-')).sort().pop() + '/standalone/'
const { chromium } = createRequire(RAIZ)('patchright')

const BASE = 'http://localhost/barcelona/'
const nav = await chromium.launch({ headless: true, channel: 'chromium' })

let fallos = 0
const af = (t, ok, d = '') => {
  console.log('  ' + t.padEnd(50) + (ok ? 'OK' : 'FALLA') + (d ? '  (' + d + ')' : ''))
  if (!ok) fallos++
}

const galleta = { name: 'DigiSportsBasketball', value: 'dsqaui0000000000000',
                  domain: 'localhost', path: '/' }

/*==============  1. El script llega en la cabecera y sin defer  ==============*/
{
  const ctx = await nav.newContext()
await sinAnimacion(ctx)
  await ctx.addCookies([galleta])
  const p = await ctx.newPage()

  /* Se lee el HTML crudo, sin interpretar: es la unica forma de saber
     DONDE esta la etiqueta. */
  const r = await p.goto(BASE + 'ds_basketball/dashboard/', { waitUntil: 'commit' })
  const html = await r.text()

  const cabeza = html.slice(0, html.search(/<\/head>/i))
  const eti = (cabeza.match(/<script[^>]*tema\.js[^>]*>/i) || [null])[0]

  af('el script del tema está en la cabecera', eti !== null,
     eti ? '' : 'no aparece antes de cerrar head')
  af('y sin defer ni async, para evitar el parpadeo',
     eti !== null && !/\b(defer|async)\b/i.test(eti), eti ? eti.slice(0, 60) : '')

  await ctx.close()
}

/*==============  2. Cambia, y se recuerda  ==============*/
{
  const ctx = await nav.newContext({ viewport: { width: 1400, height: 900 } })
  await ctx.addCookies([galleta])
  const p = await ctx.newPage()
  await p.goto(BASE + 'ds_basketball/dashboard/', { waitUntil: 'networkidle' })
  await p.waitForTimeout(400)

  const hayControl = await p.evaluate(() =>
    document.querySelectorAll('[data-ds-tema-opcion]').length)
  af('el control ofrece las tres opciones', hayControl === 3, hayControl + ' opciones')

  const elegir = async (modo) => {
    await p.evaluate((m) => document.querySelector('[data-ds-tema-opcion="' + m + '"]').click(), modo)
    await p.waitForTimeout(300)
    return p.evaluate(() => ({
      pintado: document.documentElement.getAttribute('data-bs-theme'),
      elegido: document.documentElement.getAttribute('data-ds-tema'),
      fondo:   getComputedStyle(document.body).backgroundColor,
    }))
  }

  const oscuro = await elegir('dark')
  af('al elegir oscuro, la página se oscurece',
     oscuro.pintado === 'dark' && oscuro.fondo !== 'rgb(248, 249, 250)',
     oscuro.fondo)

  const claro = await elegir('light')
  af('al elegir claro, vuelve', claro.pintado === 'light', claro.fondo)

  /* Lo importante: que sobreviva a recargar Y a irse a otra pantalla. */
  await elegir('dark')
  await p.reload({ waitUntil: 'networkidle' })
  const trasRecargar = await p.evaluate(() =>
    document.documentElement.getAttribute('data-bs-theme'))
  af('la elección sobrevive a recargar', trasRecargar === 'dark', 'quedó ' + trasRecargar)

  await p.goto(BASE + 'ds_basketball/alumnoList/', { waitUntil: 'networkidle' })
  const enOtra = await p.evaluate(() =>
    document.documentElement.getAttribute('data-bs-theme'))
  af('y al cambiar de pantalla', enOtra === 'dark', 'quedó ' + enOtra)

  /* Y que llegue a los otros módulos, que es de lo que iba tenerlo en un
     include compartido. */
  await p.goto(BASE + 'ds_league/panel/', { waitUntil: 'networkidle' })
  const enLeague = await p.evaluate(() => ({
    tema: document.documentElement.getAttribute('data-bs-theme'),
    control: document.querySelectorAll('[data-ds-tema-opcion]').length,
  }))
  af('y a los demás módulos del ecosistema',
     enLeague.tema === 'dark' && enLeague.control === 3,
     'League: ' + enLeague.tema + ', ' + enLeague.control + ' opciones')

  await ctx.close()
}

/*==============  3. «El del sistema» sigue al sistema  ==============*/
/* Se abre el navegador diciendo que el sistema esta en oscuro. Si
   «automatico» funciona, la pagina sale oscura sin haber elegido nada. */
for (const esquema of ['dark', 'light']) {
  const ctx = await nav.newContext({ colorScheme: esquema })
  await ctx.addCookies([galleta])
  const p = await ctx.newPage()
  await p.goto(BASE + 'ds_basketball/dashboard/', { waitUntil: 'networkidle' })
  await p.waitForTimeout(300)

  const r = await p.evaluate(() => ({
    pintado: document.documentElement.getAttribute('data-bs-theme'),
    elegido: document.documentElement.getAttribute('data-ds-tema'),
  }))
  af('con el sistema en ' + esquema + ', arranca en ' + esquema,
     r.pintado === esquema && r.elegido === 'auto',
     'pintado ' + r.pintado + ', elegido ' + r.elegido)

  await ctx.close()
}

/*==============  4. Sin almacenamiento, no se rompe  ==============*/
{
  const ctx = await nav.newContext()
  await ctx.addCookies([galleta])
  const p = await ctx.newPage()
  /* Se rompe localStorage a proposito, como en modo privado con la cuota
     agotada: la aplicacion tiene que seguir dibujandose. */
  await p.addInitScript(() => {
    Object.defineProperty(window, 'localStorage', {
      get() { throw new Error('sin almacenamiento') },
    })
  })
  const errores = []
  p.on('pageerror', e => errores.push(e.message.slice(0, 60)))
  await p.goto(BASE + 'ds_basketball/dashboard/', { waitUntil: 'networkidle' })
  await p.waitForTimeout(300)

  const r = await p.evaluate(() => ({
    tema: document.documentElement.getAttribute('data-bs-theme'),
    tarjetas: document.querySelectorAll('.card').length,
  }))
  af('sin poder guardar, la página sigue en pie',
     r.tema !== null && r.tarjetas > 0 && errores.length === 0,
     'tema ' + r.tema + ', ' + r.tarjetas + ' tarjetas' + (errores[0] ? ', ' + errores[0] : ''))

  await ctx.close()
}

/*==============  El icono dice lo que se esta viendo  ==============*/
/*
| Se detecto mirando una captura: con el sistema en oscuro la pagina salia
| oscura y el icono seguia siendo fa-adjust, el circulo medio relleno, que a
| 16 px se lee como un sol. Es decir, anunciaba el tema contrario al real.
|
| El codigo llevaba escrito en un comentario que el icono «dice que se esta
| viendo, no que se eligio»... y en automatico no lo hacia. Se comprueban las
| cinco combinaciones de sistema y eleccion guardada, porque el caso roto era
| justo el que NO tenia eleccion guardada —el de quien nunca toco el menu—.
*/
for (const [esquema, guardado] of [['dark', null], ['light', null],
                                   ['dark', 'dark'], ['light', 'dark'],
                                   ['dark', 'light']]) {
  const ctxI = await nav.newContext({ viewport: { width: 1400, height: 800 }, colorScheme: esquema })
  await ctxI.addCookies([{ name: 'DigiSportsBasketball', value: 'dsqaui0000000000000',
                           domain: 'localhost', path: '/' }])
  if (guardado) {
    await ctxI.addInitScript((v) => { try { localStorage.setItem('ds-tema', v) } catch (e) {} }, guardado)
  }
  const pgI = await ctxI.newPage()
  await pgI.goto('http://localhost/barcelona/ds_basketball/dashboard/', { waitUntil: 'networkidle' })
  await pgI.waitForTimeout(400)

  const ri = await pgI.evaluate(() => {
    const i = document.querySelector('[data-ds-tema-icono]')
    const b = i ? i.closest('a') : null
    return { tema: document.documentElement.getAttribute('data-bs-theme'),
             modo: document.documentElement.getAttribute('data-ds-tema'),
             icono: i ? i.className : '(sin control)',
             titulo: b ? (b.getAttribute('title') || '') : '' }
  })

  const esperado = ri.tema === 'dark' ? 'fa-moon' : 'fa-sun'
  af('sistema ' + esquema + ' / elegido ' + (guardado || 'nada') + ': el icono coincide',
     ri.icono.includes(esperado), ri.tema + ' → ' + ri.icono.replace('fas ', ''))

  /* Que sea automatico no puede perderse: si el icono ya no lo distingue,
     tiene que decirlo el rotulo. */
  if (ri.modo === 'auto') {
    af('  y el rotulo avisa de que es automatico',
       /autom/i.test(ri.titulo), ri.titulo || '(sin titulo)')
  }
  await ctxI.close()
}
console.log('\nfallos: ' + fallos)
await nav.close()
process.exit(fallos === 0 ? 0 : 1)
