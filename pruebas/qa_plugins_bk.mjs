/* Los plugins que SI se usan deben seguir funcionando tras el cambio de
   tema. No basta con que la pagina cargue: DataTables sin su tema deja la
   tabla sin paginacion y select2 sin el suyo deja un <select> pelado. */
import { createRequire } from 'node:module'
import { readdirSync } from 'node:fs'
const EXT = 'C:/Users/dbafaces/.vscode/extensions/'
const RAIZ = EXT + readdirSync(EXT).filter(d => d.startsWith('danielsanmedium.dscodegpt-')).sort().pop() + '/standalone/'
const { chromium } = createRequire(RAIZ)('patchright')
const nav = await chromium.launch({ headless: true, channel: 'chromium' })
const ctx = await nav.newContext({ viewport: { width: 1400, height: 950 } })
await ctx.addCookies([{ name:'DigiSportsBasketball', value:'dsqaui0000000000000', domain:'localhost', path:'/' }])
const p = await ctx.newPage()
let fallos = 0
const af = (t, ok, d='') => { console.log('  '+t.padEnd(52)+(ok?'OK':'FALLA')+(d?'  ('+d+')':'')); if(!ok) fallos++ }

for (const [v, comprobar] of [
  ['alumnoList/',      'datatable'],
  ['pagosList/',       'datatable'],
  ['representanteList/','datatable'],
  ['pagosNew/2/',      'nativo'],
  ['alumnoProfile/2/', 'nativo'],
  ['representanteNew/', 'nativo'],
  ['alumnoNew/',        'buscador'],
  ['agenda/',          'calendario'],
  ['estadisticas/',    'chart'],
]) {
  const resp = await p.goto('http://localhost/barcelona/ds_basketball/' + v, { waitUntil:'networkidle' })
  af(v + ' responde sin redirigir', resp.status() === 200 && p.url().includes(v.split('/')[0]), 'HTTP ' + resp.status())
  await p.waitForTimeout(900)
  await p.addScriptTag({ content: `
    document.documentElement.setAttribute('data-plug', JSON.stringify({
      /* DataTables 2 renombro sus contenedores: .dataTables_wrapper pasa a
         .dt-container y .dataTables_paginate a .dt-paging. Se aceptan los
         dos para que la comprobacion no dependa de la version. */
      dtWrapper: document.querySelectorAll('.dt-container, .dataTables_wrapper').length,
      dtPaginate: document.querySelectorAll('.dt-paging .page-link, .dataTables_paginate .page-link').length,
      s2:        document.querySelectorAll('.select2-container').length,
      selects:   document.querySelectorAll('select.select2').length,
      calendar:  document.querySelectorAll('.fc, .fc-view-harness').length,
      canvas:    document.querySelectorAll('canvas').length,
      opciones:  [...document.querySelectorAll('select')].reduce((a,s)=>a+s.options.length,0),
      modal:     typeof window.bootstrap !== 'undefined' && !!window.bootstrap.Modal
    }));
  `})
  const r = JSON.parse(await p.getAttribute('html','data-plug'))
  if (comprobar === 'datatable') {
    af(v + ' DataTables se inicializa', r.dtWrapper > 0, r.dtWrapper + ' tablas')
    af(v + ' con paginacion de Bootstrap 5', r.dtPaginate > 0, r.dtPaginate + ' botones')
  }
  if (comprobar === 'nativo') {
    /* Se retiro select2 de los desplegables cortos —de 3 a 9 opciones—
       porque un buscador ahi estorba. Lo que se comprueba ahora es que
       siguen siendo desplegables con sus opciones, ya nativos. */
    af(v + ' no carga select2', r.s2 === 0 && r.selects === 0,
       r.s2 + ' contenedores')
    af(v + ' conserva desplegables con opciones', r.opciones > 0,
       r.opciones + ' opciones en total')
  }
  if (comprobar === 'buscador') {
    af(v + ' el desplegable largo conserva el buscador', r.s2 > 0, r.s2 + '')
  }
  if (comprobar === 'select2') {
    /* No se exige que los convierta TODOS: pagosNew repite el mismo id
       seis veces —HTML invalido, anterior a esta migracion— y select2 se
       atasca con los duplicados. Lo que se comprueba es que la libreria
       funciona, no un recuento que ya fallaba antes. */
    af(v + ' select2 se inicializa', r.s2 > 0, r.s2 + ' de ' + r.selects)
  }
  if (comprobar === 'calendario') af(v + ' el calendario se dibuja', r.calendar > 0, r.calendar+'')
  if (comprobar === 'chart')      af(v + ' el grafico se dibuja', r.canvas > 0, r.canvas+'')
  af(v + ' Bootstrap 5 disponible', r.modal === true)
}
console.log('\nfallos: ' + fallos)
/* Igual que en pagos: el listado de alumnos son datos personales. */
if (fallos > 0) {
  await p.goto('http://localhost/barcelona/ds_basketball/alumnoList/', { waitUntil: 'networkidle' })
  await p.waitForTimeout(700)
  const foto = (process.env.TEMP || '/tmp') + '/qa_basket4.png'
  await p.screenshot({ path: foto })
  console.log('  captura: ' + foto)
}
await nav.close()
process.exit(fallos===0?0:1)
