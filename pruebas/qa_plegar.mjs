/*
| Los botones de plegar tarjeta PLIEGAN, y los grupos no se tocan.
|
| EL FALLO QUE MOTIVA ESTA SUITE
|
| data-card-widget es sintaxis de AdminLTE 3 y la version 4 la ignora sin
| decir nada: ni error en consola, ni aviso. El boton estaba en su sitio,
| con su icono, y al pulsarlo no pasaba nada. Asi estuvieron los 56 botones
| del sistema desde la migracion de plantilla.
|
| Por eso esta comprobacion PULSA. Mirar el marcado solo diria que el
| atributo esta escrito; lo que hay que saber es si la tarjeta se pliega.
|
| Y LA SEPARACION ENTRE TARJETAS
|
| En Bootstrap 5 la tarjeta no trae margen inferior. La regla que parecia
| darselo —.card-group>.card— solo aplica dentro de un grupo de tarjetas,
| que no es el caso. Sin una clase de separacion escrita a mano los bloques
| quedan pegados, sin una linea de aire entre uno y otro.
*/
import { createRequire } from 'node:module'
import { readdirSync } from 'node:fs'
const EXT = 'C:/Users/dbafaces/.vscode/extensions/'
const RAIZ = EXT + readdirSync(EXT).filter(d => d.startsWith('danielsanmedium.dscodegpt-')).sort().pop() + '/standalone/'
const { chromium } = createRequire(RAIZ)('patchright')

const HUB = 'http://localhost/barcelona/'
const PANTALLAS = [
  ['panel',        HUB + 'ds_basketball/dashboard/'],
  ['alumnos',      HUB + 'ds_basketball/alumnoList/'],
  ['torneos',      HUB + 'ds_basketball/torneoList/'],
  ['pagos',        HUB + 'ds_basketball/pagosList/'],
  ['carnets',      HUB + 'ds_basketball/carnetList/'],
  ['empleados',    HUB + 'ds_basketball/empleadoList/'],
  ['cobranza',     HUB + 'ds_league/cobranzaPanel/29/'],
  ['equipos',      HUB + 'ds_league/equipoList/'],
  ['instalaciones', HUB + 'ds_arena/instalacionList/'],
]

const nav = await chromium.launch({ headless: true, channel: 'chromium' })
const ctx = await nav.newContext({ viewport: { width: 1500, height: 1000 } })
await ctx.addCookies([{ name: 'DigiSportsBasketball', value: 'dsqaui0000000000000',
                        domain: 'localhost', path: '/' }])
const p = await ctx.newPage()

let fallos = 0
const af = (t, ok, d = '') => {
  console.log('  ' + t.padEnd(46) + (ok ? 'OK' : 'FALLA') + (d ? '  (' + d + ')' : ''))
  if (!ok) fallos++
}

let botonesTotales = 0

for (const [nombre, url] of PANTALLAS) {
  await p.goto(url, { waitUntil: 'networkidle' })
  await p.waitForTimeout(500)

  /* Que no quede ni un boton con la sintaxis vieja. */
  const viejos = await p.evaluate(() =>
    document.querySelectorAll('[data-card-widget]').length)
  af(nombre + ': sin botones de la v3', viejos === 0, viejos + ' encontrados')

  const cuantos = await p.evaluate(() =>
    document.querySelectorAll('[data-lte-toggle="card-collapse"]').length)
  botonesTotales += cuantos

  if (cuantos > 0) {
    /* Se pulsa el primero y se mide la tarjeta antes y despues. Un boton
       que no hace nada deja la altura igual. */
    const resultado = await p.evaluate(async () => {
      const b = document.querySelector('[data-lte-toggle="card-collapse"]')
      const tarjeta = b.closest('.card')
      const antes = Math.round(tarjeta.getBoundingClientRect().height)
      b.click()
      await new Promise(r => setTimeout(r, 600))
      const despues = Math.round(tarjeta.getBoundingClientRect().height)
      b.click()
      await new Promise(r => setTimeout(r, 600))
      const vuelta = Math.round(tarjeta.getBoundingClientRect().height)

      /* Y que el boton nunca se quede sin icono visible: la version 4
         oculta los dos por CSS y muestra el que toca. */
      const iconos = [...b.querySelectorAll('i')]
      const visibles = iconos.filter(i => i.getBoundingClientRect().width > 0).length
      return { antes, despues, vuelta, iconos: iconos.length, visibles }
    })

    af(nombre + ': la tarjeta se pliega',
       resultado.despues < resultado.antes,
       resultado.antes + 'px → ' + resultado.despues + 'px')

    af(nombre + ': y vuelve a desplegarse',
       Math.abs(resultado.vuelta - resultado.antes) <= 2,
       resultado.vuelta + 'px')

    af(nombre + ': el botón muestra un icono',
       resultado.iconos === 2 && resultado.visibles === 1,
       resultado.iconos + ' iconos, ' + resultado.visibles + ' visible')
  }

  /* Los grupos apilados no pueden quedar pegados. */
  const huecos = await p.evaluate(() => {
    const tarjetas = [...document.querySelectorAll('.app-content > .container-fluid > .card, '
                    + '.app-content .container-fluid > .card')]
    const h = []
    for (let i = 1; i < tarjetas.length; i++) {
      h.push(Math.round(tarjetas[i].getBoundingClientRect().top
                      - tarjetas[i - 1].getBoundingClientRect().bottom))
    }
    return h
  })
  if (huecos.length) {
    af(nombre + ': los grupos no se tocan', huecos.every(h => h >= 8),
       'huecos: ' + huecos.slice(0, 4).join(', ') + 'px')
  }
}

console.log('\n  botones de plegar comprobados: ' + botonesTotales)
console.log('\nfallos: ' + fallos)
await nav.close()
process.exit(fallos === 0 ? 0 : 1)
