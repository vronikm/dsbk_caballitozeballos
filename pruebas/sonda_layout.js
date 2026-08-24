/*
| Sonda de maquetación. Deja el resultado en un atributo del DOM, que es
| lo único que comparten el contexto aislado del navegador y el de la
| página.
*/
(function () {
    var res = { desborde: false, anchoTotal: 0, contrasteMalo: 0, detalle: [] };

    /* 1. ¿Se desplaza la página en horizontal? */
    res.desborde = document.documentElement.scrollWidth > window.innerWidth + 2;

    /* 2. Controles que ocupan TODA la fila dentro de un contenedor flex
          horizontal. Es la huella de un .form-control que en Bootstrap 4
          tenía width:auto por estar dentro de .form-inline. */
    var flexes = document.querySelectorAll('.d-flex:not(.flex-column)');
    for (var i = 0; i < flexes.length; i++) {
        var cont = flexes[i];
        var estilo = window.getComputedStyle(cont);
        if (estilo.flexDirection !== 'row') { continue; }

        var ctrls = cont.querySelectorAll(':scope > .form-control, :scope > .form-select,'
                                        + ':scope > form > .form-control, :scope > form > .form-select');
        if (ctrls.length < 2) { continue; }

        var anchoCont = cont.getBoundingClientRect().width;
        for (var j = 0; j < ctrls.length; j++) {
            var b = ctrls[j].getBoundingClientRect();
            /* Ocupa casi todo el ancho del contenedor y hay más de uno:
               van a apilarse. */
            if (b.width > anchoCont * 0.9 && anchoCont > 200) {
                res.anchoTotal++;
                res.detalle.push('ancho total: ' + (ctrls[j].name || ctrls[j].id || '?'));
            }
        }
    }

    /* 3. Contraste del texto visible más común. */
    var lum = function (c) {
        var p = c.match(/[\d.]+/g);
        if (!p) { return 0; }
        var v = p.slice(0, 3).map(function (x) {
            x = Number(x) / 255;
            return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2];
    };
    var razon = function (a, b) {
        var l1 = lum(a), l2 = lum(b);
        return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
    };
    /*
    | EL CANAL ALFA IMPORTA, Y NO TENERLO EN CUENTA INVENTA PROBLEMAS.
    |
    | El elemento activo del menú tiene rgba(255,255,255,0.1): una veladura
    | blanca sobre un fondo oscuro, que a la vista queda gris oscuro. Leído
    | sin el alfa se toma por blanco puro, y entonces el texto blanco encima
    | «da» contraste 1.00. Con eso la sonda marcó 30 vistas como ilegibles
    | cuando ninguna lo era.
    |
    | Un fondo semitransparente se compone con lo que hay detrás, así que
    | no es el fondo efectivo: se sigue subiendo hasta encontrar uno opaco.
    */
    var fondoDe = function (el) {
        var n = el;
        while (n && n !== document.documentElement) {
            var bg = window.getComputedStyle(n).backgroundColor;
            if (bg && bg !== 'rgba(0, 0, 0, 0)' && bg !== 'transparent') {
                var m = bg.match(/rgba?\(([^)]+)\)/);
                var partes = m ? m[1].split(',') : [];
                var alfa = partes.length > 3 ? parseFloat(partes[3]) : 1;
                if (alfa >= 0.95) { return bg; }
            }
            n = n.parentElement;
        }
        return 'rgb(255, 255, 255)';
    };

    var textos = document.querySelectorAll(
        '.sidebar-menu .nav-link p, .card-title, .app-content-header h1, '
        + '.badge, .btn-primary, thead th');

    for (var k = 0; k < textos.length; k++) {
        var el = textos[k];
        if (!el.offsetParent && el.offsetHeight === 0) { continue; }
        var txt = (el.innerText || '').trim();
        if (txt === '') { continue; }
        var c = window.getComputedStyle(el).color;
        var f = fondoDe(el);
        if (razon(c, f) < 4.5) {
            res.contrasteMalo++;
            if (res.detalle.length < 8) {
                res.detalle.push('contraste ' + razon(c, f).toFixed(2)
                    + ' en «' + txt.slice(0, 28) + '»');
            }
        }
    }

    document.documentElement.setAttribute('data-layout', JSON.stringify(res));
})();
