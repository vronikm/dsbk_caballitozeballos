/* Tras retirar select2 de donde no aportaba:
     - los desplegables deben seguir teniendo TODAS sus opciones y poder
       seleccionarse (ahora nativos)
     - el de horarios debe conservar el buscador
     - la libreria no debe descargarse donde ya no se usa */
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
const af = (t, ok, d='') => { console.log('  '+t.padEnd(56)+(ok?'OK':'FALLA')+(d?'  ('+d+')':'')); if(!ok) fallos++ }

/* Los desplegables clave, con las opciones que tenian ANTES del cambio. */
const ESPERADO = {
  'alumnoList/':          { alumno_sedeid: 8 },
  'pagosList/':           { alumno_sedeid: 8 },
  'egresoList/':          { egreso_sedeid: 7, egreso_formaentrega: 4, egreso_concepto: 3 },
  'empleadoList/':        { empleado_sedeid: 7, empleado_tipopersonalid: 4 },
  'pagosNew/2/':          { pago_formapagoid: 7 },
  'representanteNew/':    { repre_parentesco: 6 },
  'pagosDescuento/':      { alumno_sedeid: 8 },
}

for (const [v, campos] of Object.entries(ESPERADO)) {
  const red = []
  const onResp = r => { if (r.url().includes('plugins/select2')) red.push(r.url().split('/').pop()) }
  p.on('response', onResp)
  await p.goto('http://localhost/barcelona/ds_basketball/' + v, { waitUntil:'networkidle' })
  await p.waitForTimeout(400)
  p.off('response', onResp)

  const r = await p.evaluate(ids => {
    const out = {}
    for (const id of ids) {
      const e = document.getElementById(id)
      out[id] = e ? { n: e.options.length, tipo: e.tagName, visible: e.offsetParent !== null } : null
    }
    return out
  }, Object.keys(campos))

  for (const [id, n] of Object.entries(campos)) {
    const got = r[id]
    af(v + ' · ' + id + ' conserva sus ' + n + ' opciones',
       got !== null && got.n === n, got ? got.n + ' opciones' : 'no existe')
  }
  af(v + ' no descarga select2', red.length === 0, red.join(', '))
}

/* El buscador de horarios: sigue vivo. */
await p.goto('http://localhost/barcelona/ds_basketball/alumnoNew/', { waitUntil:'networkidle' })
await p.waitForTimeout(1000)
const bus = await p.evaluate(() => {
  const s = document.getElementById('horarioid')
  return s ? { opciones: s.options.length,
               convertido: !!(s.nextElementSibling && s.nextElementSibling.classList.contains('select2')),
               clase: s.className } : null
})
af('el desplegable de horarios conserva sus 51 opciones', bus && bus.opciones === 51,
   bus ? bus.opciones + '' : 'no existe')
af('y select2 le pone el buscador', bus && bus.convertido, bus ? bus.clase : '')

/* Y en esa vista los cortos ya son nativos. */
const cortos = await p.evaluate(() => {
  const s = document.getElementById('alumno_sedeid')
  return s ? { n: s.options.length,
               convertido: !!(s.nextElementSibling && s.nextElementSibling.classList.contains('select2')) } : null
})
af('los desplegables cortos quedan nativos', cortos && !cortos.convertido,
   cortos ? cortos.n + ' opciones, convertido: ' + cortos.convertido : '')

console.log('\nfallos: ' + fallos)
await nav.close()
process.exit(fallos===0?0:1)
