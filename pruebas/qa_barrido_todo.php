<?php
/*
| Barrido de todas las vistas del ecosistema tras los cambios de
| cotejamiento, conexión y pies de formulario.
|
| Se busca lo que un cambio de charset puede provocar y no salta a la
| vista: consultas que devuelven vacío en silencio, avisos de PHP, o una
| página que responde 200 pero no monta.
*/
$sid = 'dsqaui0000000000000';
file_put_contents('c:/wamp64/tmp/sess_' . $sid,
    'usuario|s:9:"qa_tester";usuarioid|i:1;rol|i:1;usuario_id|i:0;sede|s:0:"";nombre|s:2:"QA";foto|s:0:"";');

$modulos = [
    'ds_core/admin' => ['panel/', 'usuarioList/', 'rolList/', 'permisoRol/', 'menuList/',
                        'moduloRol/', 'organizacionForm/', 'sedeList/', 'catalogoList/',
                        'facturacionConfigSri/', 'carnetConfig/', 'puntoEmisionList/'],
    'ds_basketball' => ['dashboard/', 'representanteList/', 'alumnoList/', 'pagosList/',
                        'agenda/', 'asistencia/', 'reporteAsistencia/', 'asistenciaHora/',
                        'asistenciaLugar/', 'asistenciaListHorario/', 'empleadoList/',
                        'empleadoEntrada/', 'empleadoAsistencias/', 'ingresoList/',
                        'egresoList/', 'balanceResultados/', 'cobranzaPension/',
                        'cobranzaUniforme/', 'reportePagos/', 'reporteRubros/',
                        'facturasList/', 'carnetList/', 'cumpleaniosList/',
                        'consentimientoList/', 'inscripcionPendientes/',
                        'pagosRecibo/1054/', 'asistenciaVerHorario/2/',
                        'representanteFLPD/2/'],
    'ds_arena'      => ['panel/', 'instalacionList/', 'horarioList/', 'bloqueoList/',
                        'clienteList/'],
    'ds_league'     => ['panel/', 'temporadaList/', 'torneoList/', 'categoriaList/',
                        'equipoList/', 'partidoAgenda/', 'conceptoList/',
                        'cobranzaPanel/', 'cobranzaPanel/29/'],
];

$fallos = 0;
$total  = 0;

foreach ($modulos as $mod => $rutas) {
    echo "=== {$mod} ===\n";
    foreach ($rutas as $r) {
        $total++;
        $ch = curl_init("http://localhost/barcelona/{$mod}/{$r}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIE         => 'DigiSportsBasketball=' . $sid,
            CURLOPT_TIMEOUT        => 45,
        ]);
        $b    = (string)curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $phpErr = preg_match('/Fatal error|Parse error|Warning:|Notice:|Deprecated:/i', $b, $m);
        /* Un error de cotejamiento sale por aquí si algo lo imprime. */
        $colErr = stripos($b, 'Illegal mix of collations') !== false;

        $ok = $code === 200 && !$phpErr && !$colErr && strlen($b) > 1500;
        if (!$ok) { $fallos++; }

        printf("  %-28s %3d %7d b  %s\n", $r, $code, strlen($b),
            $ok ? 'OK'
                : ($colErr ? 'COTEJAMIENTO'
                : ($phpErr ? 'PHP: ' . $m[0]
                : ($code === 302 ? 'redirige' : 'FALLA'))));
    }
}

echo "\n{$total} vistas, {$fallos} con problema\n";
exit($fallos === 0 ? 0 : 1);
