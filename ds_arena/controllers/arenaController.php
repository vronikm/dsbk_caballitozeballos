<?php

namespace arena\controllers;

use PDO;

/**
 * DigiSports Arena.
 *
 * Instalaciones, disponibilidad, reservas y monedero. Se apoya en la
 * conexion del nucleo; las tablas propias llevan prefijo dsa_ y son
 * InnoDB, lo que permite envolver en transaccion las operaciones que
 * tocan saldo (pagos y monedero).
 */
class arenaController
{
    /*----------  Acceso a datos  ----------*/

    private function con(): ?PDO
    {
        return seguridad_conexion();
    }

    private function filas(string $sql, array $params = []): array
    {
        try {
            $con = $this->con();
            if ($con === null) return [];
            $stmt = $con->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function escalar(string $sql, array $params = [])
    {
        try {
            $con = $this->con();
            if ($con === null) return 0;
            $stmt = $con->prepare($sql);
            $stmt->execute($params);
            $f = $stmt->fetch(PDO::FETCH_NUM);
            return $f === false ? 0 : $f[0];
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function escribir(string $sql, array $params = []): int
    {
        try {
            $con = $this->con();
            if ($con === null) return -1;
            $stmt = $con->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            return -1;
        }
    }

    public function alerta(string $tipo, string $titulo, string $texto, string $icono = 'success', string $url = ''): string
    {
        $a = ['tipo' => $tipo, 'titulo' => $titulo, 'texto' => $texto, 'icono' => $icono];
        if ($url !== '') $a['url'] = $url;
        return json_encode($a, JSON_UNESCAPED_UNICODE);
    }

    /*======================================================================
      Sedes: sólo las que ofrecen alquiler
      ====================================================================*/

    /**
     * general_sede es compartida con la escuela. Arena sólo administra las
     * sedes cuyo tipo incluye alquiler (catálogo sede_tipoingreso).
     */
    public function sedesAlquiler(): array
    {
        $codigos = ARENA_SEDES_ALQUILER;
        $marcas  = implode(',', array_fill(0, count($codigos), '?'));

        return $this->filas(
            "SELECT sede_id, sede_nombre, sede_tipoingreso
               FROM general_sede
              WHERE sede_tipoingreso IN ($marcas)
              ORDER BY sede_nombre",
            $codigos
        );
    }

    public function sedeOfreceAlquiler(int $sedeid): bool
    {
        $tipo = (string)$this->escalar(
            "SELECT sede_tipoingreso FROM general_sede WHERE sede_id = :id",
            [':id' => $sedeid]
        );

        return in_array($tipo, ARENA_SEDES_ALQUILER, true);
    }

    /*======================================================================
      Catálogos
      ====================================================================*/

    public function tiposPiso(): array
    {
        return $this->filas(
            "SELECT piso_id, piso_nombre, piso_detalle
               FROM dsa_tipo_piso WHERE piso_estado = 'A' ORDER BY piso_nombre"
        );
    }

    public function formasIngreso(): array
    {
        return $this->filas(
            "SELECT forma_id, forma_codigo, forma_nombre, forma_esmonedero, forma_requiereref
               FROM dsa_forma_ingreso WHERE forma_estado = 'A' ORDER BY forma_orden"
        );
    }

    /*======================================================================
      Instalaciones
      ====================================================================*/

    public function instalaciones(int $sedeid = 0, string $clase = ''): array
    {
        $sql = "SELECT i.*, s.sede_nombre, p.piso_nombre,
                       (SELECT COUNT(1) FROM dsa_horario h
                         WHERE h.horario_instalacionid = i.instalacion_id
                           AND h.horario_estado = 'A') AS franjas,
                       (SELECT COUNT(1) FROM dsa_reserva r
                         WHERE r.reserva_instalacionid = i.instalacion_id
                           AND r.reserva_estado IN ('P','C')) AS reservas
                  FROM dsa_instalacion i
                  LEFT JOIN general_sede  s ON s.sede_id = i.instalacion_sedeid
                  LEFT JOIN dsa_tipo_piso p ON p.piso_id = i.instalacion_pisoid
                 WHERE i.instalacion_estado <> 'E'";

        $params = [];
        if ($sedeid > 0) { $sql .= " AND i.instalacion_sedeid = :sede"; $params[':sede'] = $sedeid; }
        if ($clase !== '') { $sql .= " AND i.instalacion_clase = :clase"; $params[':clase'] = $clase; }

        $sql .= " ORDER BY s.sede_nombre, i.instalacion_clase, i.instalacion_nombre";

        return $this->filas($sql, $params);
    }

    public function instalacion(int $id): ?array
    {
        $r = $this->filas("SELECT * FROM dsa_instalacion WHERE instalacion_id = :id", [':id' => $id]);
        return $r[0] ?? null;
    }

    /**
     * Prefijo de la sede para el código: "Sauces" -> SAU, "La Salle" -> LAS.
     *
     * Si dos sedes comparten las tres primeras letras se alarga a cuatro, y
     * si aún así coinciden se añade el id. Sin eso "SAU-C01" podría nombrar
     * dos canchas distintas, que es justo lo que el código debe evitar
     * cuando alguien lo dice por teléfono o por radio.
     */
    private function prefijoSede(int $sedeid): string
    {
        static $cache = null;

        if ($cache === null) {
            $cache  = [];
            $usados = [];

            foreach ($this->filas("SELECT sede_id, sede_nombre FROM general_sede ORDER BY sede_id") as $s) {
                $letras = strtoupper(preg_replace('/[^A-Za-z]/', '', \ds_sin_tildes((string)$s['sede_nombre'])));
                if ($letras === '') { $letras = 'SEDE'; }

                $p = substr($letras, 0, 3);
                if (isset($usados[$p])) { $p = substr($letras, 0, 4); }
                if (isset($usados[$p])) { $p = substr($letras, 0, 3) . $s['sede_id']; }

                $usados[$p] = true;
                $cache[(int)$s['sede_id']] = $p;
            }
        }

        return $cache[$sedeid] ?? ('S' . $sedeid);
    }

    /**
     * Propone el código de una instalación: PREFIJO-CLASE + consecutivo.
     * Ej.: SAU-C01 (cancha 1 de Sauces), LAS-R02 (residencia 2 de La Salle).
     *
     * El código sólo servía para impedir duplicados dentro de la sede, pero
     * se pedía a mano, así que cada alta inventaba su propia convención.
     * Generándolo aquí la restricción sigue en pie y el usuario deja de
     * cargar con ella.
     */
    public function codigoSugerido(int $sedeid, string $clase, int $excluirId = 0): string
    {
        $clase = ($clase === 'R') ? 'R' : 'C';
        $raiz  = $this->prefijoSede($sedeid) . '-' . $clase;

        /* Se parte del mayor consecutivo ya usado con esa raíz, no del
           número de filas: si se da de baja una instalación intermedia el
           conteo se repetiría y chocaría con la clave única. */
        $mayor = (int)$this->escalar(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(instalacion_codigo, :largo) AS UNSIGNED)), 0)
               FROM dsa_instalacion
              WHERE instalacion_sedeid = :s
                AND instalacion_codigo LIKE :raiz",
            [':largo' => strlen($raiz) + 1, ':s' => $sedeid, ':raiz' => $raiz . '%']
        );

        /* Aun así se comprueba hueco por hueco: pueden convivir códigos
           escritos a mano que no sigan el patrón y ocupen el sitio. La
           consulta NO filtra por estado, porque la clave única tampoco lo
           hace: una instalación dada de baja sigue reservando su código. */
        for ($n = $mayor + 1; $n <= $mayor + 999; $n++) {
            $codigo = $raiz . str_pad((string)$n, 2, '0', STR_PAD_LEFT);

            $ocupado = (int)$this->escalar(
                "SELECT COUNT(1) FROM dsa_instalacion
                  WHERE instalacion_sedeid = :s AND instalacion_codigo = :c
                    AND instalacion_id <> :id",
                [':s' => $sedeid, ':c' => $codigo, ':id' => $excluirId]
            );
            if ($ocupado === 0) { return $codigo; }
        }

        /* Mil huecos ocupados seguidos no debería ocurrir; antes que
           devolver vacío y dejar el alta sin código, se desempata. */
        return $raiz . substr((string)time(), -4);
    }

    /**
     * Versión AJAX de codigoSugerido(), para que el formulario muestre la
     * propuesta en cuanto se eligen sede y tipo.
     */
    public function sugerirCodigo(): string
    {
        if (!puede_crear('instalacionList') && !puede_editar('instalacionList')) {
            return json_encode(['codigo' => '']);
        }

        $sedeid = (int)($_POST['instalacion_sedeid'] ?? 0);
        $clase  = (string)($_POST['instalacion_clase'] ?? 'C');
        $id     = (int)($_POST['instalacion_id'] ?? 0);

        if ($sedeid <= 0 || !$this->sedeOfreceAlquiler($sedeid)) {
            return json_encode(['codigo' => '']);
        }

        return json_encode(['codigo' => $this->codigoSugerido($sedeid, $clase, $id)]);
    }

    public function guardarInstalacion(): string
    {
        $id = (int)($_POST['instalacion_id'] ?? 0);

        if ($id > 0 && !puede_editar('instalacionList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede editar instalaciones.', 'error');
        }
        if ($id === 0 && !puede_crear('instalacionList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede crear instalaciones.', 'error');
        }

        $sedeid    = (int)($_POST['instalacion_sedeid'] ?? 0);
        $clase     = ($_POST['instalacion_clase'] ?? 'C') === 'R' ? 'R' : 'C';
        $codigo    = trim((string)($_POST['instalacion_codigo'] ?? ''));
        $nombre    = trim((string)($_POST['instalacion_nombre'] ?? ''));
        $cubierta  = ($_POST['instalacion_cubierta'] ?? '') === 'S' ? 'S' : 'N';
        $pisoid    = (int)($_POST['instalacion_pisoid'] ?? 0);
        $capacidad = (int)($_POST['instalacion_capacidad'] ?? 0);
        $valorhora = (float)str_replace(',', '.', (string)($_POST['instalacion_valorhora'] ?? '0'));
        $detalle   = trim((string)($_POST['instalacion_detalle'] ?? ''));
        $estado    = ($_POST['instalacion_estado'] ?? 'A') === 'A' ? 'A' : 'I';

        /* El código ya no se exige: si llega en blanco se genera más abajo,
           en cuanto la sede esté validada. */
        if ($nombre === '') {
            return $this->alerta('simple', 'Faltan datos', 'El nombre es obligatorio.', 'error');
        }

        /* general_sede es MyISAM y no admite clave foránea: la referencia
           se valida aquí, junto con la regla de negocio. */
        if ($sedeid <= 0 || !$this->sedeOfreceAlquiler($sedeid)) {
            return $this->alerta('simple', 'Sede no válida',
                'La sede no existe o no está marcada como sede de alquiler. Cámbielo en el catálogo de sedes.', 'error');
        }

        if ($valorhora < 0) {
            return $this->alerta('simple', 'Tarifa no válida', 'El valor por hora no puede ser negativo.', 'error');
        }

        /* Las residencias no tienen piso ni condición de cubierta. */
        if ($clase === 'R') { $cubierta = null; $pisoid = 0; }

        /* Código en blanco: se genera aquí, no en el navegador. El
           formulario sólo muestra la propuesta; si alguien envía la
           petición sin pasar por él, el código se asigna igual. */
        if ($codigo === '') {
            $codigo = $this->codigoSugerido($sedeid, $clase, $id);
        }

        $duplicado = (int)$this->escalar(
            "SELECT COUNT(1) FROM dsa_instalacion
              WHERE instalacion_sedeid = :s AND instalacion_codigo = :c
                AND instalacion_id <> :id",
            [':s' => $sedeid, ':c' => $codigo, ':id' => $id]
        );
        /* Antes se excluían las bajas ('E'), pero la clave única de la
           tabla no las excluye: el código de una instalación dada de baja
           sigue ocupado. La comprobación decía "libre", el INSERT moría
           contra la clave y el usuario sólo veía "No fue posible crear la
           instalación", sin saber por qué. */
        if ($duplicado > 0) {
            return $this->alerta('simple', 'Código repetido',
                "Ya existe una instalación con el código {$codigo} en esa sede. "
                . "Puede ser una instalación dada de baja: el código le sigue perteneciendo. "
                . "Deje el campo en blanco y el sistema asignará el siguiente libre.", 'error');
        }

        $params = [
            ':s'  => $sedeid,     ':cl' => $clase,     ':co' => $codigo,
            ':n'  => $nombre,     ':cu' => $cubierta,  ':p'  => $pisoid > 0 ? $pisoid : null,
            ':ca' => $capacidad > 0 ? $capacidad : null,
            ':v'  => $valorhora,  ':d'  => $detalle !== '' ? $detalle : null,
            ':e'  => $estado,
        ];

        if ($id > 0) {
            $params[':id'] = $id;
            $n = $this->escribir(
                "UPDATE dsa_instalacion
                    SET instalacion_sedeid = :s, instalacion_clase = :cl,
                        instalacion_codigo = :co, instalacion_nombre = :n,
                        instalacion_cubierta = :cu, instalacion_pisoid = :p,
                        instalacion_capacidad = :ca, instalacion_valorhora = :v,
                        instalacion_detalle = :d, instalacion_estado = :e
                  WHERE instalacion_id = :id", $params
            );

            return $n >= 0
                ? $this->alerta('redireccionar', 'Instalación actualizada',
                    "{$nombre} se actualizó correctamente.", 'success', APP_URL . 'instalacionList/')
                : $this->alerta('simple', 'Error', 'No fue posible actualizar la instalación.', 'error');
        }

        $n = $this->escribir(
            "INSERT INTO dsa_instalacion
                (instalacion_sedeid, instalacion_clase, instalacion_codigo, instalacion_nombre,
                 instalacion_cubierta, instalacion_pisoid, instalacion_capacidad,
                 instalacion_valorhora, instalacion_detalle, instalacion_estado)
             VALUES (:s, :cl, :co, :n, :cu, :p, :ca, :v, :d, :e)", $params
        );

        return $n > 0
            ? $this->alerta('redireccionar', 'Instalación creada',
                "{$nombre} quedó registrada. Defina su disponibilidad en Horarios.",
                'success', APP_URL . 'instalacionList/')
            : $this->alerta('simple', 'Error', 'No fue posible crear la instalación.', 'error');
    }

    public function eliminarInstalacion(): string
    {
        if (!puede_eliminar('instalacionList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede eliminar instalaciones.', 'error');
        }

        $id = (int)($_POST['instalacion_id'] ?? 0);

        $reservas = (int)$this->escalar(
            "SELECT COUNT(1) FROM dsa_reserva
              WHERE reserva_instalacionid = :id AND reserva_estado IN ('P','C')",
            [':id' => $id]
        );
        if ($reservas > 0) {
            return $this->alerta('simple', 'Instalación con reservas',
                "No se puede eliminar: tiene {$reservas} reserva(s) vigente(s). Cancélelas o desactive la instalación.", 'error');
        }

        /* Baja lógica: conserva el histórico de reservas ya cumplidas. */
        $n = $this->escribir(
            "UPDATE dsa_instalacion SET instalacion_estado = 'E' WHERE instalacion_id = :id",
            [':id' => $id]
        );

        return $n > 0
            ? $this->alerta('recargar', 'Instalación eliminada', 'La instalación fue dada de baja.')
            : $this->alerta('simple', 'Error', 'No fue posible eliminar la instalación.', 'error');
    }

    /*======================================================================
      Disponibilidad semanal
      ====================================================================*/

    /** Nombre de los días, indexado 1=lunes … 7=domingo (ISO-8601). */
    public function dias(): array
    {
        return [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves',
                5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
    }

    public function horarios(int $instalacionid): array
    {
        return $this->filas(
            "SELECT h.*, i.instalacion_nombre
               FROM dsa_horario h
               JOIN dsa_instalacion i ON i.instalacion_id = h.horario_instalacionid
              WHERE h.horario_instalacionid = :i AND h.horario_estado = 'A'
              ORDER BY h.horario_dia, h.horario_desde",
            [':i' => $instalacionid]
        );
    }

    public function guardarHorario(): string
    {
        if (!puede_crear('horarioList') && !puede_editar('horarioList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede administrar horarios.', 'error');
        }

        $id           = (int)($_POST['horario_id'] ?? 0);
        $instalacion  = (int)($_POST['horario_instalacionid'] ?? 0);
        $desde        = trim((string)($_POST['horario_desde'] ?? ''));
        $hasta        = trim((string)($_POST['horario_hasta'] ?? ''));
        $dias         = $_POST['horario_dia'] ?? [];

        if ($id > 0 && !puede_editar('horarioList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede editar horarios.', 'error');
        }
        if ($id === 0 && !puede_crear('horarioList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede crear horarios.', 'error');
        }

        if ($instalacion <= 0 || $this->instalacion($instalacion) === null) {
            return $this->alerta('simple', 'Instalación no válida', 'Seleccione una instalación.', 'error');
        }

        if (!preg_match('/^\d{2}:\d{2}$/', $desde) || !preg_match('/^\d{2}:\d{2}$/', $hasta)) {
            return $this->alerta('simple', 'Horas no válidas', 'Indique hora de inicio y fin.', 'error');
        }
        if ($hasta <= $desde) {
            return $this->alerta('simple', 'Rango no válido', 'La hora de fin debe ser posterior a la de inicio.', 'error');
        }

        /* Al editar se toca un solo día; al crear se aceptan varios de una vez. */
        $seleccion = $id > 0 ? [(int)($_POST['horario_diaunico'] ?? 0)]
                             : array_map('intval', (array)$dias);
        $seleccion = array_values(array_filter($seleccion, fn($d) => $d >= 1 && $d <= 7));

        if (!$seleccion) {
            return $this->alerta('simple', 'Sin días', 'Seleccione al menos un día de la semana.', 'error');
        }

        $nombres    = $this->dias();
        $solapados  = [];
        $guardados  = 0;

        foreach ($seleccion as $dia) {

            /* Dos franjas del mismo día no pueden cruzarse: harían ambigua
               la disponibilidad. Se solapan si empiezan antes de que la
               otra termine y terminan después de que la otra empiece. */
            $choca = (int)$this->escalar(
                "SELECT COUNT(1) FROM dsa_horario
                  WHERE horario_instalacionid = :i AND horario_dia = :d
                    AND horario_estado = 'A' AND horario_id <> :id
                    AND horario_desde < :hasta AND horario_hasta > :desde",
                [':i' => $instalacion, ':d' => $dia, ':id' => $id,
                 ':desde' => $desde, ':hasta' => $hasta]
            );

            if ($choca > 0) {
                $solapados[] = $nombres[$dia];
                continue;
            }

            if ($id > 0) {
                $this->escribir(
                    "UPDATE dsa_horario
                        SET horario_dia = :d, horario_desde = :desde, horario_hasta = :hasta
                      WHERE horario_id = :id",
                    [':d' => $dia, ':desde' => $desde, ':hasta' => $hasta, ':id' => $id]
                );
            } else {
                $this->escribir(
                    "INSERT INTO dsa_horario
                        (horario_instalacionid, horario_dia, horario_desde, horario_hasta, horario_estado)
                     VALUES (:i, :d, :desde, :hasta, 'A')",
                    [':i' => $instalacion, ':d' => $dia, ':desde' => $desde, ':hasta' => $hasta]
                );
            }

            $guardados++;
        }

        if ($guardados === 0) {
            return $this->alerta('simple', 'Franja solapada',
                'Ya existe disponibilidad que se cruza con ese rango en: ' . implode(', ', $solapados) . '.', 'error');
        }

        $texto = $guardados . ' franja(s) guardada(s).';
        if ($solapados) {
            $texto .= ' Se omitieron por solaparse: ' . implode(', ', $solapados) . '.';
        }

        return $this->alerta('recargar', 'Disponibilidad actualizada', $texto,
            $solapados ? 'warning' : 'success');
    }

    public function eliminarHorario(): string
    {
        if (!puede_eliminar('horarioList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede eliminar horarios.', 'error');
        }

        $id = (int)($_POST['horario_id'] ?? 0);

        $n = $this->escribir("DELETE FROM dsa_horario WHERE horario_id = :id", [':id' => $id]);

        return $n > 0
            ? $this->alerta('recargar', 'Franja eliminada', 'La franja de disponibilidad fue eliminada.')
            : $this->alerta('simple', 'Error', 'No fue posible eliminar la franja.', 'error');
    }

    /*======================================================================
      Bloqueos: mantenimiento y otros cierres
      ====================================================================*/

    public function bloqueos(int $instalacionid = 0, bool $soloVigentes = true): array
    {
        $sql = "SELECT b.*, i.instalacion_nombre, i.instalacion_codigo, s.sede_nombre
                  FROM dsa_bloqueo b
                  JOIN dsa_instalacion i ON i.instalacion_id = b.bloqueo_instalacionid
                  LEFT JOIN general_sede s ON s.sede_id = i.instalacion_sedeid
                 WHERE b.bloqueo_estado = 'A'";

        $params = [];
        if ($instalacionid > 0) { $sql .= " AND b.bloqueo_instalacionid = :i"; $params[':i'] = $instalacionid; }
        if ($soloVigentes)      { $sql .= " AND b.bloqueo_fin >= NOW()"; }

        $sql .= " ORDER BY b.bloqueo_inicio";

        return $this->filas($sql, $params);
    }

    public function guardarBloqueo(): string
    {
        $id = (int)($_POST['bloqueo_id'] ?? 0);

        if ($id > 0 && !puede_editar('bloqueoList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede editar bloqueos.', 'error');
        }
        if ($id === 0 && !puede_crear('bloqueoList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede crear bloqueos.', 'error');
        }

        $instalacion = (int)($_POST['bloqueo_instalacionid'] ?? 0);
        $tipo        = in_array($_POST['bloqueo_tipo'] ?? 'M', ['M','E','O'], true) ? $_POST['bloqueo_tipo'] : 'M';
        $inicio      = trim((string)($_POST['bloqueo_inicio'] ?? ''));
        $fin         = trim((string)($_POST['bloqueo_fin'] ?? ''));
        $motivo      = trim((string)($_POST['bloqueo_motivo'] ?? ''));

        if ($instalacion <= 0 || $this->instalacion($instalacion) === null) {
            return $this->alerta('simple', 'Instalación no válida', 'Seleccione una instalación.', 'error');
        }

        /* Los campos datetime-local llegan como "Y-m-dTH:i". */
        $inicio = str_replace('T', ' ', $inicio);
        $fin    = str_replace('T', ' ', $fin);

        if ($inicio === '' || $fin === '') {
            return $this->alerta('simple', 'Faltan fechas', 'Indique inicio y fin del bloqueo.', 'error');
        }
        if ($fin <= $inicio) {
            return $this->alerta('simple', 'Rango no válido', 'El fin debe ser posterior al inicio.', 'error');
        }

        /* Un bloqueo sobre reservas ya aceptadas deja al cliente sin
           instalación: se avisa en lugar de romperlas en silencio. */
        $enConflicto = (int)$this->escalar(
            "SELECT COUNT(1) FROM dsa_reserva
              WHERE reserva_instalacionid = :i
                AND reserva_estado IN ('P','C')
                AND TIMESTAMP(reserva_fecha, reserva_horainicio) < :fin
                AND TIMESTAMP(reserva_fecha, reserva_horafin)    > :inicio",
            [':i' => $instalacion, ':inicio' => $inicio, ':fin' => $fin]
        );

        $params = [':i' => $instalacion, ':t' => $tipo, ':ini' => $inicio, ':fin' => $fin,
                   ':m' => $motivo !== '' ? $motivo : null,
                   ':u' => (int)($_SESSION['usuarioid'] ?? 0) ?: null];

        if ($id > 0) {
            $params[':id'] = $id;
            $n = $this->escribir(
                "UPDATE dsa_bloqueo
                    SET bloqueo_instalacionid = :i, bloqueo_tipo = :t,
                        bloqueo_inicio = :ini, bloqueo_fin = :fin,
                        bloqueo_motivo = :m, bloqueo_usuarioid = :u
                  WHERE bloqueo_id = :id", $params);
        } else {
            $n = $this->escribir(
                "INSERT INTO dsa_bloqueo
                    (bloqueo_instalacionid, bloqueo_tipo, bloqueo_inicio, bloqueo_fin,
                     bloqueo_motivo, bloqueo_usuarioid, bloqueo_estado)
                 VALUES (:i, :t, :ini, :fin, :m, :u, 'A')", $params);
        }

        if ($n < 0) {
            return $this->alerta('simple', 'Error', 'No fue posible guardar el bloqueo.', 'error');
        }

        $texto = 'El periodo quedó bloqueado y no se podrá reservar.';
        if ($enConflicto > 0) {
            $texto .= " Atención: hay {$enConflicto} reserva(s) vigente(s) dentro de ese periodo que deberá reubicar.";
        }

        return $this->alerta('recargar', 'Bloqueo registrado', $texto,
            $enConflicto > 0 ? 'warning' : 'success');
    }

    public function eliminarBloqueo(): string
    {
        if (!puede_eliminar('bloqueoList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede eliminar bloqueos.', 'error');
        }

        $id = (int)($_POST['bloqueo_id'] ?? 0);
        $n  = $this->escribir("DELETE FROM dsa_bloqueo WHERE bloqueo_id = :id", [':id' => $id]);

        return $n > 0
            ? $this->alerta('recargar', 'Bloqueo eliminado', 'La instalación vuelve a estar disponible en ese periodo.')
            : $this->alerta('simple', 'Error', 'No fue posible eliminar el bloqueo.', 'error');
    }

    /*======================================================================
      Clientes
      ====================================================================*/

    public function clientes(string $busqueda = ''): array
    {
        $sql = "SELECT c.*,
                       COALESCE(m.monedero_saldo, 0) AS saldo,
                       (SELECT COUNT(1) FROM dsa_reserva r
                         WHERE r.reserva_clienteid = c.cliente_id
                           AND r.reserva_estado IN ('P','C')) AS reservas
                  FROM dsa_cliente c
                  LEFT JOIN dsa_monedero m ON m.monedero_clienteid = c.cliente_id
                 WHERE c.cliente_estado <> 'E'";

        $params = [];
        if ($busqueda !== '') {
            $sql .= " AND (c.cliente_nombre LIKE :b OR c.cliente_identificacion LIKE :b2)";
            $params[':b']  = '%' . $busqueda . '%';
            $params[':b2'] = '%' . $busqueda . '%';
        }

        $sql .= " ORDER BY c.cliente_nombre";

        return $this->filas($sql, $params);
    }

    public function cliente(int $id): ?array
    {
        $r = $this->filas("SELECT * FROM dsa_cliente WHERE cliente_id = :id", [':id' => $id]);
        return $r[0] ?? null;
    }

    public function guardarCliente(): string
    {
        $id = (int)($_POST['cliente_id'] ?? 0);

        if ($id > 0 && !puede_editar('clienteList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede editar clientes.', 'error');
        }
        if ($id === 0 && !puede_crear('clienteList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede crear clientes.', 'error');
        }

        $identificacion = trim((string)($_POST['cliente_identificacion'] ?? ''));
        $nombre         = trim((string)($_POST['cliente_nombre'] ?? ''));
        $correo         = trim((string)($_POST['cliente_correo'] ?? ''));
        $celular        = trim((string)($_POST['cliente_celular'] ?? ''));
        $direccion      = trim((string)($_POST['cliente_direccion'] ?? ''));
        $estado         = ($_POST['cliente_estado'] ?? 'A') === 'A' ? 'A' : 'I';

        if ($nombre === '' || $identificacion === '') {
            return $this->alerta('simple', 'Faltan datos', 'Identificación y nombre son obligatorios.', 'error');
        }

        /* Arena alquila también a extranjeros: se admite cédula, RUC o
           pasaporte, por eso no se exige el dígito verificador ecuatoriano. */
        if (!preg_match('/^[A-Za-z0-9\-]{5,20}$/', $identificacion)) {
            return $this->alerta('simple', 'Identificación no válida',
                'Entre 5 y 20 caracteres, sin espacios ni símbolos.', 'error');
        }

        if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            return $this->alerta('simple', 'Correo no válido', 'Revise la dirección de correo.', 'error');
        }

        $duplicado = (int)$this->escalar(
            "SELECT COUNT(1) FROM dsa_cliente
              WHERE cliente_identificacion = :i AND cliente_id <> :id",
            [':i' => $identificacion, ':id' => $id]
        );
        if ($duplicado > 0) {
            return $this->alerta('simple', 'Cliente repetido',
                "Ya existe un cliente con la identificación {$identificacion}.", 'error');
        }

        $params = [':i' => $identificacion, ':n' => $nombre,
                   ':c' => $correo !== '' ? $correo : null,
                   ':ce' => $celular !== '' ? $celular : null,
                   ':d' => $direccion !== '' ? $direccion : null,
                   ':e' => $estado];

        if ($id > 0) {
            $params[':id'] = $id;
            $n = $this->escribir(
                "UPDATE dsa_cliente
                    SET cliente_identificacion = :i, cliente_nombre = :n,
                        cliente_correo = :c, cliente_celular = :ce,
                        cliente_direccion = :d, cliente_estado = :e
                  WHERE cliente_id = :id", $params);

            return $n >= 0
                ? $this->alerta('redireccionar', 'Cliente actualizado',
                    "{$nombre} se actualizó correctamente.", 'success', APP_URL . 'clienteList/')
                : $this->alerta('simple', 'Error', 'No fue posible actualizar el cliente.', 'error');
        }

        $n = $this->escribir(
            "INSERT INTO dsa_cliente
                (cliente_identificacion, cliente_nombre, cliente_correo,
                 cliente_celular, cliente_direccion, cliente_estado)
             VALUES (:i, :n, :c, :ce, :d, :e)", $params);

        return $n > 0
            ? $this->alerta('redireccionar', 'Cliente creado',
                "{$nombre} quedó registrado.", 'success', APP_URL . 'clienteList/')
            : $this->alerta('simple', 'Error', 'No fue posible crear el cliente.', 'error');
    }

    public function eliminarCliente(): string
    {
        if (!puede_eliminar('clienteList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede eliminar clientes.', 'error');
        }

        $id = (int)($_POST['cliente_id'] ?? 0);

        $vigentes = (int)$this->escalar(
            "SELECT COUNT(1) FROM dsa_reserva
              WHERE reserva_clienteid = :id AND reserva_estado IN ('P','C')",
            [':id' => $id]
        );
        if ($vigentes > 0) {
            return $this->alerta('simple', 'Cliente con reservas',
                "No se puede eliminar: tiene {$vigentes} reserva(s) vigente(s).", 'error');
        }

        $saldo = (float)$this->escalar(
            "SELECT COALESCE(monedero_saldo,0) FROM dsa_monedero WHERE monedero_clienteid = :id",
            [':id' => $id]
        );
        if ($saldo > 0) {
            return $this->alerta('simple', 'Cliente con saldo',
                'No se puede eliminar: tiene $' . number_format($saldo, 2) .
                ' en el monedero. Devuélvalo antes de dar de baja.', 'error');
        }

        $n = $this->escribir(
            "UPDATE dsa_cliente SET cliente_estado = 'E' WHERE cliente_id = :id", [':id' => $id]);

        return $n > 0
            ? $this->alerta('recargar', 'Cliente eliminado', 'El cliente fue dado de baja.')
            : $this->alerta('simple', 'Error', 'No fue posible eliminar el cliente.', 'error');
    }

    /*======================================================================
      Disponibilidad y tarifa
      ====================================================================*/

    /**
     * Tarifa por hora aplicable a una instalación en una fecha y hora.
     * Si hay una tarifa vigente para esa franja se usa; si no, la base.
     */
    public function tarifaAplicable(int $instalacionid, string $fecha, string $hora): float
    {
        $dia = (int)date('N', strtotime($fecha));

        $especial = $this->filas(
            "SELECT tarifa_valorhora FROM dsa_tarifa
              WHERE tarifa_instalacionid = :i
                AND tarifa_estado = 'A'
                AND (tarifa_dia IS NULL OR tarifa_dia = :d)
                AND tarifa_desde <= :h AND tarifa_hasta > :h2
                AND (tarifa_vigenciadesde IS NULL OR tarifa_vigenciadesde <= :f)
                AND (tarifa_vigenciahasta IS NULL OR tarifa_vigenciahasta >= :f2)
              ORDER BY tarifa_dia IS NULL, tarifa_id DESC
              LIMIT 1",
            [':i' => $instalacionid, ':d' => $dia, ':h' => $hora, ':h2' => $hora,
             ':f' => $fecha, ':f2' => $fecha]
        );

        if ($especial) {
            return (float)$especial[0]['tarifa_valorhora'];
        }

        return (float)$this->escalar(
            "SELECT instalacion_valorhora FROM dsa_instalacion WHERE instalacion_id = :i",
            [':i' => $instalacionid]
        );
    }

    /**
     * Comprueba si una instalación se puede reservar en un intervalo.
     *
     * Son tres condiciones y las tres deben cumplirse:
     *   1. El intervalo cae completo dentro de una franja de disponibilidad
     *      del día de la semana correspondiente.
     *   2. No pisa ningún bloqueo (mantenimiento, evento).
     *   3. No se cruza con otra reserva vigente de la misma instalación.
     *
     * Devuelve ['ok'=>bool, 'motivo'=>string].
     */
    public function verificarDisponibilidad(int $instalacionid, string $fecha,
                                            string $inicio, string $fin,
                                            int $excluirReserva = 0): array
    {
        $inst = $this->instalacion($instalacionid);
        if ($inst === null || $inst['instalacion_estado'] !== 'A') {
            return ['ok' => false, 'motivo' => 'La instalación no existe o está inactiva.'];
        }

        $dia     = (int)date('N', strtotime($fecha));
        $nombres = $this->dias();

        /* 1. Dentro del horario semanal */
        $cabe = (int)$this->escalar(
            "SELECT COUNT(1) FROM dsa_horario
              WHERE horario_instalacionid = :i AND horario_dia = :d
                AND horario_estado = 'A'
                AND horario_desde <= :ini AND horario_hasta >= :fin",
            [':i' => $instalacionid, ':d' => $dia, ':ini' => $inicio, ':fin' => $fin]
        );

        if ($cabe === 0) {
            return ['ok' => false,
                    'motivo' => 'Fuera del horario de atención: el ' . strtolower($nombres[$dia]) .
                                ' no hay una franja que cubra ' . $inicio . '–' . $fin . '.'];
        }

        /* 2. Sin bloqueos que se crucen */
        $bloqueo = $this->filas(
            "SELECT bloqueo_motivo, bloqueo_tipo FROM dsa_bloqueo
              WHERE bloqueo_instalacionid = :i AND bloqueo_estado = 'A'
                AND bloqueo_inicio < TIMESTAMP(:f, :fin)
                AND bloqueo_fin    > TIMESTAMP(:f2, :ini)
              LIMIT 1",
            [':i' => $instalacionid, ':f' => $fecha, ':fin' => $fin,
             ':f2' => $fecha, ':ini' => $inicio]
        );

        if ($bloqueo) {
            $motivo = $bloqueo[0]['bloqueo_motivo'] ?: 'mantenimiento programado';
            return ['ok' => false, 'motivo' => 'La instalación está bloqueada en ese periodo: ' . $motivo . '.'];
        }

        /* 3. Sin cruce con otra reserva vigente */
        $ocupada = $this->filas(
            "SELECT r.reserva_codigo, r.reserva_horainicio, r.reserva_horafin
               FROM dsa_reserva r
              WHERE r.reserva_instalacionid = :i
                AND r.reserva_fecha = :f
                AND r.reserva_estado IN ('P','C')
                AND r.reserva_id <> :ex
                AND r.reserva_horainicio < :fin
                AND r.reserva_horafin    > :ini
              LIMIT 1",
            [':i' => $instalacionid, ':f' => $fecha, ':ex' => $excluirReserva,
             ':fin' => $fin, ':ini' => $inicio]
        );

        if ($ocupada) {
            return ['ok' => false,
                    'motivo' => 'Ya hay una reserva (' . $ocupada[0]['reserva_codigo'] . ') de ' .
                                substr($ocupada[0]['reserva_horainicio'], 0, 5) . ' a ' .
                                substr($ocupada[0]['reserva_horafin'], 0, 5) . '.'];
        }

        return ['ok' => true, 'motivo' => ''];
    }

    /*======================================================================
      Reservas
      ====================================================================*/

    public function reservas(array $filtros = []): array
    {
        $sql = "SELECT r.*, c.cliente_nombre, c.cliente_identificacion,
                       i.instalacion_nombre, i.instalacion_codigo, i.instalacion_clase,
                       s.sede_nombre
                  FROM dsa_reserva     r
                  JOIN dsa_cliente     c ON c.cliente_id     = r.reserva_clienteid
                  JOIN dsa_instalacion i ON i.instalacion_id = r.reserva_instalacionid
                  LEFT JOIN general_sede s ON s.sede_id      = r.reserva_sedeid
                 WHERE 1 = 1";

        $params = [];

        if (!empty($filtros['instalacion'])) {
            $sql .= " AND r.reserva_instalacionid = :i"; $params[':i'] = (int)$filtros['instalacion'];
        }
        if (!empty($filtros['estado'])) {
            $sql .= " AND r.reserva_estado = :e";        $params[':e'] = $filtros['estado'];
        }
        if (!empty($filtros['desde'])) {
            $sql .= " AND r.reserva_fecha >= :d";        $params[':d'] = $filtros['desde'];
        }
        if (!empty($filtros['hasta'])) {
            $sql .= " AND r.reserva_fecha <= :h";        $params[':h'] = $filtros['hasta'];
        }
        if (!empty($filtros['saldo'])) {
            $sql .= " AND r.reserva_saldo > 0";
        }

        $sql .= " ORDER BY r.reserva_fecha DESC, r.reserva_horainicio DESC";

        return $this->filas($sql, $params);
    }

    public function reserva(int $id): ?array
    {
        $r = $this->filas(
            "SELECT r.*, c.cliente_nombre, c.cliente_identificacion,
                    i.instalacion_nombre, i.instalacion_codigo
               FROM dsa_reserva r
               JOIN dsa_cliente c ON c.cliente_id = r.reserva_clienteid
               JOIN dsa_instalacion i ON i.instalacion_id = r.reserva_instalacionid
              WHERE r.reserva_id = :id", [':id' => $id]);

        return $r[0] ?? null;
    }

    /** Código legible y correlativo por año: RES-2026-00001 */
    private function siguienteCodigo(): string
    {
        $anio = date('Y');

        $ultimo = (string)$this->escalar(
            "SELECT reserva_codigo FROM dsa_reserva
              WHERE reserva_codigo LIKE :p
              ORDER BY reserva_id DESC LIMIT 1",
            [':p' => 'RES-' . $anio . '-%']
        );

        $n = $ultimo !== '' ? ((int)substr($ultimo, -5)) + 1 : 1;

        return sprintf('RES-%s-%05d', $anio, $n);
    }

    public function guardarReserva(): string
    {
        $id = (int)($_POST['reserva_id'] ?? 0);

        if ($id > 0 && !puede_editar('reservaList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede editar reservas.', 'error');
        }
        if ($id === 0 && !puede_crear('reservaList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede crear reservas.', 'error');
        }

        $cliente     = (int)($_POST['reserva_clienteid'] ?? 0);
        $instalacion = (int)($_POST['reserva_instalacionid'] ?? 0);
        $fecha       = trim((string)($_POST['reserva_fecha'] ?? ''));
        $inicio      = trim((string)($_POST['reserva_horainicio'] ?? ''));
        $fin         = trim((string)($_POST['reserva_horafin'] ?? ''));
        $observacion = trim((string)($_POST['reserva_observacion'] ?? ''));

        if ($this->cliente($cliente) === null) {
            return $this->alerta('simple', 'Cliente no válido', 'Seleccione un cliente.', 'error');
        }

        $inst = $this->instalacion($instalacion);
        if ($inst === null) {
            return $this->alerta('simple', 'Instalación no válida', 'Seleccione una instalación.', 'error');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)
            || !preg_match('/^\d{2}:\d{2}$/', $inicio)
            || !preg_match('/^\d{2}:\d{2}$/', $fin)) {
            return $this->alerta('simple', 'Datos incompletos', 'Indique fecha, hora de inicio y hora de fin.', 'error');
        }

        if ($fin <= $inicio) {
            return $this->alerta('simple', 'Rango no válido', 'La hora de fin debe ser posterior a la de inicio.', 'error');
        }

        /* Las tres comprobaciones de disponibilidad. */
        $disp = $this->verificarDisponibilidad($instalacion, $fecha, $inicio, $fin, $id);
        if (!$disp['ok']) {
            return $this->alerta('simple', 'No disponible', $disp['motivo'], 'error');
        }

        /* El importe se congela al reservar: un cambio posterior de tarifa
           no altera lo ya pactado con el cliente. */
        $horas     = round((strtotime($fin) - strtotime($inicio)) / 3600, 2);
        $valorhora = $this->tarifaAplicable($instalacion, $fecha, $inicio);
        $total     = round($horas * $valorhora, 2);

        if ($total <= 0) {
            return $this->alerta('simple', 'Tarifa no definida',
                'La instalación no tiene tarifa por hora. Configúrela antes de reservar.', 'error');
        }

        if ($id > 0) {
            $actual = $this->reserva($id);
            if ($actual === null) {
                return $this->alerta('simple', 'Error', 'La reserva no existe.', 'error');
            }

            /* Reducir el total por debajo de lo ya abonado dejaría un saldo
               negativo: hay que devolver primero. */
            if ($total < (float)$actual['reserva_abonado']) {
                return $this->alerta('simple', 'Importe menor a lo abonado',
                    'El cliente ya abonó $' . number_format((float)$actual['reserva_abonado'], 2) .
                    '. Devuelva la diferencia antes de reducir la reserva.', 'error');
            }

            $n = $this->escribir(
                "UPDATE dsa_reserva
                    SET reserva_clienteid = :c, reserva_instalacionid = :i,
                        reserva_sedeid = :s, reserva_fecha = :f,
                        reserva_horainicio = :ini, reserva_horafin = :fin,
                        reserva_horas = :h, reserva_valorhora = :v,
                        reserva_total = :t, reserva_saldo = :t2 - reserva_abonado,
                        reserva_observacion = :o
                  WHERE reserva_id = :id",
                [':c' => $cliente, ':i' => $instalacion, ':s' => (int)$inst['instalacion_sedeid'],
                 ':f' => $fecha, ':ini' => $inicio, ':fin' => $fin, ':h' => $horas,
                 ':v' => $valorhora, ':t' => $total, ':t2' => $total,
                 ':o' => $observacion !== '' ? $observacion : null, ':id' => $id]
            );

            return $n >= 0
                ? $this->alerta('redireccionar', 'Reserva actualizada',
                    'La reserva se actualizó correctamente.', 'success', APP_URL . 'reservaList/')
                : $this->alerta('simple', 'Error', 'No fue posible actualizar la reserva.', 'error');
        }

        $codigo = $this->siguienteCodigo();

        $n = $this->escribir(
            "INSERT INTO dsa_reserva
                (reserva_codigo, reserva_clienteid, reserva_instalacionid, reserva_sedeid,
                 reserva_fecha, reserva_horainicio, reserva_horafin, reserva_horas,
                 reserva_valorhora, reserva_total, reserva_abonado, reserva_saldo,
                 reserva_estado, reserva_observacion, reserva_usuarioid)
             VALUES (:cod, :c, :i, :s, :f, :ini, :fin, :h, :v, :t, 0, :t2, 'P', :o, :u)",
            [':cod' => $codigo, ':c' => $cliente, ':i' => $instalacion,
             ':s' => (int)$inst['instalacion_sedeid'], ':f' => $fecha,
             ':ini' => $inicio, ':fin' => $fin, ':h' => $horas, ':v' => $valorhora,
             ':t' => $total, ':t2' => $total,
             ':o' => $observacion !== '' ? $observacion : null,
             ':u' => (int)($_SESSION['usuarioid'] ?? 0) ?: null]
        );

        return $n > 0
            ? $this->alerta('redireccionar', 'Reserva creada',
                "{$codigo} · {$horas} h × \$" . number_format($valorhora, 2) .
                " = \$" . number_format($total, 2) . ". Registre el abono desde el detalle.",
                'success', APP_URL . 'reservaList/')
            : $this->alerta('simple', 'Error', 'No fue posible crear la reserva.', 'error');
    }

    public function cambiarEstadoReserva(): string
    {
        if (!puede_editar('reservaList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede modificar reservas.', 'error');
        }

        $id     = (int)($_POST['reserva_id'] ?? 0);
        $estado = (string)($_POST['reserva_estado'] ?? '');

        if (!in_array($estado, ['P','C','U','X'], true)) {
            return $this->alerta('simple', 'Estado no válido', 'El estado indicado no existe.', 'error');
        }

        $r = $this->reserva($id);
        if ($r === null) {
            return $this->alerta('simple', 'Error', 'La reserva no existe.', 'error');
        }

        /* Cancelar con dinero ya abonado exige decidir qué se hace con él;
           se bloquea aquí para que no quede un abono huérfano. */
        if ($estado === 'X' && (float)$r['reserva_abonado'] > 0) {
            return $this->alerta('simple', 'Reserva con abonos',
                'Tiene $' . number_format((float)$r['reserva_abonado'], 2) .
                ' abonados. Devuelva el importe o páselo al monedero antes de cancelar.', 'error');
        }

        $n = $this->escribir(
            "UPDATE dsa_reserva SET reserva_estado = :e WHERE reserva_id = :id",
            [':e' => $estado, ':id' => $id]);

        $nombres = ['P' => 'pendiente', 'C' => 'confirmada', 'U' => 'cumplida', 'X' => 'cancelada'];

        return $n >= 0
            ? $this->alerta('recargar', 'Reserva ' . $nombres[$estado],
                "{$r['reserva_codigo']} quedó como {$nombres[$estado]}.")
            : $this->alerta('simple', 'Error', 'No fue posible cambiar el estado.', 'error');
    }

    /*======================================================================
      Monedero
      ====================================================================*/

    /** Monedero del cliente; lo crea si aún no existe. Devuelve su id. */
    private function asegurarMonedero(int $clienteid): int
    {
        $id = (int)$this->escalar(
            "SELECT monedero_id FROM dsa_monedero WHERE monedero_clienteid = :c",
            [':c' => $clienteid]
        );

        if ($id > 0) {
            return $id;
        }

        $this->escribir(
            "INSERT INTO dsa_monedero (monedero_clienteid, monedero_saldo) VALUES (:c, 0)",
            [':c' => $clienteid]
        );

        return (int)$this->escalar(
            "SELECT monedero_id FROM dsa_monedero WHERE monedero_clienteid = :c",
            [':c' => $clienteid]
        );
    }

    /**
     * Aplica un movimiento al monedero dentro de la transacción en curso.
     *
     * Deja constancia del saldo antes y después, de modo que el saldo
     * actual siempre se pueda auditar recorriendo el libro mayor. Lanza
     * excepción si el saldo no alcanza: quien llama está dentro de una
     * transacción y debe deshacerla.
     */
    private function moverMonedero(PDO $con, int $monederoId, string $tipo, string $origen,
                                   float $valor, array $extra = []): void
    {
        $stmt = $con->prepare(
            "SELECT monedero_saldo FROM dsa_monedero WHERE monedero_id = :m FOR UPDATE");
        $stmt->execute([':m' => $monederoId]);
        $saldoAnterior = (float)$stmt->fetchColumn();

        $saldoNuevo = $tipo === 'I' ? $saldoAnterior + $valor : $saldoAnterior - $valor;

        if ($saldoNuevo < 0) {
            throw new \RuntimeException(
                'Saldo insuficiente en el monedero: dispone de $' . number_format($saldoAnterior, 2) . '.');
        }

        $con->prepare("UPDATE dsa_monedero SET monedero_saldo = :s WHERE monedero_id = :m")
            ->execute([':s' => $saldoNuevo, ':m' => $monederoId]);

        $con->prepare(
            "INSERT INTO dsa_monedero_movimiento
                (movimiento_monederoid, movimiento_tipo, movimiento_origen, movimiento_valor,
                 movimiento_saldoanterior, movimiento_saldonuevo, movimiento_reservaid,
                 movimiento_pagoid, movimiento_referencia, movimiento_detalle,
                 movimiento_usuarioid, movimiento_fecha)
             VALUES (:m, :t, :o, :v, :sa, :sn, :r, :p, :ref, :d, :u, CURDATE())"
        )->execute([
            ':m' => $monederoId, ':t' => $tipo, ':o' => $origen, ':v' => $valor,
            ':sa' => $saldoAnterior, ':sn' => $saldoNuevo,
            ':r' => $extra['reserva'] ?? null, ':p' => $extra['pago'] ?? null,
            ':ref' => $extra['referencia'] ?? null, ':d' => $extra['detalle'] ?? null,
            ':u' => (int)($_SESSION['usuarioid'] ?? 0) ?: null,
        ]);
    }

    public function monederos(): array
    {
        return $this->filas(
            "SELECT m.monedero_id, m.monedero_saldo, c.cliente_id,
                    c.cliente_nombre, c.cliente_identificacion,
                    (SELECT COUNT(1) FROM dsa_monedero_movimiento mv
                      WHERE mv.movimiento_monederoid = m.monedero_id) AS movimientos,
                    (SELECT MAX(mv2.movimiento_fecha) FROM dsa_monedero_movimiento mv2
                      WHERE mv2.movimiento_monederoid = m.monedero_id) AS ultimo
               FROM dsa_monedero m
               JOIN dsa_cliente  c ON c.cliente_id = m.monedero_clienteid
              WHERE c.cliente_estado <> 'E'
              ORDER BY m.monedero_saldo DESC, c.cliente_nombre"
        );
    }

    public function movimientos(int $clienteid): array
    {
        return $this->filas(
            "SELECT mv.*, r.reserva_codigo
               FROM dsa_monedero_movimiento mv
               JOIN dsa_monedero m ON m.monedero_id = mv.movimiento_monederoid
               LEFT JOIN dsa_reserva r ON r.reserva_id = mv.movimiento_reservaid
              WHERE m.monedero_clienteid = :c
              ORDER BY mv.movimiento_id DESC",
            [':c' => $clienteid]
        );
    }

    public function saldoMonedero(int $clienteid): float
    {
        return (float)$this->escalar(
            "SELECT COALESCE(monedero_saldo, 0) FROM dsa_monedero WHERE monedero_clienteid = :c",
            [':c' => $clienteid]
        );
    }

    /** Transferencia anticipada o ajuste que suma saldo a favor. */
    public function ingresoMonedero(): string
    {
        if (!puede_crear('monederoList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede registrar movimientos.', 'error');
        }

        $cliente    = (int)($_POST['cliente_id'] ?? 0);
        $valor      = round((float)str_replace(',', '.', (string)($_POST['valor'] ?? '0')), 2);
        $origen     = in_array($_POST['origen'] ?? 'TRA', ['TRA','AJU'], true) ? $_POST['origen'] : 'TRA';
        $referencia = trim((string)($_POST['referencia'] ?? ''));
        $detalle    = trim((string)($_POST['detalle'] ?? ''));

        if ($this->cliente($cliente) === null) {
            return $this->alerta('simple', 'Cliente no válido', 'Seleccione un cliente.', 'error');
        }
        if ($valor <= 0) {
            return $this->alerta('simple', 'Valor no válido', 'El importe debe ser mayor que cero.', 'error');
        }
        if ($origen === 'TRA' && $referencia === '') {
            return $this->alerta('simple', 'Falta la referencia',
                'Indique el número o comprobante de la transferencia.', 'error');
        }

        $con = $this->con();
        if ($con === null) {
            return $this->alerta('simple', 'Error', 'Sin conexión a la base de datos.', 'error');
        }

        try {
            $con->beginTransaction();
            $mon = $this->asegurarMonedero($cliente);
            $this->moverMonedero($con, $mon, 'I', $origen, $valor,
                ['referencia' => $referencia !== '' ? $referencia : null,
                 'detalle'    => $detalle !== '' ? $detalle : null]);
            $con->commit();
        } catch (\Throwable $e) {
            if ($con->inTransaction()) $con->rollBack();
            return $this->alerta('simple', 'No se pudo registrar', $e->getMessage(), 'error');
        }

        return $this->alerta('recargar', 'Saldo acreditado',
            '$' . number_format($valor, 2) . ' ingresaron al monedero del cliente.');
    }

    /**
     * Egreso: salida de dinero del monedero a pedido del cliente.
     *
     * Se registra con origen 'DEV' en el libro mayor. El saldo no puede
     * quedar negativo; moverMonedero() lanza excepción si no alcanza y la
     * transacción se deshace.
     */
    public function egresoMonedero(): string
    {
        if (!puede_eliminar('monederoList') && !puede_editar('monederoList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede retirar saldo.', 'error');
        }

        $cliente = (int)($_POST['cliente_id'] ?? 0);
        $valor   = round((float)str_replace(',', '.', (string)($_POST['valor'] ?? '0')), 2);
        $detalle = trim((string)($_POST['detalle'] ?? ''));

        if ($this->cliente($cliente) === null) {
            return $this->alerta('simple', 'Cliente no válido', 'Seleccione un cliente.', 'error');
        }
        if ($valor <= 0) {
            return $this->alerta('simple', 'Valor no válido', 'El importe debe ser mayor que cero.', 'error');
        }

        $con = $this->con();
        if ($con === null) {
            return $this->alerta('simple', 'Error', 'Sin conexión a la base de datos.', 'error');
        }

        try {
            $con->beginTransaction();
            $mon = $this->asegurarMonedero($cliente);
            $this->moverMonedero($con, $mon, 'E', 'DEV', $valor,
                ['detalle' => $detalle !== '' ? $detalle : 'Egreso a pedido del cliente']);
            $con->commit();
        } catch (\Throwable $e) {
            if ($con->inTransaction()) $con->rollBack();
            return $this->alerta('simple', 'No se pudo registrar el egreso', $e->getMessage(), 'error');
        }

        return $this->alerta('recargar', 'Egreso registrado',
            '$' . number_format($valor, 2) . ' salieron del monedero del cliente.');
    }

    /*======================================================================
      Pagos y abonos de una reserva
      ====================================================================*/

    public function pagos(int $reservaid): array
    {
        return $this->filas(
            "SELECT p.*, f.forma_nombre, f.forma_codigo
               FROM dsa_pago p
               JOIN dsa_forma_ingreso f ON f.forma_id = p.pago_formaid
              WHERE p.pago_reservaid = :r AND p.pago_estado = 'A'
              ORDER BY p.pago_id",
            [':r' => $reservaid]
        );
    }

    /**
     * Registra un abono contra una reserva.
     *
     * Todo ocurre dentro de una transacción porque son cuatro escrituras
     * que deben cuadrar entre sí: el pago, el acumulado de la reserva y,
     * según el caso, el saldo del monedero y su asiento en el libro mayor.
     * Si algo falla, no debe quedar un abono sin reflejar o un saldo movido
     * sin respaldo.
     */
    public function registrarPago(): string
    {
        if (!puede_crear('reservaList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede registrar pagos.', 'error');
        }

        $reservaid  = (int)($_POST['reserva_id'] ?? 0);
        $formaid    = (int)($_POST['pago_formaid'] ?? 0);
        $valor      = round((float)str_replace(',', '.', (string)($_POST['pago_valor'] ?? '0')), 2);
        $recibido   = round((float)str_replace(',', '.', (string)($_POST['pago_recibido'] ?? '0')), 2);
        $aMonedero  = ($_POST['pago_vueltoamonedero'] ?? 'N') === 'S';
        $referencia = trim((string)($_POST['pago_referencia'] ?? ''));
        $observacion= trim((string)($_POST['pago_observacion'] ?? ''));

        $reserva = $this->reserva($reservaid);
        if ($reserva === null) {
            return $this->alerta('simple', 'Reserva no encontrada', 'La reserva no existe.', 'error');
        }
        if ($reserva['reserva_estado'] === 'X') {
            return $this->alerta('simple', 'Reserva cancelada', 'No se pueden registrar abonos en una reserva cancelada.', 'error');
        }

        $forma = $this->filas(
            "SELECT * FROM dsa_forma_ingreso WHERE forma_id = :f AND forma_estado = 'A'",
            [':f' => $formaid]);
        if (!$forma) {
            return $this->alerta('simple', 'Forma de ingreso no válida', 'Seleccione cómo paga el cliente.', 'error');
        }
        $forma = $forma[0];

        if ($valor <= 0) {
            return $this->alerta('simple', 'Valor no válido', 'El abono debe ser mayor que cero.', 'error');
        }

        $saldo = (float)$reserva['reserva_saldo'];
        if ($valor > $saldo + 0.001) {
            return $this->alerta('simple', 'Abono mayor al saldo',
                'El saldo pendiente es $' . number_format($saldo, 2) .
                '. Registre como máximo ese importe.', 'error');
        }

        if ($forma['forma_requiereref'] === 'S' && $referencia === '') {
            return $this->alerta('simple', 'Falta la referencia',
                'Indique el número de comprobante para ' . mb_strtolower($forma['forma_nombre']) . '.', 'error');
        }

        /* El vuelto sólo tiene sentido si se recibió más de lo que se cobra. */
        $vuelto = 0.00;
        if ($forma['forma_esmonedero'] !== 'S' && $recibido > 0) {
            if ($recibido < $valor) {
                return $this->alerta('simple', 'Recibido insuficiente',
                    'Lo recibido no cubre el abono indicado.', 'error');
            }
            $vuelto = round($recibido - $valor, 2);
        }

        $con = $this->con();
        if ($con === null) {
            return $this->alerta('simple', 'Error', 'Sin conexión a la base de datos.', 'error');
        }

        try {
            $con->beginTransaction();

            /* 1. Pagar con monedero descuenta el saldo antes que nada: si
                  no alcanza, la excepción deshace todo lo demás. */
            if ($forma['forma_esmonedero'] === 'S') {
                $mon = $this->asegurarMonedero((int)$reserva['reserva_clienteid']);
                $this->moverMonedero($con, $mon, 'E', 'RES', $valor,
                    ['reserva' => $reservaid,
                     'detalle' => 'Aplicado a la reserva ' . $reserva['reserva_codigo']]);
            }

            /* 2. El abono */
            $con->prepare(
                "INSERT INTO dsa_pago
                    (pago_reservaid, pago_formaid, pago_valor, pago_recibido, pago_vuelto,
                     pago_vueltoamonedero, pago_referencia, pago_fecha, pago_observacion,
                     pago_usuarioid, pago_estado)
                 VALUES (:r, :f, :v, :rec, :vu, :vm, :ref, CURDATE(), :o, :u, 'A')"
            )->execute([
                ':r' => $reservaid, ':f' => $formaid, ':v' => $valor,
                ':rec' => $recibido > 0 ? $recibido : null, ':vu' => $vuelto,
                ':vm' => ($vuelto > 0 && $aMonedero) ? 'S' : 'N',
                ':ref' => $referencia !== '' ? $referencia : null,
                ':o' => $observacion !== '' ? $observacion : null,
                ':u' => (int)($_SESSION['usuarioid'] ?? 0) ?: null,
            ]);

            $pagoId = (int)$con->lastInsertId();

            /* 3. Acumulado de la reserva.
                  MySQL evalúa las asignaciones de izquierda a derecha y las
                  posteriores ven el valor YA actualizado. Por eso el saldo
                  se calcula contra reserva_abonado a secas: en ese punto
                  ya incluye este abono. Volver a sumar :valor lo restaría
                  dos veces. */
            $con->prepare(
                "UPDATE dsa_reserva
                    SET reserva_abonado = reserva_abonado + :v,
                        reserva_saldo   = reserva_total - reserva_abonado
                  WHERE reserva_id = :r"
            )->execute([':v' => $valor, ':r' => $reservaid]);

            /* 4. El vuelto que el cliente decide dejar a su favor */
            if ($vuelto > 0 && $aMonedero) {
                $mon = $this->asegurarMonedero((int)$reserva['reserva_clienteid']);
                $this->moverMonedero($con, $mon, 'I', 'VUE', $vuelto,
                    ['reserva' => $reservaid, 'pago' => $pagoId,
                     'detalle' => 'Vuelto de la reserva ' . $reserva['reserva_codigo']]);
            }

            $con->commit();

        } catch (\Throwable $e) {
            if ($con->inTransaction()) $con->rollBack();
            return $this->alerta('simple', 'No se pudo registrar el abono', $e->getMessage(), 'error');
        }

        $nuevoSaldo = round($saldo - $valor, 2);

        $texto = '$' . number_format($valor, 2) . ' registrados. ';
        $texto .= $nuevoSaldo <= 0.001
            ? 'La reserva queda totalmente pagada.'
            : 'Saldo pendiente: $' . number_format($nuevoSaldo, 2) . '.';

        if ($vuelto > 0) {
            $texto .= $aMonedero
                ? ' Vuelto de $' . number_format($vuelto, 2) . ' acreditado al monedero.'
                : ' Vuelto entregado: $' . number_format($vuelto, 2) . '.';
        }

        return $this->alerta('recargar', 'Abono registrado', $texto);
    }

    /*======================================================================
      Panel
      ====================================================================*/

    public function resumen(): array
    {
        return [
            ['valor' => $this->escalar("SELECT COUNT(1) FROM dsa_instalacion WHERE instalacion_estado='A' AND instalacion_clase='C'"),
             'etiqueta' => 'Canchas',      'icono' => 'fas fa-basketball-ball', 'color' => 'primary'],
            ['valor' => $this->escalar("SELECT COUNT(1) FROM dsa_instalacion WHERE instalacion_estado='A' AND instalacion_clase='R'"),
             'etiqueta' => 'Residencias',  'icono' => 'fas fa-bed',             'color' => 'info'],
            ['valor' => $this->escalar("SELECT COUNT(1) FROM dsa_reserva WHERE reserva_estado IN ('P','C') AND reserva_fecha >= CURDATE()"),
             'etiqueta' => 'Reservas vigentes', 'icono' => 'fas fa-calendar-check', 'color' => 'warning'],
            ['valor' => $this->escalar("SELECT COUNT(1) FROM dsa_cliente WHERE cliente_estado='A'"),
             'etiqueta' => 'Clientes',     'icono' => 'fas fa-user-friends',    'color' => 'success'],
        ];
    }

    /** Reservas con saldo pendiente: el control de abonos del panel. */
    public function saldosPendientes(int $limite = 8): array
    {
        return $this->filas(
            "SELECT r.reserva_codigo, r.reserva_fecha, r.reserva_total,
                    r.reserva_abonado, r.reserva_saldo,
                    c.cliente_nombre, i.instalacion_nombre
               FROM dsa_reserva   r
               JOIN dsa_cliente   c ON c.cliente_id     = r.reserva_clienteid
               JOIN dsa_instalacion i ON i.instalacion_id = r.reserva_instalacionid
              WHERE r.reserva_estado IN ('P','C') AND r.reserva_saldo > 0
              ORDER BY r.reserva_fecha
              LIMIT " . (int)$limite
        );
    }

    /*======================================================================
      Menú lateral del módulo
      ====================================================================*/

    public function menuLateral(string $vistaActual): string
    {
        $sql = "SELECT m.menu_vista, m.menu_nombre, m.menu_icono
                  FROM seguridad_menu m
                 WHERE m.menu_estado = 'A' AND m.menu_modulo = 'arena'";

        if (!es_superadministrador()) {
            $sql .= " AND EXISTS (SELECT 1 FROM seguridad_permiso p
                                   WHERE p.permiso_menuid = m.menu_id
                                     AND p.permiso_rolid  = :rol
                                     AND p.permiso_estado = 'A'
                                     AND p.permiso_ver    = 'S')";
        }
        $sql .= " ORDER BY m.menu_orden";

        $filas = $this->filas($sql, es_superadministrador() ? [] : [':rol' => rol_actual()]);

        $html = '';
        foreach ($filas as $f) {
            $activo = ($f['menu_vista'] === $vistaActual) ? ' active' : '';
            $html .= '<li class="nav-item">'
                   . '<a href="' . APP_URL . $f['menu_vista'] . '/" class="nav-link' . $activo . '">'
                   /* nav-icon es de AdminLTE 4: sin ella el texto se pega
                      al borde y los iconos no quedan alineados entre si. */
                   . '<i class="nav-icon ' . htmlspecialchars($f['menu_icono'], ENT_QUOTES, 'UTF-8') . '"></i>'
                   . '<p>' . htmlspecialchars($f['menu_nombre'], ENT_QUOTES, 'UTF-8') . '</p>'
                   . '</a></li>';
        }

        return $html;
    }
}
