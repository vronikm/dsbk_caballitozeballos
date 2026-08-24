/*
| Apagar transiciones y animaciones antes de medir colores.
|
| POR QUE HACE FALTA
|
| qa_identidad medía el contraste tras cambiar de tema y esperar 300 ms.
| Con la máquina cargada denunció un contraste de 1.16 en pagosRecibo que no
| existía. Al cambiar la espera fija por «espera a que cambie el fondo»
| aparecieron cinco vistas entre 1.09 y 1.31 — y tampoco eran ciertas:
| midiendo el mismo texto tres veces salieron tres colores distintos,
| rgb(44,48,52), rgb(52,56,60) y rgb(127,131,135). No eran colores: eran
| fotogramas de una transición.
|
| AdminLTE y digisports.css animan «color» entre 0.15 s y 0.2 s. Cronometrar
| una animación es perder siempre: o se espera de más y la prueba se
| eterniza, o se mide a medias y el resultado es ficción. Se apaga.
|
| SE INSTALA EN EL CONTEXTO, NO EN LA PÁGINA
|
| Con addInitScript se aplica en CADA navegación sin que la suite tenga que
| acordarse de repetirlo. Una suite que recorre setenta vistas no puede
| depender de que nadie olvide una línea.
|
| USO
|
|     import { sinAnimacion } from './sin_animacion.mjs'
|     const ctx = await nav.newContext({ ... })
|     await sinAnimacion(ctx)
*/
export async function sinAnimacion(ctx) {
  await ctx.addInitScript(() => {
    const poner = () => {
      const s = document.createElement('style')
      s.setAttribute('data-qa-sin-animacion', '')
      s.textContent = '*, *::before, *::after {' +
        ' transition: none !important;' +
        ' animation: none !important;' +
        ' scroll-behavior: auto !important; }'
      document.head.appendChild(s)
    }
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', poner)
    } else {
      poner()
    }
  })
}
