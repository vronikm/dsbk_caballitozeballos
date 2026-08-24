/* Los DOS botones de salir deben pedir confirmacion. Antes solo el
   primero: el del menu lateral cerraba sesion sin avisar. */
import { createRequire } from 'node:module'
import { readdirSync } from 'node:fs'
const EXT = 'C:/Users/dbafaces/.vscode/extensions/'
const RAIZ = EXT + readdirSync(EXT).filter(d => d.startsWith('danielsanmedium.dscodegpt-')).sort().pop() + '/standalone/'
const { chromium } = createRequire(RAIZ)('patchright')
const nav = await chromium.launch({ headless: true, channel: 'chromium' })
let fallos = 0
const af = (t, ok, d='') => { console.log('  '+t.padEnd(54)+(ok?'OK':'FALLA')+(d?'  ('+d+')':'')); if(!ok) fallos++ }

for (const [donde, sel] of [
  ['menú de usuario', '.dropdown-menu .js-salir'],
  ['menú lateral',    '.sidebar-menu .js-salir'],
]) {
  /* Contexto nuevo por prueba: si una cierra sesion, la otra no hereda. */
  const ctx = await nav.newContext({ viewport: { width: 1400, height: 950 } })
  await ctx.addCookies([{ name:'DigiSportsBasketball', value:'dsqaui0000000000000', domain:'localhost', path:'/' }])
  const p = await ctx.newPage()
  await p.goto('http://localhost/barcelona/ds_basketball/alumnoList/', { waitUntil:'networkidle' })
  await p.waitForTimeout(700)

  const existe = await p.evaluate(s => document.querySelectorAll(s).length, sel)
  af('existe el botón del ' + donde, existe === 1, existe + '')

  await p.evaluate(s => document.querySelector(s).click(), sel)
  await p.waitForTimeout(800)

  const dialogo = await p.evaluate(() => {
    const t = document.querySelector('.swal2-title')
    return t ? t.innerText.trim() : null
  })
  af('el del ' + donde + ' pide confirmación',
     dialogo !== null && /salir del sistema/i.test(dialogo), dialogo ?? 'no salió el aviso')

  const urlIgual = p.url().includes('alumnoList')
  af('y no cierra sesión sin responder', urlIgual, p.url().slice(-40))

  await ctx.close()
}

/* Y ningun id repetido en una vista cualquiera. */
const ctx = await nav.newContext({ viewport: { width: 1400, height: 950 } })
await ctx.addCookies([{ name:'DigiSportsBasketball', value:'dsqaui0000000000000', domain:'localhost', path:'/' }])
const p = await ctx.newPage()
for (const v of ['alumnoList/','pagosList/','dashboard/','pagosNew/2/','empleadoIE/']) {
  await p.goto('http://localhost/barcelona/ds_basketball/' + v, { waitUntil:'networkidle' })
  await p.waitForTimeout(400)
  const dup = await p.evaluate(() => {
    const c = {}; document.querySelectorAll('[id]').forEach(e => c[e.id]=(c[e.id]||0)+1)
    return Object.entries(c).filter(([,n]) => n>1)
  })
  af(v + ' sin ids repetidos', dup.length === 0, JSON.stringify(dup.slice(0,2)))
}
await ctx.close()

console.log('\nfallos: ' + fallos)
await nav.close()
process.exit(fallos===0?0:1)
