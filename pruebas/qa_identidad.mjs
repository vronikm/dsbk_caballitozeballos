/*
| La identidad visual, en las vistas donde ya esta activada y en los dos temas.
|
| POR QUE HACE FALTA UNA SUITE PROPIA
|
| La activacion va por fases: una clase en el envoltorio de cada vista
| enciende 39 reglas EN ESA VISTA. Eso es lo que permite avanzar por bloques,
| pero tambien significa que cada bloque nuevo puede traer su propio
| estropicio sin que se note en los anteriores.
|
| Y como los colores del archivo ya se pasaron a las variables de Bootstrap
| 5.3, cada vista tiene que aguantar EN CLARO Y EN OSCURO. Un color que se
| escapara sin convertir se ve exactamente igual en los dos temas: por eso
| se comprueba tambien que el fondo CAMBIE.
|
| La lista de vistas no se escribe a mano: se pregunta cuales tienen la
| clase, asi la suite cubre sola las fases siguientes.
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
const EXT = 'C:/Users/dbafaces/.vscode/extensions/'
const RAIZ = EXT + readdirSync(EXT).filter(d => d.startsWith('danielsanmedium.dscodegpt-')).sort().pop() + '/standalone/'
const { chromium } = createRequire(RAIZ)('patchright')

const VISTAS_DIR = 'c:/wamp64/www/barcelona/ds_basketball/app/views/content'
const BASE = 'http://localhost/barcelona/ds_basketball/'

/* Las que necesitan un identificador en la URL: sin el redirigen a otra
   pantalla y se estaria midiendo algo distinto. */
const CON_ID = {
  pagosNew:                   '2',        /* toma un ALUMNO, no un pago */
  pagosUpdate:                '1',
  pagosRecibo:                '1',
  pagosPendiente:             '1',
  pagosDescuento:             '2',
  pagosUniformeUpdate:        '1',
  pagospendienteRecibo:       '2',
  pagospendienteUpdate:       '1',
  facturasNew:                '6',
  asistenciaAlumno:           '2',
  asistenciaHorarioLista:     '2',
  asistenciaVerHorario:       '2',
  buscarAsistencia:           '2',
  empleadoIE:                 '1',
  jugadorNew:                 '2/1',      /* torneo y equipo, dos segmentos */
  alumnoProfile:         '2',
  alumnoUpdate:          '2',
  representanteProfile:  '2',
  representanteUpdate:   '2',
  representanteFLPD:     '2',
  representanteVinc:     '2',
}

/*
| Vistas que NO se pueden comprobar por URL, y por que. Se declaran aqui en
| lugar de dejarlas fallar para siempre o —peor— saltarlas en silencio.
*/
const NO_ALCANZABLES = {
  empleadoAsistenciasDetalle: 'solo se llega enviando el formulario del reporte (POST)',
  empleadoDescargaEgreso:     'la tabla empleado_egreso esta vacia',
  empleadoEgresoUpdate:       'la tabla empleado_egreso esta vacia',
}

const activadas = readdirSync(VISTAS_DIR)
  .filter(f => f.endsWith('-view.php'))
  .filter(f => readFileSync(VISTAS_DIR + '/' + f, 'utf8').includes('app-wrapper ds-core'))
  .map(f => f.replace('-view.php', ''))
  .filter(v => !NO_ALCANZABLES[v])

const nav = await chromium.launch({ headless: true, channel: 'chromium' })
const ctx = await nav.newContext({ viewport: { width: 1500, height: 1000 } })
await ctx.addCookies([{ name: 'DigiSportsBasketball', value: 'dsqaui0000000000000',
                        domain: 'localhost', path: '/' }])
const p = await ctx.newPage()

let fallos = 0
const af = (t, ok, d = '') => {
  console.log('  ' + t.padEnd(48) + (ok ? 'OK' : 'FALLA') + (d ? '  (' + d + ')' : ''))
  if (!ok) fallos++
}

console.log('  vistas con la identidad activa: ' + activadas.length)
for (const [v, motivo] of Object.entries(NO_ALCANZABLES)) {
  console.log('  · ' + v + ': no se comprueba — ' + motivo)
}
console.log('  ' + activadas.join(' ') + '\n')

/* La medida de contraste, con las capas traslucidas COMPUESTAS: saltarselas
   da numeros que no son los que ve el ojo. */
const SONDA = `(() => {
  const aRgb = (s) => { const m = s.match(/[\\d.]+/g)
    return m ? { r:+m[0], g:+m[1], b:+m[2], a: m.length>3 ? +m[3] : 1 } : null }
  const lum = (c) => { const f = (v) => { v/=255
      return v <= 0.03928 ? v/12.92 : Math.pow((v+0.055)/1.055, 2.4) }
    return 0.2126*f(c.r) + 0.7152*f(c.g) + 0.0722*f(c.b) }
  const fondoReal = (el) => {
    const capas = []
    for (let n = el; n; n = n.parentElement) {
      const c = aRgb(getComputedStyle(n).backgroundColor)
      if (!c || c.a === 0) continue
      capas.push(c); if (c.a >= 0.999) break
    }
    let b = capas.pop() || { r:255, g:255, b:255, a:1 }
    while (capas.length) { const e = capas.pop()
      b = { r: e.r*e.a + b.r*(1-e.a), g: e.g*e.a + b.g*(1-e.a), b: e.b*e.a + b.b*(1-e.a), a:1 } }
    return b
  }
  const razon = (a, b) => { const [x,y] = [lum(a), lum(b)].sort((p,q) => q-p)
    return (x + 0.05) / (y + 0.05) }

  /* Un degradado o una imagen de fondo no tienen UN color: esta sonda solo
     sabe leer background-color. Se dio el caso —la barra de filtro de
     cumpleaños, texto blanco sobre un degradado verde— y salio un 1.05 que
     no existia: el degradado se pinta y el texto se lee perfectamente.
     Esos elementos se apartan, PERO SE CUENTAN: una comprobacion que se
     salta cosas en silencio no comprueba nada. */
  const sobreDegradado = (el) => {
    for (let n = el; n; n = n.parentElement) {
      const e = getComputedStyle(n)
      if (e.backgroundImage && e.backgroundImage !== 'none') return true
      const c = aRgb(e.backgroundColor)
      if (c && c.a >= 0.999) return false
    }
    return false
  }

  const malos = []
  let apartados = 0
  /* Los botones entran a proposito: el fallo que destapo la fase 2 fue
     justamente un boton —fondo naranja de marca con el texto blanco que
     pone Bootstrap, 2.63 de contraste—. Dejarlos fuera seria dejar fuera
     el unico sitio donde ya ha fallado esto. */
  document.querySelectorAll('.app-main .card-title, .app-main .card-header, .app-main p, '
    + '.app-main th, .app-main td, .app-main label, .app-main h3, .app-main .btn').forEach(el => {
    const txt = (el.innerText || '').trim()
    if (!txt) return
    const est = getComputedStyle(el)
    const t = aRgb(est.color); if (!t || t.a < 0.95) return
    if (sobreDegradado(el)) { apartados++; return }
    const rz = razon(t, fondoReal(el))
    const px = parseFloat(est.fontSize)
    const grande = px >= 24 || (px >= 18.66 && parseInt(est.fontWeight, 10) >= 700)
    if (rz < (grande ? 3 : 4.5)) malos.push(txt.slice(0, 14) + ' ' + rz.toFixed(2))
  })
  return { malos: [...new Set(malos)].slice(0, 3), apartados,
           fondo: getComputedStyle(document.querySelector('.app-main .card')
                  || document.body).backgroundColor,
           desborde: document.documentElement.scrollWidth - document.documentElement.clientWidth }
})()`

let conProblema = 0
let apartadosTotal = 0
const apartadosPorVista = {}

for (const vista of activadas) {
  const url = BASE + vista + '/' + (CON_ID[vista] ? CON_ID[vista] + '/' : '')
  const errores = []
  const oy = e => errores.push(e.message.slice(0, 70))
  p.on('pageerror', oy)

  const r = await p.goto(url, { waitUntil: 'networkidle' })
  await p.waitForTimeout(400)

  /* Que se haya llegado a la vista pedida y no a otra. */
  const llegada = await p.evaluate(() => location.pathname)
  if (!llegada.includes(vista)) {
    af(vista + ': llega a su pantalla', false, 'redirigió a ' + llegada)
    conProblema++; p.off('pageerror', oy); continue
  }

  const tieneClase = await p.evaluate(() =>
    !!document.querySelector('.app-wrapper.ds-core'))

  /*
  | LAS TRANSICIONES SE APAGAN ANTES DE MEDIR.
  |
  | Antes se cambiaba de tema y se dormia 300 ms. Con la maquina cargada eso
  | no basta, y la sonda denuncio un contraste de 1.16 en pagosRecibo. Al
  | cambiar la espera fija por «espera a que cambie el fondo» aparecieron
  | CINCO vistas con contrastes de 1.09 a 1.31, y tampoco eran ciertos:
  | midiendo el mismo texto tres veces salieron tres colores distintos
  | —rgb(44,48,52), rgb(52,56,60), rgb(127,131,135)—, que son fotogramas de
  | una transicion, no colores.
  |
  | AdminLTE y digisports.css animan «color» entre 0.15 s y 0.2 s. Cronometrar
  | una animacion es perder siempre: o se espera de mas, o se mide a medias.
  | Se apaga, que ademas es lo que hace cualquier prueba visual seria.
  |
  | El fondo se sigue esperando por si la hoja tarda, pero sin animacion de
  | por medio resuelve en el primer intento.
  */
  await p.addStyleTag({ content:
    '*, *::before, *::after { transition: none !important; animation: none !important; }' })

  const claro = await p.evaluate(SONDA)

  const fondoClaro = await p.evaluate(() => getComputedStyle(document.body).backgroundColor)
  await p.evaluate(() => document.documentElement.setAttribute('data-bs-theme', 'dark'))
  try {
    await p.waitForFunction(
      (antes) => getComputedStyle(document.body).backgroundColor !== antes,
      fondoClaro, { timeout: 5000 })
  } catch (e) {
    /* Si el fondo no cambia nunca, el aserto de mas abajo lo dice con su
       propio mensaje; aqui solo se deja de esperar. */
  }
  const oscuro = await p.evaluate(SONDA)
  if (claro.apartados) { apartadosTotal += claro.apartados; apartadosPorVista[vista] = claro.apartados }

  const bien = tieneClase
            && claro.malos.length === 0 && oscuro.malos.length === 0
            && claro.desborde <= 1 && oscuro.desborde <= 1
            && claro.fondo !== oscuro.fondo
            && errores.length === 0

  if (!bien) {
    conProblema++
    const por = []
    if (!tieneClase)               por.push('sin la clase')
    if (claro.malos.length)        por.push('claro: ' + claro.malos.join(' '))
    if (oscuro.malos.length)       por.push('oscuro: ' + oscuro.malos.join(' '))
    if (claro.desborde > 1)        por.push('desborda ' + claro.desborde + 'px')
    if (claro.fondo === oscuro.fondo) por.push('el fondo no cambia con el tema')
    if (errores.length)            por.push(errores[0])
    af(vista, false, por.join(' · '))
  }

  p.off('pageerror', oy)
}

af('las ' + activadas.length + ' vistas activadas están sanas', conProblema === 0,
   conProblema ? conProblema + ' con problema' : 'claro y oscuro, sin desbordes')

/* Cuantos textos quedaron fuera de la medida de contraste, y donde. Si un
   dia ese numero sube de golpe, es que alguien puso medio sistema sobre
   degradados y esta suite esta mirando cada vez menos. */
if (apartadosTotal > 0) {
  console.log('\n  textos sobre degradado, no medibles con esta sonda: '
    + apartadosTotal + ' (' + Object.entries(apartadosPorVista)
        .map(([v, n]) => v + ':' + n).join(' ') + ')')
}

console.log('\nfallos: ' + fallos)
await nav.close()
process.exit(fallos === 0 ? 0 : 1)
