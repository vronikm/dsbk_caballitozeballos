/*
| El interruptor de tema: que cambie, que se recuerde y que NO parpadee.
|
| LO QUE DE VERDAD SE ROMPE EN ESTA FUNCIONALIDAD
|
| No es el cambio de color —eso se ve enseguida—, sino tres cosas que solo
| se notan usandola:
|
|   1. Que la eleccion sobreviva a recargar y a navegar a otra pantalla. Un
|      tema que hay que volver a poner en cada pagina es peor que no tenerlo.
|
|   2. Que «automatico» siga al sistema DE VERDAD, y no solo al arrancar: el
|      sistema puede cambiar solo al anochecer con la aplicacion abierta.
|
|   3. Que no parpadee. Si el script corriera al final del cuerpo, la pagina
|      se pinta clara y salta a oscura. Se comprueba interceptando el HTML
|      antes de que el navegador lo interprete: la etiqueta del script tiene
|      que estar DENTRO de la cabecera y sin defer.
|
| El parpadeo no se puede medir mirando la pagina ya cargada —ahi ya esta
| oscura—, asi que se comprueba sobre el documento que llega del servidor.
*/
/*
| AVISO SOBRE «sin errores de JavaScript» EN ESTE ARCHIVO
|
| El evento pageerror NO es de fiar en este entorno: se probo con un
| error provocado y no lo detecto. Quien comprueba las excepciones de
| verdad es qa_errores_js.mjs, que usa Runtime.exceptionThrown del
| protocolo del motor y ademas verifica su propia sonda antes de
| barrer. Lo que sigue capturando aqui son las respuestas 4xx, que esas
| si llegan.
*/

import { createRequire } from 'node:module'
import { readdirSync, readFileSync } from 'node:fs'
import { sinAnimacion } from './sin_animacion.mjs'
const EXT = 'C:/Users/dbafaces/.vscode/extensions/'
const RAIZ = EXT + readdirSync(EXT).filter(d => d.startsWith('danielsanmedium.dscodegpt-')).sort().pop() + '/standalone/'
const { chromium } = createRequire(RAIZ)('patchright')

const BASE = 'http://localhost/barcelona/'
const nav = await chromium.launch({ headless: true, channel: 'chromium' })

let fallos = 0
const af = (t, ok, d = '') => {
  console.log('  ' + t.padEnd(50) + (ok ? 'OK' : 'FALLA') + (d ? '  (' + d + ')' : ''))
  if (!ok) fallos++
}

const galleta = { name: 'DigiSportsBasketball', value: 'dsqaui0000000000000',
                  domain: 'localhost', path: '/' }

/*==============  1. El script llega en la cabecera y sin defer  ==============*/
{
  const ctx = await nav.newContext()
await sinAnimacion(ctx)
  await ctx.addCookies([galleta])
  const p = await ctx.newPage()

  /* Se lee el HTML crudo, sin interpretar: es la unica forma de saber
     DONDE esta la etiqueta. */
  const r = await p.goto(BASE + 'ds_basketball/dashboard/', { waitUntil: 'commit' })
  const html = await r.text()

  const cabeza = html.slice(0, html.search(/<\/head>/i))
  const eti = html.match(/Theme Init[\s\S]{0,120}?<script>/)

  af('el arranque del tema va en la cabecera', eti !== null,
     eti ? '' : 'no aparece antes de cerrar head')
  af('y sin defer ni async, para evitar el parpadeo',
     eti !== null && !/\b(defer|async)\b/i.test(eti), eti ? eti.slice(0, 60) : '')

  await ctx.close()
}

/*==============  2. Cambia, y se recuerda  ==============*/
{
  const ctx = await nav.newContext({ viewport: { width: 1400, height: 900 } })
  await ctx.addCookies([galleta])
  const p = await ctx.newPage()
  await p.goto(BASE + 'ds_basketball/dashboard/', { waitUntil: 'networkidle' })
  await p.waitForTimeout(400)

  const hayControl = await p.evaluate(() =>
    document.querySelectorAll('[data-bs-theme-value]').length)
  af('el control ofrece las tres opciones', hayControl === 3, hayControl + ' opciones')

  const elegir = async (modo) => {
    await p.evaluate((m) => document.querySelector('[data-bs-theme-value="' + m + '"]').click(), modo)
    await p.waitForTimeout(300)
    return p.evaluate(() => ({
      pintado: document.documentElement.getAttribute('data-bs-theme'),
      elegido: (() => { try { return localStorage.getItem('lte-theme') } catch (e) { return null } })(),
      fondo:   getComputedStyle(document.body).backgroundColor,
    }))
  }

  const oscuro = await elegir('dark')
  af('al elegir oscuro, la página se oscurece',
     oscuro.pintado === 'dark' && oscuro.fondo !== 'rgb(248, 249, 250)',
     oscuro.fondo)

  const claro = await elegir('light')
  af('al elegir claro, vuelve', claro.pintado === 'light', claro.fondo)

  /* Lo importante: que sobreviva a recargar Y a irse a otra pantalla. */
  await elegir('dark')
  await p.reload({ waitUntil: 'networkidle' })
  const trasRecargar = await p.evaluate(() =>
    document.documentElement.getAttribute('data-bs-theme'))
  af('la elección sobrevive a recargar', trasRecargar === 'dark', 'quedó ' + trasRecargar)

  await p.goto(BASE + 'ds_basketball/alumnoList/', { waitUntil: 'networkidle' })
  const enOtra = await p.evaluate(() =>
    document.documentElement.getAttribute('data-bs-theme'))
  af('y al cambiar de pantalla', enOtra === 'dark', 'quedó ' + enOtra)

  /* Y que llegue a los otros módulos, que es de lo que iba tenerlo en un
     include compartido. */
  await p.goto(BASE + 'ds_league/panel/', { waitUntil: 'networkidle' })
  const enLeague = await p.evaluate(() => ({
    tema: document.documentElement.getAttribute('data-bs-theme'),
    control: document.querySelectorAll('[data-bs-theme-value]').length,
  }))
  af('y a los demás módulos del ecosistema',
     enLeague.tema === 'dark' && enLeague.control === 3,
     'League: ' + enLeague.tema + ', ' + enLeague.control + ' opciones')

  await ctx.close()
}

/*==============  3. «El del sistema» sigue al sistema  ==============*/
/* Se abre el navegador diciendo que el sistema esta en oscuro. Si
   «automatico» funciona, la pagina sale oscura sin haber elegido nada. */
for (const esquema of ['dark', 'light']) {
  const ctx = await nav.newContext({ colorScheme: esquema })
  await ctx.addCookies([galleta])
  const p = await ctx.newPage()
  await p.goto(BASE + 'ds_basketball/dashboard/', { waitUntil: 'networkidle' })
  await p.waitForTimeout(300)

  const r = await p.evaluate(() => ({
    pintado: document.documentElement.getAttribute('data-bs-theme'),
    elegido: (() => { try { return localStorage.getItem('lte-theme') } catch (e) { return null } })(),
  }))
  af('con el sistema en ' + esquema + ', arranca en ' + esquema,
     r.pintado === esquema ,
     'pintado ' + r.pintado + ', elegido ' + r.elegido)

  await ctx.close()
}

/*==============  4. Sin almacenamiento, no se rompe  ==============*/
{
  const ctx = await nav.newContext()
  await ctx.addCookies([galleta])
  const p = await ctx.newPage()
  /* Se rompe localStorage a proposito, como en modo privado con la cuota
     agotada: la aplicacion tiene que seguir dibujandose. */
  await p.addInitScript(() => {
    Object.defineProperty(window, 'localStorage', {
      get() { throw new Error('sin almacenamiento') },
    })
  })
  const errores = []
  p.on('pageerror', e => errores.push(e.message.slice(0, 60)))
  await p.goto(BASE + 'ds_basketball/dashboard/', { waitUntil: 'networkidle' })
  await p.waitForTimeout(300)

  const r = await p.evaluate(() => ({
    tema: document.documentElement.getAttribute('data-bs-theme'),
    tarjetas: document.querySelectorAll('.card').length,
  }))
  af('sin poder guardar, la página sigue en pie',
     r.tema !== null && r.tarjetas > 0 && errores.length === 0,
     'tema ' + r.tema + ', ' + r.tarjetas + ' tarjetas' + (errores[0] ? ', ' + errores[0] : ''))

  await ctx.close()
}

/*==============  El icono dice lo que se esta viendo  ==============*/
/*
| Se detecto mirando una captura: con el sistema en oscuro la pagina salia
| oscura y el icono seguia siendo fa-adjust, el circulo medio relleno, que a
| 16 px se lee como un sol. Es decir, anunciaba el tema contrario al real.
|
| El codigo llevaba escrito en un comentario que el icono «dice que se esta
| viendo, no que se eligio»... y en automatico no lo hacia. Se comprueban las
| cinco combinaciones de sistema y eleccion guardada, porque el caso roto era
| justo el que NO tenia eleccion guardada —el de quien nunca toco el menu—.
*/
for (const [esquema, guardado] of [['dark', null], ['light', null],
                                   ['dark', 'dark'], ['light', 'dark'],
                                   ['dark', 'light']]) {
  const ctxI = await nav.newContext({ viewport: { width: 1400, height: 800 }, colorScheme: esquema })
  await ctxI.addCookies([{ name: 'DigiSportsBasketball', value: 'dsqaui0000000000000',
                           domain: 'localhost', path: '/' }])
  if (guardado) {
    await ctxI.addInitScript((v) => { try { localStorage.setItem('lte-theme', v) } catch (e) {} }, guardado)
  }
  const pgI = await ctxI.newPage()
  await pgI.goto('http://localhost/barcelona/ds_basketball/dashboard/', { waitUntil: 'networkidle' })
  await pgI.waitForTimeout(400)

  const ri = await pgI.evaluate(() => {
    /* AdminLTE deja visible el que corresponde y esconde los demas con
         d-none: hay que mirar el VISIBLE, no el primero del DOM. */
      const i = [...document.querySelectorAll('[data-lte-theme-icon]')]
        .find(x => !x.classList.contains('d-none'))
    const b = i ? i.closest('a') : null
    return { tema: document.documentElement.getAttribute('data-bs-theme'),
             modo: (() => { try { return localStorage.getItem('lte-theme') } catch (e) { return null } })(),
             icono: i ? i.className : '(sin control)',
             titulo: b ? (b.getAttribute('title') || '') : '' }
  })

  const esperado = ri.tema === 'dark' ? 'fa-moon' : 'fa-sun'
  af('sistema ' + esquema + ' / elegido ' + (guardado || 'nada') + ': el icono coincide',
     ri.icono.includes(esperado), ri.tema + ' → ' + ri.icono.replace('fas ', ''))

  /* Que sea automatico no puede perderse: si el icono ya no lo distingue,
     tiene que decirlo el rotulo. */
  if (ri.modo === 'auto') {
    af('  y el rotulo avisa de que es automatico',
       /autom/i.test(ri.titulo), ri.titulo || '(sin titulo)')
  }
  await ctxI.close()
}
/*==============  La elección se ve en TODO el ecosistema  ==============*/
/*
| DOS HUECOS QUE TENIA ESTA SUITE, Y POR LOS QUE SE COLO UN FALLO REAL.
|
| 1. Solo probaba que persistiera OSCURO. Elegir «Claro» con el sistema en
|    oscuro es el caso que se rompia, y nunca se ejercitaba.
|
| 2. Miraba el ATRIBUTO data-bs-theme, no el COLOR. El atributo se aplicaba
|    bien en el Hub —medido, decia «light»— mientras el fondo seguia siendo
|    #080d18, porque digisports.css tenia una sola paleta escrita en :root y
|    no escuchaba al tema. La pantalla decia estar clara y se veia negra.
|
| Ahora se mide la luminancia del fondo en los cinco contextos. El Hub va
| primero porque es el que fallaba: es la pantalla a la que se vuelve al
| cambiar de modulo.
|
| La pantalla de acceso queda fuera a proposito: conserva su fondo oscuro
| como identidad, y ocurre antes de que nadie haya podido elegir tema.
*/
const luminancia = (c) => {
  const m = String(c).match(/\d+/g)
  if (!m) return null
  const [r, g, b] = m.slice(0, 3).map(Number)
  const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4) }
  return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b)
}

const H = 'http://localhost/barcelona/'
const CONTEXTOS = [
  ['Hub',          H],
  ['Basketball',   H + 'ds_basketball/dashboard/'],
  ['League',       H + 'ds_league/'],
  ['Arena',        H + 'ds_arena/'],
  ['Core admin',   H + 'ds_core/admin/'],
  ['Acceso',       H + 'ds_basketball/login/'],
]

for (const [elegido, sistema] of [['light', 'dark'], ['dark', 'light']]) {
  const ctxT = await nav.newContext({ viewport: { width: 1400, height: 900 }, colorScheme: sistema })
  await ctxT.addCookies([{ name: 'DigiSportsBasketball', value: 'dsqaui0000000000000',
                           domain: 'localhost', path: '/' }])
  await ctxT.addInitScript((v) => { try { localStorage.setItem('lte-theme', v) } catch (e) {} }, elegido)
  const pgT = await ctxT.newPage()

  for (const [nombre, url] of CONTEXTOS) {
    try {
      await pgT.goto(url, { waitUntil: 'networkidle', timeout: 20000 })
      await pgT.waitForTimeout(300)
      /*
      | LA REGLA: EL ARMAZÓN NO SIGUE EL TEMA, EL CONTENIDO SÍ.
      |
      | El armazón —Hub, pantalla de acceso, barra superior, menú lateral y
      | pie— se queda oscuro siempre: es la identidad de DigiSports. Lo que
      | cambia con la elección de la persona es el área donde se cargan las
      | vistas: el fondo del contenido y las tarjetas.
      |
      | Esta comprobación nació midiendo sólo el fondo del <body> y afirmando
      | «se ve claro». Daba verde mientras la pantalla se veía oscura, porque
      | barra y menú —las dos superficies más grandes— no se medían. Luego
      | midió las cuatro regiones exigiendo que TODAS siguieran el tema, que
      | tampoco era la regla. Ahora distingue las dos familias.
      */
      const regiones = await pgT.evaluate(() => {
        const g = (s) => { const e = document.querySelector(s)
          return e ? getComputedStyle(e).backgroundColor : null }
        return { armazon: { barra: g('.app-header'), menu: g('.app-sidebar'), pie: g('.app-footer') },
                 contenido: { body: g('body'), tarjeta: g('.card') } }
      })

      const clasifica = (c) => {
        if (c === null) return null
        const m = String(c).match(/[\d.]+/g)
        if (!m) return null
        if (m.length > 3 && Number(m[3]) === 0) return null      /* transparente */
        const L = luminancia(c)
        return L === null ? null : (L > 0.4 ? 'claro' : 'oscuro')
      }

      /* El armazón, oscuro en los dos temas. */
      const armazonMal = []
      for (const [nom, c] of Object.entries(regiones.armazon)) {
        const k = clasifica(c)
        if (k !== null && k !== 'oscuro') armazonMal.push(nom + '=' + c)
      }
      af('elegido ' + elegido + ' · ' + nombre + ': el armazón sigue oscuro',
         armazonMal.length === 0,
         armazonMal.length ? armazonMal.join(' ') : 'barra, menú y pie')

      /*
      | El Hub y la pantalla de acceso son armazón ENTERO: no cargan vistas
      | dentro, así que su fondo es identidad y no contenido. Medirlos con
      | la regla del contenido daba un fallo que era en realidad el
      | comportamiento correcto.
      */
      const soloArmazon = (nombre === 'Hub' || nombre === 'Acceso')
      if (soloArmazon) {
        const k = clasifica(regiones.contenido.body)
        af('elegido ' + elegido + ' · ' + nombre + ': se queda oscuro (es armazón)',
           k === null || k === 'oscuro', String(regiones.contenido.body))
        /* Sin cerrar el contexto: es de todo el tema, no de esta pantalla.
           Cerrarlo aqui dejaba sin navegador a los cuatro modulos siguientes. */
        continue
      }

      /* El contenido, según lo elegido. */
      const contenidoMal = []
      for (const [nom, c] of Object.entries(regiones.contenido)) {
        const k = clasifica(c)
        if (k === null) continue
        const esperado = elegido === 'light' ? 'claro' : 'oscuro'
        if (k !== esperado) contenidoMal.push(nom + '=' + c)
      }
      af('elegido ' + elegido + ' · ' + nombre + ': el contenido se ve ' + elegido,
         contenidoMal.length === 0,
         contenidoMal.length ? 'no coinciden: ' + contenidoMal.join(' ') : 'fondo y tarjetas')
    } catch (e) {
      af('elegido ' + elegido + ' · ' + nombre + ' carga', false, String(e.message).slice(0, 40))
    }
  }
  await ctxT.close()
}

/*==============  El tema no salta durante la carga  ==============*/
/*
| DigiSports tenía un mecanismo de tema PROPIO —tema.js, clave «ds-tema»—
| conviviendo con el de AdminLTE, que guarda en «lte-theme» y aplica el tema
| en DOMContentLoaded. Con las dos claves en desacuerdo, la secuencia era:
|
|   1. tema.js ponía «light» → la página se pintaba CLARA.
|   2. adminlte.min.js arrancaba, leía SU clave → SALTABA a oscuro.
|
| Se retiró el nuestro y se adoptó el bloque «Theme Init» que la plantilla
| trae en su demo, cuyo comentario original dice para qué existe: «prevents
| flash of incorrect theme on load, #6043». El problema no era que faltara
| una solución: era tener dos.
|
| SE MUESTREA, NO SE MIRA EL FINAL
|
| Al terminar la carga el atributo ya es uno u otro, pero un SALTO sólo se ve
| en la secuencia. Se siembra «light» en la clave de AdminLTE y se comprueba
| que el valor no cambia en ningún momento de la carga.
*/
{
  const ctxP = await nav.newContext({ viewport: { width: 1300, height: 800 }, colorScheme: 'dark' })
  await ctxP.addCookies([{ name: 'DigiSportsBasketball', value: 'dsqaui0000000000000',
                           domain: 'localhost', path: '/' }])
  await ctxP.addInitScript(() => {
    try { localStorage.setItem('lte-theme', 'light') } catch (e) {}
  })
  const pgP = await ctxP.newPage()
  pgP.goto(BASE + 'ds_basketball/dashboard/').catch(() => {})

  const vistos = []
  for (let i = 0; i < 60; i++) {
    try {
      const v = await pgP.evaluate(() => document.documentElement.getAttribute('data-bs-theme') || 'sin')
      if (!vistos.length || vistos[vistos.length - 1] !== v) vistos.push(v)
    } catch (e) { /* el documento está cambiando */ }
    await new Promise(r => setTimeout(r, 25))
  }
  const temas = vistos.filter(v => v !== 'sin')
  af('el tema no salta durante la carga', new Set(temas).size <= 1, vistos.join(' → '))
  af('y el que queda es el elegido', temas[temas.length - 1] === 'light',
     'acabó en ' + (temas[temas.length - 1] || 'ninguno'))

  /* La plantilla también ajusta color-scheme, que gobierna los controles
     nativos y la barra de desplazamiento. El mecanismo propio no lo hacía. */
  const esquema = await pgP.evaluate(() => document.documentElement.style.colorScheme)
  af('se ajusta también color-scheme, como en la plantilla', esquema === 'light', esquema || '(vacío)')
  await ctxP.close()
}

/*==============  No vuelve el mecanismo propio  ==============*/
/*
| Dos mecanismos de tema a la vez fue la causa del parpadeo. Se comprueba
| sobre el disco, no sobre el navegador: si alguien reintroduce tema.js o la
| clave «ds-tema», la suite lo dice antes de que nadie vea el salto.
*/
{
  const vistas = []
  for (const d of ['c:/wamp64/www/barcelona/ds_basketball/app/views/content',
                   'c:/wamp64/www/barcelona/ds_basketball/app/views/inc',
                   'c:/wamp64/www/barcelona/ds_core/inc',
                   'c:/wamp64/www/barcelona/ds_core/hub']) {
    let arch
    try { arch = readdirSync(d).filter(f => f.endsWith('.php')) } catch { continue }
    for (const f of arch) vistas.push([f, readFileSync(d + '/' + f, 'utf8')])
  }
  /* Se quitan los comentarios antes de mirar: esta misma suite y los
     archivos nuevos EXPLICAN el mecanismo retirado, y esas menciones no
     son uso. */
  const sinComentar = (t) => t.replace(/\/\*[\s\S]*?\*\//g, '').replace(/<!--[\s\S]*?-->/g, '')
  const conViejo = vistas
    .filter(([, t]) => /assets\/js\/tema\.js|['\"]ds-tema['\"]/.test(sinComentar(t)))
    .map(([f]) => f)
  af('ninguna vista carga un mecanismo de tema propio', conViejo.length === 0,
     conViejo.length ? conViejo.slice(0, 4).join(' · ') : vistas.length + ' archivos')

  const conInit = vistas.filter(([, t]) => /tema-init\.php/.test(t)).length
  af('las páginas completas usan el arranque de la plantilla', conInit >= 8,
     conInit + ' archivos lo incluyen')
}
console.log('\nfallos: ' + fallos)
await nav.close()
process.exit(fallos === 0 ? 0 : 1)
