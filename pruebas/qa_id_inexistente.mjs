/*
| Una pantalla pedida con un identificador que no existe no puede reventar.
|
| EL FALLO QUE MOTIVA ESTO
|
| Muchas vistas hacen:
|
|     $x = $ctrl->Buscar($id);
|     if ($x->rowCount() == 1) { $x = $x->fetch(); }
|
| Si no hay fila, $x SIGUE SIENDO el statement, y mas abajo la vista lo usa
| como si fuera un array. PHP lanza un error fatal y —con display_errors
| encendido— escupe en la pagina la ruta absoluta del servidor y la pila de
| llamadas completa.
|
| Se llega ahi sin hacer nada raro: un enlace viejo, un registro borrado, o
| alguien que cambia un numero en la barra de direcciones.
|
| POR QUE SE MIDE Y NO SE LEE EL CODIGO
|
| El patron aparece en 41 vistas, pero solo es un fallo cuando ademas se
| usa la variable mas abajo. Distinguirlo leyendo es lento y se presta a
| equivocarse; pedir la pagina con un identificador imposible lo dice sin
| ambiguedad.
|
| SE PIDE CON UN NUMERO ENORME, NO CON UNO CUALQUIERA
|
| Un identificador pequeño puede existir de verdad y la prueba pasaria por
| el motivo equivocado.
*/
import { createRequire } from 'node:module'
import { readdirSync, readFileSync } from 'node:fs'
const EXT = 'C:/Users/dbafaces/.vscode/extensions/'
const RAIZ = EXT + readdirSync(EXT).filter(d => d.startsWith('danielsanmedium.dscodegpt-')).sort().pop() + '/standalone/'
const { chromium } = createRequire(RAIZ)('patchright')

const DIR  = 'c:/wamp64/www/barcelona/ds_basketball/app/views/content'
const BASE = 'http://localhost/barcelona/ds_basketball/'
const IMPOSIBLE = '99999999'

/* Solo las vistas que leen un identificador de la URL. */
const candidatas = readdirSync(DIR)
  .filter(f => f.endsWith('-view.php'))
  .filter(f => readFileSync(DIR + '/' + f, 'utf8').includes('ds_id_de_url'))
  .map(f => f.replace('-view.php', ''))

const nav = await chromium.launch({ headless: true, channel: 'chromium' })
const ctx = await nav.newContext({ viewport: { width: 1280, height: 800 } })
await ctx.addCookies([{ name: 'DigiSportsBasketball', value: 'dsqaui0000000000000',
                        domain: 'localhost', path: '/' }])
const p = await ctx.newPage()

let fallos = 0
const rotas = []

for (const vista of candidatas) {
  const r = await p.goto(BASE + vista + '/' + IMPOSIBLE + '/',
                         { waitUntil: 'domcontentloaded' }).catch(() => null)
  if (!r) { continue }

  const cuerpo = await p.evaluate(() => document.body ? document.body.innerText : '')

  /* Lo que delata el fallo: el mensaje de PHP, y de paso la ruta del
     servidor, que no deberia salir nunca al navegador. */
  const revienta = /Fatal error|Uncaught Error|Call Stack/i.test(cuerpo)
  const filtraRuta = /C:\\wamp64|\/wamp64\//i.test(cuerpo)

  if (revienta || filtraRuta) {
    const detalle = (cuerpo.match(/Uncaught Error:[^\n]{0,70}|Fatal error:[^\n]{0,70}/i) || [''])[0]
    rotas.push(vista + '  ' + detalle.trim().slice(0, 62))
  }
}

console.log('  vistas que leen un id de la URL: ' + candidatas.length)
if (rotas.length) {
  console.log('\n  revientan con un id inexistente:')
  for (const r of rotas) { console.log('    ' + r) }
}

const af = (t, ok, d = '') => {
  console.log('\n  ' + t.padEnd(46) + (ok ? 'OK' : 'FALLA') + (d ? '  (' + d + ')' : ''))
  if (!ok) fallos++
}
af('ninguna revienta con un id inexistente', rotas.length === 0,
   rotas.length + ' de ' + candidatas.length)

console.log('\nfallos: ' + fallos)
await nav.close()
process.exit(fallos === 0 ? 0 : 1)
