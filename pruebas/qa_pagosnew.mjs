/* pagosNew tras dar ids unicos: lo que se comprueba es el EFECTO, no que
   los ids se hayan renombrado. */
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
const nav = await chromium.launch({ headless: true, channel: 'chromium' })
const ctx = await nav.newContext({ viewport: { width: 1450, height: 1000 } })
await ctx.addCookies([{ name:'DigiSportsBasketball', value:'dsqaui0000000000000', domain:'localhost', path:'/' }])
const p = await ctx.newPage()
let fallos = 0
const af = (t, ok, d='') => { console.log('  '+t.padEnd(54)+(ok?'OK':'FALLA')+(d?'  ('+d+')':'')); if(!ok) fallos++ }

const errs = []
p.on('pageerror', e => errs.push(e.message.slice(0,120)))
await p.goto('http://localhost/barcelona/ds_basketball/pagosNew/2/', { waitUntil:'networkidle' })
await p.waitForTimeout(1500)

/* 1. Ningun id repetido, que era el problema. */
const dup = await p.evaluate(() => {
  const c = {}; document.querySelectorAll('[id]').forEach(e => c[e.id] = (c[e.id]||0)+1)
  return Object.entries(c).filter(([,n]) => n > 1)
})
af('ningun id repetido en la pagina', dup.length === 0, JSON.stringify(dup.slice(0,3)))

/* 2. select2 convierte TODOS los <select>, que antes eran 3 de 8. */
const s2 = await p.evaluate(() => ({
  selects: document.querySelectorAll('select.select2').length,
  cont:    document.querySelectorAll('.select2-container').length
}))
af('select2 convierte todos los desplegables', s2.cont >= s2.selects,
   s2.cont + ' de ' + s2.selects)

/* 3. Cada <label for> encuentra su campo, y en SU pestana. */
const labels = await p.evaluate(() => {
  let mal = 0, fuera = 0
  document.querySelectorAll('label[for]').forEach(l => {
    const d = document.getElementById(l.htmlFor)
    if (!d) { mal++; return }
    const paneL = l.closest('.tab-pane'), paneD = d.closest('.tab-pane')
    if (paneL && paneD && paneL !== paneD) { fuera++ }
  })
  return { mal, fuera, total: document.querySelectorAll('label[for]').length }
})
af('todas las etiquetas encuentran su campo', labels.mal === 0, labels.mal + ' huerfanas')
af('y ninguna apunta a otra pestaña', labels.fuera === 0,
   labels.fuera + ' de ' + labels.total)

/* 4. Las seis pestanas abren. */
let abiertas = 0
for (const t of ['pension','inscripcion','torneo','uniforme','kit','otros']) {
  await p.click('a[href="#' + t + '"]').catch(()=>{})
  await p.waitForTimeout(320)
  const vis = await p.evaluate(id => {
    const e = document.getElementById(id)
    return !!e && e.classList.contains('active') && e.offsetHeight > 0
  }, t)
  if (vis) abiertas++
  else console.log('       no abrio: ' + t)
}
af('las seis pestañas abren al pulsarlas', abiertas === 6, abiertas + ' de 6')

/* 5. La fecha en palabras: antes solo funcionaba en la primera. */
await p.click('a[href="#uniforme"]')
await p.waitForTimeout(400)
const antes = await p.evaluate(() => {
  const c = document.querySelector('#uniforme .js-fecha-en-palabras')
  return c ? c.id : null
})
af('el campo de fecha de otra pestaña lleva la clase', antes !== null, antes ?? 'no existe')

const campos = await p.evaluate(() => document.querySelectorAll('.js-fecha-en-palabras').length)
af('las seis fechas responden al mismo script', campos === 6, campos + ' campos')

af('sin excepciones de JavaScript', errs.length === 0, errs[0] ?? '')

console.log('\nfallos: ' + fallos)
/* Sólo si algo falló, y FUERA del proyecto: la pantalla de pagos
   retrata nombres de alumnos, y una captura es un dato personal más
   que guardar. */
if (fallos > 0) {
  await p.click('a[href="#pension"]').catch(() => {})
  await p.waitForTimeout(400)
  const foto = (process.env.TEMP || '/tmp') + '/qa_pagosnew.png'
  await p.screenshot({ path: foto })
  console.log('  captura: ' + foto)
}
await nav.close()
process.exit(fallos===0?0:1)
