/*
| Revisión de maquetación tras migrar a Bootstrap 5.
|
| Busca los tres fallos que deja este salto y que NO producen ningún error
| en consola —la página se ve, sólo se ve mal—:
|
|   1. Desbordamiento horizontal. Casi siempre un .form-control que en
|      Bootstrap 5 vale width:100% y antes, dentro de .form-inline, valía
|      auto.
|   2. Controles apilados donde deberían ir en línea, por lo mismo.
|   3. Texto ilegible: contraste por debajo de 4.5.
|
| Se MIDE. Una captura de pantalla por vista no se puede revisar a ojo en
| cuarenta pantallas, y a ojo es justo como se pasan por alto.
*/
import { createRequire } from 'node:module'
import { readdirSync } from 'node:fs'
import { sinAnimacion } from './sin_animacion.mjs'
const EXT = 'C:/Users/dbafaces/.vscode/extensions/'
const RAIZ = EXT + readdirSync(EXT).filter(d => d.startsWith('danielsanmedium.dscodegpt-')).sort().pop() + '/standalone/'
const { chromium } = createRequire(RAIZ)('patchright')

const VISTAS = [
  ...['panel/', 'temporadaList/', 'torneoList/', 'categoriaList/', 'equipoList/',
      'partidoAgenda/', 'conceptoList/', 'cobranzaPanel/', 'cobranzaPanel/29/']
     .map(v => 'ds_league/' + v),
  ...['panel/', 'instalacionList/', 'horarioList/', 'bloqueoList/', 'clienteList/',
      'reservaList/', 'monederoList/', 'instalacionForm/']
     .map(v => 'ds_arena/' + v),
  ...['panel/', 'usuarioList/', 'usuarioForm/', 'rolList/', 'permisoRol/', 'menuList/',
      'menuForm/', 'moduloRol/', 'organizacionForm/', 'sedeList/', 'sedeForm/',
      'catalogoList/', 'facturacionConfigSri/', 'puntoEmisionList/', 'carnetConfig/']
     .map(v => 'ds_core/admin/' + v),
]

const nav = await chromium.launch({ headless: true, channel: 'chromium' })
const ctx = await nav.newContext({ viewport: { width: 1400, height: 950 } })
await sinAnimacion(ctx)
await ctx.addCookies([{ name: 'DigiSportsBasketball', value: 'dsqaui0000000000000',
                        domain: 'localhost', path: '/' }])
const p = await ctx.newPage()

let problemas = 0

for (const v of VISTAS) {
  const errores = []
  const onCon = m => { if (m.type() === 'error' && !/404|Failed to load/.test(m.text())) {
    errores.push(m.text().slice(0, 110)) } }
  p.on('console', onCon)

  let http = 0
  let r = null
  try {
    const resp = await p.goto('http://localhost/barcelona/' + v,
                              { waitUntil: 'networkidle', timeout: 40000 })
    http = resp ? resp.status() : 0
    await p.waitForTimeout(250)
    await p.addScriptTag({ path: 'sonda_layout.js' })
    r = JSON.parse(await p.getAttribute('html', 'data-layout') || 'null')
  } catch (e) {
    errores.push('nav: ' + e.message.slice(0, 70))
  }
  p.off('console', onCon)

  const mal = http !== 200 || errores.length > 0 || !r
             || r.desborde || r.anchoTotal > 0 || r.contrasteMalo > 0
  if (mal) { problemas++ }

  console.log('  ' + v.padEnd(32) + http + '  ' + (mal ? 'REVISAR' : 'OK')
    + (r ? (r.desborde ? '  desborda' : '')
         + (r.anchoTotal > 0 ? '  ' + r.anchoTotal + ' controles a ancho total en fila' : '')
         + (r.contrasteMalo > 0 ? '  ' + r.contrasteMalo + ' textos ilegibles' : '')
       : '  sin sonda')
    + (errores.length ? '  ' + errores[0] : ''))
}

console.log('\n' + VISTAS.length + ' vistas, ' + problemas + ' con problema')
await nav.close()
process.exit(problemas === 0 ? 0 : 1)
