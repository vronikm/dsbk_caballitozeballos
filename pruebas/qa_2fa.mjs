/*
| Segundo factor, de punta a punta y con el navegador.
|
| EL TOTP SE CALCULA AQUÍ, EN NODE, CON node:crypto.
|
| Es otra implementación distinta de la de PHP. Si el código que genera
| ésta lo acepta aquélla, las dos coinciden — y coincidir dos veces por
| separado es lo que hace creíble que también coincidan con Google
| Authenticator. Reutilizar la implementación de PHP para comprobarla
| habría demostrado únicamente que es consistente consigo misma.
|
| Se usa un usuario desechable: la prueba activa y desactiva el factor
| varias veces, y hacerlo sobre la cuenta real dejaría al usuario fuera si
| algo se interrumpiera a mitad.
*/
import { createRequire } from 'node:module'
import { readdirSync } from 'node:fs'
import { createHmac } from 'node:crypto'
const EXT = 'C:/Users/dbafaces/.vscode/extensions/'
const RAIZ = EXT + readdirSync(EXT).filter(d => d.startsWith('danielsanmedium.dscodegpt-')).sort().pop() + '/standalone/'
const { chromium } = createRequire(RAIZ)('patchright')

const HUB   = 'http://localhost/barcelona/'
const LOGIN = 'http://localhost/barcelona/ds_basketball/login/'

const USUARIO = process.env.QA2FA_USER
const CLAVE   = process.env.QA2FA_PASS
if (!USUARIO || !CLAVE) { console.log('faltan QA2FA_USER / QA2FA_PASS'); process.exit(2) }

let fallos = 0
const af = (t, ok, d = '') => {
  console.log('  ' + t.padEnd(58) + (ok ? 'OK' : 'FALLA') + (d ? '  (' + d + ')' : ''))
  if (!ok) fallos++
}

/*==============  TOTP en Node, independiente del de PHP  ==============*/
const base32aBytes = (s) => {
  const A = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'
  s = s.toUpperCase().replace(/[^A-Z2-7]/g, '')
  let bits = ''
  for (const ch of s) bits += A.indexOf(ch).toString(2).padStart(5, '0')
  const out = []
  for (let i = 0; i + 8 <= bits.length; i += 8) out.push(parseInt(bits.slice(i, i + 8), 2))
  return Buffer.from(out)
}

const totp = (secreto, desfase = 0) => {
  const paso = Math.floor(Date.now() / 1000 / 30) + desfase
  const buf = Buffer.alloc(8)
  buf.writeBigUInt64BE(BigInt(paso))
  const h = createHmac('sha1', base32aBytes(secreto)).update(buf).digest()
  const o = h[19] & 0x0f
  const n = ((h[o] & 0x7f) << 24) | (h[o + 1] << 16) | (h[o + 2] << 8) | h[o + 3]
  return String(n % 1000000).padStart(6, '0')
}

/*==============  Navegador  ==============*/
const nav = await chromium.launch({ headless: true, channel: 'chromium' })
const ctx = await nav.newContext({ viewport: { width: 1300, height: 950 } })
const p = await ctx.newPage()

const entrar = async (clave, esperarSegundoPaso) => {
  await p.goto(LOGIN, { waitUntil: 'networkidle' })
  await p.fill('#login_usuario', USUARIO)
  await p.fill('#login_clave', clave)
  await Promise.all([
    p.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {}),
    p.click('#botonEntrar')
  ])
  await p.waitForTimeout(700)
  return p.url()
}

const salir = async () => {
  await p.goto('http://localhost/barcelona/ds_basketball/logOut/', { waitUntil: 'networkidle' })
  await ctx.clearCookies()
}

/*==============  1. Primer acceso: un solo paso  ==============*/
let url = await entrar(CLAVE)
af('sin segundo factor, entra en un solo paso',
   !url.includes('/login/') && !url.includes('verificar2fa'), url.replace(HUB, ''))

/*==============  2. La pantalla existe y es alcanzable  ==============*/
const rSeg = await p.goto(HUB + '?p=seguridad', { waitUntil: 'networkidle' })
af('la pantalla de seguridad responde 200', rSeg.status() === 200, 'HTTP ' + rSeg.status())
af('parte de «Desactivada»',
   (await p.textContent('.sg-pastilla'))?.trim() === 'Desactivada',
   (await p.textContent('.sg-pastilla'))?.trim())

/*==============  3. Preparar  ==============*/
await p.click('#btnPreparar')
await p.waitForTimeout(900)
await p.click('.swal2-confirm').catch(() => {})
await p.waitForTimeout(1200)
await p.goto(HUB + '?p=seguridad', { waitUntil: 'networkidle' })

af('pasa a «A medio configurar»',
   (await p.textContent('.sg-pastilla'))?.trim() === 'A medio configurar',
   (await p.textContent('.sg-pastilla'))?.trim())

const hayQr = await p.evaluate(() => {
  const svg = document.querySelector('.sg-qr svg')
  return svg ? { rects: svg.querySelectorAll('rect').length,
                 w: svg.getAttribute('width') } : null
})
af('dibuja el código QR', hayQr !== null && hayQr.rects > 100,
   hayQr ? hayQr.rects + ' módulos, ' + hayQr.w + 'px' : 'no hay QR')

const secreto = (await p.inputValue('#claveManual')).replace(/\s+/g, '')
af('muestra también la clave para teclear a mano',
   /^[A-Z2-7]{32}$/.test(secreto), secreto.slice(0, 12) + '…')

/*==============  4. Un código equivocado no activa  ==============*/
await p.fill('#codigoConfirma', '000000')
await p.click('#btnActivar')
await p.waitForTimeout(1100)
const malActivar = await p.textContent('.swal2-title').catch(() => '')
af('un código equivocado no activa', /No se pudo activar/i.test(malActivar), malActivar)
await p.click('.swal2-confirm').catch(() => {})
await p.waitForTimeout(500)

/*==============  5. El código bueno activa  ==============*/
await p.fill('#codigoConfirma', totp(secreto))
await p.click('#btnActivar')
await p.waitForTimeout(1300)

const tituloCodigos = await p.textContent('.swal2-title').catch(() => '')
af('el código correcto activa la verificación',
   /Guarde estos códigos/i.test(tituloCodigos), tituloCodigos)

const codigos = await p.evaluate(() =>
  [...document.querySelectorAll('.swal2-html-container code')].map(c => c.textContent.trim()))
af('entrega diez códigos de recuperación', codigos.length === 10, codigos.length + '')
af('con el formato esperado',
   codigos.every(c => /^[2-9A-HJ-NP-Z]{4}-[2-9A-HJ-NP-Z]{4}$/.test(c)), codigos[0] ?? '')

await p.click('.swal2-confirm')
await p.waitForTimeout(1300)
await p.goto(HUB + '?p=seguridad', { waitUntil: 'networkidle' })
af('la pantalla dice «Activa»',
   (await p.textContent('.sg-pastilla'))?.trim() === 'Activa',
   (await p.textContent('.sg-pastilla'))?.trim())

/*==============  6. Ahora el acceso pide el código  ==============*/
await salir()
url = await entrar(CLAVE)
af('al entrar, ahora pide el segundo paso', url.includes('verificar2fa'), url.replace(HUB, ''))

/* LO MÁS IMPORTANTE DE TODA LA PRUEBA: no estar autenticado todavía.
   Si desde el paso intermedio se pudiera navegar a cualquier pantalla, el
   segundo factor no serviría de nada. */
const colado = await p.goto(HUB, { waitUntil: 'networkidle' })
const dentroSinCodigo = !p.url().includes('/login/')
af('desde el paso intermedio NO se puede saltar al Hub', !dentroSinCodigo,
   dentroSinCodigo ? 'ENTRÓ SIN EL CÓDIGO' : 'rebotado al login')

/* Repetir el acceso para volver al paso intermedio. */
url = await entrar(CLAVE)
af('vuelve al paso intermedio', url.includes('verificar2fa'))

/*==============  7. Código equivocado  ==============*/
await p.fill('#codigo_2fa', '000000')
await p.waitForTimeout(1500)
af('un código equivocado no deja pasar', p.url().includes('verificar2fa'),
   p.url().replace(HUB, ''))

/*==============  8. Código bueno  ==============*/
await p.goto(HUB + 'ds_basketball/verificar2fa/', { waitUntil: 'networkidle' })
await p.fill('#codigo_2fa', totp(secreto))
await p.waitForTimeout(2200)
af('el código correcto completa el acceso',
   !p.url().includes('verificar2fa') && !p.url().includes('/login/'),
   p.url().replace(HUB, ''))

/*==============  9. Código de recuperación  ==============*/
await salir()
await entrar(CLAVE)
await p.click('#alternar')
await p.waitForTimeout(300)
await p.fill('#codigo_rec', codigos[0])
await Promise.all([
  p.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {}),
  p.click('#formRecuperacion button[type=submit]')
])
await p.waitForTimeout(1400)
af('un código de recuperación deja entrar',
   !p.url().includes('verificar2fa') && !p.url().includes('/login/'),
   p.url().replace(HUB, ''))

/*==============  10. El mismo código NO sirve dos veces  ==============*/
await salir()
await entrar(CLAVE)
await p.click('#alternar')
await p.waitForTimeout(300)
await p.fill('#codigo_rec', codigos[0])
await Promise.all([
  p.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {}),
  p.click('#formRecuperacion button[type=submit]')
])
await p.waitForTimeout(1400)
af('el MISMO código de recuperación no sirve dos veces',
   p.url().includes('verificar2fa'), p.url().replace(HUB, ''))

/* Entrar de verdad para poder desactivar. */
await p.goto(HUB + 'ds_basketball/verificar2fa/', { waitUntil: 'networkidle' })
await p.fill('#codigo_2fa', totp(secreto))
await p.waitForTimeout(2200)

await p.goto(HUB + '?p=seguridad', { waitUntil: 'networkidle' })
const quedan = await p.evaluate(() => {
  const m = document.body.innerText.match(/sin usar:\s*(\d+)\s*de\s*10/)
  return m ? +m[1] : -1
})
af('queda un código menos tras usarlo', quedan === 9, quedan + ' de 10')

/*==============  11. Desactivar exige la contraseña  ==============*/
await p.click('#btnDesactivar')
await p.waitForTimeout(700)
await p.fill('.swal2-input', 'contraseña-que-no-es')
await p.click('.swal2-confirm')
await p.waitForTimeout(1200)
const malClave = await p.textContent('.swal2-title').catch(() => '')
af('con la contraseña equivocada no se desactiva',
   /Contraseña incorrecta/i.test(malClave), malClave)
await p.click('.swal2-confirm').catch(() => {})
await p.waitForTimeout(600)

await p.goto(HUB + '?p=seguridad', { waitUntil: 'networkidle' })
af('sigue activa tras el intento fallido',
   (await p.textContent('.sg-pastilla'))?.trim() === 'Activa')

/*==============  12. Desactivar de verdad  ==============*/
await p.click('#btnDesactivar')
await p.waitForTimeout(700)
await p.fill('.swal2-input', CLAVE)
await p.click('.swal2-confirm')
await p.waitForTimeout(1500)
await p.goto(HUB + '?p=seguridad', { waitUntil: 'networkidle' })
af('con la contraseña correcta se desactiva',
   (await p.textContent('.sg-pastilla'))?.trim() === 'Desactivada',
   (await p.textContent('.sg-pastilla'))?.trim())

/*==============  13. Vuelve a entrar en un paso  ==============*/
await salir()
url = await entrar(CLAVE)
af('el acceso vuelve a ser de un solo paso',
   !url.includes('verificar2fa') && !url.includes('/login/'), url.replace(HUB, ''))

/*==============  14. El historial registra lo ocurrido  ==============*/
await p.goto(HUB + '?p=seguridad', { waitUntil: 'networkidle' })
const acciones = await p.evaluate(() =>
  [...document.querySelectorAll('.sg-eti')].map(e => e.textContent.trim()))
af('el historial registra la activación', acciones.includes('ACTIVAR'), acciones.join(' '))
af('registra el uso del código de recuperación', acciones.includes('RECUPERACION'))
af('registra los intentos fallidos', acciones.includes('FALLO'))
af('registra la desactivación', acciones.includes('DESACTIVAR'))

console.log('\nfallos: ' + fallos)
await nav.close()
process.exit(fallos === 0 ? 0 : 1)
