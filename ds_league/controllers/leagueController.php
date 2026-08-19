<?php

namespace league\controllers;

use PDO;

/**
 * DigiSports League.
 *
 * Torneos y ligas. Las tablas propias llevan prefijo dsl_ y son InnoDB, lo
 * que permite envolver en transaccion las tres operaciones donde una
 * escritura a medias deja datos incoherentes: generacion de fixture,
 * ejecucion de sorteo y registro de obligaciones economicas.
 *
 * Este modulo NO administra escenarios. Los consume de Arena (dsa_*) para
 * que no existan dos calendarios sobre la misma cancha.
 */
class leagueController
{
    /*==================================================================
      Acceso a datos
      ==================================================================*/

    protected function con(): ?PDO
    {
        return seguridad_conexion();
    }

    protected function filas(string $sql, array $params = []): array
    {
        try {
            $con = $this->con();
            if ($con === null) { return []; }
            $stmt = $con->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function fila(string $sql, array $params = []): array
    {
        $f = $this->filas($sql, $params);
        return $f[0] ?? [];
    }

    protected function escalar(string $sql, array $params = [], $porDefecto = 0)
    {
        try {
            $con = $this->con();
            if ($con === null) { return $porDefecto; }
            $stmt = $con->prepare($sql);
            $stmt->execute($params);
            $v = $stmt->fetchColumn();
            return $v === false ? $porDefecto : $v;
        } catch (\Throwable $e) {
            return $porDefecto;
        }
    }

    /*==================================================================
      Estados y transiciones

      La regla de que movimiento es legal vive en dsl_estado_transicion,
      no repartida por los controladores. Un partido finalizado no vuelve
      a programado porque no existe la fila, no porque alguien se acordo
      de comprobarlo.
      ==================================================================*/

    /** Estados de una entidad, en su orden de catalogo. */
    public function estados(string $entidad): array
    {
        return $this->filas(
            "SELECT estado_id, estado_codigo, estado_nombre, estado_tono,
                    estado_final, estado_efectivo
               FROM dsl_estado
              WHERE estado_entidad = :e AND estado_activo = 'S'
              ORDER BY estado_orden",
            [':e' => $entidad]
        );
    }

    /** Un estado concreto por su codigo. Devuelve [] si no existe. */
    public function estado(string $entidad, string $codigo): array
    {
        return $this->fila(
            "SELECT estado_id, estado_codigo, estado_nombre, estado_tono,
                    estado_final, estado_efectivo
               FROM dsl_estado
              WHERE estado_entidad = :e AND estado_codigo = :c",
            [':e' => $entidad, ':c' => $codigo]
        );
    }

    /**
     * Movimientos legales desde un estado, para pintar los botones de
     * accion sin que la vista tenga que saberse las reglas.
     */
    public function transicionesDesde(string $entidad, string $codigoDesde): array
    {
        return $this->filas(
            "SELECT H.estado_codigo AS hacia, H.estado_nombre, H.estado_tono,
                    T.trans_accion  AS accion,
                    T.trans_motivo  AS exige_motivo
               FROM dsl_estado_transicion T
               JOIN dsl_estado D ON D.estado_id = T.trans_desde
               JOIN dsl_estado H ON H.estado_id = T.trans_hasta
              WHERE T.trans_entidad = :e
                AND T.trans_activo  = 'S'
                AND D.estado_codigo = :d
              ORDER BY H.estado_orden",
            [':e' => $entidad, ':d' => $codigoDesde]
        );
    }

    /**
     * Comprueba si un movimiento esta permitido.
     *
     * Se consulta ANTES de escribir, no despues: es la unica forma de que
     * la transaccion que cambia el estado pueda abortar limpia.
     */
    public function transicionPermitida(string $entidad, string $desde, string $hasta): bool
    {
        return (int)$this->escalar(
            "SELECT COUNT(*)
               FROM dsl_estado_transicion T
               JOIN dsl_estado D ON D.estado_id = T.trans_desde
               JOIN dsl_estado H ON H.estado_id = T.trans_hasta
              WHERE T.trans_entidad = :e AND T.trans_activo = 'S'
                AND D.estado_codigo = :d AND H.estado_codigo = :h",
            [':e' => $entidad, ':d' => $desde, ':h' => $hasta]
        ) > 0;
    }

    /*==================================================================
      Auditoria

      Un solo punto de escritura. Los controladores llaman aqui; no
      escriben en dsl_auditoria por su cuenta.

      Se guardan los valores, no una frase: "se cambio el resultado" es
      justo lo que no sirve cuando hay que demostrar cual era el marcador
      anterior.
      ==================================================================*/

    public function auditar(string $entidad, int $entidadId, string $accion,
                            ?array $antes = null, ?array $despues = null,
                            string $nota = ''): bool
    {
        try {
            $con = $this->con();
            if ($con === null) { return false; }

            $stmt = $con->prepare(
                "INSERT INTO dsl_auditoria
                        (audit_entidad, audit_entidadid, audit_accion,
                         audit_usuarioid, audit_usuario, audit_ip,
                         audit_antes, audit_despues, audit_nota)
                 VALUES (:ent, :entid, :acc, :uid, :usr, :ip, :antes, :desp, :nota)"
            );

            /* JSON_UNESCAPED_UNICODE para que un nombre con tilde se lea
               tal cual en la consulta, y no como á. */
            $aJson = static function (?array $v): ?string {
                return $v === null ? null
                     : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            };

            return $stmt->execute([
                ':ent'   => $entidad,
                ':entid' => $entidadId,
                ':acc'   => $accion,
                ':uid'   => usuario_actual_id() ?: null,
                ':usr'   => substr(ds_nombre_usuario(), 0, 20),
                /* Se reutiliza el helper del nucleo en vez de repetirlo:
                   ya valida la direccion y usa REMOTE_ADDR y nunca
                   X-Forwarded-For, que es la parte que importa. */
                ':ip'    => intentos_ip_binaria(),
                ':antes' => $aJson($antes),
                ':desp'  => $aJson($despues),
                ':nota'  => substr($nota, 0, 250),
            ]);

        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Historial de una entidad, del cambio mas reciente al mas antiguo. */
    public function historial(string $entidad, int $entidadId, int $limite = 50): array
    {
        $limite = max(1, min(500, $limite));
        return $this->filas(
            "SELECT audit_id, audit_accion, audit_usuario, audit_nota,
                    audit_antes, audit_despues, audit_fecha,
                    INET6_NTOA(audit_ip) AS audit_ip
               FROM dsl_auditoria
              WHERE audit_entidad = :e AND audit_entidadid = :id
              ORDER BY audit_fecha DESC, audit_id DESC
              LIMIT $limite",
            [':e' => $entidad, ':id' => $entidadId]
        );
    }

    /*==================================================================
      Facturacion: el punto de emision de este modulo

      La identidad fiscal (RUC, razon social, certificado) es del
      contribuyente y vive en el Core. Lo propio de League es el punto de
      emision, y que sea distinto del de Basketball es lo que impide que
      las dos series de secuenciales colisionen.
      ==================================================================*/

    public function puntoEmision(): array
    {
        return $this->fila(
            "SELECT punto_establecimiento, punto_codigo,
                    punto_secuencialinicio, punto_estado, punto_descripcion
               FROM facturas_electronicas_punto_emision
              WHERE punto_modulo = :m",
            [':m' => DS_MODULO]
        );
    }

    /*==================================================================
      Estado de los cimientos

      Lo que el panel necesita saber mientras el modulo no tiene datos de
      negocio: si la instalacion quedo completa.
      ==================================================================*/

    public function diagnostico(): array
    {
        $punto = $this->puntoEmision();

        return [
            'estados' => [
                'etiqueta' => 'Estados catalogados',
                'valor'    => (int)$this->escalar("SELECT COUNT(*) FROM dsl_estado"),
                'detalle'  => (int)$this->escalar("SELECT COUNT(*) FROM dsl_estado_transicion")
                              . ' transiciones permitidas',
                'ok'       => (int)$this->escalar("SELECT COUNT(*) FROM dsl_estado") > 0,
            ],
            'permisos' => [
                'etiqueta' => 'Permisos',
                'valor'    => permisos_estrictos() ? 'Estrictos' : 'Permisivos',
                'detalle'  => permisos_estrictos()
                              ? 'Una vista no registrada se deniega'
                              : 'Una vista no registrada quedaria abierta',
                'ok'       => permisos_estrictos(),
            ],
            'facturacion' => [
                'etiqueta' => 'Punto de emisión',
                'valor'    => $punto
                              ? $punto['punto_establecimiento'] . '-' . $punto['punto_codigo']
                              : 'Sin asignar',
                'detalle'  => $punto
                              ? ($punto['punto_estado'] === 'A'
                                 ? 'Activo, numera desde ' . (int)$punto['punto_secuencialinicio']
                                 : 'Reservado, pendiente de activar en el Core')
                              : 'Debe asignarse antes de facturar',
                'ok'       => $punto && $punto['punto_estado'] === 'A',
            ],
            'escenarios' => [
                'etiqueta' => 'Escenarios (Arena)',
                'valor'    => (int)$this->escalar(
                                  "SELECT COUNT(*) FROM dsa_instalacion WHERE instalacion_estado = 'A'"),
                'detalle'  => 'Se consumen de Arena, no se duplican aquí',
                'ok'       => (int)$this->escalar(
                                  "SELECT COUNT(*) FROM dsa_instalacion WHERE instalacion_estado = 'A'") > 0,
            ],
            'auditoria' => [
                'etiqueta' => 'Auditoría',
                'valor'    => (int)$this->escalar("SELECT COUNT(*) FROM dsl_auditoria"),
                'detalle'  => 'Registros acumulados',
                'ok'       => true,
            ],
        ];
    }

    /*==================================================================
      Menu lateral
      ==================================================================*/

    /**
     * Solo se pintan los menus con estado 'A'. Los registrados como 'O'
     * (ocultos) estan sujetos a permiso pero no aparecen aqui: son las
     * vistas de apoyo que el modo estricto obliga a registrar y que no
     * tienen sentido como entrada de menu.
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
                   . '<i class="' . htmlspecialchars($f['menu_icono'], ENT_QUOTES, 'UTF-8') . '"></i>'
                   . '<p>' . htmlspecialchars($f['menu_nombre'], ENT_QUOTES, 'UTF-8') . '</p>'
                   . '</a></li>';
        }

        return $html;
    }
}
