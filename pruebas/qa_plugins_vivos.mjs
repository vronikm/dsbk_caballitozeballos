/*
| Ninguna vista pide un archivo que no esta, y que carpetas de plugins se
| usan de verdad al navegar.
|
| LO QUE ESTA PRUEBA APRENDIO A NO CREERSE
|
| La primera version recorria las 70 vistas navegables y anunciaba «70 de
| 70, sin 404». Era falso por omision: la mitad de esas vistas necesitan un
| identificador en la URL, y sin el REDIRIGEN a su listado. pagosNew/ acaba
| en pagosList/, pagosUpdate/ tambien, facturasNew/ en facturasList/. Se
| estaba revisando pagosList una y otra vez y contandolo como catorce
| vistas distintas.
|
| Se noto porque el inventario no cuadraba: cinco vistas cargan
| ekko-lightbox con una etiqueta <script> normal y no se registro ni una
| peticion. No era que el plugin sobrara: era que esas paginas nunca se
| llegaron a abrir.
|
| Ahora se cuentan aparte las que llegan a su destino y las que redirigen, y
| el numero que se anuncia es el primero. Un barrido que exagera su alcance
| tranquiliza sin cubrir, que es lo peor que puede hacer una prueba.
|
| DE LO ESTATICO SE OCUPA OTRA
|
| Para lo que una vista declara en su marcado —incluidas las que solo se
| alcanzan con un id— esta qa_estaticos.php, que lo lee sin abrir el
| navegador y por tanto sin redirecciones. Las dos se complementan: esta ve
| lo que se pide en ejecucion, aquella ve las 98 vistas enteras.
*/
import { createRequire } from 'node:module'
import { readdirSync, readFileSync, writeFileSync } from 'node:fs'
const EXT = 'C:/Users/dbafaces/.vscode/extensions/'
const RAIZ = EXT + readdirSync(EXT).filter(d => d.startsWith('danielsanmedium.dscodegpt-')).sort().pop() + '/standalone/'
const { chromium } = createRequire(RAIZ)('patchright')

const BASE = 'http://localhost/barcelona/ds_basketball/'

/* La lista blanca del modulo es la fuente de verdad de que rutas existen. */
const CONF = 'c:/wamp64/www/barcelona/ds_basketball/config/vistas.php'
const VISTAS = [...readFileSync(CONF, 'utf8').matchAll(/"([A-Za-z0-9_]+)"/g)]
  .map(m => m[1])
  .filter(v => !/PDF$|Envio$|^logOut$|Descarga/.test(v))

const nav = await chromium.launch({ headless: true, channel: 'chromium' })
const ctx = await nav.newContext({ viewport: { width: 1500, height: 950 } })
await ctx.addCookies([{ name: 'DigiSportsBasketball', value: 'dsqaui0000000000000',
                        domain: 'localhost', path: '/' }])
const p = await ctx.newPage()

let fallos = 0
const af = (t, ok, d = '') => {
  console.log('  ' + t.padEnd(50) + (ok ? 'OK' : 'FALLA') + (d ? '  (' + d + ')' : ''))
  if (!ok) fallos++
}

const uso = new Map()
const rotos = []
let vistaActual = ''

p.on('response', (r) => {
  const u = r.url(), est = r.status()
  const m = u.match(/dist\/plugins\/([A-Za-z0-9._-]+)\//)
  if (m) {
    if (!uso.has(m[1])) uso.set(m[1], { n: 0, vistas: new Set() })
    const e = uso.get(m[1]); e.n++; e.vistas.add(vistaActual)
  }
  if (est === 404 && !/favicon/.test(u)) {
    rotos.push(vistaActual + ' → ' + u.replace(/^.*\/barcelona\//, ''))
  }
})

/*==============  0. Hay sesion  ==============*/
await p.goto(BASE + 'dashboard/', { waitUntil: 'networkidle' })
if (await p.evaluate(() => !!document.getElementById('login_clave'))) {
  af('la sesión de pruebas sigue viva', false, 'cayó al login')
  console.log('\nfallos: ' + fallos); await nav.close(); process.exit(1)
}
af('la sesión de pruebas sigue viva', true, 'dashboard')

/*==============  1. Recorrer, distinguiendo destino real  ==============*/
let llegaron = 0
const redirigidas = []
for (const v of VISTAS) {
  vistaActual = v
  try {
    await p.goto(BASE + v + '/', { waitUntil: 'networkidle', timeout: 25000 })
    const donde = await p.evaluate(() => location.pathname)
    /* Se compara el ultimo segmento con la vista pedida. */
    const fin = donde.replace(/\/+$/, '').split('/').pop()
    if (fin.toLowerCase() === v.toLowerCase()) { llegaron++ }
    else { redirigidas.push(v + '→' + fin) }
  } catch (e) {
    redirigidas.push(v + '(no cargó)')
  }
}
console.log('  vistas que llegaron a su destino: ' + llegaron +
            ' · redirigidas: ' + redirigidas.length + ' de ' + VISTAS.length)
if (redirigidas.length) {
  console.log('    ' + redirigidas.slice(0, 8).join(' · ') +
              (redirigidas.length > 8 ? ' …' : ''))
}
af('se abrieron vistas de verdad, no solo redirecciones', llegaron >= 25,
   llegaron + ' páginas distintas')

/*==============  2. Nada devuelve 404  ==============*/
af('ninguna página pide un archivo que no está', rotos.length === 0,
   rotos.length ? rotos.slice(0, 5).join(' · ') : 'sin 404')

/*==============  3. Inventario, con su alcance dicho  ==============*/
const orden = [...uso.entries()].sort((a, b) => b[1].n - a[1].n)
console.log('\n  plugins pedidos en las ' + llegaron + ' páginas abiertas: ' + orden.length)
for (const [c, e] of orden) {
  console.log('    ' + c.padEnd(26) + String(e.n).padStart(4) + ' peticiones · ' + e.vistas.size + ' vistas')
}
console.log('  (jszip y pdfmake no salen aquí: exportar.js los baja al pulsar)')
writeFileSync(process.env.TEMP + '/plugins_vivos.txt', orden.map(([c]) => c).join('\n'))

console.log('\nfallos: ' + fallos)
await nav.close()
process.exit(fallos === 0 ? 0 : 1)
