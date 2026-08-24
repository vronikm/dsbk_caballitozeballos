/*
| ¿Rompe la CSP alguna pantalla?
|
| No basta con que la página responda 200: una CSP mal puesta deja la
| página en pie y muda, porque bloquea un script y nada lo dice salvo la
| consola. Aquí se escucha el evento securitypolicyviolation, que es el
| navegador informando de cada bloqueo real, y también los errores de
| consola y las peticiones fallidas.
*/
import { createRequire } from 'node:module'
import { readdirSync } from 'node:fs'
const EXT = 'C:/Users/dbafaces/.vscode/extensions/'
const RAIZ = EXT + readdirSync(EXT).filter(d => d.startsWith('danielsanmedium.dscodegpt-')).sort().pop() + '/standalone/'
const { chromium } = createRequire(RAIZ)('patchright')

const RUTAS = {
  'ds_core/admin': ['panel/', 'usuarioList/', 'rolList/', 'permisoRol/', 'menuList/',
                    'sedeList/', 'catalogoList/', 'facturacionConfigSri/', 'carnetConfig/',
                    'puntoEmisionList/'],
  'ds_basketball': ['dashboard/', 'representanteList/', 'alumnoList/', 'pagosList/',
                    'agenda/', 'asistencia/', 'asistenciaHora/', 'asistenciaListHorario/',
                    'empleadoList/', 'ingresoList/', 'egresoList/', 'balanceResultados/',
                    'cobranzaPension/', 'reportePagos/', 'facturasList/', 'carnetList/',
                    'cumpleaniosList/', 'estadisticas/', 'ingresosLugarEntrenamiento/',
                    'reporteIngresosMorames/', 'pagosRecibo/1054/'],
  'ds_arena':      ['panel/', 'instalacionList/', 'horarioList/', 'bloqueoList/'],
  'ds_league':     ['panel/', 'temporadaList/', 'equipoList/', 'conceptoList/',
                    'cobranzaPanel/29/'],
  '':             ['', '?p=seguridad'],
}

const nav = await chromium.launch({ headless: true, channel: 'chromium' })
const ctx = await nav.newContext({ viewport: { width: 1400, height: 950 } })
await ctx.addCookies([{ name: 'DigiSportsBasketball', value: 'dsqaui0000000000000',
                        domain: 'localhost', path: '/' }])
const p = await ctx.newPage()

let total = 0, conProblema = 0
const violacionesTotales = []

for (const [mod, rutas] of Object.entries(RUTAS)) {
  console.log('=== ' + mod + ' ===')
  for (const r of rutas) {
    total++
    const violaciones = [], errores = [], fallidas = []

    /* El navegador dispara este evento por CADA recurso que la política
       bloquea. Es la única fuente fiable: la página sigue viéndose bien. */
    await p.addInitScript(() => {
      window.__csp = []
      document.addEventListener('securitypolicyviolation', e => {
        window.__csp.push({ directiva: e.violatedDirective, recurso: e.blockedURI })
      })
    })

    const onCon = m => { if (m.type() === 'error') errores.push(m.text().slice(0, 160)) }
    const onFail = req => fallidas.push(req.url().slice(0, 110))
    p.on('console', onCon)
    p.on('requestfailed', onFail)

    let http = 0
    try {
      const resp = await p.goto('http://localhost/barcelona/' + mod + '/' + r,
                                { waitUntil: 'networkidle', timeout: 45000 })
      http = resp ? resp.status() : 0
      await p.waitForTimeout(400)
      violaciones.push(...await p.evaluate(() => window.__csp || []))
    } catch (e) {
      errores.push('navegación: ' + e.message.slice(0, 90))
    }

    p.off('console', onCon)
    p.off('requestfailed', onFail)

    /* Un error de consola por CSP se cuenta una vez, en violaciones. */
    const erroresReales = errores.filter(e => !/Content Security Policy/i.test(e))

    const mal = violaciones.length > 0 || erroresReales.length > 0
    if (mal) { conProblema++ }

    console.log('  ' + r.padEnd(30) + http + '  ' +
      (mal ? 'REVISAR' : 'OK') +
      (violaciones.length ? '  bloqueos: ' + violaciones.length : '') +
      (erroresReales.length ? '  errores: ' + erroresReales.length : ''))

    for (const v of violaciones) {
      console.log('        bloqueado  ' + v.directiva + '  ->  ' + v.recurso)
      violacionesTotales.push(mod + '/' + r + '  ' + v.directiva + '  ' + v.recurso)
    }
    for (const e of erroresReales.slice(0, 2)) { console.log('        error  ' + e) }
  }
}

console.log('\n' + total + ' vistas, ' + conProblema + ' con problema')
console.log(violacionesTotales.length + ' bloqueos de CSP en total')
await nav.close()
process.exit(conProblema === 0 ? 0 : 1)
