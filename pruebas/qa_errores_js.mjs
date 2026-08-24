/*
| Excepciones de JavaScript en las vistas. DE VERDAD, esta vez.
|
| POR QUE EXISTE ESTA SUITE
|
| Varias suites afirmaban «sin errores de JavaScript» apoyandose en el
| evento pageerror del navegador automatizado. Se comprobo inyectando un
| error a proposito:
|
|     new NoExisteEstaCosa()   →  la sonda no vio NADA
|
| Ni pageerror, ni console.error, ni un throw dentro de un setTimeout. Es
| decir: todas esas comprobaciones llevaban tiempo pasando en vacio. El
| ejemplo que lo destapo fue real —«ReferenceError: DataTable is not
| defined» en dos vistas— y ninguna suite lo delataba.
|
| LO QUE SI FUNCIONA
|
| Runtime.exceptionThrown, del protocolo del propio motor. Es la fuente de
| la que sale la consola del navegador, asi que no hay intermediario que
| se pierda el aviso. Se comprueba ademas que la sonda sirve: se provoca un
| error y se exige verlo. Una sonda que no se prueba a si misma es
| exactamente el problema que se esta corrigiendo.
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
  representanteFLPD: '2', empleadoIE: '1', asistenciaHorarioLista: '2',
  asistenciaVerHorario: '2', asistenciaAlumno: '2', buscarAsistencia: '2',
  jugadorNew: '2/1', asistenciaHorarioJugador: '2', equipoList: '2',
  jugadorLista: '2/1',
}

const NO_ALCANZABLES = {
  empleadoAsistenciasDetalle: 'sólo se llega por POST',
  empleadoDescargaEgreso:     'la tabla empleado_egreso está vacía',
  empleadoEgresoUpdate:       'la tabla empleado_egreso está vacía',
}

const vistas = readdirSync(DIR)
  .filter(f => f.endsWith('-view.php'))
  .filter(f => /<html[\s>]/i.test(readFileSync(DIR + '/' + f, 'utf8')))
  .map(f => f.replace('-view.php', ''))
  .filter(v => !NO_ALCANZABLES[v])

const nav = await chromium.launch({ headless: true, channel: 'chromium' })
const ctx = await nav.newContext({ viewport: { width: 1440, height: 900 } })
await ctx.addCookies([{ name: 'DigiSportsBasketball', value: 'dsqaui0000000000000',
                        domain: 'localhost', path: '/' }])
const p = await ctx.newPage()

const cdp = await ctx.newCDPSession(p)
await cdp.send('Runtime.enable')
let excepciones = []
cdp.on('Runtime.exceptionThrown', e => {
  const d = e.exceptionDetails
  const txt = ((d.exception && d.exception.description) || d.text || '').split('\n')[0]
  excepciones.push(txt.slice(0, 88))
})

let fallos = 0
const af = (t, ok, d = '') => {
  console.log('  ' + t.padEnd(50) + (ok ? 'OK' : 'FALLA') + (d ? '  (' + d + ')' : ''))
  if (!ok) fallos++
}

/*==============  0. Que la sonda sirva  ==============*/
await p.goto(BASE + 'dashboard/', { waitUntil: 'networkidle' })
excepciones = []
await p.evaluate(() => {
  const s = document.createElement('script')
  s.textContent = 'estaCosaNoExisteDeVerdad.hacerAlgo()'
  document.body.appendChild(s)
})
await p.waitForTimeout(400)
af('la sonda detecta un error provocado', excepciones.length > 0,
   excepciones[0] || 'NO LO VE: la suite no valdría de nada')

if (excepciones.length === 0) {
  console.log('\nfallos: ' + fallos)
  await nav.close()
  process.exit(1)
}

/*==============  1. El barrido  ==============*/
console.log('\n  vistas a revisar: ' + vistas.length)
for (const [v, m] of Object.entries(NO_ALCANZABLES)) {
  console.log('  · ' + v + ': no se revisa — ' + m)
}

const conError = []

for (const vista of vistas) {
  const url = BASE + vista + '/' + (CON_ID[vista] ? CON_ID[vista] + '/' : '')
  excepciones = []
  const r = await p.goto(url, { waitUntil: 'networkidle' }).catch(() => null)
  if (!r || r.status() !== 200) { continue }
  await p.waitForTimeout(800)

  const llegada = await p.evaluate(() => location.pathname)
  if (!llegada.includes(vista)) { continue }   /* redirigió: se mide en otra suite */

  if (excepciones.length) {
    conError.push(vista + ': ' + [...new Set(excepciones)].slice(0, 2).join(' | '))
  }
}

if (conError.length) {
  console.log('\n  vistas con excepciones:')
  for (const x of conError) { console.log('    ' + x) }
}

af('\n  ninguna vista lanza excepciones', conError.length === 0,
   conError.length + ' de ' + vistas.length)

console.log('\nfallos: ' + fallos)
await nav.close()
process.exit(fallos === 0 ? 0 : 1)
