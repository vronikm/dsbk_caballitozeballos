/*
| Las siete pantallas a las que se les quitó JavaScript muerto SIGUEN
| HACIENDO LO SUYO.
|
| «No da errores» no demuestra nada aquí: si nadie llamaba a la librería,
| quitarla no puede dar un error — y tampoco lo daría si me hubiera llevado
| por delante la que sí hacía falta, porque eso sólo se nota al usar la
| pantalla. Así que cada una se comprueba por lo que tiene que verse.
|
| DOS TRAMPAS QUE YA DIERON RESULTADOS FALSOS
|
| 1. Tres de estas pantallas exigen un identificador en la URL y sin él
|    redirigen a otra. La primera versión medía pagosList creyendo que
|    medía pagosDescuento, y las daba por buenas sin haberlas visitado.
|
| 2. El peso NO se mide aquí. Se intentó sumando las respuestas y salió que
|    la primera pantalla pedía 2,6 MB y las siguientes «0 KB»: sus
|    librerías ya estaban en la caché. Eso lo hace medir_peso.php, leyendo
|    el HTML servido y los tamaños en disco.
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

const BASE = 'http://localhost/barcelona/ds_basketball/'
const nav = await chromium.launch({ headless: true, channel: 'chromium' })
const ctx = await nav.newContext({ viewport: { width: 1450, height: 1000 } })
await ctx.addCookies([{ name: 'DigiSportsBasketball', value: 'dsqaui0000000000000',
                        domain: 'localhost', path: '/' }])
const p = await ctx.newPage()

let fallos = 0
const af = (t, ok, d = '') => {
  console.log('  ' + t.padEnd(50) + (ok ? 'OK' : 'FALLA') + (d ? '  (' + d + ')' : ''))
  if (!ok) fallos++
}

const PANTALLAS = [
  { url: 'buscarAsistencia/2/', nombre: 'buscarAsistencia', jquery: false,
    prueba: () => document.querySelectorAll('.fc-daygrid-day').length,
    dice: 'el calendario se pinta', minimo: 28 },

  { url: 'dashboard/', nombre: 'dashboard', jquery: false,
    prueba: () => document.querySelectorAll('.info-box-number, .small-box h3').length,
    dice: 'los indicadores salen', minimo: 4 },

  { url: 'agenda/', nombre: 'agenda', jquery: true,
    prueba: () => document.querySelectorAll('#calendar, .fc, table').length,
    dice: 'su contenido sale', minimo: 1 },

  /*
  | Se cuentan las tarjetas O el estado vacío, no sólo las tarjetas.
  |
  | Esta pantalla lista los cumpleaños DEL DÍA, así que su contenido
  | depende del calendario. El 26 de agosto no cumplía años nadie y la
  | suite falló anunciando que la vista estaba rota — no lo estaba: se
  | dibujó correctamente, con su mensaje de «no hay alumnos».
  |
  | Lo que esta comprobación quiere saber es si la vista SIGUE PINTANDO
  | sin jQuery, no cuántos cumpleaños hay hoy. Un aserto que depende de
  | la fecha falla solo unos días al mes y hace desconfiar del barrido
  | entero. El estado vacío es un render válido.
  */
  { url: 'cumpleaniosList/', nombre: 'cumpleaniosList', jquery: false,
    prueba: () => document.querySelectorAll('.card').length
             + document.querySelectorAll('.cumple-empty').length,
    dice: 'la pantalla se dibuja (tarjetas o estado vacío)', minimo: 1 },

  { url: 'empleadoEntrada/', nombre: 'empleadoEntrada', jquery: false,
    prueba: () => document.querySelectorAll('form.FormularioAjax input, form.FormularioAjax select').length,
    dice: 'el formulario tiene campos', minimo: 2 },

  { url: 'pagosDescuento/2/', nombre: 'pagosDescuento', jquery: false,
    prueba: () => document.querySelectorAll('form.FormularioAjax input').length,
    dice: 'el formulario tiene campos', minimo: 2 },

  { url: 'pagospendienteRecibo/2/', nombre: 'pagospendienteRecibo', jquery: false,
    prueba: () => document.querySelectorAll('form, table').length,
    dice: 'su contenido sale', minimo: 1 },
]

for (const pant of PANTALLAS) {
  const errores = []
  const oyErr = e => errores.push(e.message.slice(0, 90))
  const oyCon = m => { if (m.type() === 'error') errores.push(m.text().slice(0, 90)) }
  p.on('pageerror', oyErr); p.on('console', oyCon)

  /*
  | «networkidle» espera a que la red lleve 500 ms en calma, y con la
  | maquina cargada eso puede no llegar en 30 s: la suite fallaba con un
  | TimeoutError en pagospendienteRecibo mientras la pagina respondia en
  | 75 ms. No era un fallo de la vista, era la espera.
  |
  | Se espera a que el documento este listo —que es lo que hace falta para
  | mirar el DOM— y se da mas margen. Si aun asi no llega, se anota y se
  | sigue: una vista lenta no debe tumbar el barrido entero.
  */
  let r
  try {
    r = await p.goto(BASE + pant.url, { waitUntil: 'domcontentloaded', timeout: 45000 })
    await p.waitForTimeout(400)
  } catch (e) {
    af(pant.nombre + ': la pagina carga', false, String(e.message).slice(0, 60))
    continue
  }
  await p.waitForTimeout(1200)

  /* Que no haya redirigido: si la pantalla no es la que se pidió, lo
     demás no vale nada. */
  const llegada = await p.evaluate(() => location.pathname)
  const enSuSitio = llegada.includes(pant.url.split('/')[0])

  const valor = await p.evaluate(pant.prueba)

  /* jQuery se pregunta desde dentro de la página: evaluate no ve sus
     variables y allí siempre saldría que no está. */
  await p.addScriptTag({ content:
    "document.documentElement.setAttribute('data-qa-jq'," +
    " typeof window.jQuery !== 'undefined' ? 'si' : 'no');" })
  const jq = await p.evaluate(() => document.documentElement.getAttribute('data-qa-jq'))

  af(pant.nombre + ': ' + pant.dice,
     r.status() === 200 && enSuSitio && valor >= pant.minimo,
     'HTTP ' + r.status() + ', ' + valor + ' (mín. ' + pant.minimo + ')'
       + (enSuSitio ? '' : ', REDIRIGIÓ a ' + llegada))

  af(pant.nombre + ': jQuery ' + (pant.jquery ? 'sigue' : 'ya no está'),
     jq === (pant.jquery ? 'si' : 'no'), 'está: ' + jq)

  if (errores.length) { af(pant.nombre + ': sin errores', false, errores[0]) }

  p.off('pageerror', oyErr); p.off('console', oyCon)
}

console.log('\nfallos: ' + fallos)
await nav.close()
process.exit(fallos === 0 ? 0 : 1)
