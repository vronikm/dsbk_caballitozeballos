/*
| Todos los iconos del sistema dibujan un glifo.
|
| POR QUE ESTA SUITE EXISTE
|
| Un icono mal escrito no da ningun error: el navegador deja un hueco y
| sigue. Al cambiar de version de la libreria eso importa mucho, porque los
| nombres cambian —fa-times paso a fa-xmark, fa-adjust a fa-circle-half-
| stroke— y aunque Font Awesome 6 mantiene alias para los de la 5, no los
| mantiene para todos.
|
| Fiarse de la tabla de equivalencias es fiarse de una lista ajena. Aqui se
| mide: se recorren las vistas y se le pregunta al navegador, icono por
| icono, si esa clase acaba produciendo un caracter.
|
| COMO SE SABE QUE DIBUJA
|
| Dos cosas a la vez, porque ninguna basta sola:
|
|   la familia   tiene que ser la de Font Awesome. Si la hoja no cargara,
|                seria la tipografia por defecto y el pseudoelemento estaria
|                igualmente vacio.
|   el glifo     el pseudoelemento ::before tiene que tener contenido. Si la
|                clase no existiera en esta version, no habria ninguno.
|
| GUARDA UNA FOTO PARA COMPARAR
|
| Con «guardar» escribe el resultado en un archivo. Asi se puede medir antes
| de cambiar de version y despues, y ver exactamente que icono se perdio por
| el camino en vez de suponerlo.
|
| Uso: qa_iconos.mjs [guardar <archivo>] [comparar <archivo>]
*/
import { createRequire } from 'node:module'
import { readdirSync, readFileSync, writeFileSync, existsSync } from 'node:fs'
const EXT = 'C:/Users/dbafaces/.vscode/extensions/'
const RAIZ = EXT + readdirSync(EXT).filter(d => d.startsWith('danielsanmedium.dscodegpt-')).sort().pop() + '/standalone/'
const { chromium } = createRequire(RAIZ)('patchright')

const DIR  = 'c:/wamp64/www/barcelona/ds_basketball/app/views/content'
const BASE = 'http://localhost/barcelona/ds_basketball/'

const CON_ID = {
  pagosNew: '2', pagosUpdate: '1', pagosRecibo: '1', pagosPendiente: '1',
  pagosDescuento: '2', pagospendienteRecibo: '2', pagospendienteUpdate: '1',
  facturasNew: '6', alumnoProfile: '2', alumnoUpdate: '2',
  representanteProfile: '2', representanteUpdate: '2', representanteVinc: '2',
  representanteFLPD: '2', empleadoIE: '1', asistenciaHorarioLista: '2',
  asistenciaVerHorario: '2', asistenciaAlumno: '2', buscarAsistencia: '2',
  jugadorNew: '2/1', asistenciaHorarioJugador: '2', equipoList: '2',
  jugadorLista: '2/1',
}
const NO_ALCANZABLES = ['empleadoAsistenciasDetalle', 'empleadoDescargaEgreso', 'empleadoEgresoUpdate']

const modo    = process.argv[2] || ''
const archivo = process.argv[3] || ''

const vistas = readdirSync(DIR)
  .filter(f => f.endsWith('-view.php'))
  .filter(f => /<html[\s>]/i.test(readFileSync(DIR + '/' + f, 'utf8')))
  .map(f => f.replace('-view.php', ''))
  .filter(v => !NO_ALCANZABLES.includes(v))

const nav = await chromium.launch({ headless: true, channel: 'chromium' })
const ctx = await nav.newContext({ viewport: { width: 1500, height: 950 } })
await ctx.addCookies([{ name: 'DigiSportsBasketball', value: 'dsqaui0000000000000',
                        domain: 'localhost', path: '/' }])
const p = await ctx.newPage()

let fallos = 0
const af = (t, ok, d = '') => {
  console.log('  ' + t.padEnd(50) + (ok ? 'OK' : 'FALLA') + (d ? '  (' + d + ')' : ''))
  if (!ok) fallos++
}

const mudos = new Map()      /* clase → vistas donde no dibuja */
const vivos = new Set()      /* clases que dibujan en algún sitio */
let revisados = 0

for (const vista of vistas) {
  const url = BASE + vista + '/' + (CON_ID[vista] ? CON_ID[vista] + '/' : '')
  const r = await p.goto(url, { waitUntil: 'networkidle' }).catch(() => null)
  if (!r || r.status() !== 200) { continue }
  await p.waitForTimeout(350)

  const llegada = await p.evaluate(() => location.pathname)
  if (!llegada.includes(vista)) { continue }

  /* Se revelan las pestañas y los modales antes de medir.
     Sin esto sólo se miden los iconos de la pestaña activa: 65 de las 126
     clases que hay en el código. Un icono que sólo aparece en la ficha
     médica o dentro de un modal quedaría sin comprobar, que es justo donde
     un cambio de versión pasa desapercibido. Se toca sólo la copia que se
     está midiendo; la página se recarga en la vista siguiente. */
  await p.evaluate(() => {
    document.querySelectorAll('.tab-pane').forEach(el => {
      el.classList.add('active', 'show')
      el.style.display = 'block'
    })
    document.querySelectorAll('.modal, .collapse, .dropdown-menu').forEach(el => {
      el.classList.add('show')
      el.style.display = 'block'
      el.style.opacity = '1'
    })
  })
  await p.waitForTimeout(250)

  const iconos = await p.evaluate(() => {
    const salida = []
    document.querySelectorAll('i[class*="fa-"], span[class*="fa-"]').forEach(el => {
      /* La clase del icono, no los modificadores de tamaño ni el prefijo. */
      const clase = [...el.classList].find(c =>
        c.startsWith('fa-') &&
        !/^fa-(fw|spin|pulse|lg|xs|sm|[0-9]+x|border|pull-|stack|inverse|rotate|flip|beat|fade|shake|bounce|li|ul|layers)/.test(c))
      if (!clase) { return }
      if (el.getBoundingClientRect().width === 0 && el.offsetParent === null) {
        /* Oculto: no se puede medir su glifo de forma fiable. */
        return
      }
      const est = getComputedStyle(el, '::before')
      const dibuja = /Font Awesome/i.test(est.fontFamily)
                  && est.content !== 'none' && est.content !== '""' && est.content !== ''
      salida.push([clase, dibuja])
    })
    return salida
  })

  revisados += iconos.length
  for (const [clase, dibuja] of iconos) {
    if (dibuja) { vivos.add(clase) }
    else {
      if (!mudos.has(clase)) { mudos.set(clase, new Set()) }
      mudos.get(clase).add(vista)
    }
  }
}

/* Un icono que dibuja en una vista y no en otra suele estar oculto en la
   segunda: solo cuenta como mudo si NO dibuja en ninguna parte. */
const rotos = [...mudos.keys()].filter(c => !vivos.has(c)).sort()

console.log('  vistas revisadas: ' + vistas.length)
console.log('  iconos medidos:   ' + revisados + ' (' + vivos.size + ' clases distintas dibujan)')

if (rotos.length) {
  console.log('\n  no dibujan en ninguna vista:')
  for (const c of rotos) { console.log('    ' + c + '  →  ' + [...mudos.get(c)].slice(0, 3).join(' ')) }
}

af('\n  todos los iconos dibujan un glifo', rotos.length === 0, rotos.length + ' mudos')

/*----------  Guardar o comparar  ----------*/
if (modo === 'guardar' && archivo) {
  writeFileSync(archivo, JSON.stringify({ vivos: [...vivos].sort(), rotos }, null, 1))
  console.log('  foto guardada en ' + archivo)
}

if (modo === 'comparar' && archivo && existsSync(archivo)) {
  const antes = JSON.parse(readFileSync(archivo, 'utf8'))
  const perdidos = antes.vivos.filter(c => !vivos.has(c))
  const nuevos   = [...vivos].filter(c => !antes.vivos.includes(c))
  console.log('\n  comparación con ' + archivo + ':')
  console.log('    antes dibujaban ' + antes.vivos.length + ', ahora ' + vivos.size)
  af('  ningún icono dejó de dibujarse', perdidos.length === 0,
     perdidos.slice(0, 8).join(' '))
  if (nuevos.length) { console.log('    aparecen ' + nuevos.length + ' que antes no: ' + nuevos.slice(0, 5).join(' ')) }
}

console.log('\nfallos: ' + fallos)
await nav.close()
process.exit(fallos === 0 ? 0 : 1)
