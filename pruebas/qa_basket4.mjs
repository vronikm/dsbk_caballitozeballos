import { createRequire } from 'node:module'
import { readdirSync } from 'node:fs'
import { sinAnimacion } from './sin_animacion.mjs'
const EXT = 'C:/Users/dbafaces/.vscode/extensions/'
const RAIZ = EXT + readdirSync(EXT).filter(d => d.startsWith('danielsanmedium.dscodegpt-')).sort().pop() + '/standalone/'
const { chromium } = createRequire(RAIZ)('patchright')

const VISTAS = ['dashboard/','representanteList/','alumnoList/','pagosList/','agenda/',
  'asistencia/','reporteAsistencia/','asistenciaHora/','asistenciaLugar/','asistenciaListHorario/',
  'empleadoList/','empleadoEntrada/','empleadoAsistencias/','ingresoList/','egresoList/',
  'balanceResultados/','cobranzaPension/','cobranzaUniforme/','reportePagos/','reporteRubros/',
  'facturasList/','carnetList/','cumpleaniosList/','consentimientoList/','inscripcionPendientes/',
  'estadisticas/','ingresosLugarEntrenamiento/','reporteIngresosMorames/','torneoList/',
  'alumnoNew/','representanteNew/','pagosNew/','equipoList/','importarAlumnos/',
  'pagosRecibo/1054/','asistenciaVerHorario/2/','representanteFLPD/2/']

const nav = await chromium.launch({ headless: true, channel: 'chromium' })
const ctx = await nav.newContext({ viewport: { width: 1400, height: 950 } })
await sinAnimacion(ctx)
await ctx.addCookies([{ name:'DigiSportsBasketball', value:'dsqaui0000000000000', domain:'localhost', path:'/' }])
const p = await ctx.newPage()
let malos = 0

for (const v of VISTAS) {
  const err = []
  const oc = m => { if (m.type()==='error' && !/404|Failed to load/.test(m.text())) err.push(m.text().slice(0,110)) }
  p.on('console', oc)
  let http = 0, r = null, est = null
  try {
    const resp = await p.goto('http://localhost/barcelona/ds_basketball/' + v, { waitUntil:'networkidle', timeout:45000 })
    http = resp ? resp.status() : 0
    await p.waitForTimeout(300)
    await p.addScriptTag({ path: 'sonda_layout.js' })
    r = JSON.parse(await p.getAttribute('html','data-layout') || 'null')
    est = await p.evaluate(() => ({
      appMain: !!document.querySelector('.app-main'),
      sidebar: !!document.querySelector('.app-sidebar'),
      viejo:   !!document.querySelector('.content-wrapper, .main-sidebar, .main-header'),
      menuX:   document.querySelectorAll('.sidebar-menu .nav-link').length,
      mainX:   document.querySelector('.app-main') ? Math.round(document.querySelector('.app-main').getBoundingClientRect().left) : -1,
    }))
  } catch (e) { err.push('nav: ' + e.message.slice(0,60)) }
  p.off('console', oc)

  const mal = http !== 200 || err.length || !r || !est || !est.appMain || est.viejo
            || r.desborde || r.contrasteMalo > 0
  if (mal) malos++
  console.log('  ' + v.padEnd(30) + http + '  ' + (mal ? 'REVISAR' : 'OK')
    + (est && !est.appMain ? '  sin app-main' : '')
    + (est && est.viejo ? '  QUEDA MARCADO v3' : '')
    + (r && r.desborde ? '  desborda' : '')
    + (r && r.contrasteMalo ? '  ' + r.contrasteMalo + ' ilegibles: ' + (r.detalle[0]||'') : '')
    + (err.length ? '  ' + err[0] : ''))
}
console.log('\n' + VISTAS.length + ' vistas, ' + malos + ' con problema')
await nav.close()
