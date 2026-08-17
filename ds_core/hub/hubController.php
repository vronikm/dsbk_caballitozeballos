<?php

namespace hub;

use PDO;

/**
 * Hub de aplicaciones DigiSports.
 *
 * Toma el catalogo estatico de ds_core/modulos.php y le anade las metricas
 * en vivo de cada modulo. No depende de ningun modulo: abre su propia
 * conexion con las credenciales del nucleo, de modo que el portal siga en
 * pie aunque un modulo concreto no este desplegado.
 *
 * Todas las consultas son defensivas: si una tabla falta o la BD falla, el
 * Hub degrada a valores neutros en lugar de romper la puerta de entrada.
 */
class hubController
{
    private ?PDO $conexion = null;

    /*----------  Modulos con sus metricas resueltas  ----------*/

    public function modulos(): array
    {
        $modulos = ds_modulos();

        foreach ($modulos as $clave => $m) {
            /* 'base' conserva la raiz del modulo: 'url' puede apuntar a una
               vista de entrada concreta, pero los accesos rapidos cuelgan
               siempre de la raiz. */
            $modulos[$clave]['base']     = $m['url'];
            $modulos[$clave]['metricas'] = [];
        }

        /* Metricas de los modulos construidos. */
        $modulos['basketball']['metricas'] = $this->metricasBasketball();
        $modulos['basketball']['url']      = DS_BASKETBALL_URL . $this->entradaBasketball() . '/';
        $modulos['core']['metricas']       = $this->metricasCore();

        /* Un modulo construido solo se muestra si el rol tiene acceso. Los
           que aun no existen se dejan visibles como anticipo: no son
           navegables, asi que no revelan nada. */
        foreach ($modulos as $clave => $m) {
            if ($m['activo'] && !usuario_tiene_modulo($clave)) {
                unset($modulos[$clave]);
            }
        }

        return $modulos;
    }

    private function metricasCore(): array
    {
        return [
            ['valor' => $this->escalar("SELECT COUNT(1) FROM seguridad_usuario WHERE usuario_estado='A'"),
             'etiqueta' => 'Usuarios'],
            ['valor' => $this->escalar("SELECT COUNT(1) FROM seguridad_rol WHERE rol_estado='A'"),
             'etiqueta' => 'Roles'],
            ['valor' => $this->escalar("SELECT COUNT(1) FROM seguridad_permiso WHERE permiso_estado='A'"),
             'etiqueta' => 'Permisos'],
        ];
    }

    /**
     * Vista a la que entra Basketball segun el rol de la sesion.
     *
     * No todos los roles tienen permiso sobre 'dashboard': enviarlos alli
     * sin mas los dejaria en la pantalla de acceso denegado nada mas pulsar
     * la tarjeta.
     */
    private function entradaBasketball(): string
    {
        if (usuario_tiene_permiso('dashboard')) {
            return 'dashboard';
        }

        $alternativa = primera_vista_permitida();
        return $alternativa !== '' ? $alternativa : 'dashboard';
    }

    private function metricasBasketball(): array
    {
        return [
            ['valor' => $this->escalar("SELECT COUNT(1) FROM sujeto_alumno WHERE alumno_estado='A'"),
             'etiqueta' => 'Alumnos'],
            ['valor' => $this->escalar("SELECT COUNT(1) FROM asistencia_horario WHERE horario_estado='A'"),
             'etiqueta' => 'Horarios'],
            ['valor' => $this->escalar("SELECT COUNT(1) FROM alumno_pago WHERE pago_saldo > 0"),
             'etiqueta' => 'Por cobrar'],
        ];
    }

    /*----------  Resumen del ecosistema  ----------*/

    public function resumenHoy(): array
    {
        $ingresosMes = (float)$this->escalar(
            "SELECT IFNULL(SUM(pago_valor),0) FROM alumno_pago
              WHERE YEAR(pago_fecha)=YEAR(CURDATE()) AND MONTH(pago_fecha)=MONTH(CURDATE())"
        );

        return [
            ['valor' => '$' . number_format($ingresosMes, 0, ',', '.'), 'etiqueta' => 'Ingresos del mes'],
            ['valor' => $this->escalar("SELECT COUNT(1) FROM sujeto_alumno WHERE alumno_estado='A'"), 'etiqueta' => 'Alumnos activos'],
            ['valor' => $this->escalar("SELECT COUNT(1) FROM alumno_representante"),                  'etiqueta' => 'Representantes'],
            ['valor' => $this->escalar("SELECT COUNT(1) FROM general_sede"),                          'etiqueta' => 'Sedes'],
        ];
    }

    /*----------  Actividad reciente  ----------*/

    public function actividadReciente(int $limite = 5): array
    {
        $sql = "SELECT p.pago_valor,
                       CONCAT(a.alumno_primernombre,' ',a.alumno_apellidopaterno) AS alumno
                  FROM alumno_pago   p
                  JOIN sujeto_alumno a ON a.alumno_id = p.pago_alumnoid
                 ORDER BY p.pago_id DESC
                 LIMIT :limite";

        $items = [];

        foreach ($this->filas($sql, [':limite' => $limite]) as $f) {
            $items[] = [
                'titulo' => 'Pago registrado',
                'meta'   => trim((string)($f['alumno'] ?? '')) . ' · Basketball',
                'valor'  => '$' . number_format((float)$f['pago_valor'], 2),
                'estado' => 'success',
            ];
        }

        return $items;
    }

    /*----------  Avisos que requieren accion  ----------*/

    public function requiereAtencion(): array
    {
        $avisos = [];

        $pendientes = (int)$this->escalar("SELECT COUNT(1) FROM alumno_pago WHERE pago_saldo > 0");
        if ($pendientes > 0) {
            $avisos[] = [
                'titulo' => $pendientes . ' ' . ($pendientes === 1 ? 'pago pendiente' : 'pagos pendientes'),
                'meta'   => 'Basketball · Cobranza',
                'estado' => 'warning',
                'url'    => DS_BASKETBALL_URL . 'pagosPendiente/',
            ];
        }

        $sinHorario = (int)$this->escalar(
            "SELECT COUNT(1) FROM sujeto_alumno a
              WHERE a.alumno_estado='A'
                AND NOT EXISTS (SELECT 1 FROM asistencia_asignahorario ah
                                 WHERE ah.asignahorario_alumnoid = a.alumno_id)"
        );
        if ($sinHorario > 0) {
            $avisos[] = [
                'titulo' => $sinHorario . ' alumnos sin horario asignado',
                'meta'   => 'Basketball · Asistencia',
                'estado' => 'warning',
                'url'    => DS_BASKETBALL_URL . 'alumnoList/',
            ];
        }

        $sinConsentimiento = (int)$this->escalar(
            "SELECT COUNT(1) FROM sujeto_alumno a
              WHERE a.alumno_estado='A'
                AND NOT EXISTS (SELECT 1 FROM alumno_consentimiento c
                                 WHERE c.consent_alumnoid = a.alumno_id)"
        );
        if ($sinConsentimiento > 0) {
            $avisos[] = [
                'titulo' => $sinConsentimiento . ' alumnos sin consentimiento LOPDP',
                'meta'   => 'Basketball · Protección de datos',
                'estado' => 'danger',
                'url'    => DS_BASKETBALL_URL . 'consentimientoList/',
            ];
        }

        return $avisos;
    }

    /*----------  Acceso a datos  ----------*/

    private function conectar(): ?PDO
    {
        if ($this->conexion !== null) {
            return $this->conexion;
        }

        try {
            $this->conexion = new PDO(
                "mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME . ";charset=utf8",
                DB_USER,
                DB_PASS
            );
            $this->conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (\Throwable $e) {
            $this->conexion = null;
        }

        return $this->conexion;
    }

    /** Primer valor de la primera fila; 0 si la consulta falla. */
    private function escalar(string $sql)
    {
        try {
            $con = $this->conectar();
            if ($con === null) return 0;

            $fila = $con->query($sql)->fetch(PDO::FETCH_NUM);
            return $fila === false ? 0 : $fila[0];
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Todas las filas; [] si la consulta falla. */
    private function filas(string $sql, array $params = []): array
    {
        try {
            $con = $this->conectar();
            if ($con === null) return [];

            $stmt = $con->prepare($sql);
            foreach ($params as $marcador => $valor) {
                $stmt->bindValue($marcador, $valor, PDO::PARAM_INT);
            }
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Saludo segun la hora local configurada en ds_core/config/app.php. */
    public function saludo(): string
    {
        $hora = (int)date('G');
        if ($hora < 12) return 'Buenos días';
        if ($hora < 19) return 'Buenas tardes';
        return 'Buenas noches';
    }
}
