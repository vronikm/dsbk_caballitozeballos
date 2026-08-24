/*
| El tema del ecosistema: claro, oscuro o el del sistema.
|
| COMO FUNCIONA EL TEMA EN BOOTSTRAP 5.3
|
| No se invierten colores con CSS propio. Bootstrap define un juego de
| variables en :root y lo redefine dentro de [data-bs-theme="dark"]. Todo lo
| que este escrito en terminos de esas variables cambia solo. Este archivo
| se limita a poner ese atributo y a recordar la eleccion.
|
| ESTE ARCHIVO SE CARGA EN EL <head> Y SIN «defer», A PROPOSITO
|
| Si se cargara al final del cuerpo, o con defer, la pagina se pintaria
| clara y saltaria a oscura una decima de segundo despues. Ese parpadeo es
| feo y, en una pantalla a oscuras, molesto de verdad. La unica forma de
| evitarlo es aplicar el atributo ANTES del primer pintado, y para eso el
| script tiene que ejecutarse mientras se lee la cabecera.
|
| Por eso el archivo hace dos cosas separadas en el tiempo: aplicar el tema
| ahora mismo, y enganchar el control cuando el documento este listo.
|
| TRES OPCIONES, NO DOS
|
| «Automatico» no es un adorno: quien tiene el sistema en oscuro por la
| noche espera que la aplicacion le siga sin tener que acordarse. Y hay que
| escuchar los cambios del sistema, no solo leerlos al arrancar, porque el
| sistema puede cambiar solo al anochecer con la aplicacion abierta.
|
| SI NO HAY DONDE GUARDAR, SIGUE FUNCIONANDO
|
| localStorage puede fallar: modo privado, permisos, cuota. Se envuelve en
| try/catch y la aplicacion se queda en automatico en vez de romperse.
*/
(function () {
    'use strict';

    var CLAVE = 'ds-tema';
    var raiz  = document.documentElement;
    var consulta = window.matchMedia('(prefers-color-scheme: dark)');

    function leerPreferencia() {
        try {
            var v = localStorage.getItem(CLAVE);
            return (v === 'light' || v === 'dark' || v === 'auto') ? v : 'auto';
        } catch (e) {
            return 'auto';
        }
    }

    function guardarPreferencia(modo) {
        try { localStorage.setItem(CLAVE, modo); } catch (e) { /* sin sitio: da igual */ }
    }

    /* El tema que toca pintar. «auto» pregunta al sistema. */
    function resolver(modo) {
        return modo === 'auto' ? (consulta.matches ? 'dark' : 'light') : modo;
    }

    function aplicar(modo) {
        raiz.setAttribute('data-bs-theme', resolver(modo));
        /* Se guarda aparte lo que ELIGIO el usuario: data-bs-theme solo
           puede decir claro u oscuro, y hace falta saber si eligio
           «automatico» para marcarlo en el menu. */
        raiz.setAttribute('data-ds-tema', modo);
    }

    /*----------  1. Ahora mismo, antes de pintar  ----------*/
    aplicar(leerPreferencia());

    /*----------  2. Seguir al sistema mientras este en automatico  ----------*/
    var alCambiarSistema = function () {
        if (leerPreferencia() === 'auto') { aplicar('auto'); }
    };
    if (consulta.addEventListener) { consulta.addEventListener('change', alCambiarSistema); }
    else if (consulta.addListener) { consulta.addListener(alCambiarSistema); }

    /*----------  3. El control, cuando exista el documento  ----------*/
    function marcarActivo() {
        var modo = leerPreferencia();
        var botones = document.querySelectorAll('[data-ds-tema-opcion]');
        for (var i = 0; i < botones.length; i++) {
            var suyo = botones[i].getAttribute('data-ds-tema-opcion');
            botones[i].classList.toggle('active', suyo === modo);
            botones[i].setAttribute('aria-current', suyo === modo ? 'true' : 'false');
        }
        /*
        | EL ICONO DICE QUE SE ESTA VIENDO, TAMBIEN EN AUTOMATICO.
        |
        | Antes, en «automatico» se ponia fa-adjust —un circulo medio
        | relleno— sin mirar el tema resuelto. El resultado: con el sistema
        | en oscuro, la pagina salia oscura y el icono no lo reflejaba. A
        | 16 px ese circulo se lee como un sol, asi que parecia estar
        | anunciando el tema contrario al que se veia.
        |
        | Ahora el icono muestra siempre luna u sol segun lo que hay en
        | pantalla. Que la eleccion sea «automatico» no desaparece: lo dice
        | el titulo al pasar el raton, y el menu marca su opcion activa.
        | Son dos cosas distintas —lo que se ve y lo que se eligio— y cada
        | una tiene su sitio.
        */
        var icono = document.querySelector('[data-ds-tema-icono]');
        var visible = resolver(modo);
        if (icono) {
            icono.className = 'fas ' + (visible === 'dark' ? 'fa-moon' : 'fa-sun');
        }

        /* El boton entero se reetiqueta, que es lo que oye quien navega con
           lector de pantalla y lo que lee quien pasa el raton. */
        var boton = icono ? icono.closest('a') : null;
        if (boton) {
            var comoSeVe = visible === 'dark' ? 'oscuro' : 'claro';
            var texto = modo === 'auto'
                ? 'Tema: automatico (ahora ' + comoSeVe + ')'
                : 'Tema: ' + comoSeVe;
            boton.setAttribute('title', texto);
            boton.setAttribute('aria-label', texto);
        }
    }

    function engancharControl() {
        var botones = document.querySelectorAll('[data-ds-tema-opcion]');
        for (var i = 0; i < botones.length; i++) {
            botones[i].addEventListener('click', function (e) {
                e.preventDefault();
                var modo = this.getAttribute('data-ds-tema-opcion');
                guardarPreferencia(modo);
                aplicar(modo);
                marcarActivo();
            });
        }
        marcarActivo();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', engancharControl);
    } else {
        engancharControl();
    }
})();
