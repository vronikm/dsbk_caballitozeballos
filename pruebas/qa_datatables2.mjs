/*
| DataTables 2.x en las vistas que lo usan.
|
| QUE CAMBIO Y POR QUE HAY QUE MIRARLO ENTERO
|
| Se paso de la 1.11 a la 2.1.8, que ya no necesita jQuery. La opcion de
| disposicion cambio por completo: donde antes se colgaba el grupo de
| botones a mano del DOM
|
|     .buttons().container().appendTo('#tabla_wrapper .col-md-6:eq(0)')
|
| —jQuery puro, con un selector :eq() que no es CSS y apuntando a un marcado
| que la version 2 ya no genera— ahora se declara:
|
|     layout: { topStart: 'buttons' }
|
| Son 36 vistas. Una tabla que no arranca no da error: se ve la tabla en
| crudo, sin buscador ni paginacion, y pasa por buena a simple vista. Por
| eso se comprueba el EFECTO en cada una.
|
| QUE SE MIRA
|
|   que arranque      la clase que pone la propia libreria al inicializar
|   que filtre        se escribe en el buscador y se cuentan las filas
|   los botones       donde estaban configurados, tienen que verse
|   sin errores       ni de consola ni recursos caidos
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
import { readdirSync, readFileSync } from 'node:fs'
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
  empleadoIE: '1', asistenciaHorarioLista: '2', asistenciaVerHorario: '2',
  asistenciaAlumno: '2', buscarAsistencia: '2', jugadorNew: '2/1',
  asistenciaHorarioJugador: '2',
}

/* Solo se llega por POST: no se puede comprobar por URL. */
const NO_ALCANZABLES = { empleadoAsistenciasDetalle: 'sólo se llega por POST' }

const conTabla = readdirSync(DIR)
  .filter(f => f.endsWith('-view.php'))
  .filter(f => {
    const t = readFileSync(DIR + '/' + f, 'utf8')
    return /new DataTable\s*\(/.test(t) || /\.DataTable\s*\(/.test(t)
  })
  .map(f => f.replace('-view.php', ''))
  .filter(v => !NO_ALCANZABLES[v])

/* Cuales declaran botones: solo a esas se les exige verlos. */
const conBotones = new Set(conTabla.filter(v =>
  /["']?buttons["']?\s*:/.test(readFileSync(DIR + '/' + v + '-view.php', 'utf8'))))

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

console.log('  vistas con tabla: ' + conTabla.length + ' (' + conBotones.size + ' con botones)')
for (const [v, m] of Object.entries(NO_ALCANZABLES)) {
  console.log('  · ' + v + ': no se comprueba — ' + m)
}

const problemas = []
let sinIniciar = 0, sinFiltrar = 0, sinBotones = 0

for (const vista of conTabla) {
  const errores = []
  const oyE = e => errores.push(e.message.slice(0, 70))
  const oyC = m => { if (m.type() === 'error') errores.push(m.text().slice(0, 70)) }
  const oyR = r => { if (r.status() >= 400) errores.push(r.status() + ' ' + r.url().split('/').pop()) }
  p.on('pageerror', oyE); p.on('console', oyC); p.on('response', oyR)

  const url = BASE + vista + '/' + (CON_ID[vista] ? CON_ID[vista] + '/' : '')
  const r = await p.goto(url, { waitUntil: 'networkidle' }).catch(() => null)

  const quitar = () => { p.off('pageerror', oyE); p.off('console', oyC); p.off('response', oyR) }

  if (!r || r.status() !== 200) { quitar(); continue }
  await p.waitForTimeout(700)

  const llegada = await p.evaluate(() => location.pathname)
  if (!llegada.includes(vista)) { quitar(); continue }   /* redirigió */

  const estado = await p.evaluate(() => ({
    /* La clase la pone la propia librería al inicializar. */
    iniciadas: document.querySelectorAll('table.dataTable').length,
    /* Solo el de la tabla: desde que la barra superior tiene su propio
       buscador, un input[type=search] suelto casa con los dos. */
    buscadores: document.querySelectorAll('.dt-container .dt-search input').length,
    botones: document.querySelectorAll('.dt-buttons .dt-button, .dt-buttons button').length,
    filas: document.querySelectorAll('table.dataTable tbody tr').length,
  }))

  const por = []
  if (estado.iniciadas === 0) { por.push('la tabla no arranca'); sinIniciar++ }
  if (conBotones.has(vista) && estado.botones === 0) { por.push('sin botones'); sinBotones++ }

  /* Que filtre de verdad: se escribe algo imposible y no debe quedar nada. */
  if (estado.iniciadas > 0 && estado.buscadores > 0 && estado.filas > 0) {
    await p.fill('.dt-container .dt-search input', 'zzqqxx-imposible').catch(() => {})
    await p.waitForTimeout(500)
    const tras = await p.evaluate(() =>
      [...document.querySelectorAll('table.dataTable tbody tr')]
        .filter(t => {
          if (t.offsetParent === null) return false
          /* Una fila con una celda que abarca toda la tabla es un aviso de
             «no hay nada», no un dato. Cada vista redacta el suyo —una
             decia «No hay inscripciones en linea pendientes»— asi que
             mirar el texto no vale; el colspan si. */
          if (t.querySelector('td[colspan]')) return false
          return !/no hay datos|no se encontraron|no matching/i.test(t.innerText)
        }).length)
    if (tras !== 0) { por.push('el buscador no filtra (' + tras + ' filas)'); sinFiltrar++ }
  }

  if (errores.length) { por.push(errores[0]) }
  if (por.length) { problemas.push(vista + ': ' + por.join(' · ')) }

  quitar()
}

if (problemas.length) {
  console.log('\n  con problema:')
  for (const x of problemas.slice(0, 12)) { console.log('    ' + x) }
  if (problemas.length > 12) { console.log('    … y ' + (problemas.length - 12) + ' más') }
}

af('\n  todas las tablas arrancan', sinIniciar === 0, sinIniciar + ' sin arrancar')
af('  todas filtran al buscar', sinFiltrar === 0, sinFiltrar + ' sin filtrar')
af('  las que declaran botones los muestran', sinBotones === 0, sinBotones + ' sin botones')
af('  sin errores ni recursos caídos', problemas.length === 0, problemas.length + ' vistas')

console.log('\nfallos: ' + fallos)
await nav.close()
process.exit(fallos === 0 ? 0 : 1)
