/* El menu desplegable debe abrirse y la flecha girar. Se comprueba el
   comportamiento, no que la clase este escrita. */
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

await p.goto('http://localhost/barcelona/ds_basketball/alumnoList/', { waitUntil:'networkidle' })
await p.waitForTimeout(600)

const antes = await p.evaluate(() => {
  const g = [...document.querySelectorAll('.sidebar-menu > .nav-item')]
    .find(li => li.querySelector('.nav-treeview'))
  return g ? { abierto: g.classList.contains('menu-open'),
               subVisible: g.querySelector('.nav-treeview').offsetHeight > 0,
               flecha: !!g.querySelector('.nav-arrow') } : null
})
af('hay un grupo con submenu', antes !== null)
af('lleva la flecha de AdminLTE 4', antes && antes.flecha)

/* Se pliega/despliega al pulsar. */
await p.click('.sidebar-menu > .nav-item:has(.nav-treeview) > .nav-link')
await p.waitForTimeout(700)
const despues = await p.evaluate(() => {
  const g = [...document.querySelectorAll('.sidebar-menu > .nav-item')]
    .find(li => li.querySelector('.nav-treeview'))
  return { abierto: g.classList.contains('menu-open'),
           subVisible: g.querySelector('.nav-treeview').offsetHeight > 0 }
})
af('el grupo cambia de estado al pulsarlo',
   antes.abierto !== despues.abierto || antes.subVisible !== despues.subVisible,
   (antes.subVisible?'visible':'oculto') + ' -> ' + (despues.subVisible?'visible':'oculto'))

/* Y el boton de la barra pliega el menu entero. */
const bodyAntes = await p.evaluate(() => document.body.className)
await p.click('[data-lte-toggle="sidebar"]')
await p.waitForTimeout(600)
const bodyDespues = await p.evaluate(() => document.body.className)
af('el boton de la barra pliega el menu', bodyAntes !== bodyDespues)

console.log('\nfallos: ' + fallos)
await nav.close()
process.exit(fallos===0?0:1)
