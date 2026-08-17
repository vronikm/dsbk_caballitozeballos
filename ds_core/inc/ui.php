<?php
/*
|--------------------------------------------------------------------------
| Piezas de interfaz comunes a los módulos administrativos
|--------------------------------------------------------------------------
| Un estándar escrito sólo en la documentación se incumple a la tercera
| pantalla. Aquí se convierte en funciones: si todas las vistas piden sus
| botones a ds_boton()/ds_acciones(), no pueden salirse del patrón.
|
| Reglas que imponen estas funciones:
|   · Las acciones van a la derecha, en el mismo orden: salir primero,
|     confirmar al final.
|   · Todos los botones de acción tienen el mismo tamaño (el de Bootstrap
|     por defecto) y el mismo ancho mínimo.
|   · Cada acción tiene un icono asignado, siempre el mismo en todo el
|     ecosistema. Ver ds_icono().
*/

if (!function_exists('ds_icono')) {

    /**
     * Icono canónico de cada acción.
     *
     * El objetivo es que "guardar" se dibuje igual en Core, en Arena y en
     * Basketball. Si una acción no está en la tabla se devuelve un círculo
     * neutro, que llama la atención sin romper la pantalla.
     */
    function ds_icono(string $accion): string
    {
        $iconos = [
            /* Navegación */
            'volver'    => 'fas fa-arrow-left',
            'ver'       => 'fas fa-eye',
            'detalle'   => 'fas fa-list',
            /* Escritura */
            'nuevo'     => 'fas fa-plus',
            'guardar'   => 'fas fa-save',
            'editar'    => 'fas fa-pen',
            'eliminar'  => 'fas fa-trash',
            'quitar'    => 'fas fa-times',
            /* Estado */
            'bloqueado' => 'fas fa-lock',
            'aviso'     => 'fas fa-info-circle',
            'alerta'    => 'fas fa-exclamation-triangle',
            /* Documentos y archivos */
            'imprimir'  => 'fas fa-print',
            'descargar' => 'fas fa-download',
            'subir'     => 'fas fa-upload',
            'enviar'    => 'fas fa-paper-plane',
            'probar'    => 'fas fa-vial',
        ];

        return $iconos[$accion] ?? 'far fa-circle';
    }

    /**
     * Botón con el icono y el tamaño del estándar.
     *
     * $opciones admite: href, type, class, title, name, value, id, target,
     * data (array de atributos data-*), disabled.
     */
    function ds_boton(string $accion, string $texto, array $opciones = []): string
    {
        $estilo = $opciones['estilo'] ?? 'secondary';
        $clases = trim('btn btn-' . $estilo . ' ' . ($opciones['class'] ?? ''));

        $attr = '';
        foreach (['title', 'id', 'name', 'value', 'target'] as $a) {
            if (!empty($opciones[$a])) {
                $attr .= ' ' . $a . '="' . htmlspecialchars((string)$opciones[$a], ENT_QUOTES, 'UTF-8') . '"';
            }
        }
        foreach ($opciones['data'] ?? [] as $clave => $valor) {
            $attr .= ' data-' . $clave . '="' . htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8') . '"';
        }
        if (!empty($opciones['disabled'])) {
            $attr .= ' disabled';
        }

        $contenido = '<i class="' . ds_icono($accion) . ' mr-1"></i> '
                   . htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');

        if (!empty($opciones['href'])) {
            return '<a href="' . htmlspecialchars((string)$opciones['href'], ENT_QUOTES, 'UTF-8')
                 . '" class="' . $clases . '"' . $attr . '>' . $contenido . '</a>';
        }

        $tipo = $opciones['type'] ?? 'submit';
        return '<button type="' . $tipo . '" class="' . $clases . '"' . $attr . '>' . $contenido . '</button>';
    }

    /**
     * Pie de formulario: Volver y Guardar, siempre a la derecha.
     *
     * $nota es un texto que queda a la izquierda sin desplazar los botones;
     * sirve para avisos del tipo «Su rol sólo puede consultar».
     */
    function ds_acciones_form(string $urlVolver, array $opciones = []): string
    {
        $guardar = $opciones['guardar'] ?? 'Guardar';
        $volver  = $opciones['volver']  ?? 'Volver';
        $nota    = trim((string)($opciones['nota'] ?? ''));

        $html = '<div class="card-footer ds-acciones">';

        if ($nota !== '') {
            $html .= '<span class="ds-acciones__nota">' . $nota . '</span>';
        }

        $html .= ds_boton('volver', $volver, ['href' => $urlVolver, 'estilo' => 'secondary']);

        if (($opciones['soloLectura'] ?? false) !== true) {
            $html .= ds_boton('guardar', $guardar, ['estilo' => 'primary', 'type' => 'submit']);
        }

        return $html . '</div>';
    }

    /** Celda de acciones de una tabla: iconos del mismo tamaño, a la derecha. */
    function ds_acciones_tabla(string $contenido): string
    {
        return '<td class="ds-tabla-acciones">' . $contenido . '</td>';
    }

    /** Botón de icono para una fila de tabla. */
    function ds_boton_fila(string $accion, string $titulo, array $opciones = []): string
    {
        $estilo = $opciones['estilo'] ?? 'outline-secondary';
        $attr   = ' title="' . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . '"';

        foreach (['id', 'target'] as $a) {
            if (!empty($opciones[$a])) {
                $attr .= ' ' . $a . '="' . htmlspecialchars((string)$opciones[$a], ENT_QUOTES, 'UTF-8') . '"';
            }
        }

        $icono = '<i class="' . ds_icono($accion) . '"></i>';

        if (!empty($opciones['href'])) {
            return '<a href="' . htmlspecialchars((string)$opciones['href'], ENT_QUOTES, 'UTF-8')
                 . '" class="btn btn-sm btn-' . $estilo . '"' . $attr . '>' . $icono . '</a>';
        }

        return '<button type="submit" class="btn btn-sm btn-' . $estilo . '"' . $attr . '>' . $icono . '</button>';
    }

    /** Hueco del ancho de un botón, para que las filas no se descuadren. */
    function ds_hueco(string $titulo = ''): string
    {
        return '<span class="ds-hueco" title="' . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . '">'
             . '<i class="' . ds_icono('bloqueado') . '"></i></span>';
    }

    /**
     * Ancho del contenedor de un formulario.
     *
     * Se centraliza para que todas las fichas midan lo mismo: antes había
     * col-lg-8, col-lg-9 y col-lg-7 según quién escribiera la vista.
     */
    function ds_ancho_form(): string
    {
        return 'col-lg-9';
    }
}
