<?php
/*
|--------------------------------------------------------------------------
| Vistas navegables del módulo Insights
|--------------------------------------------------------------------------
| Fuente única de verdad sobre qué rutas existen. La usan el front controller
| para resolver URLs y DigiSports Core para validar que un menú apunte a una
| vista real.
|
| Con DS_PERMISOS_ESTRICTOS activo, estar en esta lista NO basta para poder
| abrir la vista: además hay que tenerla concedida en seguridad_permiso. Esta
| lista dice qué existe; el permiso dice quién entra.
*/

return [
    /* Vista ejecutiva: el consolidado de los tres módulos. */
    'dashboard',

    /* Analítica por módulo. */
    'basketball',
    'arena',
    'league',

    /* Económico transversal. Se separan porque responden a preguntas
       distintas y se conceden por separado: ver ingresos y ver la deuda de
       los clientes no son el mismo permiso. */
    'financiero',
    'cartera',

    /* Catálogo de reportes y el detalle al que llega el drill-down. */
    'reporteList',
    'transacciones',

    /* Configuración de indicadores y umbrales del centro de atención. */
    'configuracion',
];
