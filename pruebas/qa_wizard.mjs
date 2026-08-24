/*
| El asistente del alta de alumno.
|
| EL FALLO QUE ARREGLA, MEDIDO ANTES DE ESCRIBIRLO
|
| La ficha tiene cinco pestañas y los catorce campos obligatorios estan en la
| primera. El boton Guardar estaba siempre visible. Estando en «Horario» con
| la primera vacia y pulsando Guardar, el navegador registraba:
|
|     An invalid form control with name='alumno_identificacion'
|     is not focusable.
|
| Rechaza el envio por el obligatorio que falta pero NO puede enseñar su
| mensaje, porque el campo esta oculto en otra pestaña. Para quien lo usa: no
| pasa nada. El boton parece muerto.
|
| QUE SE COMPRUEBA
|
|   1. Que no se pueda avanzar con el paso incompleto.
|   2. Que rellenandolo, si se avance.
|   3. Que hacia atras se vaya siempre, sin condiciones.
|   4. Que Guardar solo este en el ultimo paso.
|   5. Y LO IMPORTANTE: que si el formulario se envia invalido, el asistente
|      LLEVE al paso del campo que falta en vez de callarse.
|
| El punto 5 se fuerza a proposito, saltando la navegacion normal, porque es
| la red de seguridad y hay que saber que sostiene.
*/
import { createRequire } from 'node:module'
import { readdirSync } from 'node:fs'
const EXT = 'C:/Users/dbafaces/.vscode/extensions/'
const RAIZ = EXT + readdirSync(EXT).filter(d => d.startsWith('danielsanmedium.dscodegpt-')).sort().pop() + '/standalone/'
const { chromium } = createRequire(RAIZ)('patchright')

const nav = await chromium.launch({ headless: true, channel: 'chromium' })
const ctx = await nav.newContext({ viewport: { width: 1500, height: 1000 } })
await ctx.addCookies([{ name: 'DigiSportsBasketball', value: 'dsqaui0000000000000',
                        domain: 'localhost', path: '/' }])
const p = await ctx.newPage()

let fallos = 0
const af = (t, ok, d = '') => {
  console.log('  ' + t.padEnd(50) + (ok ? 'OK' : 'FALLA') + (d ? '  (' + d + ')' : ''))
  if (!ok) fallos++
}

await p.goto('http://localhost/barcelona/ds_basketball/alumnoNew/', { waitUntil: 'networkidle' })
await p.waitForTimeout(900)

const estado = () => p.evaluate(() => {
  const caja = document.querySelector('[data-ds-wizard]')
  const guardar = (() => {
    const c = caja.querySelector('input[name], select[name], textarea[name]')
    const f = c ? c.form : null
    return f ? [...f.elements].find(e => e.type === 'submit') : null
  })()
  return {
    paso: (caja.querySelector('[data-ds-wizard-paso]') || {}).textContent || '',
    panel: (caja.querySelector('.tab-pane.active') || {}).id,
    anchoBarra: (caja.querySelector('[data-ds-wizard-barra]') || {}).style.width,
    atrasDesactivado: caja.querySelector('[data-ds-wizard="atras"]').disabled,
    adelanteVisible: !caja.querySelector('[data-ds-wizard="adelante"]').classList.contains('d-none'),
    guardarVisible: guardar ? !guardar.classList.contains('d-none') : null,
  }
})

/*==============  1. Arranca en el primer paso  ==============*/
const inicio = await estado()
af('arranca en el paso 1 con su barra de avance',
   /Paso 1 de 5/.test(inicio.paso) && inicio.anchoBarra === '20%',
   inicio.paso + ', barra ' + inicio.anchoBarra)
af('no deja retroceder desde el primero', inicio.atrasDesactivado === true)
af('el botón Guardar no está en los pasos intermedios',
   inicio.guardarVisible === false, 'visible: ' + inicio.guardarVisible)

/*==============  2. No se avanza con el paso incompleto  ==============*/
await p.evaluate(() => document.querySelector('[data-ds-wizard="adelante"]').click())
await p.waitForTimeout(500)
const bloqueado = await estado()
af('no avanza con campos obligatorios vacíos',
   bloqueado.panel === 'informacionp', 'quedó en ' + bloqueado.panel)

/*==============  3. Rellenando, sí avanza  ==============*/
await p.evaluate(() => {
  const caja = document.querySelector('[data-ds-wizard]')
  const panel = caja.querySelector('#informacionp')
  panel.querySelectorAll('input[required], select[required], textarea[required]').forEach(c => {
    if (c.type === 'radio' || c.type === 'checkbox') { c.checked = true; return }
    if (c.tagName === 'SELECT') { if (c.options.length > 1) c.selectedIndex = 1; return }
    if (c.type === 'date') { c.value = '2015-05-10'; return }
    if (c.type === 'number') { c.value = '7'; return }
    c.value = 'Prueba'
  })
})
await p.evaluate(() => document.querySelector('[data-ds-wizard="adelante"]').click())
await p.waitForTimeout(500)
const avanzo = await estado()
af('con el paso completo, avanza',
   /Paso 2 de 5/.test(avanzo.paso), avanzo.paso + ' · ' + avanzo.panel)
af('y la barra progresa', avanzo.anchoBarra === '40%', avanzo.anchoBarra)

/*==============  4. Hacia atrás, siempre  ==============*/
await p.evaluate(() => document.querySelector('[data-ds-wizard="atras"]').click())
await p.waitForTimeout(400)
const volvio = await estado()
af('se puede volver atrás sin condiciones', /Paso 1 de 5/.test(volvio.paso), volvio.paso)

/*==============  5. Guardar sólo en el último paso  ==============*/
await p.evaluate(() => {
  const enlaces = [...document.querySelectorAll('[data-ds-wizard] [data-bs-toggle="tab"]')]
  enlaces[enlaces.length - 1].click()
})
await p.waitForTimeout(600)
const ultimo = await estado()
af('en el último paso aparece Guardar y desaparece Siguiente',
   ultimo.guardarVisible === true && ultimo.adelanteVisible === false,
   'guardar: ' + ultimo.guardarVisible + ', siguiente: ' + ultimo.adelanteVisible)

/*==============  6. La red de seguridad  ==============*/
/* Se vacia un obligatorio de la primera pestaña estando en la ultima, y se
   envia. Antes: silencio absoluto. Ahora tiene que llevarnos al paso 1. */
const rescate = await p.evaluate(async () => {
  const caja = document.querySelector('[data-ds-wizard]')
  const c = caja.querySelector('input[name], select[name], textarea[name]')
  const f = c.form
  const obligatorio = caja.querySelector('#informacionp [name="alumno_identificacion"]')
  obligatorio.value = ''
  const antes = caja.querySelector('.tab-pane.active').id
  ;[...f.elements].find(e => e.type === 'submit').click()
  await new Promise(r => setTimeout(r, 900))
  return {
    antes,
    despues: caja.querySelector('.tab-pane.active').id,
    enfocado: document.activeElement ? document.activeElement.name : '(ninguno)',
  }
})
af('al enviar incompleto, lleva al paso del campo que falta',
   rescate.despues === 'informacionp',
   'de ' + rescate.antes + ' a ' + rescate.despues + ', foco en ' + rescate.enfocado)

/*==============  7. Con todo relleno, el asistente NO estorba  ==============*/
/* Se completa lo que falta y se pulsa Guardar. No se llega a crear ningún
   alumno: ajax.js pide confirmación antes de enviar, y ahí se cancela. Basta
   para saber que el camino sigue abierto —que es justo lo que el asistente
   podría haber roto— sin dejar por medio los datos de un menor. */
await p.evaluate(() => {
  const caja = document.querySelector('[data-ds-wizard]')
  caja.querySelector('#informacionp [name="alumno_identificacion"]').value = '1104015282'
})

const abierto = await p.evaluate(async () => {
  const caja = document.querySelector('[data-ds-wizard]')
  const f = caja.querySelector('input[name], select[name], textarea[name]').form
  const valido = f.checkValidity()
  const b = [...f.elements].find(e => e.type === 'submit')
  b.click()
  await new Promise(r => setTimeout(r, 1400))
  return {
    valido,
    confirmacion: (document.querySelector('.swal2-popup') || {}).innerText || '(ninguna)',
  }
})

af('con el formulario válido, el envío sigue su curso',
   abierto.valido && /realizar/i.test(abierto.confirmacion),
   'válido: ' + abierto.valido + ' · ' + abierto.confirmacion.split('\n')[0])

/* Se cancela: no se crea nada. */
await p.click('.swal2-cancel').catch(() => {})
await p.waitForTimeout(700)
af('y al cancelar no queda nada creado',
   !(await p.evaluate(() => !!document.querySelector('.swal2-popup'))),
   'aviso cerrado')

console.log('\nfallos: ' + fallos)
await nav.close()
process.exit(fallos === 0 ? 0 : 1)
