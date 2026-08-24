/*
| Mide el contraste real del menú lateral y lo deja en un atributo del DOM.
|
| Va en su propio archivo y no dentro de una plantilla de JavaScript
| incrustada en un heredoc de shell: ahí las barras invertidas se escapan
| dos veces, el script revienta en silencio y el atributo nunca se escribe.
| El síntoma es un «Cannot convert undefined to object» que no dice nada
| del problema real.
*/
(function () {
    var luminancia = function (color) {
        var p = color.match(/[\d.]+/g);
        if (!p) { return 0; }
        var v = p.slice(0, 3).map(function (x) {
            x = Number(x) / 255;
            return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2];
    };

    var razon = function (a, b) {
        var l1 = luminancia(a), l2 = luminancia(b);
        return ((Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05)).toFixed(2);
    };

    /* El fondo efectivo: se sube por los padres hasta encontrar uno que
       pinte algo. Mirar sólo el elemento da «transparent» casi siempre. */
    var fondoDe = function (el) {
        var n = el;
        while (n && n !== document.documentElement) {
            var bg = window.getComputedStyle(n).backgroundColor;
            if (bg && bg !== 'rgba(0, 0, 0, 0)' && bg !== 'transparent') { return bg; }
            n = n.parentElement;
        }
        return 'rgb(255, 255, 255)';
    };

    var objetivos = {
        menuInactivo: document.querySelector('.sidebar-menu .nav-link:not(.active) p'),
        menuActivo:   document.querySelector('.sidebar-menu .nav-link.active p'),
        marca:        document.querySelector('.brand-text'),
        tituloPagina: document.querySelector('.app-content-header h1'),
        textoTabla:   document.querySelector('.card-body td, .card-body p')
    };

    var salida = {};
    for (var k in objetivos) {
        var el = objetivos[k];
        if (!el) { salida[k] = { error: 'no encontrado' }; continue; }
        var c = window.getComputedStyle(el).color;
        var f = fondoDe(el);
        salida[k] = { texto: c, fondo: f, contraste: razon(c, f) };
    }

    document.documentElement.setAttribute('data-contraste', JSON.stringify(salida));
})();
