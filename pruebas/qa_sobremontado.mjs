/*
| Ningun elemento puede montarse encima de otro.
|
| EL FALLO QUE MOTIVA ESTA SUITE
|
| En la pantalla de torneos la foto se montaba encima de la tabla, y los
| botones encima del buscador. La causa no era el ancho —eso ya lo vigila
| qa_responsive— sino ALTURAS FIJAS EN PIXELES sobre cajas cuyo contenido
| crece:
|
|     <div class="row" style="height: 187px;">
|
| Medido: ese bloque necesita 230 px en escritorio y 900 EN UN MOVIL, porque
| las columnas se apilan. Todo lo que no cabe sale de la caja y se dibuja
| encima de lo siguiente. La pagina no se desborda a lo ancho, asi que la
| otra suite la daba por buena.
|
| COMO SE DETECTA
|
| Una caja con alto fijo cuyo scrollHeight supera su clientHeight tiene
| contenido fuera. Es objetivo y no depende de mirar la pantalla.
|
| Se prueba a CUATRO anchos porque el problema empeora al estrechar: lo que
| en escritorio sobresale cuarenta pixeles, en un movil sobresale
| setecientos.
*/
import { createRequire } from 'node:module'
import { readdirSync, readFileSync } from 'node:fs'
const EXT = 'C:/Users/dbafaces/.vscode/extensions/'
const RAIZ = EXT + readdirSync(EXT).filter(d => d.startsWith('danielsanmedium.dscodegpt-')).sort().pop() + '/standalone/'
const { chromium } = createRequire(RAIZ)('patchright')

const DIR  = 'c:/wamp64/www/barcelona/ds_basketball/app/views/content'
const BASE = 'http://localhost/barcelona/ds_basketball/'

/* Las que piden identificador; sin el se mide otra pantalla. */
const CON_ID = {
  pagosNew: '2', pagosUpdate: '1', pagosRecibo: '1', pagosPendiente: '1',
  pagosDescuento: '2', pagosUniformeUpdate: '1', pagospendienteRecibo: '2',
  pagospendienteUpdate: '1', facturasNew: '6', alumnoProfile: '2',
  alumnoUpdate: '2', representanteProfile: '2', representanteUpdate: '2',
  representanteVinc: '2', representanteFLPD: '2', empleadoIE: '1',
  asistenciaHorarioLista: '2', asistenciaVerHorario: '2', asistenciaAlumno: '2',
  buscarAsistencia: '2', jugadorNew: '2/1',
}

/* Solo las vistas que declaran una altura fija: son las unicas que pueden
   fallar asi, y recorrer las 69 a cuatro anchos seria muy lento. */
const candidatas = readdirSync(DIR)
  .filter(f => f.endsWith('-view.php'))
  .filter(f => /style="[^"]*\bheight:\s*\d+px/i.test(readFileSync(DIR + '/' + f, 'utf8')))
  .map(f => f.replace('-view.php', ''))

const ANCHOS = [1500, 992, 768, 390]

const nav = await chromium.launch({ headless: true, channel: 'chromium' })

let fallos = 0
const af = (t, ok, d = '') => {
  console.log('  ' + t.padEnd(48) + (ok ? 'OK' : 'FALLA') + (d ? '  (' + d + ')' : ''))
  if (!ok) fallos++
}

console.log('  vistas con alturas fijas: ' + candidatas.length)

const problemas = []

for (const ancho of ANCHOS) {
  const ctx = await nav.newContext({ viewport: { width: ancho, height: 900 },
                                     isMobile: ancho < 768, hasTouch: ancho < 768 })
  await ctx.addCookies([{ name: 'DigiSportsBasketball', value: 'dsqaui0000000000000',
                          domain: 'localhost', path: '/' }])
  const p = await ctx.newPage()

  for (const vista of candidatas) {
    const url = BASE + vista + '/' + (CON_ID[vista] ? CON_ID[vista] + '/' : '')
    const r = await p.goto(url, { waitUntil: 'networkidle' }).catch(() => null)
    if (!r || r.status() !== 200) { continue }
    await p.waitForTimeout(250)

    const llegada = await p.evaluate(() => location.pathname)
    if (!llegada.includes(vista)) { continue }   /* redirigió: se mide en otra suite */

    const fuera = await p.evaluate(() => {
      const salida = []
      document.querySelectorAll('.app-main *').forEach(el => {
        const estilo = el.getAttribute('style')
        if (!estilo || !/height:\s*\d+px/i.test(estilo)) { return }
        /* max-height no fuerza nada: no puede provocar desborde. */
        if (/^\s*max-height/i.test(estilo.replace(/.*?(max-)?height/i, '$1height'))) { return }
        const sobra = el.scrollHeight - el.clientHeight
        if (sobra > 2) {
          salida.push(el.tagName.toLowerCase()
            + (el.className ? '.' + String(el.className).trim().split(/\s+/)[0] : '')
            + ' +' + sobra + 'px')
        }
      })
      return salida
    })

    if (fuera.length) {
      problemas.push(vista + ' @' + ancho + ': ' + fuera.slice(0, 2).join(', '))
    }
  }

  await ctx.close()
}

if (problemas.length) {
  console.log('\n  contenido fuera de su caja:')
  for (const x of problemas.slice(0, 14)) { console.log('    ' + x) }
  if (problemas.length > 14) { console.log('    … y ' + (problemas.length - 14) + ' más') }
}

af('\n  nada se sale de su caja en ningún ancho', problemas.length === 0,
   problemas.length + ' casos')

console.log('\nfallos: ' + fallos)
await nav.close()
process.exit(fallos === 0 ? 0 : 1)
