<?php

namespace insights\controllers;

use PDO;
use InsightsConexion;

require_once __DIR__ . '/../config/conexion.php';

/*
|--------------------------------------------------------------------------
| Controlador de DigiSports Insights
|--------------------------------------------------------------------------
| Sigue la convencion de Arena y League: el controlador habla con PDO
| directamente, sin capa models/. Se eligio asi porque es la convencion de
| los dos modulos recientes, y porque su front controller devuelve 403 de
| verdad y admite DS_PERMISOS_ESTRICTOS, cosa que el de Basketball no.
|
|
| LA CONEXION ES DISTINTA, Y ESE ES EL PUNTO
|
| No usa seguridad_conexion() como los demas modulos, sino
| InsightsConexion, que RECHAZA escribir fuera de insights_*. El encargo
| pide que Insights consulte y no modifique nada de Basketball, Arena ni
| League; con la conexion compartida eso seria una intencion, porque el
| usuario de MySQL tiene escritura sobre las 93 tablas.
|
| Su limite esta escrito en config/conexion.php: inspecciona el texto de la
| sentencia, no lo impone el motor. La garantia fuerte sigue siendo un GRANT
| de solo lectura, que no cambiaria una linea de este archivo.
*/

class insightsController
{
    private ?PDO $con = null;

    /*==============  Acceso a datos  ==============*/

    protected function conexion(): PDO
    {
        if ($this->con === null) {
            $this->con = InsightsConexion::abrir();
        }
        return $this->con;
    }

    /** Varias filas. Siempre con marcadores: nunca concatenando. */
    protected function filas(string $sql, array $params = []): array
    {
        $q = $this->conexion()->prepare($sql);
        $q->execute($params);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Una sola celda, con valor por defecto si no hay fila. */
    protected function escalar(string $sql, array $params = [], $siNada = 0)
    {
        $q = $this->conexion()->prepare($sql);
        $q->execute($params);
        $v = $q->fetchColumn();
        return $v === false ? $siNada : $v;
    }

    /*==============  Ambito de sede del usuario  ==============*/
    /*
    | seguridad_usuario_sede limita a algunos usuarios a determinadas sedes.
    | Insights TIENE que respetarlo: un coordinador que solo ve su sede en
    | Basketball no puede verlas todas porque el informe sea consolidado.
    |
    | Devuelve [] cuando el usuario no esta limitado, y entonces no se filtra.
    | Se distingue de «limitado a ninguna sede», que devolveria una lista
    | vacia de resultados y no acceso total.
    */
    public function sedesDelUsuario(): array
    {
        if (es_superadministrador()) {
            return [];
        }

        $filas = $this->filas(
            "SELECT usuariosede_sedeid FROM seguridad_usuario_sede
              WHERE usuariosede_usuarioid = :u",
            [':u' => usuario_actual_id()]
        );

        return array_map('intval', array_column($filas, 'usuariosede_sedeid'));
    }

    /*==============  Menu lateral  ==============*/
    /*
    | Se arma desde seguridad_menu, no desde una lista en codigo: asi el menu
    | y los permisos no pueden desincronizarse. Un rol solo ve las entradas
    | que tiene concedidas.
    */
    public function menuLateral(string $vistaActual): string
    {
        $sql = "SELECT m.menu_vista, m.menu_nombre, m.menu_icono
                  FROM seguridad_menu m
                 WHERE m.menu_estado = 'A' AND m.menu_modulo = :modulo";

        $params = [':modulo' => DS_MODULO];

        if (!es_superadministrador()) {
            $sql .= " AND EXISTS (SELECT 1 FROM seguridad_permiso p
                                   WHERE p.permiso_menuid = m.menu_id
                                     AND p.permiso_rolid  = :rol
                                     AND p.permiso_estado = 'A'
                                     AND p.permiso_ver    = 'S')";
            $params[':rol'] = rol_actual();
        }
        $sql .= " ORDER BY m.menu_orden";

        $html = '';
        foreach ($this->filas($sql, $params) as $f) {
            $activo = ($f['menu_vista'] === $vistaActual) ? ' active' : '';
            $html .= '<li class="nav-item">'
                   . '<a href="' . APP_URL . $f['menu_vista'] . '/" class="nav-link' . $activo . '">'
                   . '<i class="nav-icon ' . htmlspecialchars((string) $f['menu_icono'], ENT_QUOTES, 'UTF-8') . '"></i>'
                   . '<p>' . htmlspecialchars((string) $f['menu_nombre'], ENT_QUOTES, 'UTF-8') . '</p>'
                   . '</a></li>';
        }

        return $html;
    }

    /*==============  Diagnostico de la instalacion  ==============*/
    /*
    | Lo que el panel muestra mientras no haya tableros: si el modulo esta
    | bien montado. Cada linea comprueba una pieza de las que este modulo
    | depende, para que un despliegue incompleto se vea en la primera
    | pantalla y no al abrir un informe.
    */
    public function diagnostico(): array
    {
        $d = [];

        $d['permisos'] = [
            'etiqueta' => 'Permisos',
            'valor'    => permisos_estrictos() ? 'Estrictos' : 'Permisivos',
            'detalle'  => permisos_estrictos()
                ? 'Una vista no registrada se deniega'
                : 'ATENCION: lo no registrado quedaria abierto',
            'ok'       => permisos_estrictos(),
        ];

        $vistas = require __DIR__ . '/../config/vistas.php';
        $registradas = (int) $this->escalar(
            "SELECT COUNT(*) FROM seguridad_menu
              WHERE menu_modulo = :m AND menu_estado = 'A'",
            [':m' => DS_MODULO]
        );
        $d['vistas'] = [
            'etiqueta' => 'Vistas',
            'valor'    => $registradas . ' de ' . count($vistas),
            'detalle'  => 'Registradas en el menú frente a las declaradas',
            'ok'       => $registradas > 0,
        ];

        /* La conexion de solo lectura, comprobada de verdad: se intenta una
           escritura prohibida y se espera que falle. */
        $candado = false;
        try {
            $this->conexion()->prepare('UPDATE alumno_pago SET pago_valor = 0');
        } catch (\Throwable $e) {
            $candado = true;
        }
        $d['conexion'] = [
            'etiqueta' => 'Conexión',
            'valor'    => $candado ? 'Sólo lectura' : 'ESCRITURA ABIERTA',
            'detalle'  => $candado
                ? 'Rechaza escribir fuera de insights_*'
                : 'ATENCION: Insights podría modificar datos de otros módulos',
            'ok'       => $candado,
        ];

        $graficos = is_file(DS_HUB_PATH . 'ds_core/assets/vendor/apexcharts3/js/apexcharts.min.js');
        $d['graficos'] = [
            'etiqueta' => 'Gráficos',
            'valor'    => $graficos ? 'ApexCharts 3' : 'NO INSTALADA',
            'detalle'  => $graficos ? 'Autoalojada, sin CDN' : 'Falta el vendor',
            'ok'       => $graficos,
        ];

        $snapshots = (int) $this->escalar('SELECT COUNT(*) FROM insights_cartera_snapshot');
        $d['cartera'] = [
            'etiqueta' => 'Fotos de cartera',
            'valor'    => (string) $snapshots,
            'detalle'  => $snapshots > 0
                ? 'Capturadas con cli/capturar_cartera.php'
                : 'Aún sin capturar: la cartera no se podrá comparar',
            'ok'       => $snapshots > 0,
        ];

        $asistencia = (int) $this->escalar(
            "SELECT COUNT(*) FROM information_schema.VIEWS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'insights_v_asistencia_dia'"
        );
        $d['asistencia'] = [
            'etiqueta' => 'Asistencia por día',
            'valor'    => $asistencia === 1 ? 'Disponible' : 'NO CREADA',
            'detalle'  => $asistencia === 1
                ? 'Vista que despivota las 31 columnas'
                : 'Falta la migración 047',
            'ok'       => $asistencia === 1,
        ];

        return $d;
    }

    /*==============  Periodo y comparacion  ==============*/
    /*
    | Todo el tablero trabaja con un periodo y su comparable. Se resuelve una
    | sola vez y se pasa a cada consulta: si cada una calculara sus propias
    | fechas, dos tarjetas de la misma pantalla podrian estar mirando meses
    | distintos sin que nadie lo notara.
    */
    public function periodo(?string $desde = null, ?string $hasta = null): array
    {
        $hoy = new \DateTimeImmutable('today');

        $d = $desde !== null && preg_match('~^\d{4}-\d{2}-\d{2}$~', $desde)
            ? new \DateTimeImmutable($desde)
            : $hoy->modify('first day of this month');

        $h = $hasta !== null && preg_match('~^\d{4}-\d{2}-\d{2}$~', $hasta)
            ? new \DateTimeImmutable($hasta)
            : $hoy->modify('last day of this month');

        if ($h < $d) { [$d, $h] = [$h, $d]; }

        /* El comparable es el periodo inmediatamente anterior de la MISMA
           duracion. Comparar un mes con un trimestre daria una variacion sin
           sentido y con aspecto de dato. */
        $dias = (int) $d->diff($h)->days + 1;
        $hAnt = $d->modify('-1 day');
        $dAnt = $hAnt->modify('-' . ($dias - 1) . ' days');

        return [
            'desde'    => $d->format('Y-m-d'),
            'hasta'    => $h->format('Y-m-d'),
            'antDesde' => $dAnt->format('Y-m-d'),
            'antHasta' => $hAnt->format('Y-m-d'),
            'dias'     => $dias,
            'etiqueta' => $d->format('d/m/Y') . ' — ' . $h->format('d/m/Y'),
        ];
    }

    /*==============  Ingresos cobrados  ==============*/
    /*
    | Los tres origenes, cada uno con el estado que significa «cobrado» en su
    | modulo: 'C' en Basketball, 'A' en Arena, y en League un abono no
    | anulado. No se unifican los codigos: cada modulo es dueno de los suyos.
    */
    public function ingresos(array $p, bool $anterior = false): array
    {
        $d = $anterior ? $p['antDesde'] : $p['desde'];
        $h = $anterior ? $p['antHasta'] : $p['hasta'];

        $r = [
            'basketball' => (float) $this->escalar(
                "SELECT IFNULL(SUM(pago_valor),0) FROM alumno_pago
                  WHERE pago_estado = 'C' AND pago_fecha BETWEEN :d AND :h",
                [':d' => $d, ':h' => $h]),
            'arena' => (float) $this->escalar(
                "SELECT IFNULL(SUM(pago_valor),0) FROM dsa_pago
                  WHERE pago_estado = 'A' AND pago_fecha BETWEEN :d AND :h",
                [':d' => $d, ':h' => $h]),
            'league' => (float) $this->escalar(
                "SELECT IFNULL(SUM(abono_valor),0) FROM dsl_abono
                  WHERE abono_anulado = 'N' AND abono_fecha BETWEEN :d AND :h",
                [':d' => $d, ':h' => $h]),
        ];
        $r['total'] = $r['basketball'] + $r['arena'] + $r['league'];
        return $r;
    }

    /** Numero de cobros del periodo, en los tres modulos. */
    public function transacciones(array $p, bool $anterior = false): int
    {
        $d = $anterior ? $p['antDesde'] : $p['desde'];
        $h = $anterior ? $p['antHasta'] : $p['hasta'];

        return (int) $this->escalar(
            "SELECT
               (SELECT COUNT(*) FROM alumno_pago WHERE pago_estado='C' AND pago_fecha BETWEEN :d1 AND :h1)
             + (SELECT COUNT(*) FROM dsa_pago    WHERE pago_estado='A' AND pago_fecha BETWEEN :d2 AND :h2)
             + (SELECT COUNT(*) FROM dsl_abono   WHERE abono_anulado='N' AND abono_fecha BETWEEN :d3 AND :h3)",
            [':d1' => $d, ':h1' => $h, ':d2' => $d, ':h2' => $h, ':d3' => $d, ':h3' => $h]);
    }

    /*==============  Lo que se debe  ==============*/
    /*
    | SIN comparacion con el periodo anterior, y es deliberado. La cartera de
    | Basketball es una proyeccion desde HOY: subir la pension un dolar la
    | infla 217 sin que nadie deje de pagar. Comparar dos proyecciones hechas
    | desde el mismo instante no mide nada.
    |
    | Para compararla estan las fotos de insights_cartera_snapshot, que es
    | justamente para lo que existen.
    */
    public function porCobrar(): array
    {
        $r = [
            'basketball' => (float) $this->escalar(
                "SELECT IFNULL(SUM(pago_saldo),0) FROM alumno_pago WHERE pago_estado='P'"),
            'arena' => (float) $this->escalar(
                "SELECT IFNULL(SUM(reserva_saldo),0) FROM dsa_reserva WHERE reserva_estado<>'X'"),
            'league' => (float) $this->escalar(
                "SELECT IFNULL(SUM(o.obligacion_valor - o.obligacion_descuento + o.obligacion_recargo
                        - IFNULL((SELECT SUM(a.abono_valor) FROM dsl_abono a
                                   WHERE a.abono_obligacionid = o.obligacion_id
                                     AND a.abono_anulado = 'N'), 0)), 0)
                   FROM dsl_obligacion o WHERE o.obligacion_estado <> 'ANULADA'"),
        ];
        $r['total'] = $r['basketball'] + $r['arena'] + $r['league'];
        return $r;
    }

    /*==============  Ocupacion de Arena  ==============*/
    /*
    | horas reservadas / horas de apertura. El denominador NO esta en ninguna
    | tabla: se construye contando cuantas veces cae cada dia de la semana
    | dentro del periodo y multiplicando por lo que dsa_horario declara para
    | ese dia.
    |
    | Se devuelve el porcentaje, y la comparacion se expresa en PUNTOS: pasar
    | de 34,8 % a 31,9 % es -2,9 puntos, no -8,3 %. Confundirlo es un error
    | clasico de tablero.
    */
    public function ocupacion(array $p, bool $anterior = false): array
    {
        $d = $anterior ? $p['antDesde'] : $p['desde'];
        $h = $anterior ? $p['antHasta'] : $p['hasta'];

        $reservadas = (float) $this->escalar(
            "SELECT IFNULL(SUM(reserva_horas),0) FROM dsa_reserva
              WHERE reserva_estado IN ('U','C') AND reserva_fecha BETWEEN :d AND :h",
            [':d' => $d, ':h' => $h]);

        /* Horas de apertura del periodo. El calendario se genera con un
           producto cartesiano de digitos —1000 filas, casi tres anos— para
           no crear una tabla auxiliar solo para contar dias. */
        $disponibles = (float) $this->escalar(
            "SELECT IFNULL(SUM(TIME_TO_SEC(TIMEDIFF(h.horario_hasta, h.horario_desde))/3600
                    * (SELECT COUNT(*) FROM (
                         SELECT ADDDATE(:d1, INTERVAL seq DAY) f FROM (
                           SELECT a.n + b.n*10 + c.n*100 seq FROM
                             (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
                              UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) a,
                             (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
                              UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) b,
                             (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
                              UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) c
                         ) s WHERE ADDDATE(:d2, INTERVAL seq DAY) <= :h1
                       ) cal
                      WHERE DAYOFWEEK(cal.f) = IF(h.horario_dia = 7, 1, h.horario_dia + 1))
                    ), 0)
               FROM dsa_horario h
               JOIN dsa_instalacion i ON i.instalacion_id = h.horario_instalacionid
              WHERE h.horario_estado = 'A' AND i.instalacion_estado = 'A'",
            [':d1' => $d, ':d2' => $d, ':h1' => $h]);

        return [
            'reservadas'  => $reservadas,
            'disponibles' => $disponibles,
            'pct'         => $disponibles > 0 ? $reservadas / $disponibles * 100 : 0.0,
        ];
    }

    /*==============  Serie mensual para el grafico  ==============*/
    public function serieMensual(int $meses = 8): array
    {
        $desde = (new \DateTimeImmutable('first day of this month'))
            ->modify('-' . ($meses - 1) . ' months')->format('Y-m-01');

        /* Tope por arriba. Sin el, las reservas FUTURAS de Arena metian
           septiembre y octubre en un grafico titulado «ultimos 8 meses».
           Se descubrio ejecutandolo: devolvia 10 filas. */
        $hasta = (new \DateTimeImmutable('last day of this month'))->format('Y-m-d');

        return $this->filas(
            "SELECT mes, SUM(bk) basketball, SUM(ar) arena, SUM(lg) league
               FROM (
                 SELECT DATE_FORMAT(pago_fecha,'%Y-%m') mes, SUM(pago_valor) bk, 0 ar, 0 lg
                   FROM alumno_pago WHERE pago_estado='C'
                    AND pago_fecha BETWEEN :d1 AND :h1 GROUP BY mes
                 UNION ALL
                 SELECT DATE_FORMAT(pago_fecha,'%Y-%m'), 0, SUM(pago_valor), 0
                   FROM dsa_pago WHERE pago_estado='A'
                    AND pago_fecha BETWEEN :d2 AND :h2 GROUP BY 1
                 UNION ALL
                 SELECT DATE_FORMAT(abono_fecha,'%Y-%m'), 0, 0, SUM(abono_valor)
                   FROM dsl_abono WHERE abono_anulado='N'
                    AND abono_fecha BETWEEN :d3 AND :h3 GROUP BY 1
               ) t
              GROUP BY mes ORDER BY mes",
            [':d1' => $desde, ':h1' => $hasta, ':d2' => $desde, ':h2' => $hasta,
             ':d3' => $desde, ':h3' => $hasta]);
    }

    /*==============  Asistencia  ==============*/
    /*
    | El justificado cuenta como INASISTENCIA: el alumno no fue. Que el
    | representante avisara es otra cosa y tiene su propio indicador, porque
    | disuelto dentro del porcentaje no se veria.
    */
    public function porcentajeAsistencia(): string
    {
        $r = $this->filas(
            "SELECT COUNT(*) t, SUM(dia_marca IN ('P','A')) a FROM insights_v_asistencia_dia");
        $t = (int) ($r[0]['t'] ?? 0);
        return $t > 0 ? number_format((int) $r[0]['a'] / $t * 100, 1) : '—';
    }

    public function porcentajeAvisadas(): string
    {
        $r = $this->filas(
            "SELECT SUM(dia_marca IN ('F','J')) inas, SUM(dia_marca='J') avis
               FROM insights_v_asistencia_dia");
        $i = (int) ($r[0]['inas'] ?? 0);
        return $i > 0 ? number_format((int) $r[0]['avis'] / $i * 100, 1) : '—';
    }

    /*==============  Variacion entre periodos  ==============*/
    /*
    | Devuelve null cuando el periodo anterior fue CERO. No es lo mismo que
    | 0 %: pasar de nada a algo no tiene variacion porcentual, tiene infinita,
    | y pintar «+100 %» o «+∞» seria inventar un dato.
    |
    | Se vio al probar el tablero con el periodo enero-agosto: el comparable
    | cae en 2025, donde no hay nada, y la tarjeta habria mostrado una
    | variacion imposible con aspecto de medicion.
    */
    public function variacion(float $actual, float $anterior): ?float
    {
        if ($anterior <= 0.0) { return null; }
        return ($actual - $anterior) / $anterior * 100;
    }

    /*==============  Resumen de cada modulo  ==============*/
    public function resumenModulos(array $p): array
    {
        $ing = $this->ingresos($p);

        return [
            'basketball' => [
                'nombre'  => 'Basketball',
                'icono'   => 'fas fa-basketball-ball',
                'url'     => DS_BASKETBALL_URL,
                'ingreso' => $ing['basketball'],
                'lineas'  => [
                    ['alumnos activos', (string) (int) $this->escalar(
                        "SELECT COUNT(*) FROM sujeto_alumno WHERE alumno_estado='A'")],
                    ['asistencia', $this->porcentajeAsistencia() . ' %'],
                    ['inasistencias avisadas', $this->porcentajeAvisadas() . ' %'],
                ],
            ],
            'arena' => [
                'nombre'  => 'Arena',
                'icono'   => 'fas fa-warehouse',
                'url'     => DS_ARENA_URL,
                'ingreso' => $ing['arena'],
                'lineas'  => [
                    ['reservas del periodo', (string) (int) $this->escalar(
                        "SELECT COUNT(*) FROM dsa_reserva WHERE reserva_fecha BETWEEN :d AND :h",
                        [':d' => $p['desde'], ':h' => $p['hasta']])],
                    ['reservas vigentes', (string) (int) $this->escalar(
                        "SELECT COUNT(*) FROM dsa_reserva
                          WHERE reserva_estado IN ('P','C') AND reserva_fecha >= CURDATE()")],
                    ['clientes activos', (string) (int) $this->escalar(
                        "SELECT COUNT(*) FROM dsa_cliente WHERE cliente_estado='A'")],
                ],
            ],
            'league' => [
                'nombre'  => 'League',
                'icono'   => 'fas fa-trophy',
                'url'     => DS_LEAGUE_URL,
                'ingreso' => $ing['league'],
                'lineas'  => [
                    ['torneos activos', (string) (int) $this->escalar(
                        "SELECT COUNT(*) FROM dsl_torneo WHERE torneo_estado='A'")],
                    ['equipos inscritos', (string) (int) $this->escalar(
                        "SELECT COUNT(*) FROM dsl_inscripcion")],
                    ['partidos por jugar', (string) (int) $this->escalar(
                        "SELECT COUNT(*) FROM dsl_partido p
                           JOIN dsl_estado e ON e.estado_id = p.partido_estadoid
                          WHERE e.estado_final = 'N'")],
                ],
            ],
        ];
    }

    /*==============  Centro de atencion gerencial  ==============*/
    /*
    | Situaciones que merecen mirarse, no una lista de errores. Cada regla
    | solo aparece si de verdad ocurre: un centro de atencion que siempre
    | muestra lo mismo se deja de leer.
    */
    public function requiereAtencion(array $p): array
    {
        $avisos = [];

        /* El indicio mas directo del problema de cobro que aparecio al
           disenar el modelo: la matricula se retiene, el pago no. */
        $sinPagar = (int) $this->escalar(
            "SELECT COUNT(*) FROM sujeto_alumno a
              WHERE a.alumno_estado = 'A'
                AND NOT EXISTS (SELECT 1 FROM alumno_pago p
                                 WHERE p.pago_alumnoid = a.alumno_id
                                   AND p.pago_rubroid = 'RPE')");
        if ($sinPagar > 0) {
            $avisos[] = ['tono' => 'danger', 'icono' => 'fa-user-clock',
                'texto' => "$sinPagar alumno(s) activo(s) sin ningún pago de pensión registrado"];
        }

        $carteraArena = (float) $this->escalar(
            "SELECT IFNULL(SUM(reserva_saldo),0) FROM dsa_reserva WHERE reserva_estado <> 'X'");
        if ($carteraArena > 0) {
            $avisos[] = ['tono' => 'warning', 'icono' => 'fa-warehouse',
                'texto' => 'Arena tiene $' . number_format($carteraArena, 2) . ' por cobrar en reservas'];
        }

        $vencidas = (int) $this->escalar(
            "SELECT COUNT(*) FROM alumno_pago WHERE pago_estado = 'P' AND pago_saldo > 0");
        if ($vencidas > 0) {
            $avisos[] = ['tono' => 'warning', 'icono' => 'fa-file-invoice-dollar',
                'texto' => "$vencidas pago(s) de Basketball con saldo pendiente"];
        }

        $sinResultado = (int) $this->escalar(
            "SELECT COUNT(*) FROM dsl_partido p
               JOIN dsl_estado e ON e.estado_id = p.partido_estadoid
              WHERE e.estado_codigo = 'FINALIZADO'
                AND (p.partido_puntoslocal IS NULL OR p.partido_puntosvisitante IS NULL)");
        if ($sinResultado > 0) {
            $avisos[] = ['tono' => 'warning', 'icono' => 'fa-trophy',
                'texto' => "$sinResultado partido(s) finalizado(s) sin resultado registrado"];
        }

        $obligVencidas = (int) $this->escalar(
            "SELECT COUNT(*) FROM dsl_obligacion
              WHERE obligacion_estado NOT IN ('PAGADA','ANULADA')
                AND obligacion_vence < CURDATE()");
        if ($obligVencidas > 0) {
            $avisos[] = ['tono' => 'warning', 'icono' => 'fa-clock',
                'texto' => "$obligVencidas obligación(es) de League vencida(s) sin pagar"];
        }

        return $avisos;
    }
}
