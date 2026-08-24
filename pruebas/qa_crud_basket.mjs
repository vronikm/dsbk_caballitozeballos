/*
| Lo que faltaba: comprobar que los formularios SE ENVÍEN.
|
| Todo lo verificado hasta ahora era que las pantallas se dibujan: HTTP 200,
| sin errores de consola, con la maquetación en su sitio. Nada de eso detecta
| que un formulario haya dejado de guardar, y ese es justo el riesgo de haber
| tocado ajax.js, el navbar y setenta vistas.
|
| Aquí se recorre crear, leer, editar y borrar PULSANDO EL BOTÓN en el
| navegador, de modo que la ruta sea la misma que la de un usuario:
| formulario → ajax.js → torneoAjax.php → base de datos.
|
| ENTIDAD DESECHABLE
|
| Torneos: no toca dinero, ni alumnos, ni asistencia. El registro se crea con
| un sello único y se borra al final; si la prueba se interrumpe, lo que
| quede es identificable y no afecta a nada real.
|
| DOS TRAMPAS QUE YA COSTARON UNA VERSIÓN ENTERA DE ESTE ARCHIVO
|
| 1. La página tiene DIECIOCHO formularios de la misma clase: el de cambiar
|    contraseña del navbar —que va primero en el documento—, el de alta y uno
|    por fila del listado. Buscar «el primero» rellenaba el del navbar y no
|    guardaba nada. Aquí cada formulario se localiza por el valor de su campo
|    modulo_torneo, que es lo que de verdad lo identifica.
|
| 2. Todo envío muestra ANTES una confirmación. Quien espere la respuesta del
|    servidor nada más pulsar, lee la confirmación y la toma por resultado.
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
  console.log('  ' + t.padEnd(52) + (ok ? 'OK' : 'FALLA') + (d ? '  (' + d + ')' : ''))
  if (!ok) fallos++
}

const excepciones = []
p.on('pageerror', e => excepciones.push(e.message.slice(0, 130)))

const sello = 'QT' + Date.now().toString().slice(-6)

/*----------  Localizar el formulario correcto  ----------*/
/* Por el valor de modulo_torneo: registrar, actualizar o eliminar. Es el
   campo que le dice al servidor qué hacer, así que identifica al formulario
   mejor que cualquier posición o id. */
const selForm = (accion) =>
  'form.FormularioAjax:has(input[name="modulo_torneo"][value="' + accion + '"])'

/*----------  Leer y encadenar los avisos  ----------*/
const leerAviso = () => p.evaluate(() => {
  const c = document.querySelector('.swal2-popup')
  if (!c || c.offsetParent === null) return null
  const t = c.querySelector('.swal2-title')
  const x = c.querySelector('.swal2-html-container')
  return { titulo: t ? t.innerText.trim() : '',
           texto:  x ? x.innerText.trim()  : '',
           todo:   c.innerText.trim() }
})

/* Pulsa, atraviesa la confirmación y devuelve la respuesta del servidor. */
const enviar = async (pulsar) => {
  await pulsar()
  await p.waitForSelector('.swal2-popup', { state: 'visible', timeout: 6000 })
         .catch(() => {})
  const confirmacion = await leerAviso()
  if (!confirmacion) return { confirmacion: null, respuesta: null }

  await p.click('.swal2-confirm')
  /* La respuesta es OTRO aviso: se espera a que el contenido cambie, no a
     que pase un rato. */
  const cambio = await p.waitForFunction((antes) => {
    const c = document.querySelector('.swal2-popup')
    return !!c && c.offsetParent !== null && c.innerText.trim() !== antes
  }, confirmacion.todo, { timeout: 9000 }).then(() => true).catch(() => false)

  return { confirmacion, respuesta: cambio ? await leerAviso() : null }
}

const cerrar = async () => {
  await p.click('.swal2-confirm').catch(() => {})
  await p.waitForTimeout(1200)
}

const irAlListado = async () => {
  await p.goto(BASE + 'torneoList/', { waitUntil: 'networkidle' })
  await p.waitForTimeout(600)
}

const filaConSello = (s) => p.evaluate((s) => {
  const tr = [...document.querySelectorAll('#example1 tbody tr')]
               .find(t => t.innerText.includes(s))
  return tr ? tr.innerText.replace(/\s+/g, ' ').trim() : null
}, s)

/*==============  1. La pantalla y su formulario  ==============*/
const r = await p.goto(BASE + 'torneoList/', { waitUntil: 'networkidle' })
af('la pantalla de torneos responde 200', r.status() === 200, 'HTTP ' + r.status())

const alta = await p.evaluate((sel) => {
  const f = document.querySelector(sel)
  return f ? { id: f.id, campos: [...f.elements].map(e => e.name).filter(Boolean).length } : null
}, selForm('registrar'))
af('encuentra el formulario de alta', alta !== null,
   alta ? alta.id + ', ' + alta.campos + ' campos' : 'no está')

/*==============  2. Validación del servidor  ==============*/
/* Se envía vacío: el servidor debe rechazarlo. Comprobar sólo el camino
   feliz deja pasar un endpoint que acepte cualquier cosa. */
let res = await enviar(() => p.evaluate((sel) => {
  const f = document.querySelector(sel)
  f.querySelectorAll('input, textarea, select').forEach(i => {
    if (i.type !== 'hidden' && i.type !== 'file') { i.value = ''; i.removeAttribute('required') }
  })
  f.querySelector('button[type=submit]').click()
}, selForm('registrar')))

af('el envío pide confirmación primero',
   res.confirmacion !== null && /realizar/i.test(res.confirmacion.todo),
   res.confirmacion ? res.confirmacion.todo.split('\n')[0] : 'no apareció')
af('sin los campos obligatorios, el servidor rechaza',
   res.respuesta !== null && /obligatorio/i.test(res.respuesta.texto),
   res.respuesta ? res.respuesta.titulo + ': ' + res.respuesta.texto : 'sin respuesta')
await cerrar()

/*==============  3. Crear  ==============*/
await irAlListado()
res = await enviar(() => p.evaluate((arg) => {
  const f = document.querySelector(arg.sel)
  const set = (n, v) => { const e = f.querySelector('[name="' + n + '"]'); if (e) e.value = v }
  set('torneo_nombre', arg.s)
  set('torneo_ciudad', 'Ciudad ' + arg.s)
  set('torneo_lugar', 'Lugar ' + arg.s)
  set('torneo_organizador', 'QA')
  set('torneo_fechainicio', '2026-09-01')
  set('torneo_fechafin', '2026-09-30')
  set('torneo_descripcion', 'Registro de prueba automatica')
  f.querySelector('button[type=submit]').click()
}, { sel: selForm('registrar'), s: sello }))

af('crea el torneo',
   res.respuesta !== null
     && /registr/i.test(res.respuesta.titulo + res.respuesta.texto)
     && !/no fue posible|error/i.test(res.respuesta.titulo),
   res.respuesta ? res.respuesta.titulo : 'sin respuesta')
await cerrar()

/*==============  4. Leer  ==============*/
await irAlListado()
const fila = await filaConSello(sello)
af('aparece en el listado', fila !== null, fila ? fila.slice(0, 60) : '')

/* Y DataTables lo encuentra: prueba de que la tabla está viva. Se comprueba
   que la fila visible es LA SUYA; contar «una fila» daba por bueno el
   «no hay datos» que la tabla pinta cuando no encuentra nada. */
/* DataTables 2 renombro el contenedor del buscador: el envoltorio
   #tabla_filter de la 1.x ya no existe y ahora es .dt-search. Se
   aceptan los dos para que la prueba no dependa de la version. */
const buscador = await p.$('.dt-search input, #example1_filter input')
if (buscador) {
  await buscador.fill(sello)
  await p.waitForTimeout(800)
  const visibles = await p.evaluate((s) => {
    const tr = [...document.querySelectorAll('#example1 tbody tr')]
                 .filter(t => t.offsetParent !== null)
    return { n: tr.length, suya: tr.length === 1 && tr[0].innerText.includes(s) }
  }, sello)
  af('el buscador de DataTables lo filtra', visibles.suya,
     visibles.n + ' filas visibles')
  await buscador.fill('')
  await p.waitForTimeout(500)
} else {
  af('el buscador de DataTables existe', false, 'no se encontró')
}

/*==============  5. Editar  ==============*/
/* Editar es un enlace a la misma pantalla con el id: el formulario pasa a
   modo actualizar. */
const enlaceEditar = await p.evaluate((s) => {
  const tr = [...document.querySelectorAll('#example1 tbody tr')]
               .find(t => t.innerText.includes(s))
  const a = tr && tr.querySelector('a[title="Editar"]')
  return a ? a.getAttribute('href') : null
}, sello)
af('la fila ofrece editar', enlaceEditar !== null, enlaceEditar ?? '')

if (enlaceEditar) {
  await p.goto(enlaceEditar, { waitUntil: 'networkidle' })
  await p.waitForTimeout(600)

  const cargado = await p.evaluate((arg) => {
    const f = document.querySelector(arg.sel)
    if (!f) return null
    const e = f.querySelector('[name="torneo_nombre"]')
    return { valor: e ? e.value : '', coincide: e ? e.value.includes(arg.s) : false }
  }, { sel: selForm('actualizar'), s: sello })
  af('el formulario carga los datos del torneo',
     cargado !== null && cargado.coincide,
     cargado ? cargado.valor : 'no está en modo edición')

  res = await enviar(() => p.evaluate((sel) => {
    const f = document.querySelector(sel)
    f.querySelector('[name="torneo_ciudad"]').value = 'Ciudad editada'
    f.querySelector('button[type=submit]').click()
  }, selForm('actualizar')))
  af('guarda la edición',
     res.respuesta !== null
       && !/no fue posible|error/i.test(res.respuesta.titulo + res.respuesta.texto),
     res.respuesta ? res.respuesta.titulo : 'sin respuesta')
  await cerrar()

  await irAlListado()
  const tras = await filaConSello(sello)
  af('el cambio se ve en el listado',
     tras !== null && tras.includes('Ciudad editada'),
     tras ? tras.slice(0, 60) : 'no está')
}

/*==============  6. Borrar  ==============*/
await irAlListado()
res = await enviar(() => p.evaluate((s) => {
  const tr = [...document.querySelectorAll('#example1 tbody tr')]
               .find(t => t.innerText.includes(s))
  tr.querySelector('form.FormularioAjax:has(input[value="eliminar"]) button[type=submit]').click()
}, sello))
af('borra el torneo',
   res.respuesta !== null && !/no fue posible/i.test(res.respuesta.titulo + res.respuesta.texto),
   res.respuesta ? res.respuesta.titulo : 'sin respuesta')
await cerrar()

await irAlListado()
const resto = await filaConSello(sello)
af('desaparece del listado', resto === null, resto ? 'sigue ahí' : '')

af('sin excepciones de JavaScript en todo el recorrido',
   excepciones.length === 0, excepciones[0] ?? '')

console.log('\n  sello usado: ' + sello)
console.log('\nfallos: ' + fallos)
await nav.close()
process.exit(fallos === 0 ? 0 : 1)
