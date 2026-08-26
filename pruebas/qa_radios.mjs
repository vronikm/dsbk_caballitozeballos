/*
| Los grupos de opciones se pueden cambiar de verdad.
|
| EL FALLO QUE ORIGINA ESTA PRUEBA
|
| Bootstrap 5 da a .form-check-input «float: left; margin-left: -1.5em», que
| mete el control en el hueco que .form-check reserva a su izquierda. Con DOS
| inputs dentro del MISMO .form-check, los dos aterrizan en ese hueco y se
| apilan: medido, ambos daban 16x16 en la misma posición.
|
| Consecuencia: sólo el ULTIMO radio del grupo era clicable, y el círculo
| visible quedaba junto a la etiqueta equivocada. En representanteUpdate el
| punto azul aparecía junto a «Masculino» siendo el input de «Femenino».
| Estaba en 7 vistas y 46 controles, incluidos el alta y la edición de alumno.
|
| POR QUE SE PULSA Y NO SE MIRA
|
| Un radio apilado ESTÁ en el DOM, está visible, no está deshabilitado y
| responde a .checked. Todo lo que se puede comprobar leyendo el DOM da
| correcto. Lo único que lo delata es intentar cambiarlo como lo haría una
| persona: pulsar A, comprobar A; pulsar B, comprobar B.
|
| La comprobación estructural va aparte y cubre TODAS las vistas, porque
| pulsar en todas sería lento y varias necesitan un id en la URL.
*/
import { createRequire } from 'node:module'
import { readdirSync, readFileSync } from 'node:fs'
const EXT = 'C:/Users/dbafaces/.vscode/extensions/'
const RAIZ = EXT + readdirSync(EXT).filter(d => d.startsWith('danielsanmedium.dscodegpt-')).sort().pop() + '/standalone/'
const { chromium } = createRequire(RAIZ)('patchright')

let fallos = 0
const af = (t, ok, d = '') => {
  console.log('  ' + t.padEnd(52) + (ok ? 'OK' : 'FALLA') + (d ? '  (' + d + ')' : ''))
  if (!ok) fallos++
}

/*==============  1. Estructura, en todas las vistas  ==============*/
const DIRS = [
  'c:/wamp64/www/barcelona/ds_basketball/app/views/content',
  'c:/wamp64/www/barcelona/ds_league/views',
  'c:/wamp64/www/barcelona/ds_arena/views',
  'c:/wamp64/www/barcelona/ds_core/admin/views',
]
const apilados = []
let revisadas = 0
for (const d of DIRS) {
  let archivos
  try { archivos = readdirSync(d).filter(f => f.endsWith('.php')) } catch { continue }
  for (const f of archivos) {
    revisadas++
    const t = readFileSync(d + '/' + f, 'utf8')
    const bloques = t.match(/<div[^>]*class="[^"]*\bform-check\b[^"]*"[^>]*>([\s\S]*?)<\/div>/g) || []
    for (const b of bloques) {
      const n = (b.match(/<input[^>]*type="(radio|checkbox)"/gi) || []).length
      if (n > 1) apilados.push(f + ' (' + n + ')')
    }
  }
}
af('ningún .form-check lleva más de un control', apilados.length === 0,
   apilados.length ? apilados.slice(0, 5).join(' · ') : revisadas + ' vistas revisadas')

/*==============  2. Comportamiento, pulsando  ==============*/
const nav = await chromium.launch({ headless: true, channel: 'chromium' })
const ctx = await nav.newContext({ viewport: { width: 1500, height: 950 } })
await ctx.addCookies([{ name: 'DigiSportsBasketball', value: 'dsqaui0000000000000',
                        domain: 'localhost', path: '/' }])
const p = await ctx.newPage()

const alterna = async (idA, idB, nombre) => {
  const existe = await p.evaluate(([a, b]) =>
    !!document.getElementById(a) && !!document.getElementById(b), [idA, idB])
  if (!existe) { af(nombre + ': el grupo existe', false, 'no se encontraron los dos inputs'); return }

  await p.click('#' + idA)
  const trasA = await p.evaluate(([a, b]) => ({
    a: document.getElementById(a).checked, b: document.getElementById(b).checked }), [idA, idB])
  await p.click('#' + idB)
  const trasB = await p.evaluate(([a, b]) => ({
    a: document.getElementById(a).checked, b: document.getElementById(b).checked }), [idA, idB])

  af(nombre + ': alterna al pulsar',
     trasA.a && !trasA.b && !trasB.a && trasB.b,
     'A→' + (trasA.a ? 'sí' : 'NO') + ' · B→' + (trasB.b ? 'sí' : 'NO'))

  /* Y que no ocupen el mismo punto, que es la causa raíz. */
  const solapan = await p.evaluate(([a, b]) => {
    const ra = document.getElementById(a).getBoundingClientRect()
    const rb = document.getElementById(b).getBoundingClientRect()
    return Math.abs(ra.left - rb.left) < 4 && Math.abs(ra.top - rb.top) < 4
  }, [idA, idB])
  af(nombre + ': los dos controles no se solapan', !solapan)
}

const BASE = 'http://localhost/barcelona/ds_basketball/'

await p.goto(BASE + 'representanteUpdate/2/', { waitUntil: 'networkidle' })
await p.waitForTimeout(400)
await alterna('repre_sexoM', 'repre_sexoF', 'Género del representante')
await alterna('repre_facturaS', 'repre_facturaN', 'Requiere factura')

await p.goto(BASE + 'alumnoNew/', { waitUntil: 'networkidle' })
await p.waitForTimeout(400)
await alterna('alumno_generoM', 'alumno_generoF', 'Género del alumno')
await alterna('alumno_hermanosSi', 'alumno_hermanosNo', 'Tiene hermanos')


/*==============  3. Los desplegables anuncian que se despliegan  ==============*/
/*
| En Bootstrap 5 el triangulito lo dibuja .form-select como imagen de fondo.
| Con .form-control un <select> se pinta igual que una caja de texto: parece
| que hay que escribir en él. Estaban así los 136 desplegables del sistema.
|
| Se comprueba sobre el marcado —cubre las 61 vistas— y además se mide en una
| página real que el fondo está puesto de verdad, porque la clase correcta y
| la hoja cargada son dos cosas distintas.
|
| Quedan fuera los que gobierna select2 (js-buscador, custom-select2): esa
| librería oculta el <select> y dibuja el suyo, así que la clase no pinta.
*/
const leerVistas = () => {
  const out = []
  for (const d of DIRS) {
    let archivos
    try { archivos = readdirSync(d).filter(f => f.endsWith('.php')) } catch { continue }
    for (const f of archivos) out.push([f, readFileSync(d + '/' + f, 'utf8')])
  }
  return out
}
const VISTAS = leerVistas()

const malSelect = []
for (const [f, t] of VISTAS) {
  for (const et of t.match(/<select\b[^>]*>/gi) || []) {
    if (/js-buscador|custom-select2|swal2-input/.test(et)) continue
    if (/\bform-control\b/.test(et)) malSelect.push(f)
  }
}
af('ningún <select> usa form-control en vez de form-select',
   malSelect.length === 0,
   malSelect.length ? [...new Set(malSelect)].slice(0, 5).join(' · ') : VISTAS.length + ' vistas')

/*==============  4. Nada de puntos como separador  ==============*/
/*
| Había quince <label>.</label> usados para reservar la línea de la etiqueta y
| alinear un botón. Además de verse el punto, llevaban for="alumno_sedeid", así
| que le daban al desplegable de Sede una SEGUNDA etiqueta que un lector de
| pantalla lee como «punto».
*/
const puntos = VISTAS.filter(([, t]) => /<label[^>]*>\s*\.\s*<\/label>/.test(t)).map(([f]) => f)
af('ninguna etiqueta es un punto de relleno', puntos.length === 0,
   puntos.length ? puntos.slice(0, 4).join(' · ') : VISTAS.length + ' vistas')

/*==============  5. Y en una página de verdad  ==============*/
await p.goto(BASE + 'alumnoList/', { waitUntil: 'networkidle' })
await p.waitForTimeout(400)
const sede = await p.evaluate(() => {
  const s = document.getElementById('alumno_sedeid')
  if (!s) return null
  return {
    triangulo: getComputedStyle(s).backgroundImage !== 'none',
    etiquetas: [...document.querySelectorAll('label[for="alumno_sedeid"]')].map(l => l.textContent.trim()),
    puntos: [...document.querySelectorAll('label, span')]
      .filter(e => e.offsetParent !== null && e.textContent.trim() === '.').length,
  }
})
af('el desplegable de Sede muestra su triángulo', !!sede && sede.triangulo)
af('y tiene una sola etiqueta, la suya',
   !!sede && sede.etiquetas.length === 1 && sede.etiquetas[0] === 'Sede',
   sede ? sede.etiquetas.join(' | ') : '—')
af('no queda ningún punto suelto en pantalla', !!sede && sede.puntos === 0,
   sede ? sede.puntos + ' puntos' : '—')

/*==============  6. El botón de buscar, a la altura de los campos  ==============*/
/*
| Se apilaban dos apaños. El botón llevaba class="form-control btn ..." —el
| form-control estaba por el ancho, y de paso le daba el alto de una caja de
| texto, 40 px frente a 38—; y encima se reservaba la línea de la etiqueta con
| un hueco que llevaba .form-label y sus 8 px de margen, que las etiquetas de
| estas vistas no tienen. El botón quedaba 8 px por debajo y 2 px más alto.
|
| Ahora la columna alinea su contenido al final. Se mide la CAJA, no la clase:
| que el borde inferior del botón coincida con el de los campos.
*/
await p.goto(BASE + 'alumnoList/', { waitUntil: 'networkidle' })
await p.waitForTimeout(400)
const caja = await p.evaluate(() => {
  const r = e => { const b = e.getBoundingClientRect(); return { t: Math.round(b.top), b: Math.round(b.bottom) } }
  const campo = document.getElementById('alumno_identificacion')
  const boton = document.querySelector('button[type="submit"].btn-primary')
  if (!campo || !boton) return null
  return { campo: r(campo), boton: r(boton) }
})
af('el botón Buscar comparte línea base con los campos',
   !!caja && caja.campo.b === caja.boton.b,
   caja ? `campo ${caja.campo.t}-${caja.campo.b} · botón ${caja.boton.t}-${caja.boton.b}` : '—')
af('y tiene la misma altura',
   !!caja && (caja.campo.b - caja.campo.t) === (caja.boton.b - caja.boton.t),
   caja ? `${caja.campo.b - caja.campo.t} vs ${caja.boton.b - caja.boton.t} px` : '—')

/*==============  7. Los botones de tabla, del tamaño de los de cabecera  ==============*/
/*
| Usaban btn-xs, que es de Bootstrap 3 / AdminLTE 2. En Bootstrap 5 no existe
| y NO ESTABA DEFINIDA en ninguna hoja del proyecto: los 88 sitios que la
| escribían no aplicaban nada y sus botones tomaban el tamaño por defecto,
| 38 px, más grandes que el «Nuevo Representante» de la cabecera —31 px—.
|
| Una clase inexistente no protesta: simplemente no hace nada, y el resultado
| pasa por decisión de diseño. Por eso se comprueba de dos formas: que la
| clase no vuelva, y que las alturas COINCIDAN de verdad en la página.
*/
const conXs = VISTAS.filter(([, t]) => /\bbtn-xs\b/.test(t)).map(([f]) => f)
af('ninguna vista usa btn-xs (no existe en Bootstrap 5)', conXs.length === 0,
   conXs.length ? conXs.slice(0, 4).join(' · ') : VISTAS.length + ' vistas')

await p.goto(BASE + 'representanteList/', { waitUntil: 'networkidle' })
await p.waitForTimeout(700)
const alturas = await p.evaluate(() => {
  const alto = e => Math.round(e.getBoundingClientRect().height)
  const cab = [...document.querySelectorAll('.card-tools .btn')].filter(e => e.offsetParent && e.textContent.trim())
  const tab = [...document.querySelectorAll('table tbody .btn')].filter(e => e.offsetParent)
  if (!cab.length || !tab.length) return null
  return { cabecera: alto(cab[0]), distintas: [...new Set(tab.map(alto))], cuantos: tab.length }
})
af('los botones de la tabla miden todos lo mismo',
   !!alturas && alturas.distintas.length === 1,
   alturas ? alturas.cuantos + ' botones · alturas ' + alturas.distintas.join('/') : '—')
af('y coinciden con el botón de la cabecera',
   !!alturas && alturas.distintas.length === 1 && alturas.distintas[0] === alturas.cabecera,
   alturas ? 'tabla ' + alturas.distintas[0] + 'px · cabecera ' + alturas.cabecera + 'px' : '—')
console.log('\nfallos: ' + fallos)
await nav.close()
process.exit(fallos === 0 ? 0 : 1)
