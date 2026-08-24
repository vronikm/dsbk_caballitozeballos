/*
| La respuesta «limpiar» debe vaciar EL FORMULARIO ENVIADO.
|
| EL FALLO
|
| alertas_ajax hacía reset sobre el primer formulario del documento. Desde
| que el navbar se incluye antes que el contenido, ese primero es el de
| cambiar la contraseña. Consecuencia real: al registrar un alumno salía
| «Alumno registrado» y los datos SEGUÍAN en pantalla, invitando a pulsar
| Guardar otra vez y duplicar el registro.
|
| POR QUÉ SE PRUEBA SOBRE TORNEOS
|
| El único que responde «limpiar» es el alta de alumno, y un alumno son
| datos personales de un menor: no es una entidad desechable. Pero ajax.js
| es el mismo archivo para todo el módulo, así que se invoca la función
| directamente sobre el formulario de torneos y se comprueba a la vez lo que
| SÍ debe vaciarse y lo que NO debe tocarse.
|
| SE INYECTA CON addScriptTag, NO CON evaluate
|
| evaluate corre en un contexto aislado y desde ahí alertas_ajax no existe.
| El resultado vuelve por un atributo del documento, que sí es compartido.
*/
import { createRequire } from 'node:module'
import { readdirSync } from 'node:fs'
const EXT = 'C:/Users/dbafaces/.vscode/extensions/'
const RAIZ = EXT + readdirSync(EXT).filter(d => d.startsWith('danielsanmedium.dscodegpt-')).sort().pop() + '/standalone/'
const { chromium } = createRequire(RAIZ)('patchright')

const BASE = 'http://localhost/barcelona/ds_basketball/'
const nav = await chromium.launch({ headless: true, channel: 'chromium' })
const ctx = await nav.newContext({ viewport: { width: 1450, height: 1000 } })
await ctx.addCookies([{ name: 'DigiSportsBasketball', value: 'dsqaui0000000000000',
                        domain: 'localhost', path: '/' }])
const p = await ctx.newPage()

let fallos = 0
const af = (t, ok, d = '') => {
  console.log('  ' + t.padEnd(52) + (ok ? 'OK' : 'FALLA') + (d ? '  (' + d + ')' : ''))
  if (!ok) fallos++
}

await p.goto(BASE + 'torneoList/', { waitUntil: 'networkidle' })

const MARCA = 'no-tocar-esto'

/* Se ensucian los dos: el que debe limpiarse y el que no. */
await p.evaluate((marca) => {
  document.querySelector('[name="torneo_nombre"]').value = 'texto de prueba'
  const clave = document.querySelector('#formCambiarClave [name="usuario_clave"]')
             || document.querySelector('#formCambiarClave input[type="password"]')
  if (clave) { clave.value = marca }
}, MARCA)

const antes = await p.evaluate(() => ({
  torneo: document.querySelector('[name="torneo_nombre"]').value,
  clave: (document.querySelector('#formCambiarClave input[type="password"]') || {}).value ?? '(no hay)'
}))
af('el estado de partida es el esperado',
   antes.torneo === 'texto de prueba' && antes.clave === MARCA,
   JSON.stringify(antes))

/* La llamada, en el mundo de la página, tal como la haría ajax.js. */
await p.addScriptTag({ content: `
  (function () {
    var f = document.querySelector('form.FormularioAjax:has(input[name="modulo_torneo"][value="registrar"])');
    document.documentElement.setAttribute('data-qa-existe', f ? 'si' : 'no');
    document.documentElement.setAttribute('data-qa-arity', String(alertas_ajax.length));
    alertas_ajax({ tipo: 'limpiar', titulo: 'Prueba', texto: 'Prueba', icono: 'success' }, f);
  })();
` })

const dato = (n) => p.evaluate((n) => document.documentElement.getAttribute(n), n)
af('alertas_ajax acepta el formulario como parámetro',
   (await dato('data-qa-arity')) === '2', 'aridad ' + await dato('data-qa-arity'))
af('el formulario de alta está localizado', (await dato('data-qa-existe')) === 'si')

/* El reset ocurre al aceptar el aviso. */
await p.waitForSelector('.swal2-confirm', { state: 'visible', timeout: 6000 })
await p.click('.swal2-confirm')
await p.waitForTimeout(900)

const despues = await p.evaluate(() => ({
  torneo: document.querySelector('[name="torneo_nombre"]').value,
  clave: (document.querySelector('#formCambiarClave input[type="password"]') || {}).value ?? '(no hay)'
}))

af('vacía el formulario que se envió', despues.torneo === '',
   'quedó: ' + JSON.stringify(despues.torneo))
af('NO toca el formulario de contraseña del navbar', despues.clave === MARCA,
   'quedó: ' + JSON.stringify(despues.clave))

console.log('\nfallos: ' + fallos)
await nav.close()
process.exit(fallos === 0 ? 0 : 1)
