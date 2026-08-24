/* Ids repetidos sobre el HTML RENDERIZADO, que es lo que ve el navegador.
   El recuento sobre el archivo cuenta las dos ramas de un if/else, y la
   pagina solo pinta una. */
import { createRequire } from 'node:module'
import { readdirSync } from 'node:fs'
const EXT = 'C:/Users/dbafaces/.vscode/extensions/'
const RAIZ = EXT + readdirSync(EXT).filter(d => d.startsWith('danielsanmedium.dscodegpt-')).sort().pop() + '/standalone/'
const { chromium } = createRequire(RAIZ)('patchright')

const VISTAS = ['dashboard/','representanteList/','alumnoList/','pagosList/','agenda/',
  'asistencia/','asistenciaHora/','asistenciaListHorario/','empleadoList/','empleadoIE/',
  'ingresoList/','egresoList/','balanceResultados/','cobranzaPension/','reportePagos/',
  'facturasList/','facturasNew/','carnetList/','cumpleaniosList/','consentimientoList/',
  'estadisticas/','torneoList/','alumnoNew/','representanteNew/','equipoList/',
  'reportePagosReceptadosResumen/','pagosNew/2/','alumnoProfile/2/','alumnoUpdate/2/',
  'pagosPendiente/']

const nav = await chromium.launch({ headless: true, channel: 'chromium' })
const ctx = await nav.newContext({ viewport: { width: 1400, height: 950 } })
await ctx.addCookies([{ name:'DigiSportsBasketball', value:'dsqaui0000000000000', domain:'localhost', path:'/' }])
const p = await ctx.newPage()
let conDup = 0

for (const v of VISTAS) {
  let dup = []
  try {
    const r = await p.goto('http://localhost/barcelona/ds_basketball/' + v, { waitUntil:'networkidle', timeout:40000 })
    if (r.status() !== 200) { console.log('  ' + v.padEnd(32) + 'HTTP ' + r.status()); continue }
    await p.waitForTimeout(300)
    dup = await p.evaluate(() => {
      const c = {}; document.querySelectorAll('[id]').forEach(e => c[e.id]=(c[e.id]||0)+1)
      return Object.entries(c).filter(([,n]) => n>1)
    })
  } catch (e) { console.log('  ' + v.padEnd(32) + 'error'); continue }

  if (dup.length) { conDup++
    console.log('  ' + v.padEnd(32) + 'REPETIDOS  ' + dup.map(d=>d[0]+' x'+d[1]).join(', '))
  }
}
console.log('\n' + VISTAS.length + ' vistas, ' + conDup + ' con ids repetidos')
await nav.close()
