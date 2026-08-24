/*
| El panel de Basketball: marcado de AdminLTE 4 y texto legible.
|
| LO QUE SE ROMPIO AL ACTUALIZAR LA PLANTILLA
|
| Las tarjetas seguian escritas para la version 3. Medido en el navegador
| antes del arreglo: el icono salia en position:static a 16px —la clase que
| lo colocaba, «icon», ya no existe en la version 4— y quedaba diminuto
| abajo a la izquierda; el pie iba subrayado y en gris oscuro sobre fondo
| rojo y verde, porque bg-* pinta el fondo y no toca el texto.
|
| POR QUE SE MIDE EL CONTRASTE Y NO SE MIRA
|
| Un color «que se ve bien» en la pantalla de quien lo elige puede ser
| ilegible en un portatil con brillo bajo o para quien distingue peor los
| tonos. Se calcula la razon de contraste de la norma WCAG y se exige 4.5
| para el texto pequeño y 3.0 para el grande, que es el umbral AA.
|
| Y SE TIENE EN CUENTA EL CANAL ALFA
|
| Una version anterior de esta sonda dio treinta falsos positivos por leer
| rgba(255,255,255,0.1) como blanco puro. Cuando un fondo es traslucido hay
| que seguir subiendo por los padres hasta encontrar uno opaco.
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

const nav = await chromium.launch({ headless: true, channel: 'chromium' })
const ctx = await nav.newContext({ viewport: { width: 1500, height: 1000 } })
await sinAnimacion(ctx)
await ctx.addCookies([{ name: 'DigiSportsBasketball', value: 'dsqaui0000000000000',
                        domain: 'localhost', path: '/' }])
const p = await ctx.newPage()

let fallos = 0
const af = (t, ok, d = '') => {
  console.log('  ' + t.padEnd(50) + (ok ? 'OK' : 'FALLA') + (d ? '  (' + d + ')' : ''))
  if (!ok) fallos++
}

const errores = []
p.on('pageerror', e => errores.push(e.message.slice(0, 90)))
p.on('console', m => { if (m.type() === 'error') errores.push(m.text().slice(0, 90)) })

const r = await p.goto('http://localhost/barcelona/ds_basketball/dashboard/',
                       { waitUntil: 'networkidle' })
await p.waitForTimeout(900)
af('el panel responde 200', r.status() === 200, 'HTTP ' + r.status())

/*==============  1. Marcado de la version 4  ==============*/
const marcado = await p.evaluate(() => {
  const cajas = [...document.querySelectorAll('.small-box')]
  return {
    total:      cajas.length,
    v3:         cajas.filter(b => b.querySelector(':scope > .icon')).length,
    sinIcono:   cajas.filter(b => !b.querySelector('.small-box-icon')).length,
    /* Se compara con la lista de clases, no con una expresion sobre el
       texto: «bg-info» casa dentro de «text-bg-info» porque el guion cuenta
       como frontera de palabra, y daba por antiguas las veintiocho. */
    colorViejo: cajas.filter(b =>
      ['bg-info', 'bg-success', 'bg-warning', 'bg-danger'].some(c => b.classList.contains(c))).length,
    iconoSuelto: cajas.filter(b => {
      const i = b.querySelector('.small-box-icon')
      return !i || getComputedStyle(i).position !== 'absolute'
    }).length,
    pieSinClase: [...document.querySelectorAll('.small-box-footer')]
      .filter(a => !/link-(light|dark)/.test(a.className)).length,
  }
})

af('todas las tarjetas usan el marcado de la v4',
   marcado.v3 === 0 && marcado.sinIcono === 0 && marcado.colorViejo === 0,
   marcado.total + ' tarjetas; v3: ' + marcado.v3
     + ', sin icono: ' + marcado.sinIcono + ', color viejo: ' + marcado.colorViejo)

af('el icono va colocado, no en el flujo', marcado.iconoSuelto === 0,
   marcado.iconoSuelto + ' sueltos')

af('el pie lleva su color de enlace', marcado.pieSinClase === 0,
   marcado.pieSinClase + ' sin clase')

/*==============  2. Cada metrica se distingue  ==============*/
const iconos = await p.evaluate(() => {
  const primera = [...document.querySelectorAll('.small-box')].slice(0, 4)
  return primera.map(b => {
    const i = b.querySelector('.small-box-icon')
    const clase = [...(i ? i.classList : [])].find(c => c.startsWith('fa-') && c !== 'fa-solid')
    return { texto: (b.querySelector('p')?.innerText || '').trim(), icono: clase || '—' }
  })
})
const distintos = new Set(iconos.map(i => i.icono))
af('las cuatro metricas tienen iconos distintos', distintos.size === 4,
   iconos.map(i => i.icono).join(' '))

/*==============  3. El resumen va antes que el detalle  ==============*/
const orden = await p.evaluate(() => {
  const titulos = [...document.querySelectorAll('.card-title')].map(t => t.innerText.trim())
  return { primero: titulos[0] || '', total: titulos.length }
})
af('el consolidado abre la pantalla', orden.primero === 'CONSOLIDADO',
   'el primero es: ' + orden.primero)

/*==============  4. Contraste  ==============*/
const flojos = await p.evaluate(() => {
  const aRgb = (s) => {
    const m = s.match(/[\d.]+/g)
    return m ? { r: +m[0], g: +m[1], b: +m[2], a: m.length > 3 ? +m[3] : 1 } : null
  }
  const lum = (c) => {
    const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4) }
    return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b)
  }
  /* El fondo real: el que ve el ojo.
     Se recogen las capas de abajo arriba y se COMPONEN una sobre otra.
     Dos versiones anteriores de esta sonda se equivocaron aqui: la primera
     leia rgba(255,255,255,0.1) como blanco puro y daba treinta falsos
     positivos; la segunda lo arreglo saltandose las capas traslucidas, y
     entonces se perdia el negro al 7% que el pie de las tarjetas pone
     encima del color —que oscurece el fondo y MEJORA el contraste—.
     Componer es lo unico que describe lo que se ve. */
  const fondoReal = (el) => {
    const capas = []
    for (let n = el; n; n = n.parentElement) {
      const c = aRgb(getComputedStyle(n).backgroundColor)
      if (!c || c.a === 0) continue
      capas.push(c)
      if (c.a >= 0.999) break              /* opaca: tapa lo de debajo */
    }
    let base = capas.pop() || { r: 255, g: 255, b: 255, a: 1 }
    while (capas.length) {
      const encima = capas.pop()
      base = {
        r: encima.r * encima.a + base.r * (1 - encima.a),
        g: encima.g * encima.a + base.g * (1 - encima.a),
        b: encima.b * encima.a + base.b * (1 - encima.a),
        a: 1,
      }
    }
    return base
  }
  const razon = (a, b) => {
    const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p)
    return (x + 0.05) / (y + 0.05)
  }

  const malos = []
  const textos = document.querySelectorAll(
    '.small-box h3, .small-box p, .small-box-footer, .info-box-text, .info-box-number')

  textos.forEach(el => {
    const est = getComputedStyle(el)
    const t = aRgb(est.color)
    if (!t || t.a < 0.95) return
    const f = fondoReal(el)
    const rz = razon(t, f)

    /* WCAG llama «grande» a 18.66px en negrita o 24px normal. */
    const px = parseFloat(est.fontSize)
    const grande = px >= 24 || (px >= 18.66 && parseInt(est.fontWeight, 10) >= 700)
    const minimo = grande ? 3 : 4.5

    if (rz < minimo) {
      malos.push((el.innerText || '').trim().slice(0, 18) + ' ' + rz.toFixed(2) + '/' + minimo)
    }
  })
  return { malos: [...new Set(malos)], revisados: textos.length }
})

af('todo el texto pasa el contraste AA', flojos.malos.length === 0,
   flojos.revisados + ' textos; flojos: ' + (flojos.malos.slice(0, 3).join(' · ') || 'ninguno'))

/*==============  5. Sin errores ni recursos caidos  ==============*/
af('sin errores de JavaScript', errores.length === 0, errores[0] ?? '')

/*==============  6. El otro panel, el del profesor  ==============*/
/*
| El panel tiene dos caras y sólo se ve una cada vez. La gerencial —la de
| arriba— la ve quien puede consultar el balance; la operativa la ve quien
| tiene una ficha de empleado con horarios detrás.
|
| Sus cajas también estaban con el marcado antiguo: el color se ponía con
| bg-*, que pinta el fondo y deja el dibujo del icono con el color del
| cuerpo. Oscuro sobre azul.
|
| DEPENDE DE UNA SESIÓN QUE PREPARA EL LANZADOR. Si no está, esto falla en
| vez de saltarse en silencio: una comprobación que se salta sola es una
| comprobación que no existe, y ya pasó antes en esta misma suite.
*/
const ctx2 = await nav.newContext({ viewport: { width: 1500, height: 1100 } })
await ctx2.addCookies([{ name: 'DigiSportsBasketball', value: 'dsqaoper000000000000',
                         domain: 'localhost', path: '/' }])
const p2 = await ctx2.newPage()
await p2.goto('http://localhost/barcelona/ds_basketball/dashboard/', { waitUntil: 'networkidle' })
await p2.waitForTimeout(700)

const operativo = await p2.evaluate(() => {
  const aRgb = (s) => { const m = s.match(/[\d.]+/g)
                        return m ? { r: +m[0], g: +m[1], b: +m[2], a: m.length > 3 ? +m[3] : 1 } : null }
  const lum = (c) => { const f = (v) => { v /= 255
                         return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4) }
                       return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b) }
  const razon = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p)
                            return (x + 0.05) / (y + 0.05) }

  const cajas = [...document.querySelectorAll('.info-box-icon')]
  return {
    total:  cajas.length,
    viejas: cajas.filter(s =>
      ['bg-info', 'bg-primary', 'bg-success', 'bg-warning', 'bg-secondary']
        .some(c => s.classList.contains(c))).length,
    /* Los iconos no son texto: la norma les pide 3 a 1, no 4.5. */
    flojos: cajas.map(s => {
      const i = s.querySelector('i')
      if (!i) return null
      const rz = razon(aRgb(getComputedStyle(i).color), aRgb(getComputedStyle(s).backgroundColor))
      return rz < 3 ? [...s.classList].join('.') + ' ' + rz.toFixed(2) : null
    }).filter(Boolean),
    hayHorarios: !!document.querySelector('table tbody tr'),
  }
})

af('el panel operativo se dibuja', operativo.total === 4 && operativo.hayHorarios,
   operativo.total + ' cajas, tabla de horarios: ' + operativo.hayHorarios)
af('sus cajas usan el marcado de la v4', operativo.viejas === 0,
   operativo.viejas + ' con color de la v3')
af('sus iconos contrastan con su fondo', operativo.flojos.length === 0,
   operativo.flojos.join(' · ') || 'todos por encima de 3')

console.log('\nfallos: ' + fallos)
await nav.close()
process.exit(fallos === 0 ? 0 : 1)
