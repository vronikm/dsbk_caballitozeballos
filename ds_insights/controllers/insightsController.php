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

    /** El ambito, resuelto una sola vez por peticion. */
    private ?array $ambito = null;

    /*
    | Devuelve el trozo de WHERE que limita una consulta a las sedes del
    | usuario, o cadena vacia si el usuario no esta limitado.
    |
    |     "... WHERE pago_estado = 'C'" . $this->sede('a.alumno_sedeid')
    |
    |
    | POR QUE LOS IDS VAN EN LINEA Y NO COMO PARAMETROS
    |
    | La regla del proyecto es no concatenar NUNCA informacion recibida del
    | usuario. Estos ids no vienen del usuario: se leen de
    | seguridad_usuario_sede con la clave de la sesion y pasan por
    | array_map('intval'). No hay ninguna cadena del exterior en el resultado.
    |
    | Se hace asi porque el mismo fragmento se inserta en subconsultas que ya
    | traen sus propios marcadores, y arrastrar nombres unicos por cada una
    | multiplicaria las oportunidades de equivocarse justo en el codigo que
    | protege el acceso.
    */
    protected function sede(string $columna): string
    {
        if ($this->ambito === null) {
            $this->ambito = $this->sedesDelUsuario();
        }
        if ($this->ambito === []) {
            return '';
        }
        return ' AND ' . $columna . ' IN (' . implode(',', $this->ambito) . ')';
    }

    /*
    | dsa_pago no tiene sede: la hereda de la reserva que paga. Se resuelve
    | con una subconsulta y no con un JOIN para no alterar el resto de la
    | consulta —varias son agregados, y un JOIN de mas cambiaria los conteos
    | multiplicando filas—.
    */
    protected function sedeReserva(string $columna): string
    {
        if ($this->ambito === null) {
            $this->ambito = $this->sedesDelUsuario();
        }
        if ($this->ambito === []) {
            return '';
        }
        return ' AND ' . $columna . ' IN (SELECT reserva_id FROM dsa_reserva'
             . ' WHERE reserva_sedeid IN (' . implode(',', $this->ambito) . '))';
    }

    /*
    | insights_v_asistencia_dia y facturas_electronicas tampoco llevan sede:
    | la heredan del alumno. Mismo motivo para la subconsulta.
    */
    protected function sedeAlumno(string $columna): string
    {
        if ($this->ambito === null) {
            $this->ambito = $this->sedesDelUsuario();
        }
        if ($this->ambito === []) {
            return '';
        }
        return ' AND ' . $columna . ' IN (SELECT alumno_id FROM sujeto_alumno'
             . ' WHERE alumno_sedeid IN (' . implode(',', $this->ambito) . '))';
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
                  WHERE pago_estado = 'C' AND pago_fecha BETWEEN :d AND :h"
                . $this->sede('pago_sedeid'),
                [':d' => $d, ':h' => $h]),
            'arena' => (float) $this->escalar(
                "SELECT IFNULL(SUM(pago_valor),0) FROM dsa_pago
                  WHERE pago_estado = 'A' AND pago_fecha BETWEEN :d AND :h"
                . $this->sedeReserva('pago_reservaid'),
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
               (SELECT COUNT(*) FROM alumno_pago WHERE pago_estado='C' AND pago_fecha BETWEEN :d1 AND :h1"
             . $this->sede('pago_sedeid') . ")
             + (SELECT COUNT(*) FROM dsa_pago    WHERE pago_estado='A' AND pago_fecha BETWEEN :d2 AND :h2"
             . $this->sedeReserva('pago_reservaid') . ")
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
                "SELECT IFNULL(SUM(pago_saldo),0) FROM alumno_pago WHERE pago_estado='P'"
                . $this->sede('pago_sedeid')),
            'arena' => (float) $this->escalar(
                "SELECT IFNULL(SUM(reserva_saldo),0) FROM dsa_reserva WHERE reserva_estado<>'X'"
                . $this->sede('reserva_sedeid')),
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
              WHERE reserva_estado IN ('U','C') AND reserva_fecha BETWEEN :d AND :h"
            . $this->sede('reserva_sedeid'),
            [':d' => $d, ':h' => $h]);

        /* Horas de apertura del periodo. El calendario se genera con un
           producto cartesiano de digitos —1000 filas, casi tres anos— para
           no crear una tabla auxiliar solo para contar dias. */
        /* Horas de apertura del periodo. Cuantas veces cae cada dia de la
           semana se calcula en PHP: la version anterior generaba mil filas
           en la base y las correlacionaba por cada horario. Ver
           diasPorSemana(). */
        $dias = $this->sqlDiasPorSemana($d, $h);

        $disponibles = (float) $this->escalar(
            "SELECT IFNULL(SUM(TIME_TO_SEC(TIMEDIFF(h.horario_hasta, h.horario_desde))/3600
                    * ds.veces), 0)
               FROM dsa_horario h
               JOIN $dias ds ON ds.dia = h.horario_dia
               JOIN dsa_instalacion i ON i.instalacion_id = h.horario_instalacionid
              WHERE h.horario_estado = 'A' AND i.instalacion_estado = 'A'"
            . $this->sede('i.instalacion_sedeid'));

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
                    AND pago_fecha BETWEEN :d1 AND :h1"
                     . $this->sede("pago_sedeid") . " GROUP BY mes
                 UNION ALL
                 SELECT DATE_FORMAT(pago_fecha,'%Y-%m'), 0, SUM(pago_valor), 0
                   FROM dsa_pago WHERE pago_estado='A'
                    AND pago_fecha BETWEEN :d2 AND :h2"
                     . $this->sedeReserva("pago_reservaid") . " GROUP BY 1
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
            "SELECT COUNT(*) t, SUM(dia_marca IN ('P','A')) a FROM insights_v_asistencia_dia WHERE 1 = 1"
            . $this->sedeAlumno('dia_alumnoid'));
        $t = (int) ($r[0]['t'] ?? 0);
        return $t > 0 ? number_format((int) $r[0]['a'] / $t * 100, 1) : '—';
    }

    public function porcentajeAvisadas(): string
    {
        $r = $this->filas(
            "SELECT SUM(dia_marca IN ('F','J')) inas, SUM(dia_marca='J') avis
               FROM insights_v_asistencia_dia WHERE 1 = 1"
            . $this->sedeAlumno('dia_alumnoid'));
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
                        "SELECT COUNT(*) FROM sujeto_alumno WHERE alumno_estado='A'"
                            . $this->sede('alumno_sedeid'))],
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
                        "SELECT COUNT(*) FROM dsa_reserva WHERE reserva_fecha BETWEEN :d AND :h"
                            . $this->sede('reserva_sedeid'),
                        [':d' => $p['desde'], ':h' => $p['hasta']])],
                    ['reservas vigentes', (string) (int) $this->escalar(
                        "SELECT COUNT(*) FROM dsa_reserva
                         WHERE reserva_estado IN ('P','C') AND reserva_fecha >= CURDATE()"
                         . $this->sede("reserva_sedeid"))],
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

        /*
        | El indicio mas directo del problema de cobro que aparecio al disenar
        | el modelo: la matricula se retiene, el pago no.
        |
        | SE EXCLUYEN LOS BECADOS, Y NO ES UN DETALLE. La primera version
        | contaba 46 alumnos «sin ningun pago de pension», y 6 de ellos tenian
        | beca activa: no pagan porque se les concedio una, no porque deban.
        | Un tablero que acusa de moroso a un becado se deja de creer, y con
        | razon.
        */
        $sinPagar = (int) $this->escalar(
            "SELECT COUNT(*) FROM sujeto_alumno a
              WHERE a.alumno_estado = 'A'
                AND NOT EXISTS (SELECT 1 FROM alumno_pago p
                                 WHERE p.pago_alumnoid = a.alumno_id
                                   AND p.pago_rubroid = 'RPE')
                AND NOT EXISTS (SELECT 1 FROM alumno_pago_descuento d
                                  JOIN general_tabla_catalogo c
                                    ON c.catalogo_valor = d.descuento_rubroid
                                   AND c.catalogo_tablaid = 7
                                 WHERE d.descuento_alumnoid = a.alumno_id
                                   AND d.descuento_estado = 'S'
                                   AND c.catalogo_descripcion LIKE 'Beca%')"
            . $this->sede('a.alumno_sedeid'));
        if ($sinPagar > 0) {
            $avisos[] = ['tono' => 'danger', 'icono' => 'fa-user-clock',
                'texto' => "$sinPagar alumno(s) activo(s) sin ningún pago de pensión registrado"];
        }

        $carteraArena = (float) $this->escalar(
            "SELECT IFNULL(SUM(reserva_saldo),0) FROM dsa_reserva WHERE reserva_estado <> 'X'"
            . $this->sede('reserva_sedeid'));
        if ($carteraArena > 0) {
            $avisos[] = ['tono' => 'warning', 'icono' => 'fa-warehouse',
                'texto' => 'Arena tiene $' . number_format($carteraArena, 2) . ' por cobrar en reservas'];
        }

        $vencidas = (int) $this->escalar(
            "SELECT COUNT(*) FROM alumno_pago WHERE pago_estado = 'P' AND pago_saldo > 0"
            . $this->sede('pago_sedeid'));
        if ($vencidas > 0) {
            $avisos[] = ['tono' => 'warning', 'icono' => 'fa-file-invoice-dollar',
                'texto' => "$vencidas pago(s) de Basketball con saldo pendiente"];
        }

        /*
        | Las becas del 100 % se guardan con descuento_valor = 0,00, asi que
        | el subsidio real que la escuela concede no aparece en ninguna suma.
        | Se calcula aqui a partir de la pension de la sede del alumno.
        */
        $beca = $this->filas(
            "SELECT COUNT(*) n, IFNULL(SUM(s.sede_pension), 0) v
               FROM alumno_pago_descuento d
               JOIN general_tabla_catalogo c
                 ON c.catalogo_valor = d.descuento_rubroid AND c.catalogo_tablaid = 7
               JOIN sujeto_alumno a ON a.alumno_id = d.descuento_alumnoid
               JOIN general_sede  s ON s.sede_id   = a.alumno_sedeid
              WHERE d.descuento_estado = 'S'
                AND a.alumno_estado = 'A'
                AND c.catalogo_descripcion LIKE 'Beca 100%'"
            . $this->sede('a.alumno_sedeid'));
        if ((int) ($beca[0]['n'] ?? 0) > 0) {
            $avisos[] = ['tono' => 'info', 'icono' => 'fa-graduation-cap',
                'texto' => (int) $beca[0]['n'] . ' beca(s) del 100 % suponen $'
                    . number_format((float) $beca[0]['v'], 2)
                    . ' al mes que no se cobran y que no constan como descuento'];
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

    /*==============  Ingresos por sede  ==============*/
    /*
    | Basketball por pago_sedeid —la sede CONGELADA del pago, no la actual del
    | alumno— y Arena por reserva_sedeid. Ver database/044.
    |
    | League va aparte, como «fuera de sede». No es un hueco que rellenar: sus
    | torneos pueden organizarse fuera de las canchas del club, asi que no
    | tiene sede y repartirlo a prorrateo seria inventar. Se rotula y se
    | suma al total, pero no se atribuye a nadie.
    */
    public function ingresosPorSede(array $p): array
    {
        $filas = $this->filas(
            "SELECT s.sede_id, s.sede_nombre nombre,
                    IFNULL(bk.v, 0) basketball, IFNULL(ar.v, 0) arena
               FROM general_sede s
               LEFT JOIN (SELECT pago_sedeid sede, SUM(pago_valor) v
                            FROM alumno_pago
                           WHERE pago_estado = 'C' AND pago_fecha BETWEEN :d1 AND :h1
                           GROUP BY pago_sedeid) bk ON bk.sede = s.sede_id
               LEFT JOIN (SELECT reserva_sedeid sede, SUM(p.pago_valor) v
                            FROM dsa_pago p
                            JOIN dsa_reserva r ON r.reserva_id = p.pago_reservaid
                           WHERE p.pago_estado = 'A' AND p.pago_fecha BETWEEN :d2 AND :h2
                           GROUP BY reserva_sedeid) ar ON ar.sede = s.sede_id
              WHERE 1 = 1"
             . $this->sede('s.sede_id') . "
              ORDER BY (IFNULL(bk.v,0) + IFNULL(ar.v,0)) DESC, s.sede_nombre",
            [':d1' => $p['desde'], ':h1' => $p['hasta'], ':d2' => $p['desde'], ':h2' => $p['hasta']]);

        $r = [];
        foreach ($filas as $f) {
            $total = (float) $f['basketball'] + (float) $f['arena'];
            /* Las sedes sin movimiento en el periodo no se listan: una tabla
               con siete filas a cero no informa, solo estorba. */
            if ($total <= 0) { continue; }
            $r[] = ['nombre' => $f['nombre'], 'basketball' => (float) $f['basketball'],
                    'arena' => (float) $f['arena'], 'league' => 0.0, 'total' => $total];
        }

        $league = (float) $this->escalar(
            "SELECT IFNULL(SUM(abono_valor),0) FROM dsl_abono
              WHERE abono_anulado = 'N' AND abono_fecha BETWEEN :d AND :h",
            [':d' => $p['desde'], ':h' => $p['hasta']]);

        if ($league > 0) {
            $r[] = ['nombre' => 'Fuera de sede (League)', 'basketball' => 0.0,
                    'arena' => 0.0, 'league' => $league, 'total' => $league, 'sinSede' => true];
        }

        return $r;
    }

    /*==============  Ingresos por concepto  ==============*/
    /*
    | Cada modulo tiene su propio catalogo y NO se unifican: «Pension» de
    | Basketball y «Inscripcion de equipo» de League son cosas distintas y
    | mezclarlas bajo una etiqueta comun perderia el sentido de ambas. Se
    | listan juntas pero identificadas por modulo.
    */
    public function ingresosPorConcepto(array $p): array
    {
        $r = [];

        foreach ($this->filas(
            "SELECT IFNULL(c.catalogo_descripcion, p.pago_rubroid) concepto,
                    COUNT(*) n, SUM(p.pago_valor) v
               FROM alumno_pago p
               LEFT JOIN general_tabla_catalogo c
                      ON c.catalogo_valor = p.pago_rubroid AND c.catalogo_tablaid = 5
              WHERE p.pago_estado = 'C' AND p.pago_fecha BETWEEN :d AND :h"
             . $this->sede('p.pago_sedeid') . "
              GROUP BY p.pago_rubroid ORDER BY v DESC",
            [':d' => $p['desde'], ':h' => $p['hasta']]) as $f) {
            $r[] = ['modulo' => 'Basketball', 'concepto' => $f['concepto'],
                    'n' => (int) $f['n'], 'valor' => (float) $f['v']];
        }

        /* Arena no tiene catalogo de rubros: lo que distingue un ingreso de
           otro es la clase de instalacion, cancha o residencia. */
        foreach ($this->filas(
            "SELECT CASE i.instalacion_clase WHEN 'R' THEN 'Residencias' ELSE 'Canchas' END concepto,
                    COUNT(*) n, SUM(p.pago_valor) v
               FROM dsa_pago p
               JOIN dsa_reserva r     ON r.reserva_id = p.pago_reservaid
               JOIN dsa_instalacion i ON i.instalacion_id = r.reserva_instalacionid
              WHERE p.pago_estado = 'A' AND p.pago_fecha BETWEEN :d AND :h"
             . $this->sede('r.reserva_sedeid') . "
              GROUP BY i.instalacion_clase ORDER BY v DESC",
            [':d' => $p['desde'], ':h' => $p['hasta']]) as $f) {
            $r[] = ['modulo' => 'Arena', 'concepto' => $f['concepto'],
                    'n' => (int) $f['n'], 'valor' => (float) $f['v']];
        }

        foreach ($this->filas(
            "SELECT co.concepto_nombre concepto, COUNT(*) n, SUM(a.abono_valor) v
               FROM dsl_abono a
               JOIN dsl_obligacion o ON o.obligacion_id = a.abono_obligacionid
               JOIN dsl_concepto   co ON co.concepto_id = o.obligacion_conceptoid
              WHERE a.abono_anulado = 'N' AND a.abono_fecha BETWEEN :d AND :h
              GROUP BY co.concepto_id ORDER BY v DESC",
            [':d' => $p['desde'], ':h' => $p['hasta']]) as $f) {
            $r[] = ['modulo' => 'League', 'concepto' => $f['concepto'],
                    'n' => (int) $f['n'], 'valor' => (float) $f['v']];
        }

        return $r;
    }

    /*==============  Descuentos y becas  ==============*/
    /*
    | OJO CON LAS BECAS DEL 100 %. Se guardan con descuento_valor = 0,00, asi
    | que el subsidio real que la escuela concede NO aparece en la suma de
    | descuentos. Se calcula aparte, a partir de la pension de la sede del
    | alumno, y se devuelve identificado como estimado: no es un importe
    | registrado sino una consecuencia deducida.
    */
    public function descuentos(): array
    {
        $registrados = $this->filas(
            "SELECT IFNULL(c.catalogo_descripcion, d.descuento_rubroid) concepto,
                    COUNT(*) n, SUM(d.descuento_valor) v
               FROM alumno_pago_descuento d
               LEFT JOIN general_tabla_catalogo c
                      ON c.catalogo_valor = d.descuento_rubroid AND c.catalogo_tablaid = 7
               JOIN sujeto_alumno a ON a.alumno_id = d.descuento_alumnoid
              WHERE d.descuento_estado = 'S' AND a.alumno_estado = 'A'"
             . $this->sede('a.alumno_sedeid') . "
              GROUP BY d.descuento_rubroid ORDER BY v DESC");

        $beca = $this->filas(
            "SELECT COUNT(*) n, IFNULL(SUM(s.sede_pension), 0) v
               FROM alumno_pago_descuento d
               JOIN general_tabla_catalogo c
                 ON c.catalogo_valor = d.descuento_rubroid AND c.catalogo_tablaid = 7
               JOIN sujeto_alumno a ON a.alumno_id = d.descuento_alumnoid
               JOIN general_sede  s ON s.sede_id   = a.alumno_sedeid
              WHERE d.descuento_estado = 'S' AND a.alumno_estado = 'A'
                AND c.catalogo_descripcion LIKE 'Beca 100%'"
             . $this->sede('a.alumno_sedeid'));

        return [
            'registrados' => $registrados,
            'becaCompleta' => [
                'n'     => (int) ($beca[0]['n'] ?? 0),
                'mensual' => (float) ($beca[0]['v'] ?? 0),
            ],
        ];
    }

    /*==============  Ticket promedio por modulo  ==============*/
    public function ticketPromedio(array $p): array
    {
        $t = [];
        foreach ([
            'basketball' => ["alumno_pago", "pago_valor", "pago_estado='C'", "pago_fecha",
                                 $this->sede("pago_sedeid")],
            'arena'      => ["dsa_pago",    "pago_valor", "pago_estado='A'", "pago_fecha",
                                 $this->sedeReserva("pago_reservaid")],
            'league'     => ["dsl_abono",   "abono_valor", "abono_anulado='N'", "abono_fecha",
                                 ""],
        ] as $m => [$tabla, $col, $cond, $fecha, $ambito]) {
            $r = $this->filas(
                "SELECT COUNT(*) n, IFNULL(AVG($col),0) a FROM `$tabla`
                  WHERE $cond AND $fecha BETWEEN :d AND :h$ambito",
                [':d' => $p['desde'], ':h' => $p['hasta']]);
            $t[$m] = ['n' => (int) ($r[0]['n'] ?? 0), 'ticket' => (float) ($r[0]['a'] ?? 0)];
        }
        return $t;
    }

    /*==============  Facturacion electronica  ==============*/
    /*
    | Se lee del SRI, no se calcula. Interesa el ESTADO tanto como el importe:
    | una factura emitida y no autorizada es dinero que el sistema cree
    | facturado y el SRI no reconoce.
    */
    public function facturacion(array $p): array
    {
        return $this->filas(
            "SELECT estado_sri estado, COUNT(*) n, IFNULL(SUM(total),0) v
               FROM facturas_electronicas
              WHERE fecha_emision BETWEEN :d AND :h"
             . $this->sedeAlumno('alumno_id') . "
              GROUP BY estado_sri ORDER BY v DESC",
            [':d' => $p['desde'], ':h' => $p['hasta']]);
    }

    /*==============  Becas y descuentos  ==============*/
    /*
    | Cuanto cuesta el beneficio, a cuantos alcanza, cuanto pagaron aun asi y
    | como asisten. Cuatro preguntas que hasta ahora no se podian responder de
    | una vez.
    |
    | EL VALOR REAL NO ES EL REGISTRADO, Y HAY QUE DECIRLO
    |
    | La Beca 50 % guarda su importe correctamente: 15,00 sobre una pension de
    | 30,00, la mitad exacta. Pero la Beca 100 % se guarda con
    | descuento_valor = 0,00, asi que el subsidio que la escuela concede NO
    | esta en ninguna suma.
    |
    | Aqui se calcula: para la beca completa, el valor es la pension de la
    | sede del alumno; para el resto, el importe registrado. Y se devuelven
    | las dos cifras por separado, porque una es un hecho y la otra una
    | deduccion, y presentarlas mezcladas seria repetir el error que corrigio
    | la migracion 044.
    */
    public function beneficios(): array
    {
        $filas = $this->filas(
            "SELECT c.catalogo_valor cod,
                    c.catalogo_descripcion tipo,
                    COUNT(*) alumnos,
                    SUM(a.alumno_estado = 'A')  activos,
                    SUM(a.alumno_estado <> 'A') inactivos,
                    SUM(d.descuento_valor) registrado,
                    SUM(CASE WHEN c.catalogo_valor = 'DBC'
                             THEN s.sede_pension ELSE d.descuento_valor END) mensual,
                    IFNULL(SUM((SELECT SUM(p.pago_valor) FROM alumno_pago p
                                 WHERE p.pago_alumnoid = a.alumno_id
                                   AND p.pago_estado = 'C'
                                   AND p.pago_rubroid = 'RPE')), 0) pagado,
                    IFNULL(SUM((SELECT COUNT(*) FROM alumno_pago p
                                 WHERE p.pago_alumnoid = a.alumno_id
                                   AND p.pago_estado = 'C'
                                   AND p.pago_rubroid = 'RPE')), 0) cuotas
               FROM alumno_pago_descuento d
               JOIN general_tabla_catalogo c
                 ON c.catalogo_valor = d.descuento_rubroid AND c.catalogo_tablaid = 7
               JOIN sujeto_alumno a ON a.alumno_id = d.descuento_alumnoid
               JOIN general_sede  s ON s.sede_id   = a.alumno_sedeid
              WHERE d.descuento_estado = 'S'"
             . $this->sede('a.alumno_sedeid') . "
              GROUP BY c.catalogo_valor, c.catalogo_descripcion
              ORDER BY mensual DESC");

        /* La asistencia va en consulta aparte: cruzarla en la de arriba
           multiplicaria las filas de pago por las marcas de asistencia y
           inflaria los importes. Es el error clasico de unir dos hechos de
           distinto grano en la misma consulta. */
        $asis = [];
        foreach ($this->filas(
            "SELECT d.descuento_rubroid cod,
                    COUNT(v.dia_marca) marcas,
                    SUM(v.dia_marca IN ('P','A')) asistidas,
                    SUM(v.dia_marca IN ('F','J')) faltadas,
                    SUM(v.dia_marca = 'J') avisadas
               FROM alumno_pago_descuento d
               JOIN sujeto_alumno a ON a.alumno_id = d.descuento_alumnoid
               LEFT JOIN insights_v_asistencia_dia v ON v.dia_alumnoid = a.alumno_id
              WHERE d.descuento_estado = 'S'"
             . $this->sede('a.alumno_sedeid') . "
              GROUP BY d.descuento_rubroid") as $f) {
            $asis[$f['cod']] = $f;
        }

        $r = [];
        foreach ($filas as $f) {
            $a = $asis[$f['cod']] ?? ['marcas' => 0, 'asistidas' => 0, 'faltadas' => 0, 'avisadas' => 0];
            $marcas = (int) $a['marcas'];
            $faltas = (int) $a['faltadas'];

            $r[] = [
                'cod'        => $f['cod'],
                'tipo'       => $f['tipo'],
                'alumnos'    => (int) $f['alumnos'],
                'activos'    => (int) $f['activos'],
                'inactivos'  => (int) $f['inactivos'],
                'registrado' => (float) $f['registrado'],
                'mensual'    => (float) $f['mensual'],
                /* Deducido = la diferencia entre lo que vale y lo que consta.
                   Sólo la beca completa la tiene. */
                'deducido'   => (float) $f['mensual'] - (float) $f['registrado'],
                'pagado'     => (float) $f['pagado'],
                'cuotas'     => (int) $f['cuotas'],
                'marcas'     => $marcas,
                'asistencia' => $marcas > 0 ? (int) $a['asistidas'] / $marcas * 100 : null,
                'avisadas'   => $faltas > 0 ? (int) $a['avisadas'] / $faltas * 100 : null,
            ];
        }

        return $r;
    }

    /*==============  Los alumnos sin beneficio, para comparar  ==============*/
    /*
    | Un porcentaje de asistencia no dice nada sin con que compararlo. Este es
    | el grupo de control: los que pagan la tarifa completa.
    */
    public function sinBeneficio(): array
    {
        $r = $this->filas(
            "SELECT COUNT(DISTINCT a.alumno_id) alumnos,
                    COUNT(v.dia_marca) marcas,
                    SUM(v.dia_marca IN ('P','A')) asistidas,
                    SUM(v.dia_marca IN ('F','J')) faltadas,
                    SUM(v.dia_marca = 'J') avisadas
               FROM sujeto_alumno a
               LEFT JOIN insights_v_asistencia_dia v ON v.dia_alumnoid = a.alumno_id
              WHERE a.alumno_estado = 'A'
                AND NOT EXISTS (SELECT 1 FROM alumno_pago_descuento d
                                 WHERE d.descuento_alumnoid = a.alumno_id
                                   AND d.descuento_estado = 'S')"
             . $this->sede('a.alumno_sedeid'));

        $f = $r[0] ?? [];
        $marcas = (int) ($f['marcas'] ?? 0);
        $faltas = (int) ($f['faltadas'] ?? 0);

        return [
            'alumnos'    => (int) ($f['alumnos'] ?? 0),
            'marcas'     => $marcas,
            'asistencia' => $marcas > 0 ? (int) $f['asistidas'] / $marcas * 100 : null,
            'avisadas'   => $faltas > 0 ? (int) $f['avisadas'] / $faltas * 100 : null,
        ];
    }

    /*==============  Incoherencias del beneficio  ==============*/
    /*
    | Casos que no deberian darse y que conviene mirar. No son errores del
    | sistema: son situaciones reales que el dato deja ver.
    */
    public function anomaliasBeneficio(): array
    {
        $a = [];

        /*
        | Alguien con beca del 100 % que aun asi pago pension DESPUES de que se
        | le concediera.
        |
        | La condicion de la fecha no es un detalle: sin ella, la primera
        | version señalaba dos pagos del alumno 142 hechos en abril y mayo con
        | una beca concedida el 16 de junio. No era una incoherencia, era
        | cronologia — pago la tarifa completa hasta que se le concedio la
        | beca, que es exactamente lo que debia pasar.
        |
        | Un aviso que grita por lo normal se deja de leer, y con el se dejan
        | de leer los que si importan.
        */
        foreach ($this->filas(
            "SELECT p.pago_alumnoid alumno, p.pago_fecha fecha, p.pago_valor valor,
                    d.descuento_fecha concedida
               FROM alumno_pago p
               JOIN alumno_pago_descuento d ON d.descuento_alumnoid = p.pago_alumnoid
              WHERE d.descuento_rubroid = 'DBC' AND d.descuento_estado = 'S'
                AND p.pago_estado = 'C' AND p.pago_rubroid = 'RPE'
                AND (d.descuento_fecha IS NULL OR p.pago_fecha >= d.descuento_fecha)"
             . $this->sede('p.pago_sedeid') . "
              ORDER BY p.pago_fecha") as $f) {
            $a[] = [
                'tono'  => 'warning',
                'texto' => 'El alumno ' . (int) $f['alumno'] . ' tiene beca del 100 % y pagó $'
                         . number_format((float) $f['valor'], 2) . ' de pensión el '
                         . $f['fecha']
                         . ($f['concedida'] ? ' (beca concedida el ' . $f['concedida'] . ')' : ''),
            ];
        }

        /* Beneficios activos de alumnos que ya no lo estan: no cuestan
           dinero, pero inflan el recuento de becados. */
        $huerfanos = (int) $this->escalar(
            "SELECT COUNT(*) FROM alumno_pago_descuento d
               JOIN sujeto_alumno a ON a.alumno_id = d.descuento_alumnoid
              WHERE d.descuento_estado = 'S' AND a.alumno_estado <> 'A'"
             . $this->sede('a.alumno_sedeid'));
        if ($huerfanos > 0) {
            $a[] = ['tono' => 'info',
                'texto' => "$huerfanos beneficio(s) siguen activos en alumnos que ya no lo están"];
        }

        return $a;
    }

    /*==============  Alumnos: el estado hoy  ==============*/
    public function alumnos(array $p): array
    {
        $r = $this->filas(
            "SELECT COUNT(*) total,
                    SUM(alumno_estado = 'A') activos,
                    SUM(alumno_estado = 'I') inactivos,
                    SUM(alumno_estado NOT IN ('A','I')) otros
               FROM sujeto_alumno WHERE 1 = 1"
            . $this->sede('alumno_sedeid'));
        $f = $r[0] ?? [];

        $altas = (int) $this->escalar(
            "SELECT COUNT(*) FROM sujeto_alumno
              WHERE alumno_fechaingreso BETWEEN :d AND :h"
            . $this->sede('alumno_sedeid'),
            [':d' => $p['desde'], ':h' => $p['hasta']]);

        $altasAnt = (int) $this->escalar(
            "SELECT COUNT(*) FROM sujeto_alumno
              WHERE alumno_fechaingreso BETWEEN :d AND :h"
            . $this->sede('alumno_sedeid'),
            [':d' => $p['antDesde'], ':h' => $p['antHasta']]);

        $total = (int) ($f['total'] ?? 0);
        $act   = (int) ($f['activos'] ?? 0);
        $ina   = (int) ($f['inactivos'] ?? 0);

        return [
            'total'     => $total,
            'activos'   => $act,
            'inactivos' => $ina,
            'altas'     => $altas,
            'altasAnt'  => $altasAnt,
            /* Sobre activos + inactivos, no sobre el total: los estados
               distintos de esos dos no son ni permanencia ni abandono. */
            'abandono'  => ($act + $ina) > 0 ? $ina / ($act + $ina) * 100 : null,
        ];
    }

    /*==============  Retencion por cohorte  ==============*/
    /*
    | Cuantos de los que entraron en cada mes siguen activos.
    |
    | SE DEVUELVE LA EXPOSICION, Y NO ES UN ADORNO. Los datos reales van del
    | 38 % en enero al 100 % en agosto, y leerlo como una mejora seria un
    | error: la cohorte de agosto no ha tenido tiempo de irse. Comparar 8
    | meses de exposicion con 0 no compara nada.
    |
    | Con el numero de meses delante, quien lee decide. Sin el, el grafico
    | cuenta una historia falsa muy convincente.
    */
    public function retencionPorCohorte(int $meses = 12): array
    {
        $desde = (new \DateTimeImmutable('first day of this month'))
            ->modify('-' . ($meses - 1) . ' months')->format('Y-m-01');

        $filas = $this->filas(
            "SELECT DATE_FORMAT(alumno_fechaingreso, '%Y-%m') cohorte,
                    COUNT(*) ingresaron,
                    SUM(alumno_estado = 'A') siguen,
                    TIMESTAMPDIFF(MONTH, MIN(alumno_fechaingreso), CURDATE()) meses
               FROM sujeto_alumno
              WHERE alumno_fechaingreso >= :d"
             . $this->sede('alumno_sedeid') . "
              GROUP BY cohorte ORDER BY cohorte",
            [':d' => $desde]);

        return array_map(static function (array $f): array {
            $n = (int) $f['ingresaron'];
            return [
                'cohorte'    => $f['cohorte'],
                'ingresaron' => $n,
                'siguen'     => (int) $f['siguen'],
                'meses'      => (int) $f['meses'],
                'retencion'  => $n > 0 ? (int) $f['siguen'] / $n * 100 : null,
            ];
        }, $filas);
    }

    /*==============  Distribuciones  ==============*/

    /** Por sede, con su asistencia. La sede del alumno es la ACTUAL: es una
     *  pregunta sobre el presente, no sobre el historial de pagos. */
    public function alumnosPorSede(): array
    {
        return $this->filas(
            "SELECT s.sede_nombre sede,
                    COUNT(*) alumnos,
                    SUM(a.alumno_estado = 'A') activos,
                    ROUND(AVG(TIMESTAMPDIFF(YEAR, a.alumno_fechanacimiento, CURDATE())), 1) edad
               FROM sujeto_alumno a
               JOIN general_sede s ON s.sede_id = a.alumno_sedeid
              WHERE 1 = 1"
             . $this->sede('a.alumno_sedeid') . "
              GROUP BY s.sede_id
              HAVING activos > 0
              ORDER BY activos DESC");
    }

    /*
    | Por ano de nacimiento, que es lo que este sistema llama categoria: no
    | hay bandas U8/U10 en ninguna tabla, y derivarlas exigiria una fecha de
    | corte que cambia cada ano.
    |
    | Los anos SIN alumnos se rellenan con cero. Un hueco en la serie dice
    | que no hay relevo en esa edad, y omitirlo lo escondería.
    */
    public function alumnosPorAnio(): array
    {
        $filas = $this->filas(
            "SELECT YEAR(alumno_fechanacimiento) anio, COUNT(*) alumnos
               FROM sujeto_alumno
              WHERE alumno_estado = 'A' AND alumno_fechanacimiento IS NOT NULL
                AND TIMESTAMPDIFF(YEAR, alumno_fechanacimiento, CURDATE()) BETWEEN 3 AND 60"
             . $this->sede('alumno_sedeid') . "
              GROUP BY anio ORDER BY anio");

        if ($filas === []) { return []; }

        $por = [];
        foreach ($filas as $f) { $por[(int) $f['anio']] = (int) $f['alumnos']; }

        $r = [];
        for ($a = min(array_keys($por)); $a <= max(array_keys($por)); $a++) {
            $r[] = ['anio' => $a, 'alumnos' => $por[$a] ?? 0,
                    'edad' => (int) date('Y') - $a];
        }
        return $r;
    }

    /*
    | Por entrenador. Un alumno puede tener mas de uno —hay tres asi— y por
    | eso se cuenta con DISTINCT: sumar las columnas daria mas alumnos de los
    | que hay.
    */
    public function alumnosPorEntrenador(): array
    {
        /*
        | OJO CON EL FAN-OUT, QUE AQUI YA MORDIO UNA VEZ.
        |
        | asistencia_horario_detalle tiene UNA FILA POR DIA: los 50 horarios
        | declaran cinco cada uno. Unirla directamente con la asistencia
        | multiplicaba cada marca por cinco, y la tabla mostraba 2.885 marcas
        | para un entrenador cuando en todo el sistema hay 1.245. Medido: la
        | suma salia inflada 4,4 veces.
        |
        | El porcentaje sobrevivia casi de milagro —el multiplicador es
        | uniforme— pero deja de serlo con los tres alumnos que tienen dos
        | entrenadores, y el recuento de marcas era simplemente falso.
        |
        | Se resuelve en dos pasos: primero el CONJUNTO de alumnos de cada
        | entrenador, sin repetir; despues la asistencia sobre ese conjunto.
        */
        return $this->filas(
            "SELECT e.empleado_id id, e.empleado_nombre nombre, e.empleado_estado estado,
                    COUNT(*) alumnos,
                    SUM(al.marcas) marcas,
                    ROUND(SUM(al.asistidas) / NULLIF(SUM(al.marcas),0) * 100, 1) asistencia
               FROM sujeto_empleado e
               JOIN (
                     /* Un alumno por entrenador, sin repetir por dia. */
                     SELECT DISTINCT hd.detalle_profesorid profe, a.alumno_id
                       FROM asistencia_horario_detalle hd
                       JOIN asistencia_asignahorario ah ON ah.asignahorario_horarioid = hd.detalle_horarioid
                       JOIN sujeto_alumno a ON a.alumno_id = ah.asignahorario_alumnoid
                                           AND a.alumno_estado = 'A'"
                     . $this->sede('a.alumno_sedeid') . "
                    ) ap ON ap.profe = e.empleado_id
               JOIN (
                     /* Y sus marcas, contadas una sola vez. */
                     SELECT a2.alumno_id,
                            COUNT(v.dia_marca) marcas,
                            SUM(v.dia_marca IN ('P','A')) asistidas
                       FROM sujeto_alumno a2
                       LEFT JOIN insights_v_asistencia_dia v ON v.dia_alumnoid = a2.alumno_id
                      GROUP BY a2.alumno_id
                    ) al ON al.alumno_id = ap.alumno_id
              GROUP BY e.empleado_id
              ORDER BY alumnos DESC");
    }

    /*==============  Asistencia  ==============*/

    /** Tendencia mensual, con las cuatro marcas separadas. */
    public function asistenciaMensual(): array
    {
        return $this->filas(
            "SELECT DATE_FORMAT(dia_fecha, '%Y-%m') mes,
                    COUNT(*) marcas,
                    SUM(dia_marca = 'P') presentes,
                    SUM(dia_marca = 'A') atrasos,
                    SUM(dia_marca = 'F') faltas,
                    SUM(dia_marca = 'J') justificadas,
                    ROUND(SUM(dia_marca IN ('P','A')) / COUNT(*) * 100, 1) pct
               FROM insights_v_asistencia_dia WHERE 1 = 1"
             . $this->sedeAlumno('dia_alumnoid') . "
              GROUP BY mes ORDER BY mes");
    }

    /** Por sede. */
    public function asistenciaPorSede(): array
    {
        return $this->filas(
            "SELECT s.sede_nombre sede,
                    COUNT(*) marcas,
                    ROUND(SUM(v.dia_marca IN ('P','A')) / COUNT(*) * 100, 1) asistencia,
                    ROUND(SUM(v.dia_marca = 'J') / NULLIF(SUM(v.dia_marca IN ('F','J')),0) * 100, 1) avisadas
               FROM insights_v_asistencia_dia v
               JOIN sujeto_alumno a ON a.alumno_id = v.dia_alumnoid
               JOIN general_sede  s ON s.sede_id   = a.alumno_sedeid
              WHERE 1 = 1"
             . $this->sede('a.alumno_sedeid') . "
              GROUP BY s.sede_id ORDER BY marcas DESC");
    }

    /*==============  Cumplimiento de pago  ==============*/
    /*
    | El indicador que la base pedia y el encargo no: cuantos meses paga de
    | verdad un alumno frente a los que lleva matriculado.
    |
    | Se excluyen los becados del 100 %: no pagan porque se les concedio una
    | beca, y contarlos como incumplidores fue un error real de la primera
    | version del centro de atencion.
    */
    public function cumplimientoPago(): array
    {
        $r = $this->filas(
            "SELECT COUNT(*) alumnos,
                    SUM(pagos) cuotas,
                    SUM(meses) meses,
                    SUM(pagos = 0) sinPagar
               FROM (
                 SELECT a.alumno_id,
                        (SELECT COUNT(*) FROM alumno_pago p
                          WHERE p.pago_alumnoid = a.alumno_id
                            AND p.pago_rubroid = 'RPE' AND p.pago_estado = 'C') pagos,
                        GREATEST(1, TIMESTAMPDIFF(MONTH, a.alumno_fechaingreso, CURDATE())) meses
                   FROM sujeto_alumno a
                  WHERE a.alumno_estado = 'A'"
                 . $this->sede('a.alumno_sedeid') . "
                    AND NOT EXISTS (SELECT 1 FROM alumno_pago_descuento d
                                      JOIN general_tabla_catalogo c
                                        ON c.catalogo_valor = d.descuento_rubroid
                                       AND c.catalogo_tablaid = 7
                                     WHERE d.descuento_alumnoid = a.alumno_id
                                       AND d.descuento_estado = 'S'
                                       AND c.catalogo_descripcion LIKE 'Beca 100%')
               ) t");

        $f = $r[0] ?? [];
        $meses  = (int) ($f['meses'] ?? 0);
        $cuotas = (int) ($f['cuotas'] ?? 0);

        return [
            'alumnos'      => (int) ($f['alumnos'] ?? 0),
            'cuotas'       => $cuotas,
            'mesesDebidos' => $meses,
            'sinPagar'     => (int) ($f['sinPagar'] ?? 0),
            'cumplimiento' => $meses > 0 ? $cuotas / $meses * 100 : null,
        ];
    }

    /*==============  Datos que no cuadran  ==============*/
    /*
    | Insights no corrige datos, pero callarlos seria peor: un informe
    | construido sobre una fecha de nacimiento imposible da un promedio de
    | edad imposible, y nadie sabria por que.
    */
    public function anomaliasAlumno(): array
    {
        $a = [];

        foreach ($this->filas(
            "SELECT alumno_id id, alumno_fechanacimiento nace,
                    TIMESTAMPDIFF(YEAR, alumno_fechanacimiento, CURDATE()) edad
               FROM sujeto_alumno
              WHERE alumno_estado = 'A'"
             . $this->sede('alumno_sedeid') . "
                AND (alumno_fechanacimiento IS NULL
                     OR TIMESTAMPDIFF(YEAR, alumno_fechanacimiento, CURDATE()) < 3
                     OR TIMESTAMPDIFF(YEAR, alumno_fechanacimiento, CURDATE()) > 60)
              ORDER BY nace DESC") as $f) {
            $a[] = ['tono' => 'warning', 'icono' => 'fa-calendar-xmark',
                'texto' => 'El alumno ' . (int) $f['id'] . ' figura con '
                         . ($f['nace'] === null ? 'fecha de nacimiento vacía'
                            : $f['edad'] . ' año(s), nacido el ' . $f['nace'])];
        }

        foreach ($this->filas(
            "SELECT e.empleado_id id, e.empleado_nombre nombre,
                    COUNT(DISTINCT ah.asignahorario_alumnoid) alumnos
               FROM sujeto_empleado e
               JOIN asistencia_horario_detalle hd ON hd.detalle_profesorid = e.empleado_id
               JOIN asistencia_asignahorario  ah ON ah.asignahorario_horarioid = hd.detalle_horarioid
               JOIN sujeto_alumno a ON a.alumno_id = ah.asignahorario_alumnoid
                                   AND a.alumno_estado = 'A'
              WHERE e.empleado_estado <> 'A'"
             . $this->sede('a.alumno_sedeid') . "
              GROUP BY e.empleado_id HAVING alumnos > 0") as $f) {
            $a[] = ['tono' => 'danger', 'icono' => 'fa-user-slash',
                'texto' => 'El entrenador «' . $f['nombre'] . '» está inactivo y tiene '
                         . (int) $f['alumnos'] . ' alumno(s) activo(s) asignado(s)'];
        }

        $sinHorario = (int) $this->escalar(
            "SELECT COUNT(*) FROM sujeto_alumno a
              WHERE a.alumno_estado = 'A'"
             . $this->sede('a.alumno_sedeid') . "
                AND NOT EXISTS (SELECT 1 FROM asistencia_asignahorario ah
                                 WHERE ah.asignahorario_alumnoid = a.alumno_id)");
        if ($sinHorario > 0) {
            $a[] = ['tono' => 'info', 'icono' => 'fa-calendar-day',
                'texto' => "$sinHorario alumno(s) activo(s) sin horario asignado: no se les puede tomar asistencia"];
        }

        return $a;
    }

    /*==============  Cuantas veces cae cada dia de la semana  ==============*/
    /*
    | Devuelve [1..7] => veces, con 1 = lunes, que es como numera
    | dsa_horario.horario_dia.
    |
    | ANTES LO HACIA LA BASE, Y COSTABA CARO
    |
    | La primera version generaba un calendario con un producto cartesiano de
    | digitos —1000 filas— y lo correlacionaba por cada fila del resultado.
    | Medido: el mapa de calor tardaba 112,7 ms y la vista de Arena entera
    | 338 ms, con 264 ms solo de consultas.
    |
    | Contar cuantos lunes hay entre dos fechas es aritmetica: el numero de
    | semanas completas mas los dias sueltos del final. Se hace aqui, en O(1),
    | y la consulta recibe siete numeros en vez de generar mil filas.
    |
    | Los valores se interpolan como enteros en el SQL a proposito: son
    | resultado de un calculo propio, no entrada del usuario, y como marcadores
    | habria que repetirlos en cada consulta que use el calendario.
    */
    protected function diasPorSemana(string $desde, string $hasta): array
    {
        $d = new \DateTimeImmutable($desde);
        $h = new \DateTimeImmutable($hasta);
        if ($h < $d) { return array_fill(1, 7, 0); }

        $total    = (int) $d->diff($h)->days + 1;
        $completas = intdiv($total, 7);
        $sobran    = $total % 7;

        $veces = [];
        for ($i = 1; $i <= 7; $i++) { $veces[$i] = $completas; }

        /* Los dias sueltos empiezan en el dia de la semana de la fecha inicial. */
        $dia = (int) $d->format('N');
        for ($i = 0; $i < $sobran; $i++) {
            $veces[$dia]++;
            $dia = $dia === 7 ? 1 : $dia + 1;
        }

        return $veces;
    }

    /**
     * El conteo anterior como tabla derivada, para unirla en la consulta.
     * Siete filas de enteros calculados por nosotros: no hay entrada externa.
     */
    protected function sqlDiasPorSemana(string $desde, string $hasta): string
    {
        $partes = [];
        foreach ($this->diasPorSemana($desde, $hasta) as $dia => $veces) {
            $partes[] = sprintf('SELECT %d dia, %d veces', $dia, $veces);
        }
        return '(' . implode(' UNION ALL ', $partes) . ')';
    }
    /*==============  Resumen de Arena  ==============*/
    public function arenaResumen(array $p): array
    {
        $r = $this->filas(
            "SELECT COUNT(*) reservas,
                    SUM(reserva_estado = 'X') canceladas,
                    SUM(reserva_estado IN ('U','C')) efectivas,
                    IFNULL(SUM(CASE WHEN reserva_estado <> 'X' THEN reserva_total END), 0) facturado,
                    IFNULL(SUM(CASE WHEN reserva_estado <> 'X' THEN reserva_saldo END), 0) saldo,
                    COUNT(DISTINCT reserva_clienteid) clientes
               FROM dsa_reserva
              WHERE reserva_fecha BETWEEN :d AND :h"
            . $this->sede('reserva_sedeid'),
            [':d' => $p['desde'], ':h' => $p['hasta']]);
        $f = $r[0] ?? [];

        $reservas = (int) ($f['reservas'] ?? 0);

        return [
            'reservas'    => $reservas,
            'canceladas'  => (int) ($f['canceladas'] ?? 0),
            'efectivas'   => (int) ($f['efectivas'] ?? 0),
            'facturado'   => (float) ($f['facturado'] ?? 0),
            'saldo'       => (float) ($f['saldo'] ?? 0),
            'clientes'    => (int) ($f['clientes'] ?? 0),
            'cancelacion' => $reservas > 0 ? (int) $f['canceladas'] / $reservas * 100 : null,
            'hoy'         => (int) $this->escalar(
                "SELECT COUNT(*) FROM dsa_reserva
                  WHERE reserva_fecha = CURDATE() AND reserva_estado <> 'X'"
                . $this->sede('reserva_sedeid')),
            'vigentes'    => (int) $this->escalar(
                "SELECT COUNT(*) FROM dsa_reserva
                  WHERE reserva_estado IN ('P','C') AND reserva_fecha >= CURDATE()"
                . $this->sede('reserva_sedeid')),
        ];
    }

    /*==============  Ranking de instalaciones  ==============*/
    /*
    | Ocupacion e ingreso por hora, que son dos cosas distintas y conviene
    | mirarlas juntas: una residencia se alquila por bloques largos y ocupa
    | mucho rindiendo poco por hora; una cancha, al reves. Con solo una de
    | las dos columnas se decidiria mal.
    */
    public function ocupacionPorInstalacion(array $p): array
    {
        $dias = $this->sqlDiasPorSemana($p['desde'], $p['hasta']);

        return $this->filas(
            "SELECT i.instalacion_nombre nombre,
                    i.instalacion_clase clase,
                    s.sede_nombre sede,
                    IFNULL(r.reservas, 0) reservas,
                    IFNULL(r.horas, 0)    horas,
                    IFNULL(r.ingreso, 0)  ingreso,
                    d.disponibles,
                    ROUND(IFNULL(r.horas,0) / NULLIF(d.disponibles,0) * 100, 1) ocupacion,
                    ROUND(IFNULL(r.ingreso,0) / NULLIF(r.horas,0), 2) ingresoHora
               FROM dsa_instalacion i
               JOIN general_sede s ON s.sede_id = i.instalacion_sedeid
               LEFT JOIN (
                     SELECT reserva_instalacionid iid, COUNT(*) reservas,
                            SUM(reserva_horas) horas, SUM(reserva_total) ingreso
                       FROM dsa_reserva
                      WHERE reserva_estado IN ('U','C')
                        AND reserva_fecha BETWEEN :d AND :h"
                     . $this->sede('reserva_sedeid') . "
                      GROUP BY reserva_instalacionid
                    ) r ON r.iid = i.instalacion_id
               JOIN (
                     SELECT h.horario_instalacionid iid,
                            SUM(TIME_TO_SEC(TIMEDIFF(h.horario_hasta, h.horario_desde))/3600
                                * ds.veces) disponibles
                       FROM dsa_horario h
                       JOIN $dias ds ON ds.dia = h.horario_dia
                      WHERE h.horario_estado = 'A'
                      GROUP BY h.horario_instalacionid
                    ) d ON d.iid = i.instalacion_id
              WHERE i.instalacion_estado = 'A'"
             . $this->sede('i.instalacion_sedeid') . "
              ORDER BY ingreso DESC",
            [':d' => $p['desde'], ':h' => $p['hasta']]);
    }

    /*==============  Mapa de calor  ==============*/
    /*
    | Ocupacion por dia de la semana y hora, en PORCENTAJE y no en numero de
    | reservas. La diferencia importa: cinco reservas a las 21:00 con seis
    | instalaciones abiertas es mucho, y las mismas cinco a las 10:00 con
    | veinte franjas disponibles es poco. Un mapa de conteos pintaria las dos
    | igual.
    |
    | Las franjas en que no abre ninguna instalacion se devuelven como null
    | —no como cero— para poder pintarlas en blanco: cerrado no es vacio.
    */
    public function mapaCalor(array $p): array
    {
        $dias = $this->sqlDiasPorSemana($p['desde'], $p['hasta']);

        $filas = $this->filas(
            "SELECT d.dia, d.hora, IFNULL(o.ocupadas, 0) ocupadas, d.disponibles
               FROM (
                     SELECT IF(h.horario_dia = 7, 1, h.horario_dia + 1) dia,
                            hh.hora,
                            COUNT(*) * MAX(ds.veces) disponibles
                       FROM dsa_horario h
                       JOIN $dias ds ON ds.dia = h.horario_dia
                       JOIN dsa_instalacion i ON i.instalacion_id = h.horario_instalacionid
                                             AND i.instalacion_estado = 'A'"
                     . $this->sede('i.instalacion_sedeid') . "
                       JOIN (SELECT 6 hora UNION SELECT 7 UNION SELECT 8 UNION SELECT 9
                             UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13
                             UNION SELECT 14 UNION SELECT 15 UNION SELECT 16 UNION SELECT 17
                             UNION SELECT 18 UNION SELECT 19 UNION SELECT 20 UNION SELECT 21
                             UNION SELECT 22 UNION SELECT 23) hh
                         ON hh.hora >= HOUR(h.horario_desde) AND hh.hora < HOUR(h.horario_hasta)
                      WHERE h.horario_estado = 'A'
                      GROUP BY dia, hh.hora
                    ) d
               LEFT JOIN (
                     SELECT DAYOFWEEK(reserva_fecha) dia, HOUR(reserva_horainicio) hora,
                            COUNT(*) ocupadas
                       FROM dsa_reserva
                      WHERE reserva_estado IN ('U','C')
                        AND reserva_fecha BETWEEN :d AND :h"
                     . $this->sede('reserva_sedeid') . "
                      GROUP BY dia, hora
                    ) o ON o.dia = d.dia AND o.hora = d.hora
              ORDER BY d.hora, d.dia",
            [':d' => $p['desde'], ':h' => $p['hasta']]);

        $mapa = [];
        foreach ($filas as $f) {
            $disp = (int) $f['disponibles'];
            $mapa[(int) $f['hora']][(int) $f['dia']] =
                $disp > 0 ? (int) $f['ocupadas'] / $disp * 100 : null;
        }
        return $mapa;
    }

    /*==============  Incoherencias de Arena  ==============*/
    public function arenaAnomalias(): array
    {
        $a = [];

        $sinHorario = (int) $this->escalar(
            "SELECT COUNT(*) FROM dsa_instalacion i
              WHERE i.instalacion_estado = 'A'"
             . $this->sede('i.instalacion_sedeid') . "
                AND NOT EXISTS (SELECT 1 FROM dsa_horario h
                                 WHERE h.horario_instalacionid = i.instalacion_id
                                   AND h.horario_estado = 'A')");
        if ($sinHorario > 0) {
            $a[] = ['tono' => 'warning', 'icono' => 'fa-clock',
                'texto' => "$sinHorario instalación(es) activa(s) sin horario: su ocupación no se puede calcular"];
        }

        /* Reservas fuera del horario declarado. No es un error del pasado:
           significa que hoy se puede seguir reservando fuera de hora. */
        $fuera = (int) $this->escalar(
            "SELECT COUNT(*) FROM dsa_reserva r
              WHERE r.reserva_estado <> 'X'"
             . $this->sede('r.reserva_sedeid') . "
                AND NOT EXISTS (
                      SELECT 1 FROM dsa_horario h
                       WHERE h.horario_instalacionid = r.reserva_instalacionid
                         AND h.horario_estado = 'A'
                         AND h.horario_dia = IF(DAYOFWEEK(r.reserva_fecha) = 1, 7, DAYOFWEEK(r.reserva_fecha) - 1)
                         AND r.reserva_horainicio >= h.horario_desde
                         AND r.reserva_horafin   <= h.horario_hasta)");
        if ($fuera > 0) {
            $a[] = ['tono' => 'danger', 'icono' => 'fa-calendar-xmark',
                'texto' => "$fuera reserva(s) caen fuera del horario declarado de su instalación"];
        }

        $sinTarifa = (int) $this->escalar(
            "SELECT COUNT(*) FROM dsa_instalacion i
              WHERE i.instalacion_estado = 'A'"
             . $this->sede('i.instalacion_sedeid') . "
                AND NOT EXISTS (SELECT 1 FROM dsa_tarifa t
                                 WHERE t.tarifa_instalacionid = i.instalacion_id
                                   AND t.tarifa_estado = 'A')");
        if ($sinTarifa > 0) {
            $a[] = ['tono' => 'info', 'icono' => 'fa-tag',
                'texto' => "$sinTarifa instalación(es) sin tarifa vigente"];
        }

        $deudores = (int) $this->escalar(
            "SELECT COUNT(DISTINCT reserva_clienteid) FROM dsa_reserva
              WHERE reserva_estado <> 'X' AND reserva_saldo > 0"
            . $this->sede('reserva_sedeid'));
        if ($deudores > 0) {
            $a[] = ['tono' => 'warning', 'icono' => 'fa-user-clock',
                'texto' => "$deudores cliente(s) con saldo pendiente en sus reservas"];
        }

        return $a;
    }

    /*==============  Resumen de League  ==============*/
    public function leagueResumen(array $p): array
    {
        $partidos = $this->filas(
            "SELECT SUM(e.estado_efectivo = 'S') jugados,
                    SUM(e.estado_final = 'N')    pendientes,
                    SUM(e.estado_codigo = 'SUSPENDIDO') suspendidos,
                    SUM(e.estado_codigo = 'CANCELADO')  cancelados,
                    COUNT(*) total
               FROM dsl_partido p
               JOIN dsl_estado e ON e.estado_id = p.partido_estadoid");
        $f = $partidos[0] ?? [];

        return [
            'torneos'     => (int) $this->escalar("SELECT COUNT(*) FROM dsl_torneo WHERE torneo_estado = 'A'"),
            'categorias'  => (int) $this->escalar("SELECT COUNT(*) FROM dsl_categoria WHERE categoria_estado = 'A'"),
            'equipos'     => (int) $this->escalar("SELECT COUNT(DISTINCT inscripcion_equipoid) FROM dsl_inscripcion"),
            'inscritos'   => (int) $this->escalar("SELECT COUNT(*) FROM dsl_inscripcion"),
            /* Solo los habilitados: una plantilla puede tener fichas que no
               llegaron a habilitarse, y contarlas inflaria el numero de
               jugadores que de verdad pueden jugar. */
            'jugadores'   => (int) $this->escalar(
                "SELECT COUNT(DISTINCT plantilla_personaid) FROM dsl_plantilla
                  WHERE plantilla_rol = 'J' AND plantilla_habilitado = 'S'"),
            'fichas'      => (int) $this->escalar(
                "SELECT COUNT(*) FROM dsl_plantilla WHERE plantilla_rol = 'J'"),
            'jugados'     => (int) ($f['jugados'] ?? 0),
            'pendientes'  => (int) ($f['pendientes'] ?? 0),
            'suspendidos' => (int) ($f['suspendidos'] ?? 0),
            'cancelados'  => (int) ($f['cancelados'] ?? 0),
            'partidos'    => (int) ($f['total'] ?? 0),
            /*
            | El recaudado va SIN filtro de periodo, y es deliberado.
            |
            | La primera version lo filtraba por el periodo por omision —el mes
            | en curso— y la tarjeta decia $0,00 mientras la tabla de torneos,
            | justo debajo, decia $2.250,00: los abonos son de febrero. Dos
            | cifras de lo mismo en la misma pantalla, sin nada que explicara la
            | diferencia.
            |
            | La recaudacion de un torneo es una propiedad del torneo, no de un
            | mes: se cobra la inscripcion una vez y ahi queda. El desglose por
            | periodo esta en la vista financiera, que si tiene selector.
            */
            'recaudado'   => (float) $this->escalar(
                "SELECT IFNULL(SUM(abono_valor),0) FROM dsl_abono WHERE abono_anulado = 'N'"),
            'pendienteCobro' => (float) $this->escalar(
                "SELECT IFNULL(SUM(o.obligacion_valor - o.obligacion_descuento + o.obligacion_recargo
                        - IFNULL((SELECT SUM(a.abono_valor) FROM dsl_abono a
                                   WHERE a.abono_obligacionid = o.obligacion_id
                                     AND a.abono_anulado = 'N'), 0)), 0)
                   FROM dsl_obligacion o WHERE o.obligacion_estado <> 'ANULADA'"),
        ];
    }

    /*==============  Ranking de torneos  ==============*/
    public function torneos(): array
    {
        return $this->filas(
            "SELECT t.torneo_id id, t.torneo_nombre nombre, t.torneo_estado estado,
                    t.torneo_desde desde, t.torneo_hasta hasta,
                    (SELECT COUNT(*) FROM dsl_categoria c WHERE c.categoria_torneoid = t.torneo_id) categorias,
                    (SELECT COUNT(*) FROM dsl_inscripcion i
                       JOIN dsl_categoria c ON c.categoria_id = i.inscripcion_categoriaid
                      WHERE c.categoria_torneoid = t.torneo_id) equipos,
                    (SELECT COUNT(DISTINCT pl.plantilla_personaid) FROM dsl_plantilla pl
                       JOIN dsl_inscripcion i ON i.inscripcion_id = pl.plantilla_inscripcionid
                       JOIN dsl_categoria c ON c.categoria_id = i.inscripcion_categoriaid
                      WHERE c.categoria_torneoid = t.torneo_id AND pl.plantilla_rol = 'J') jugadores,
                    (SELECT COUNT(*) FROM dsl_partido pa
                       JOIN dsl_fase f ON f.fase_id = pa.partido_faseid
                       JOIN dsl_categoria c ON c.categoria_id = f.fase_categoriaid
                      WHERE c.categoria_torneoid = t.torneo_id) partidos,
                    (SELECT COUNT(*) FROM dsl_partido pa
                       JOIN dsl_estado e ON e.estado_id = pa.partido_estadoid
                       JOIN dsl_fase f ON f.fase_id = pa.partido_faseid
                       JOIN dsl_categoria c ON c.categoria_id = f.fase_categoriaid
                      WHERE c.categoria_torneoid = t.torneo_id AND e.estado_efectivo = 'S') jugados,
                    IFNULL((SELECT SUM(ab.abono_valor) FROM dsl_abono ab
                       JOIN dsl_obligacion o ON o.obligacion_id = ab.abono_obligacionid
                       JOIN dsl_categoria c ON c.categoria_id = o.obligacion_categoriaid
                      WHERE c.categoria_torneoid = t.torneo_id AND ab.abono_anulado = 'N'), 0) recaudado
               FROM dsl_torneo t
              ORDER BY recaudado DESC, equipos DESC");
    }

    /*==============  Tabla de posiciones  ==============*/
    /*
    | LA PUNTUACION NO SE CODIFICA AQUI. Sale de dsl_categoria
    | —ptsvictoria, ptsderrota, ptswalkover— y de dsl_estado.estado_efectivo,
    | que dice que partidos cuentan. Un walkover suma; un cancelado es final
    | pero NO cuenta.
    |
    | Escribir «2 puntos por victoria» en el SQL habria funcionado hoy y
    | habria mentido el dia que alguien cambie la regla desde la pantalla de
    | categorias, sin que nada avisara.
    */
    public function tablaPosiciones(int $categoriaId): array
    {
        $cat = $this->filas(
            "SELECT categoria_id, categoria_nombre nombre, categoria_ptsvictoria v,
                    categoria_ptsderrota d, categoria_ptswalkover w
               FROM dsl_categoria WHERE categoria_id = :c",
            [':c' => $categoriaId]);
        if ($cat === []) { return ['categoria' => null, 'filas' => []]; }
        $c = $cat[0];

        $filas = $this->filas(
            "SELECT e.equipo_nombre equipo,
                    COUNT(*) jugados,
                    SUM(x.gano) ganados,
                    SUM(NOT x.gano) perdidos,
                    SUM(x.walkover) walkovers,
                    SUM(x.favor)  favor,
                    SUM(x.contra) contra,
                    SUM(x.favor) - SUM(x.contra) diferencia,
                    SUM(CASE WHEN x.gano THEN :v
                             WHEN x.walkover THEN :w
                             ELSE :d END) puntos
               FROM (
                     SELECT p.partido_localid ins,
                            p.partido_puntoslocal favor, p.partido_puntosvisitante contra,
                            (p.partido_puntoslocal > p.partido_puntosvisitante) gano,
                            (es.estado_codigo = 'WALKOVER') walkover
                       FROM dsl_partido p
                       JOIN dsl_estado es ON es.estado_id = p.partido_estadoid
                       JOIN dsl_fase f ON f.fase_id = p.partido_faseid
                      WHERE es.estado_efectivo = 'S' AND f.fase_categoriaid = :c1
                     UNION ALL
                     SELECT p.partido_visitanteid,
                            p.partido_puntosvisitante, p.partido_puntoslocal,
                            (p.partido_puntosvisitante > p.partido_puntoslocal),
                            (es.estado_codigo = 'WALKOVER')
                       FROM dsl_partido p
                       JOIN dsl_estado es ON es.estado_id = p.partido_estadoid
                       JOIN dsl_fase f ON f.fase_id = p.partido_faseid
                      WHERE es.estado_efectivo = 'S' AND f.fase_categoriaid = :c2
                    ) x
               JOIN dsl_inscripcion i ON i.inscripcion_id = x.ins
               JOIN dsl_equipo e ON e.equipo_id = i.inscripcion_equipoid
              GROUP BY x.ins, e.equipo_nombre
              ORDER BY puntos DESC, diferencia DESC, favor DESC",
            [':v' => (int) $c['v'], ':d' => (int) $c['d'], ':w' => (int) $c['w'],
             ':c1' => $categoriaId, ':c2' => $categoriaId]);

        return ['categoria' => $c, 'filas' => $filas];
    }

    /** Categorias que tienen al menos un partido jugado. */
    public function categoriasConPartidos(): array
    {
        return $this->filas(
            "SELECT c.categoria_id id, c.categoria_nombre nombre, t.torneo_nombre torneo,
                    COUNT(*) jugados
               FROM dsl_partido p
               JOIN dsl_estado es ON es.estado_id = p.partido_estadoid AND es.estado_efectivo = 'S'
               JOIN dsl_fase f ON f.fase_id = p.partido_faseid
               JOIN dsl_categoria c ON c.categoria_id = f.fase_categoriaid
               JOIN dsl_torneo t ON t.torneo_id = c.categoria_torneoid
              GROUP BY c.categoria_id ORDER BY t.torneo_nombre, c.categoria_nombre");
    }

    /*==============  Proximos partidos  ==============*/
    public function proximosPartidos(int $limite = 8): array
    {
        return $this->filas(
            "SELECT p.partido_fecha fecha, p.partido_hora hora,
                    es.estado_codigo estado, es.estado_tono tono,
                    l.equipo_nombre local, v.equipo_nombre visitante,
                    c.categoria_nombre categoria,
                    i.instalacion_nombre escenario
               FROM dsl_partido p
               JOIN dsl_estado es ON es.estado_id = p.partido_estadoid
               JOIN dsl_fase f ON f.fase_id = p.partido_faseid
               JOIN dsl_categoria c ON c.categoria_id = f.fase_categoriaid
               JOIN dsl_inscripcion il ON il.inscripcion_id = p.partido_localid
               JOIN dsl_equipo l ON l.equipo_id = il.inscripcion_equipoid
               JOIN dsl_inscripcion iv ON iv.inscripcion_id = p.partido_visitanteid
               JOIN dsl_equipo v ON v.equipo_id = iv.inscripcion_equipoid
               LEFT JOIN dsa_instalacion i ON i.instalacion_id = p.partido_instalacionid
              WHERE p.partido_fecha >= CURDATE() AND es.estado_final = 'N'
              ORDER BY p.partido_fecha, p.partido_hora
              LIMIT " . max(1, min(50, $limite)));
    }

    /*==============  Escenarios propios frente a externos  ==============*/
    /*
    | League no tiene sede porque sus torneos pueden organizarse fuera del
    | club. Pero partido_instalacionid apunta a una instalacion de Arena
    | cuando SI se juega en casa, asi que ese reparto si se puede saber. Es
    | informacion que aparecio al decidir que League no llevara sede.
    */
    public function escenarios(): array
    {
        $r = $this->filas(
            "SELECT SUM(p.partido_instalacionid IS NOT NULL) propios,
                    SUM(p.partido_instalacionid IS NULL)     externos,
                    COUNT(*) total
               FROM dsl_partido p");
        $f = $r[0] ?? [];
        $t = (int) ($f['total'] ?? 0);

        return [
            'propios'  => (int) ($f['propios'] ?? 0),
            'externos' => (int) ($f['externos'] ?? 0),
            'total'    => $t,
            'pctPropios' => $t > 0 ? (int) $f['propios'] / $t * 100 : null,
        ];
    }

    /*==============  Incoherencias de League  ==============*/
    public function leagueAnomalias(): array
    {
        $a = [];

        $sinResultado = (int) $this->escalar(
            "SELECT COUNT(*) FROM dsl_partido p
               JOIN dsl_estado e ON e.estado_id = p.partido_estadoid
              WHERE e.estado_efectivo = 'S'
                AND (p.partido_puntoslocal IS NULL OR p.partido_puntosvisitante IS NULL)");
        if ($sinResultado > 0) {
            $a[] = ['tono' => 'danger', 'icono' => 'fa-clipboard-question',
                'texto' => "$sinResultado partido(s) cuentan para la clasificación pero no tienen resultado"];
        }

        $atrasados = (int) $this->escalar(
            "SELECT COUNT(*) FROM dsl_partido p
               JOIN dsl_estado e ON e.estado_id = p.partido_estadoid
              WHERE e.estado_final = 'N' AND p.partido_fecha < CURDATE()");
        if ($atrasados > 0) {
            $a[] = ['tono' => 'warning', 'icono' => 'fa-hourglass-end',
                'texto' => "$atrasados partido(s) con fecha pasada siguen sin cerrarse"];
        }

        /* Conceptos sin precio: la inscripcion de equipo tiene 150,00 pero
           los demas estan a cero, asi que arbitraje, escenario y carne no
           generan obligacion aunque se usen. */
        $sinPrecio = (int) $this->escalar(
            "SELECT COUNT(*) FROM dsl_concepto WHERE concepto_activo = 'S' AND concepto_valor = 0");
        if ($sinPrecio > 0) {
            $a[] = ['tono' => 'info', 'icono' => 'fa-tag',
                'texto' => "$sinPrecio concepto(s) activo(s) con precio 0,00: no generarán cobro aunque se usen"];
        }

        $noHabilitados = (int) $this->escalar(
            "SELECT COUNT(*) FROM dsl_plantilla WHERE plantilla_rol = 'J' AND plantilla_habilitado <> 'S'");
        if ($noHabilitados > 0) {
            $a[] = ['tono' => 'info', 'icono' => 'fa-id-card',
                'texto' => "$noHabilitados ficha(s) de jugador sin habilitar: no pueden jugar"];
        }

        return $a;
    }

    /*==============  Catalogo de reportes  ==============*/
    /*
    | El catalogo vive en codigo y no en una tabla, a proposito: cada entrada
    | apunta a una vista concreta, y una tabla que hubiera que mantener en
    | paralelo con las vistas se desincronizaria en el primer cambio de ruta.
    |
    | NO SE DUPLICA LO QUE BASKETBALL YA TIENE. Once reportes existen ahi
    | desde antes; el catalogo ENLAZA a ellos en vez de reimplementarlos, que
    | es lo que pide el §51 del encargo. Se marcan como «externo» para que se
    | vea de donde sale cada uno.
    |
    | Cada entrada declara ademas la VISTA que hay que tener concedida para
    | abrirla. Un reporte que el usuario no puede ver no se le muestra: no
    | tiene sentido ofrecer una puerta cerrada.
    */
    public function catalogoReportes(): array
    {
        return [
            /* ---------- Consolidado ---------- */
            'consolidado' => [
                'titulo'   => 'Panel ejecutivo',
                'resumen'  => 'Ingresos, cartera, transacciones y ocupación de los tres módulos, con comparación entre periodos.',
                'categoria'=> 'Gerencial',
                'icono'    => 'fas fa-chart-line',
                'vista'    => 'dashboard',
                'url'      => APP_URL . 'dashboard/',
                'externo'  => false,
            ],
            'financiero' => [
                'titulo'   => 'Consolidado financiero',
                'resumen'  => 'Ingresos por módulo, por sede y por concepto; descuentos, ticket promedio y facturación electrónica.',
                'categoria'=> 'Financiero',
                'icono'    => 'fas fa-dollar-sign',
                'vista'    => 'financiero',
                'url'      => APP_URL . 'financiero/',
                'externo'  => false,
            ],
            'becas' => [
                'titulo'   => 'Becas y descuentos',
                'resumen'  => 'Cuántos alumnos, cuánto vale el beneficio, cuánto pagaron aun así y cómo asisten. Incluye el subsidio de las becas del 100 %, que no consta como descuento.',
                'categoria'=> 'Financiero',
                'icono'    => 'fas fa-user-graduate',
                'vista'    => 'becas',
                'url'      => APP_URL . 'becas/',
                'externo'  => false,
            ],

            /* ---------- Por módulo ---------- */
            'basketball' => [
                'titulo'   => 'Alumnos y retención',
                'resumen'  => 'Altas, bajas, retención por cohorte con su exposición, distribución por año de nacimiento, sede y entrenador.',
                'categoria'=> 'Basketball',
                'icono'    => 'fas fa-users',
                'vista'    => 'basketball',
                'url'      => APP_URL . 'basketball/',
                'externo'  => false,
            ],
            'asistencia' => [
                'titulo'   => 'Asistencia',
                'resumen'  => 'Porcentaje por sede y entrenador, tendencia mensual y proporción de inasistencias avisadas por el representante.',
                'categoria'=> 'Basketball',
                'icono'    => 'fas fa-clipboard-check',
                'vista'    => 'basketball',
                'url'      => APP_URL . 'basketball/',
                'externo'  => false,
            ],
            'ocupacion' => [
                'titulo'   => 'Ocupación y mapa de calor',
                'resumen'  => 'Ocupación por instalación, ingreso por hora y mapa de franjas por día y hora.',
                'categoria'=> 'Arena',
                'icono'    => 'fas fa-warehouse',
                'vista'    => 'arena',
                'url'      => APP_URL . 'arena/',
                'externo'  => false,
            ],
            'torneos' => [
                'titulo'   => 'Torneos y clasificación',
                'resumen'  => 'Participación, calendario, tabla de posiciones con la puntuación de cada categoría y recaudación por torneo.',
                'categoria'=> 'League',
                'icono'    => 'fas fa-trophy',
                'vista'    => 'league',
                'url'      => APP_URL . 'league/',
                'externo'  => false,
            ],

            /* ---------- Los que ya existen en Basketball ---------- */
            'bkPagos' => [
                'titulo'   => 'Consolidado de pagos',
                'resumen'  => 'Detalle de los cobros de la escuela por rubro y forma de pago.',
                'categoria'=> 'Basketball',
                'icono'    => 'fas fa-file-invoice-dollar',
                'vista'    => null,
                'url'      => DS_BASKETBALL_URL . 'reportePagos/',
                'externo'  => true,
            ],
            'bkPendientes' => [
                'titulo'   => 'Pagos pendientes',
                'resumen'  => 'Alumnos con saldo por cobrar, con su sede y su representante.',
                'categoria'=> 'Basketball',
                'icono'    => 'fas fa-hand-holding-usd',
                'vista'    => null,
                'url'      => DS_BASKETBALL_URL . 'reportePendientes/',
                'externo'  => true,
            ],
            'bkRubros' => [
                'titulo'   => 'Alumnos por rubro',
                'resumen'  => 'Qué se cobra a cada alumno y por qué concepto.',
                'categoria'=> 'Basketball',
                'icono'    => 'fas fa-tags',
                'vista'    => null,
                'url'      => DS_BASKETBALL_URL . 'reporteRubros/',
                'externo'  => true,
            ],
            'bkAsistencia' => [
                'titulo'   => 'Registro de asistencia',
                'resumen'  => 'El calendario de asistencia de cada alumno, día a día.',
                'categoria'=> 'Basketball',
                'icono'    => 'fas fa-calendar-check',
                'vista'    => null,
                'url'      => DS_BASKETBALL_URL . 'reporteAsistencia/',
                'externo'  => true,
            ],
            'bkBalance' => [
                'titulo'   => 'Balance de resultados',
                'resumen'  => 'Ingresos frente a egresos de la escuela en el periodo.',
                'categoria'=> 'Financiero',
                'icono'    => 'fas fa-scale-balanced',
                'vista'    => null,
                'url'      => DS_BASKETBALL_URL . 'balanceResultados/',
                'externo'  => true,
            ],
            'bkFactura' => [
                'titulo'   => 'Facturación por representante',
                'resumen'  => 'Comprobantes emitidos agrupados por representante.',
                'categoria'=> 'Financiero',
                'icono'    => 'fas fa-receipt',
                'vista'    => null,
                'url'      => DS_BASKETBALL_URL . 'reporteRepresentanteFactura/',
                'externo'  => true,
            ],
        ];
    }

    /*==============  Favoritos  ==============*/
    /*
    | Preferencia personal, guardada por usuario. Es lo primero que Insights
    | escribe desde la interfaz, asi que pasa por InsightsConexion: si el
    | candado estuviera mal puesto, marcar un favorito fallaria.
    */
    public function favoritos(): array
    {
        $filas = $this->filas(
            "SELECT favorito_reporte FROM insights_favorito WHERE favorito_usuarioid = :u",
            [':u' => usuario_actual_id()]);

        return array_column($filas, 'favorito_reporte');
    }

    /** Marca o desmarca. Devuelve el estado en que queda. */
    public function alternarFavorito(string $reporte): bool
    {
        /* La clave tiene que existir en el catalogo: sin esta comprobacion,
           cualquier cadena entraria en la tabla y quedarian favoritos
           huerfanos que no apuntan a nada. */
        if (!array_key_exists($reporte, $this->catalogoReportes())) {
            return false;
        }

        $u = usuario_actual_id();
        $ya = (int) $this->escalar(
            "SELECT COUNT(*) FROM insights_favorito
              WHERE favorito_usuarioid = :u AND favorito_reporte = :r",
            [':u' => $u, ':r' => $reporte]);

        if ($ya > 0) {
            $q = $this->conexion()->prepare(
                "DELETE FROM insights_favorito
                  WHERE favorito_usuarioid = :u AND favorito_reporte = :r");
            $q->execute([':u' => $u, ':r' => $reporte]);
            return false;
        }

        $q = $this->conexion()->prepare(
            "INSERT INTO insights_favorito (favorito_usuarioid, favorito_reporte, favorito_fecha)
             VALUES (:u, :r, NOW())");
        $q->execute([':u' => $u, ':r' => $reporte]);
        return true;
    }

    /*==============  Auditoria  ==============*/
    /*
    | Registra que hizo quien. Los filtros van ESTRUCTURADOS —periodo y sede—
    | y nunca en texto libre: un filtro puede llevar el nombre o la cedula de
    | un alumno, y entonces la tabla de auditoria se vuelve un almacen
    | paralelo de datos personales que nadie vigila. Ver database/051.
    |
    | No lanza nunca. Que falle el registro no puede impedir la accion que se
    | estaba auditando ni, peor, romper una descarga a medias; pero tampoco
    | se traga el error en silencio sin dejar rastro en el log de PHP.
    */
    public function auditar(string $accion, string $objeto, array $opciones = []): void
    {
        try {
            $q = $this->conexion()->prepare(
                "INSERT INTO insights_auditoria
                    (auditoria_usuarioid, auditoria_rolid, auditoria_accion, auditoria_objeto,
                     auditoria_desde, auditoria_hasta, auditoria_sedeid,
                     auditoria_filas, auditoria_ok, auditoria_ip, auditoria_fecha)
                 VALUES (:u, :rol, :a, :o, :d, :h, :s, :n, :ok, :ip, NOW())");

            /* INET6_ATON se hace en PHP y no en SQL para que una IP invalida
               —o ausente— no rompa la sentencia entera. */
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $ipBin = $ip !== '' ? @inet_pton($ip) : null;

            $q->execute([
                ':u'   => usuario_actual_id(),
                ':rol' => rol_actual(),
                ':a'   => substr($accion, 0, 24),
                ':o'   => substr($objeto, 0, 40),
                ':d'   => $opciones['desde']  ?? null,
                ':h'   => $opciones['hasta']  ?? null,
                ':s'   => $opciones['sede']   ?? null,
                ':n'   => $opciones['filas']  ?? null,
                ':ok'  => ($opciones['ok'] ?? true) ? 'S' : 'N',
                ':ip'  => $ipBin !== false ? $ipBin : null,
            ]);
        } catch (\Throwable $e) {
            error_log('Insights: no se pudo auditar «' . $accion . ' ' . $objeto . '»: ' . $e->getMessage());
        }
    }

    /*==============  Datos exportables  ==============*/
    /*
    | Devuelve titulo, cabeceras y filas de un reporte, en el mismo formato
    | para todos. Asi el exportador no sabe de que reporte se trata: recibe
    | una tabla y la escribe.
    |
    | LA EXPORTACION SALE DE LAS MISMAS CONSULTAS QUE LA PANTALLA. No hay una
    | version «para exportar» que pudiera divergir: si la pantalla dice 33,5 %
    | el archivo dice 33,5 %, porque es la misma llamada.
    |
    | Devuelve null si el reporte no es exportable. No todos lo son: un mapa
    | de calor o un grafico no se llevan a una tabla sin perder lo que los
    | hace utiles.
    */
    public function datosExportables(string $reporte, array $p): ?array
    {
        switch ($reporte) {
            case 'financiero':
                $filas = [];
                foreach ($this->ingresosPorSede($p) as $s) {
                    $filas[] = [$s['nombre'], $s['basketball'], $s['arena'], $s['league'], $s['total']];
                }
                return [
                    'titulo'    => 'Ingresos por sede',
                    'vista'     => 'financiero',
                    'cabeceras' => ['Sede', 'Basketball', 'Arena', 'League', 'Total'],
                    'tipos'     => ['texto', 'dinero', 'dinero', 'dinero', 'dinero'],
                    'filas'     => $filas,
                ];

            case 'conceptos':
                $filas = [];
                foreach ($this->ingresosPorConcepto($p) as $c) {
                    $filas[] = [$c['modulo'], $c['concepto'], $c['n'], $c['valor']];
                }
                return [
                    'titulo'    => 'Ingresos por concepto',
                    'vista'     => 'financiero',
                    'cabeceras' => ['Módulo', 'Concepto', 'Cobros', 'Importe'],
                    'tipos'     => ['texto', 'texto', 'entero', 'dinero'],
                    'filas'     => $filas,
                ];

            case 'becas':
                $filas = [];
                foreach ($this->beneficios() as $b) {
                    $filas[] = [$b['tipo'], $b['alumnos'], $b['activos'], $b['registrado'],
                                $b['mensual'], $b['pagado'], $b['cuotas'],
                                $b['asistencia'] === null ? '' : round($b['asistencia'], 1)];
                }
                return [
                    'titulo'    => 'Becas y descuentos vigentes',
                    'vista'     => 'becas',
                    'cabeceras' => ['Tipo', 'Alumnos', 'Activos', 'Registrado',
                                    'Valor real mensual', 'Pagado', 'Cuotas', 'Asistencia %'],
                    'tipos'     => ['texto', 'entero', 'entero', 'dinero',
                                    'dinero', 'dinero', 'entero', 'decimal'],
                    'filas'     => $filas,
                ];

            case 'retencion':
                $filas = [];
                foreach ($this->retencionPorCohorte(12) as $c) {
                    $filas[] = [$c['cohorte'], $c['ingresaron'], $c['siguen'],
                                round((float) $c['retencion'], 1), $c['meses']];
                }
                return [
                    'titulo'    => 'Retención por mes de ingreso',
                    'vista'     => 'basketball',
                    'cabeceras' => ['Cohorte', 'Ingresaron', 'Siguen activos',
                                    'Retención %', 'Meses de exposición'],
                    'tipos'     => ['texto', 'entero', 'entero', 'decimal', 'entero'],
                    'filas'     => $filas,
                ];

            case 'instalaciones':
                $filas = [];
                foreach ($this->ocupacionPorInstalacion($p) as $i) {
                    $filas[] = [$i['nombre'], $i['clase'] === 'R' ? 'Residencia' : 'Cancha',
                                $i['sede'], (int) $i['reservas'], round((float) $i['horas'], 1),
                                (float) $i['ingreso'], round((float) $i['ocupacion'], 1),
                                $i['ingresoHora'] === null ? '' : (float) $i['ingresoHora']];
                }
                return [
                    'titulo'    => 'Ocupación por instalación',
                    'vista'     => 'arena',
                    'cabeceras' => ['Instalación', 'Tipo', 'Sede', 'Reservas', 'Horas',
                                    'Ingreso', 'Ocupación %', 'Ingreso por hora'],
                    'tipos'     => ['texto', 'texto', 'texto', 'entero', 'decimal',
                                    'dinero', 'decimal', 'dinero'],
                    'filas'     => $filas,
                ];

            case 'torneos':
                $filas = [];
                foreach ($this->torneos() as $t) {
                    $filas[] = [$t['nombre'], (int) $t['categorias'], (int) $t['equipos'],
                                (int) $t['jugadores'], (int) $t['partidos'], (int) $t['jugados'],
                                (float) $t['recaudado']];
                }
                return [
                    'titulo'    => 'Torneos',
                    'vista'     => 'league',
                    'cabeceras' => ['Torneo', 'Categorías', 'Equipos', 'Jugadores',
                                    'Partidos', 'Jugados', 'Recaudado'],
                    'tipos'     => ['texto', 'entero', 'entero', 'entero',
                                    'entero', 'entero', 'dinero'],
                    'filas'     => $filas,
                ];

            default:
                return null;
        }
    }

    /** Los reportes que se pueden exportar, para pintar los botones. */
    public function exportables(): array
    {
        return [
            'financiero'    => 'Ingresos por sede',
            'conceptos'     => 'Ingresos por concepto',
            'becas'         => 'Becas y descuentos',
            'retencion'     => 'Retención por cohorte',
            'instalaciones' => 'Ocupación por instalación',
            'torneos'       => 'Torneos',
        ];
    }
}
