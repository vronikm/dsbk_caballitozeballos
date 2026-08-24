<?php

namespace admin\controllers;

use PDO;

/**
 * Administracion del ecosistema DigiSports.
 *
 * Concentra la lectura y escritura de usuarios, roles, permisos, menus y
 * acceso a modulos. Se apoya en la conexion del nucleo (seguridad_conexion)
 * para no abrir una segunda por peticion.
 *
 * Todas las escrituras exigen la accion correspondiente sobre la vista que
 * las origina; el guard de los endpoints AJAX lo verifica antes de llegar
 * aqui, y los metodos vuelven a comprobarlo por si se invocan desde otro
 * punto.
 */
class coreController
{
    /*----------  Utilidades  ----------*/

    private function con(): ?PDO
    {
        return seguridad_conexion();
    }

    /** Consulta preparada que devuelve todas las filas; [] si falla. */
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

    /** La primera fila, o [] si no hay ninguna. */
    private function fila(string $sql, array $params = []): array
    {
        return $this->filas($sql, $params)[0] ?? [];
    }

    private function escalar(string $sql, array $params = [])
    {
        try {
            $con = $this->con();
            if ($con === null) return 0;

            $stmt = $con->prepare($sql);
            $stmt->execute($params);
            $fila = $stmt->fetch(PDO::FETCH_NUM);
            return $fila === false ? 0 : $fila[0];
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Ejecuta una escritura. Devuelve filas afectadas o -1 si hubo error. */
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

    /** Alerta con el formato que espera alertas_ajax() en ajax.js */
    public function alerta(string $tipo, string $titulo, string $texto, string $icono = 'success', string $url = ''): string
    {
        $a = ['tipo' => $tipo, 'titulo' => $titulo, 'texto' => $texto, 'icono' => $icono];
        if ($url !== '') $a['url'] = $url;
        return json_encode($a, JSON_UNESCAPED_UNICODE);
    }

    /*======================================================================
      Panel
      ====================================================================*/

    public function resumen(): array
    {
        return [
            ['valor' => $this->escalar("SELECT COUNT(1) FROM seguridad_usuario WHERE usuario_estado='A'"),
             'etiqueta' => 'Usuarios activos', 'icono' => 'fas fa-users',       'color' => 'primary'],
            ['valor' => $this->escalar("SELECT COUNT(1) FROM seguridad_rol WHERE rol_estado='A'"),
             'etiqueta' => 'Roles',            'icono' => 'fas fa-user-shield', 'color' => 'info'],
            ['valor' => $this->escalar("SELECT COUNT(1) FROM seguridad_menu WHERE menu_estado='A'"),
             'etiqueta' => 'Vistas registradas','icono' => 'fas fa-bars',       'color' => 'warning'],
            ['valor' => $this->escalar("SELECT COUNT(1) FROM seguridad_permiso WHERE permiso_estado='A'"),
             'etiqueta' => 'Permisos otorgados','icono' => 'fas fa-key',        'color' => 'success'],
        ];
    }

    /*======================================================================
      Roles
      ====================================================================*/

    public function roles(bool $soloActivos = true): array
    {
        $sql = "SELECT r.*,
                       (SELECT COUNT(1) FROM seguridad_usuario u
                         WHERE u.usuario_rolid = r.rol_id AND u.usuario_estado='A') AS usuarios,
                       (SELECT COUNT(1) FROM seguridad_permiso p
                         WHERE p.permiso_rolid = r.rol_id AND p.permiso_estado='A') AS permisos,
                       (SELECT GROUP_CONCAT(rm.rolmod_modulo ORDER BY rm.rolmod_modulo SEPARATOR ',')
                          FROM seguridad_rol_modulo rm
                         WHERE rm.rolmod_rolid = r.rol_id AND rm.rolmod_estado='A') AS modulos
                  FROM seguridad_rol r"
             . ($soloActivos ? " WHERE r.rol_estado <> 'E'" : "")
             . " ORDER BY r.rol_id";

        return $this->filas($sql);
    }

    public function rol(int $id): ?array
    {
        $r = $this->filas("SELECT * FROM seguridad_rol WHERE rol_id = :id", [':id' => $id]);
        return $r[0] ?? null;
    }

    public function guardarRol(): string
    {
        if (!puede_crear('rolList') && !puede_editar('rolList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede administrar roles.', 'error');
        }

        $id      = (int)($_POST['rol_id'] ?? 0);
        $nombre  = trim((string)($_POST['rol_nombre'] ?? ''));
        $detalle = trim((string)($_POST['rol_detalle'] ?? ''));
        $estado  = ($_POST['rol_estado'] ?? 'A') === 'A' ? 'A' : 'I';

        if ($nombre === '') {
            return $this->alerta('simple', 'Faltan datos', 'El nombre del rol es obligatorio.', 'error');
        }
        if (mb_strlen($nombre) > 20) {
            return $this->alerta('simple', 'Nombre muy largo', 'El nombre no puede superar 20 caracteres.', 'error');
        }

        if ($id > 0) {
            if (!puede_editar('rolList')) {
                return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede editar roles.', 'error');
            }
            if ($id === self_rol_superadmin()) {
                return $this->alerta('simple', 'Rol protegido',
                    'El Super Administrador no se puede modificar: es el rol que garantiza el acceso al sistema.', 'error');
            }

            $n = $this->escribir(
                "UPDATE seguridad_rol
                    SET rol_nombre = :n, rol_detalle = :d, rol_estado = :e,
                        rol_fechaactualizacion = NOW()
                  WHERE rol_id = :id",
                [':n' => $nombre, ':d' => $detalle, ':e' => $estado, ':id' => $id]
            );

            return $n >= 0
                ? $this->alerta('recargar', 'Rol actualizado', "El rol $nombre se actualizó correctamente.")
                : $this->alerta('simple', 'Error', 'No fue posible actualizar el rol.', 'error');
        }

        if (!puede_crear('rolList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede crear roles.', 'error');
        }

        $n = $this->escribir(
            "INSERT INTO seguridad_rol (rol_nombre, rol_detalle, rol_estado)
             VALUES (:n, :d, :e)",
            [':n' => $nombre, ':d' => $detalle, ':e' => $estado]
        );

        return $n > 0
            ? $this->alerta('recargar', 'Rol creado', "El rol $nombre se registró correctamente.")
            : $this->alerta('simple', 'Error', 'No fue posible crear el rol.', 'error');
    }

    public function eliminarRol(): string
    {
        if (!puede_eliminar('rolList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede eliminar roles.', 'error');
        }

        $id = (int)($_POST['rol_id'] ?? 0);

        if ($id === self_rol_superadmin()) {
            return $this->alerta('simple', 'Rol protegido',
                'El Super Administrador no se puede eliminar.', 'error');
        }

        $enUso = (int)$this->escalar(
            "SELECT COUNT(1) FROM seguridad_usuario WHERE usuario_rolid = :id AND usuario_estado <> 'E'",
            [':id' => $id]
        );
        if ($enUso > 0) {
            return $this->alerta('simple', 'Rol en uso',
                "No se puede eliminar: hay $enUso usuario(s) con este rol. Reasígnelos primero.", 'error');
        }

        $this->escribir("DELETE FROM seguridad_permiso   WHERE permiso_rolid = :id", [':id' => $id]);
        $this->escribir("DELETE FROM seguridad_rol_modulo WHERE rolmod_rolid = :id", [':id' => $id]);
        $n = $this->escribir("DELETE FROM seguridad_rol   WHERE rol_id       = :id", [':id' => $id]);

        return $n > 0
            ? $this->alerta('recargar', 'Rol eliminado', 'El rol y sus permisos fueron eliminados.')
            : $this->alerta('simple', 'Error', 'No fue posible eliminar el rol.', 'error');
    }

    /*======================================================================
      Modulos por rol
      ====================================================================*/

    public function modulosDelRol(int $rolid): array
    {
        $filas = $this->filas(
            "SELECT rolmod_modulo FROM seguridad_rol_modulo
              WHERE rolmod_rolid = :id AND rolmod_estado = 'A'",
            [':id' => $rolid]
        );

        return array_column($filas, 'rolmod_modulo');
    }

    public function guardarModulosRol(): string
    {
        if (!puede_editar('moduloRol')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede asignar módulos.', 'error');
        }

        $rolid    = (int)($_POST['rol_id'] ?? 0);
        $enviados = $_POST['modulos'] ?? [];
        $validos  = array_keys(ds_modulos_conocidos());

        if ($rolid <= 0) {
            return $this->alerta('simple', 'Error', 'Rol no indicado.', 'error');
        }

        $seleccion = array_values(array_intersect((array)$enviados, $validos));

        /* Se marcan como inactivos los que ya no vienen y se activan los
           enviados: asi no se pierde el historico de la fila. */
        $this->escribir(
            "UPDATE seguridad_rol_modulo SET rolmod_estado = 'I' WHERE rolmod_rolid = :id",
            [':id' => $rolid]
        );

        foreach ($seleccion as $modulo) {
            $this->escribir(
                "INSERT INTO seguridad_rol_modulo (rolmod_rolid, rolmod_modulo, rolmod_estado)
                 VALUES (:r, :m, 'A')
                 ON DUPLICATE KEY UPDATE rolmod_estado = 'A'",
                [':r' => $rolid, ':m' => $modulo]
            );
        }

        return $this->alerta('recargar', 'Módulos actualizados',
            count($seleccion) . ' módulo(s) asignados al rol.');
    }

    /*======================================================================
      Permisos: matriz rol x vista x accion
      ====================================================================*/

    /** Menus de un modulo con el permiso que el rol tiene sobre cada uno. */
    public function matrizPermisos(int $rolid, string $modulo): array
    {
        return $this->filas(
            "SELECT m.menu_id,
                    m.menu_nombre,
                    m.menu_vista,
                    m.menu_icono,
                    COALESCE(pa.menu_nombre, '')                 AS padre,
                    COALESCE(p.permiso_ver, 'N')                 AS ver,
                    COALESCE(p.permiso_crear, 'N')               AS crear,
                    COALESCE(p.permiso_editar, 'N')              AS editar,
                    COALESCE(p.permiso_eliminar, 'N')            AS eliminar
               FROM seguridad_menu m
               LEFT JOIN seguridad_menu pa ON pa.menu_id = m.menu_padreid
               LEFT JOIN seguridad_permiso p
                      ON p.permiso_menuid = m.menu_id
                     AND p.permiso_rolid  = :rol
                     AND p.permiso_estado = 'A'
              WHERE m.menu_estado = 'A'
                AND m.menu_modulo = :modulo
                AND m.menu_hijo  <> 'S'
                AND m.menu_vista NOT IN ('', 'No')
              ORDER BY m.menu_padreid, m.menu_orden",
            [':rol' => $rolid, ':modulo' => $modulo]
        );
    }

    public function guardarPermisos(): string
    {
        if (!puede_editar('permisoRol')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede administrar permisos.', 'error');
        }

        $rolid  = (int)($_POST['rol_id'] ?? 0);
        $modulo = (string)($_POST['modulo'] ?? '');
        $perm   = $_POST['perm'] ?? [];     // [menu_id => ['ver'=>'1', ...]]

        if ($rolid <= 0 || $modulo === '') {
            return $this->alerta('simple', 'Error', 'Rol o módulo no indicados.', 'error');
        }

        if ($rolid === self_rol_superadmin()) {
            return $this->alerta('simple', 'Rol protegido',
                'El Super Administrador tiene acceso total por definición: no se le asignan permisos.', 'error');
        }

        /* Menus del modulo: solo se tocan permisos de este modulo, para no
           borrar sin querer los que el rol tenga en otros. */
        $menus = array_column($this->matrizPermisos($rolid, $modulo), 'menu_id');
        if (!$menus) {
            return $this->alerta('simple', 'Sin vistas', 'Ese módulo no tiene vistas registradas.', 'error');
        }

        $otorgados = 0;

        foreach ($menus as $menuid) {
            $a = $perm[$menuid] ?? [];

            $ver      = !empty($a['ver'])      ? 'S' : 'N';
            $crear    = !empty($a['crear'])    ? 'S' : 'N';
            $editar   = !empty($a['editar'])   ? 'S' : 'N';
            $eliminar = !empty($a['eliminar']) ? 'S' : 'N';

            /* Sin lectura no hay nada que hacer en la pantalla: se retira
               la fila completa en lugar de dejar acciones huerfanas. */
            if ($ver === 'N') {
                $this->escribir(
                    "DELETE FROM seguridad_permiso
                      WHERE permiso_rolid = :r AND permiso_menuid = :m",
                    [':r' => $rolid, ':m' => $menuid]
                );
                continue;
            }

            $existe = (int)$this->escalar(
                "SELECT COUNT(1) FROM seguridad_permiso
                  WHERE permiso_rolid = :r AND permiso_menuid = :m",
                [':r' => $rolid, ':m' => $menuid]
            );

            if ($existe > 0) {
                $this->escribir(
                    "UPDATE seguridad_permiso
                        SET permiso_ver = :v, permiso_crear = :c,
                            permiso_editar = :e, permiso_eliminar = :d,
                            permiso_estado = 'A'
                      WHERE permiso_rolid = :r AND permiso_menuid = :m",
                    [':v' => $ver, ':c' => $crear, ':e' => $editar, ':d' => $eliminar,
                     ':r' => $rolid, ':m' => $menuid]
                );
            } else {
                $this->escribir(
                    "INSERT INTO seguridad_permiso
                        (permiso_rolid, permiso_menuid, permiso_ver, permiso_crear,
                         permiso_editar, permiso_eliminar, permiso_estado)
                     VALUES (:r, :m, :v, :c, :e, :d, 'A')",
                    [':r' => $rolid, ':m' => $menuid, ':v' => $ver, ':c' => $crear,
                     ':e' => $editar, ':d' => $eliminar]
                );
            }

            $otorgados++;
        }

        return $this->alerta('recargar', 'Permisos guardados',
            "$otorgados vista(s) habilitadas para este rol en el módulo.");
    }

    /*======================================================================
      Usuarios
      ====================================================================*/

    public function usuarios(): array
    {
        return $this->filas(
            "SELECT u.usuario_id, u.usuario_usuario, u.usuario_rolid,
                    u.usuario_estado, u.usuario_tienebloqueo, u.usuario_fechacreacion,
                    u.usuario_2fa_estado,
                    r.rol_nombre,
                    COALESCE(e.empleado_nombre, '') AS empleado,
                    s.sede_nombre
               FROM seguridad_usuario u
               LEFT JOIN seguridad_rol   r ON r.rol_id      = u.usuario_rolid
               LEFT JOIN sujeto_empleado e ON e.empleado_id = u.usuario_empleadoid
               LEFT JOIN general_sede    s ON s.sede_id     = e.empleado_sedeid
              WHERE u.usuario_estado <> 'E'
              ORDER BY u.usuario_usuario"
        );
    }

    public function usuario(int $id): ?array
    {
        $r = $this->filas("SELECT * FROM seguridad_usuario WHERE usuario_id = :id", [':id' => $id]);
        return $r[0] ?? null;
    }

    public function empleadosSinUsuario(int $usuarioActual = 0): array
    {
        return $this->filas(
            "SELECT e.empleado_id,
                    COALESCE(e.empleado_nombre, CONCAT('Empleado #', e.empleado_id)) AS nombre
               FROM sujeto_empleado e
              WHERE e.empleado_estado = 'A'
                AND (NOT EXISTS (SELECT 1 FROM seguridad_usuario u
                                  WHERE u.usuario_empleadoid = e.empleado_id
                                    AND u.usuario_estado <> 'E')
                     OR EXISTS (SELECT 1 FROM seguridad_usuario u2
                                 WHERE u2.usuario_empleadoid = e.empleado_id
                                   AND u2.usuario_id = :actual))
              ORDER BY nombre",
            [':actual' => $usuarioActual]
        );
    }

    public function guardarUsuario(): string
    {
        $id = (int)($_POST['usuario_id'] ?? 0);

        if ($id > 0 && !puede_editar('usuarioList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede editar usuarios.', 'error');
        }
        if ($id === 0 && !puede_crear('usuarioList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede crear usuarios.', 'error');
        }

        $usuario  = trim((string)($_POST['usuario_usuario'] ?? ''));
        $rolid    = (int)($_POST['usuario_rolid'] ?? 0);
        $empleado = (int)($_POST['usuario_empleadoid'] ?? 0);
        $estado   = ($_POST['usuario_estado'] ?? 'A') === 'A' ? 'A' : 'I';
        $bloqueo  = ($_POST['usuario_tienebloqueo'] ?? 'N') === 'S' ? 'S' : 'N';
        $clave    = (string)($_POST['usuario_clave'] ?? '');

        if (!preg_match('/^[a-zA-Z0-9]{4,20}$/', $usuario)) {
            return $this->alerta('simple', 'Usuario no válido',
                'Debe tener entre 4 y 20 caracteres, solo letras y números.', 'error');
        }
        if ($rolid <= 0) {
            return $this->alerta('simple', 'Faltan datos', 'Seleccione un rol.', 'error');
        }

        /* Solo el Super Administrador puede otorgar el rol de Super
           Administrador: evita una escalada de privilegios desde Core. */
        if ($rolid === self_rol_superadmin() && !es_superadministrador()) {
            return $this->alerta('simple', 'Acción no permitida',
                'Solo un Super Administrador puede asignar ese rol.', 'error');
        }

        $duplicado = (int)$this->escalar(
            "SELECT COUNT(1) FROM seguridad_usuario
              WHERE usuario_usuario = :u AND usuario_id <> :id",
            [':u' => $usuario, ':id' => $id]
        );
        if ($duplicado > 0) {
            return $this->alerta('simple', 'Usuario repetido',
                "Ya existe un usuario llamado $usuario.", 'error');
        }

        /* Editar a un Super Administrador equivale a poder entrar como él:
           bastaría con ponerle otra contraseña. Así que sólo otro Super
           Administrador puede hacerlo, por mucho permiso de edición que
           tenga el rol sobre usuarioList. */
        if ($id > 0) {
            $actual = $this->usuario($id);
            if ($actual === null) {
                return $this->alerta('simple', 'Error', 'El usuario no existe.', 'error');
            }
            if ((int)$actual['usuario_rolid'] === self_rol_superadmin() && !es_superadministrador()) {
                return $this->alerta('simple', 'Usuario protegido',
                    'Sólo un Super Administrador puede modificar la cuenta de otro Super Administrador.', 'error');
            }
        }

        if ($id > 0) {
            $campos = "usuario_usuario = :u, usuario_rolid = :r,
                       usuario_empleadoid = :e, usuario_estado = :s,
                       usuario_tienebloqueo = :b, usuario_fechaactualizado = NOW()";
            $params = [':u' => $usuario, ':r' => $rolid,
                       ':e' => $empleado > 0 ? $empleado : null,
                       ':s' => $estado, ':b' => $bloqueo, ':id' => $id];

            /* La contrasena solo se toca si se escribio una nueva. */
            if ($clave !== '') {
                if (!clave_valida($clave, $motivo, $usuario)) {
                    return $this->alerta('simple', 'Contraseña no válida', $motivo, 'error');
                }
                $campos .= ", usuario_clave = :c, usuario_fechacambioclave = NOW()";
                $params[':c'] = password_hash($clave, PASSWORD_DEFAULT);
            }

            $n = $this->escribir("UPDATE seguridad_usuario SET $campos WHERE usuario_id = :id", $params);

            return $n >= 0
                ? $this->alerta('redireccionar', 'Usuario actualizado',
                    "El usuario $usuario se actualizó correctamente.", 'success', APP_URL . 'usuarioList/')
                : $this->alerta('simple', 'Error', 'No fue posible actualizar el usuario.', 'error');
        }

        if (!clave_valida($clave, $motivo, $usuario)) {
            return $this->alerta('simple', 'Contraseña no válida', $motivo, 'error');
        }

        $n = $this->escribir(
            "INSERT INTO seguridad_usuario
                (usuario_usuario, usuario_rolid, usuario_empleadoid, usuario_clave,
                 usuario_estado, usuario_tienebloqueo, usuario_cambiaclave)
             VALUES (:u, :r, :e, :c, :s, :b, 'S')",
            [':u' => $usuario, ':r' => $rolid,
             ':e' => $empleado > 0 ? $empleado : null,
             ':c' => password_hash($clave, PASSWORD_DEFAULT),
             ':s' => $estado, ':b' => $bloqueo]
        );

        return $n > 0
            ? $this->alerta('redireccionar', 'Usuario creado',
                "El usuario $usuario fue creado correctamente.", 'success', APP_URL . 'usuarioList/')
            : $this->alerta('simple', 'Error', 'No fue posible crear el usuario.', 'error');
    }

    public function eliminarUsuario(): string
    {
        if (!puede_eliminar('usuarioList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede eliminar usuarios.', 'error');
        }

        $id = (int)($_POST['usuario_id'] ?? 0);

        if ($id === (int)($_SESSION['usuarioid'] ?? 0)) {
            return $this->alerta('simple', 'Acción no permitida',
                'No puede eliminar su propio usuario.', 'error');
        }

        $datos = $this->usuario($id);
        if ($datos === null) {
            return $this->alerta('simple', 'Error', 'Usuario no encontrado.', 'error');
        }

        /* Un Super Administrador no se da de baja desde aquí.
           Antes sólo se protegía al último, pero la cuenta con acceso total
           es la que sostiene el sistema: si hace falta retirarla, primero se
           le cambia el rol y después se da de baja, de modo que la decisión
           quede en dos pasos deliberados y no en un clic. */
        if ((int)$datos['usuario_rolid'] === self_rol_superadmin()) {
            return $this->alerta('simple', 'Usuario protegido',
                'Un Super Administrador no se puede dar de baja. Cámbiele antes el rol si necesita retirarlo.',
                'error');
        }

        /* Baja logica: conserva la trazabilidad de lo que hizo el usuario. */
        $n = $this->escribir(
            "UPDATE seguridad_usuario SET usuario_estado = 'E', usuario_fechaactualizado = NOW()
              WHERE usuario_id = :id",
            [':id' => $id]
        );

        return $n > 0
            ? $this->alerta('recargar', 'Usuario eliminado', 'El usuario fue dado de baja.')
            : $this->alerta('simple', 'Error', 'No fue posible eliminar el usuario.', 'error');
    }

    /*======================================================================
      Menus
      ====================================================================*/

    public function menus(string $modulo = ''): array
    {
        $sql = "SELECT m.*, COALESCE(pa.menu_nombre,'') AS padre,
                       (SELECT COUNT(1) FROM seguridad_permiso p
                         WHERE p.permiso_menuid = m.menu_id AND p.permiso_estado='A') AS roles
                  FROM seguridad_menu m
                  LEFT JOIN seguridad_menu pa ON pa.menu_id = m.menu_padreid
                 WHERE m.menu_estado <> 'E'"
             . ($modulo !== '' ? " AND m.menu_modulo = :modulo" : "")
             . " ORDER BY m.menu_modulo, m.menu_padreid, m.menu_orden";

        return $this->filas($sql, $modulo !== '' ? [':modulo' => $modulo] : []);
    }

    public function menu(int $id): ?array
    {
        $r = $this->filas("SELECT * FROM seguridad_menu WHERE menu_id = :id", [':id' => $id]);
        return $r[0] ?? null;
    }

    /** Menús que pueden actuar como grupo (padre) dentro de un módulo. */
    /** ¿De este menú cuelga alguna entrada? */
    public function menuTieneHijos(int $id): bool
    {
        return (int)$this->escalar(
            "SELECT COUNT(1) FROM seguridad_menu
              WHERE menu_padreid = :id AND menu_estado <> 'E'", [':id' => $id]) > 0;
    }

    /**
     * Grupos disponibles como padre. Sólo cabeceras: un menú que abre una
     * pantalla no puede tener hijos, porque el menú lateral dibuja un
     * único nivel de anidamiento.
     */
    public function menusPadre(string $modulo): array
    {
        return $this->filas(
            "SELECT menu_id, menu_nombre
               FROM seguridad_menu
              WHERE menu_modulo = :m AND menu_estado = 'A'
                AND menu_padreid = 0 AND menu_hijo = 'S'
              ORDER BY menu_orden",
            [':m' => $modulo]
        );
    }

    /**
     * Vistas del módulo que todavía no tienen menú.
     *
     * Es lo que se ofrece al crear: así el menú nunca puede apuntar a una
     * ruta inexistente ni duplicar una ya registrada. Al editar se incluye
     * la vista actual para que siga siendo seleccionable.
     */
    public function vistasDisponibles(string $modulo, string $incluir = ''): array
    {
        $todas = ds_vistas_modulo($modulo);
        if (!$todas) {
            return [];
        }

        $usadas = array_column(
            $this->filas(
                "SELECT menu_vista FROM seguridad_menu
                  WHERE menu_modulo = :m AND menu_estado <> 'E'",
                [':m' => $modulo]
            ),
            'menu_vista'
        );

        $libres = array_values(array_diff($todas, $usadas));

        if ($incluir !== '' && in_array($incluir, $todas, true) && !in_array($incluir, $libres, true)) {
            $libres[] = $incluir;
            sort($libres);
        }

        return $libres;
    }

    public function guardarMenu(): string
    {
        $id = (int)($_POST['menu_id'] ?? 0);

        if ($id > 0 && !puede_editar('menuList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede editar menús.', 'error');
        }
        if ($id === 0 && !puede_crear('menuList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede crear menús.', 'error');
        }

        $modulo  = trim((string)($_POST['menu_modulo'] ?? ''));
        $nombre  = trim((string)($_POST['menu_nombre'] ?? ''));
        $vista   = trim((string)($_POST['menu_vista'] ?? ''));
        $icono   = trim((string)($_POST['menu_icono'] ?? ''));
        $orden   = (int)($_POST['menu_orden'] ?? 0);
        $padre   = (int)($_POST['menu_padreid'] ?? 0);
        $estado  = ($_POST['menu_estado'] ?? 'A') === 'A' ? 'A' : 'I';

        /* Un grupo es sólo una cabecera que agrupa otros menús: no abre
           ninguna pantalla, así que no lleva vista ni permiso propio. */
        $esGrupo = ($_POST['menu_grupo'] ?? '') === '1';

        if ($nombre === '' || $modulo === '') {
            return $this->alerta('simple', 'Faltan datos',
                'Módulo y nombre son obligatorios.', 'error');
        }

        if (!isset(ds_modulos_conocidos()[$modulo])) {
            return $this->alerta('simple', 'Módulo no válido',
                'El módulo indicado no pertenece al ecosistema.', 'error');
        }

        if ($esGrupo) {
            /* Un grupo cuelga siempre del primer nivel: el menú lateral
               sólo dibuja un nivel de anidamiento. */
            $vista = '';
            $padre = 0;
        } else {
            if ($vista === '') {
                return $this->alerta('simple', 'Faltan datos',
                    'Indique la vista que abre este menú, o márquelo como grupo.', 'error');
            }

            /* La validación que evita menús rotos: la vista debe existir en la
               lista blanca del módulo destino. */
            if (!ds_vista_existe($modulo, $vista)) {
                $disponibles = ds_vistas_modulo($modulo);
                /* Las llaves son necesarias: sin ellas PHP toma el » de cierre
                   como parte del nombre de la variable. */
                $texto = $disponibles
                    ? "La vista «{$vista}» no existe en el módulo " . ds_modulos_conocidos()[$modulo] .
                      ". Debe estar declarada en su archivo config/vistas.php."
                    : "El módulo " . ds_modulos_conocidos()[$modulo] . " todavía no publica su lista de vistas.";

                return $this->alerta('simple', 'Vista inexistente', $texto, 'error');
            }

            /* Una vista no puede tener dos menús: haría ambiguo el permiso. */
            $duplicada = (int)$this->escalar(
                "SELECT COUNT(1) FROM seguridad_menu
                  WHERE menu_modulo = :m AND menu_vista = :v
                    AND menu_estado <> 'E' AND menu_id <> :id",
                [':m' => $modulo, ':v' => $vista, ':id' => $id]
            );
            if ($duplicada > 0) {
                return $this->alerta('simple', 'Vista ya registrada',
                    "Ya existe un menú que apunta a «{$vista}» en ese módulo.", 'error');
            }
        }

        /* El padre indicado tiene que ser un grupo del mismo módulo. */
        if (!$esGrupo && $padre > 0) {
            $destino = $this->menu($padre);
            if ($destino === null || $destino['menu_modulo'] !== $modulo) {
                return $this->alerta('simple', 'Grupo no válido',
                    'El grupo elegido no existe en ese módulo.', 'error');
            }
            if ((int)$destino['menu_padreid'] !== 0) {
                return $this->alerta('simple', 'Jerarquía no válida',
                    'Sólo se admite un nivel de agrupación.', 'error');
            }
        }

        if ($icono === '') {
            $icono = 'nav-icon far fa-circle';
        }

        $hijo = $esGrupo ? 'S' : 'N';

        if ($id > 0) {
            /* Un menú no puede ser su propio padre. */
            if ($padre === $id) {
                return $this->alerta('simple', 'Jerarquía no válida',
                    'Un menú no puede depender de sí mismo.', 'error');
            }

            /* Dejar de ser grupo con hijos colgando los dejaría huérfanos:
               desaparecerían del menú sin que nadie lo note. */
            $conHijos = (int)$this->escalar(
                "SELECT COUNT(1) FROM seguridad_menu
                  WHERE menu_padreid = :id AND menu_estado <> 'E'", [':id' => $id]);

            if (!$esGrupo && $conHijos > 0) {
                return $this->alerta('simple', 'El grupo tiene contenido',
                    "«{$nombre}» agrupa {$conHijos} menú(s). Muévalos a otro grupo antes de convertirlo en una entrada normal.",
                    'error');
            }

            $n = $this->escribir(
                "UPDATE seguridad_menu
                    SET menu_modulo = :mo, menu_nombre = :n, menu_vista = :v,
                        menu_icono = :i, menu_orden = :o, menu_padreid = :p,
                        menu_hijo = :h, menu_estado = :e
                  WHERE menu_id = :id",
                [':mo' => $modulo, ':n' => $nombre, ':v' => $vista, ':i' => $icono,
                 ':o' => $orden, ':p' => $padre, ':h' => $hijo, ':e' => $estado, ':id' => $id]
            );

            return $n >= 0
                ? $this->alerta('redireccionar', 'Menú actualizado',
                    "El menú $nombre se actualizó correctamente.", 'success', APP_URL . 'menuList/')
                : $this->alerta('simple', 'Error', 'No fue posible actualizar el menú.', 'error');
        }

        $n = $this->escribir(
            "INSERT INTO seguridad_menu
                (menu_modulo, menu_nombre, menu_vista, menu_icono, menu_orden,
                 menu_padreid, menu_hijo, menu_estado)
             VALUES (:mo, :n, :v, :i, :o, :p, :h, :e)",
            [':mo' => $modulo, ':n' => $nombre, ':v' => $vista, ':i' => $icono,
             ':o' => $orden, ':p' => $padre, ':h' => $hijo, ':e' => $estado]
        );

        $aviso = $esGrupo
            ? "El grupo $nombre quedó creado. Ahora puede colgar menús de él."
            : "El menú $nombre quedó registrado. Asígnelo a los roles desde Permisos.";

        return $n > 0
            ? $this->alerta('redireccionar', 'Menú creado', $aviso, 'success', APP_URL . 'menuList/')
            : $this->alerta('simple', 'Error', 'No fue posible crear el menú.', 'error');
    }

    public function eliminarMenu(): string
    {
        if (!puede_eliminar('menuList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede eliminar menús.', 'error');
        }

        $id = (int)($_POST['menu_id'] ?? 0);
        $datos = $this->menu($id);

        if ($datos === null) {
            return $this->alerta('simple', 'Error', 'Menú no encontrado.', 'error');
        }

        /* Los menús del propio Core sostienen la administración: si se
           borran, nadie puede volver a conceder permisos. */
        if ($datos['menu_modulo'] === 'core') {
            return $this->alerta('simple', 'Menú protegido',
                'Los menús del módulo Core no se pueden eliminar: son los que permiten administrar el ecosistema.', 'error');
        }

        $hijos = (int)$this->escalar(
            "SELECT COUNT(1) FROM seguridad_menu WHERE menu_padreid = :id AND menu_estado <> 'E'",
            [':id' => $id]
        );
        if ($hijos > 0) {
            return $this->alerta('simple', 'Menú con dependencias',
                "No se puede eliminar: $hijos menú(s) dependen de este como grupo.", 'error');
        }

        $this->escribir("DELETE FROM seguridad_permiso WHERE permiso_menuid = :id", [':id' => $id]);
        $n = $this->escribir("DELETE FROM seguridad_menu WHERE menu_id = :id", [':id' => $id]);

        return $n > 0
            ? $this->alerta('recargar', 'Menú eliminado',
                'El menú y los permisos asociados fueron eliminados.')
            : $this->alerta('simple', 'Error', 'No fue posible eliminar el menú.', 'error');
    }

    /*======================================================================
      Sedes — dato compartido por todo el ecosistema
      ====================================================================*/

    /**
     * Las sedes las administra Core y las consumen los módulos: Basketball
     * asigna alumnos y horarios, Arena registra instalaciones. Por eso la
     * baja comprueba dependencias en todos ellos, no sólo en uno.
     */
    public function sedes(): array
    {
        return $this->filas(
            "SELECT s.*, e.escuela_nombre,
                    c.catalogo_descripcion AS tipo_nombre,
                    (SELECT COUNT(1) FROM sujeto_alumno a
                      WHERE a.alumno_sedeid = s.sede_id AND a.alumno_estado = 'A') AS alumnos,
                    (SELECT COUNT(1) FROM sujeto_empleado em
                      WHERE em.empleado_sedeid = s.sede_id AND em.empleado_estado = 'A') AS empleados,
                    (SELECT COUNT(1) FROM dsa_instalacion i
                      WHERE i.instalacion_sedeid = s.sede_id AND i.instalacion_estado = 'A') AS instalaciones
               FROM general_sede s
               LEFT JOIN general_escuela e ON e.escuela_id = s.sede_escuelaid
               LEFT JOIN general_tabla_catalogo c
                      ON c.catalogo_valor = s.sede_tipoingreso
                     AND c.catalogo_tablaid = (SELECT tabla_id FROM general_tabla
                                                WHERE tabla_nombre = 'sede_tipoingreso' LIMIT 1)
              ORDER BY s.sede_nombre"
        );
    }

    public function sede(int $id): ?array
    {
        $r = $this->filas("SELECT * FROM general_sede WHERE sede_id = :id", [':id' => $id]);
        return $r[0] ?? null;
    }

    public function escuelas(): array
    {
        return $this->filas("SELECT escuela_id, escuela_nombre FROM general_escuela ORDER BY escuela_nombre");
    }

    /** Valores activos de un catálogo del sistema, por su nombre. */
    public function valoresPorNombre(string $tabla): array
    {
        return $this->filas(
            "SELECT c.catalogo_valor, c.catalogo_descripcion
               FROM general_tabla_catalogo c
               JOIN general_tabla t ON t.tabla_id = c.catalogo_tablaid
              WHERE t.tabla_nombre = :t AND c.catalogo_estado = 'A'
              ORDER BY c.catalogo_descripcion",
            [':t' => $tabla]
        );
    }

    public function guardarSede(): string
    {
        $id = (int)($_POST['sede_id'] ?? 0);

        if ($id > 0 && !puede_editar('sedeList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede editar sedes.', 'error');
        }
        if ($id === 0 && !puede_crear('sedeList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede crear sedes.', 'error');
        }

        $escuela     = (int)($_POST['sede_escuelaid'] ?? 0);
        $nombre      = trim((string)($_POST['sede_nombre'] ?? ''));
        $tipo        = trim((string)($_POST['sede_tipoingreso'] ?? 'STF'));
        $direccion   = trim((string)($_POST['sede_direccion'] ?? ''));
        $email       = trim((string)($_POST['sede_email'] ?? ''));
        $telefono    = trim((string)($_POST['sede_telefono'] ?? ''));
        $inscripcion = round((float)str_replace(',', '.', (string)($_POST['sede_inscripcion'] ?? '0')), 2);
        $pension     = round((float)str_replace(',', '.', (string)($_POST['sede_pension'] ?? '0')), 2);

        if ($nombre === '') {
            return $this->alerta('simple', 'Faltan datos', 'El nombre de la sede es obligatorio.', 'error');
        }
        if ($escuela <= 0) {
            return $this->alerta('simple', 'Faltan datos', 'Seleccione la organización a la que pertenece.', 'error');
        }

        /* El tipo debe existir en el catálogo: es el que decide si Arena
           puede alquilar la sede. */
        $tiposValidos = array_column($this->valoresPorNombre('sede_tipoingreso'), 'catalogo_valor');
        if (!in_array($tipo, $tiposValidos, true)) {
            return $this->alerta('simple', 'Tipo no válido',
                'El tipo de sede indicado no existe en el catálogo.', 'error');
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->alerta('simple', 'Correo no válido', 'Revise la dirección de correo.', 'error');
        }
        if ($inscripcion < 0 || $pension < 0) {
            return $this->alerta('simple', 'Importe no válido', 'Los valores no pueden ser negativos.', 'error');
        }

        $duplicado = (int)$this->escalar(
            "SELECT COUNT(1) FROM general_sede WHERE sede_nombre = :n AND sede_id <> :id",
            [':n' => $nombre, ':id' => $id]
        );
        if ($duplicado > 0) {
            return $this->alerta('simple', 'Sede repetida', "Ya existe una sede llamada {$nombre}.", 'error');
        }

        /* Logo y firma propios de la sede. Si no los tiene, hereda los de
           la organización: la regla vive en ds_imagen_marca(). */
        $sedeActual = $id > 0 ? $this->sede($id) : [];

        $logo = $this->resolverImagenMarca('sede_foto', 'sede',
            (string)($sedeActual['sede_foto'] ?? ''), ($_POST['quitar_logo'] ?? '') === '1');
        if (str_starts_with($logo, '!')) {
            return $this->alerta('simple', 'Logo no válido', substr($logo, 1), 'error');
        }

        $firma = $this->resolverImagenMarca('sede_firma', 'firma_sede',
            (string)($sedeActual['sede_firma'] ?? ''), ($_POST['quitar_firma'] ?? '') === '1');
        if (str_starts_with($firma, '!')) {
            return $this->alerta('simple', 'Firma no válida', substr($firma, 1), 'error');
        }

        $params = [':e' => $escuela, ':n' => $nombre, ':t' => $tipo, ':d' => $direccion,
                   ':m' => $email, ':tel' => $telefono !== '' ? $telefono : null,
                   ':i' => $inscripcion, ':p' => $pension, ':f' => $logo, ':fi' => $firma];

        if ($id > 0) {
            $params[':id'] = $id;
            $n = $this->escribir(
                "UPDATE general_sede
                    SET sede_escuelaid = :e, sede_nombre = :n, sede_tipoingreso = :t,
                        sede_direccion = :d, sede_email = :m, sede_telefono = :tel,
                        sede_inscripcion = :i, sede_pension = :p, sede_foto = :f,
                        sede_firma = :fi
                  WHERE sede_id = :id", $params);

            return $n >= 0
                ? $this->alerta('redireccionar', 'Sede actualizada',
                    "{$nombre} se actualizó correctamente.", 'success', APP_URL . 'sedeList/')
                : $this->alerta('simple', 'Error', 'No fue posible actualizar la sede.', 'error');
        }

        $n = $this->escribir(
            "INSERT INTO general_sede
                (sede_escuelaid, sede_nombre, sede_tipoingreso, sede_direccion,
                 sede_email, sede_telefono, sede_inscripcion, sede_pension,
                 sede_foto, sede_firma)
             VALUES (:e, :n, :t, :d, :m, :tel, :i, :p, :f, :fi)", $params);

        return $n > 0
            ? $this->alerta('redireccionar', 'Sede creada',
                "{$nombre} quedó registrada y ya está disponible para los módulos.",
                'success', APP_URL . 'sedeList/')
            : $this->alerta('simple', 'Error', 'No fue posible crear la sede.', 'error');
    }

    public function eliminarSede(): string
    {
        if (!puede_eliminar('sedeList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede eliminar sedes.', 'error');
        }

        $id   = (int)($_POST['sede_id'] ?? 0);
        $sede = $this->sede($id);

        if ($sede === null) {
            return $this->alerta('simple', 'Error', 'La sede no existe.', 'error');
        }

        /* Una sede la usan varios módulos: se comprueban todos antes de
           permitir la baja, para no dejar registros huérfanos. */
        $usos = [];

        $alumnos = (int)$this->escalar(
            "SELECT COUNT(1) FROM sujeto_alumno WHERE alumno_sedeid = :id AND alumno_estado <> 'E'", [':id' => $id]);
        if ($alumnos > 0) $usos[] = "$alumnos alumno(s)";

        $empleados = (int)$this->escalar(
            "SELECT COUNT(1) FROM sujeto_empleado WHERE empleado_sedeid = :id AND empleado_estado <> 'E'", [':id' => $id]);
        if ($empleados > 0) $usos[] = "$empleados empleado(s)";

        $instalaciones = (int)$this->escalar(
            "SELECT COUNT(1) FROM dsa_instalacion WHERE instalacion_sedeid = :id AND instalacion_estado <> 'E'", [':id' => $id]);
        if ($instalaciones > 0) $usos[] = "$instalaciones instalación(es) en Arena";

        if ($usos) {
            return $this->alerta('simple', 'Sede en uso',
                'No se puede eliminar: tiene ' . implode(', ', $usos) . '. Reasígnelos primero.', 'error');
        }

        $n = $this->escribir("DELETE FROM general_sede WHERE sede_id = :id", [':id' => $id]);

        return $n > 0
            ? $this->alerta('recargar', 'Sede eliminada', "{$sede['sede_nombre']} fue eliminada.")
            : $this->alerta('simple', 'Error', 'No fue posible eliminar la sede.', 'error');
    }

    /*======================================================================
      Organización: nombre legal y logo
      ====================================================================*/

    /**
     * Guarda un logo en la carpeta de marca del núcleo.
     *
     * Devuelve el nombre del archivo, '' si no se envió imagen, o un
     * mensaje de error precedido de '!' si algo no cuadra.
     */
    private function guardarLogo(string $campo, string $prefijo): string
    {
        if (!isset($_FILES[$campo]) || $_FILES[$campo]['error'] === UPLOAD_ERR_NO_FILE) {
            return '';
        }

        $f = $_FILES[$campo];

        if ($f['error'] !== UPLOAD_ERR_OK) {
            return '!No se pudo subir la imagen (código ' . $f['error'] . ').';
        }
        if (!is_uploaded_file($f['tmp_name'])) {
            return '!El archivo recibido no proviene de una subida válida.';
        }
        if ($f['size'] > 2 * 1024 * 1024) {
            return '!La imagen no puede superar 2 MB.';
        }

        /* Se confía en el contenido real, no en la extensión enviada. */
        $tipo = @mime_content_type($f['tmp_name']);

        if (!in_array($tipo, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return '!Formato no admitido. Use JPG, PNG o WEBP.';
        }

        $dir = ds_marca_dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            return '!No existe la carpeta de marca y no se pudo crear.';
        }

        /* Siempre se reescribe como PNG plano: FPDF revienta al encontrar un
           canal alfa, y estos logos van a parar a recibos y facturas. */
        $nombre = $prefijo . '_' . date('YmdHis') . '.png';
        $error  = $this->normalizarLogo($f['tmp_name'], $dir . $nombre, $tipo);

        if ($error !== '') {
            return '!' . $error;
        }

        return $nombre;
    }

    /**
     * Aplana la imagen sobre fondo blanco y la guarda como PNG sin
     * transparencia ni entrelazado, con el lado mayor limitado a 600 px.
     *
     * Un PNG RGBA subido por el usuario dejaba a FPDF llamando a
     * gzuncompress sobre datos que no sabe separar, y con eso caía cada
     * PDF del sistema. Normalizar en la subida evita el problema de raíz.
     *
     * Devuelve '' si todo fue bien, o el motivo del fallo.
     */
    private function normalizarLogo(string $origen, string $destino, string $mime): string
    {
        $origenImg = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($origen),
            'image/png'  => @imagecreatefrompng($origen),
            'image/webp' => @imagecreatefromwebp($origen),
            default      => false,
        };

        if ($origenImg === false) {
            return 'La imagen está dañada o no se pudo leer.';
        }

        $ancho = imagesx($origenImg);
        $alto  = imagesy($origenImg);
        $lado  = max($ancho, $alto);
        $razon = $lado > 600 ? 600 / $lado : 1;

        $nuevoAncho = max(1, (int)round($ancho * $razon));
        $nuevoAlto  = max(1, (int)round($alto  * $razon));

        $destinoImg = imagecreatetruecolor($nuevoAncho, $nuevoAlto);
        $blanco     = imagecolorallocate($destinoImg, 255, 255, 255);
        imagefilledrectangle($destinoImg, 0, 0, $nuevoAncho, $nuevoAlto, $blanco);
        imagecopyresampled($destinoImg, $origenImg, 0, 0, 0, 0,
                           $nuevoAncho, $nuevoAlto, $ancho, $alto);

        imageinterlace($destinoImg, false);
        $guardado = imagepng($destinoImg, $destino, 6);

        imagedestroy($origenImg);
        imagedestroy($destinoImg);

        return $guardado ? '' : 'No se pudo guardar la imagen en el servidor.';
    }

    /** Borra una imagen de marca anterior que ya no se usa. */
    private function borrarLogo(string $archivo): void
    {
        if ($archivo !== '' && is_file(ds_marca_dir() . $archivo)) {
            @unlink(ds_marca_dir() . $archivo);
        }
    }

    /**
     * Decide con qué archivo se queda un campo de marca (logo o firma):
     * el recién subido, ninguno si se pidió quitarlo, o el que ya había.
     * Borra del disco el que deja de usarse.
     *
     * Devuelve el nombre resultante, o el motivo del fallo precedido de '!'.
     */
    private function resolverImagenMarca(string $campo, string $prefijo,
                                         string $actual, bool $quitar): string
    {
        $subido = $this->guardarLogo($campo, $prefijo);

        if (str_starts_with($subido, '!')) {
            return $subido;
        }
        if ($subido !== '') {
            $this->borrarLogo($actual);
            return $subido;
        }
        if ($quitar) {
            $this->borrarLogo($actual);
            return '';
        }

        return $actual;
    }

    public function organizacion(): array
    {
        return ds_organizacion();
    }

    public function guardarOrganizacion(): string
    {
        if (!puede_editar('organizacionForm')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede editar la organización.', 'error');
        }

        $id        = (int)($_POST['escuela_id'] ?? 0);
        $nombre    = trim((string)($_POST['escuela_nombre'] ?? ''));
        $ruc       = trim((string)($_POST['escuela_ruc'] ?? ''));
        $direccion = trim((string)($_POST['escuela_direccion'] ?? ''));
        $email     = trim((string)($_POST['escuela_email'] ?? ''));
        $telefono  = trim((string)($_POST['escuela_telefono'] ?? ''));
        $movil     = trim((string)($_POST['escuela_movil'] ?? ''));
        $quitarLogo = ($_POST['quitar_logo']  ?? '') === '1';
        $quitarFirma= ($_POST['quitar_firma'] ?? '') === '1';

        if ($nombre === '') {
            return $this->alerta('simple', 'Faltan datos',
                'El nombre legal es obligatorio: aparece en recibos, facturas y reportes.', 'error');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->alerta('simple', 'Correo no válido', 'Revise la dirección de correo.', 'error');
        }

        $actual = $this->filas("SELECT * FROM general_escuela WHERE escuela_id = :id", [':id' => $id]);
        $actual = $actual[0] ?? null;
        if ($actual === null) {
            return $this->alerta('simple', 'Error', 'La organización no existe.', 'error');
        }

        $logo = $this->resolverImagenMarca(
            'escuela_logo', 'organizacion', (string)$actual['escuela_logo'], $quitarLogo);
        if (str_starts_with($logo, '!')) {
            return $this->alerta('simple', 'Logo no válido', substr($logo, 1), 'error');
        }

        $firma = $this->resolverImagenMarca(
            'escuela_firma', 'firma', (string)($actual['escuela_firma'] ?? ''), $quitarFirma);
        if (str_starts_with($firma, '!')) {
            return $this->alerta('simple', 'Firma no válida', substr($firma, 1), 'error');
        }

        $n = $this->escribir(
            "UPDATE general_escuela
                SET escuela_nombre = :n, escuela_ruc = :r, escuela_direccion = :d,
                    escuela_email = :e, escuela_telefono = :t, escuela_movil = :m,
                    escuela_logo = :l, escuela_firma = :f
              WHERE escuela_id = :id",
            [':n' => $nombre, ':r' => $ruc, ':d' => $direccion, ':e' => $email,
             ':t' => $telefono !== '' ? $telefono : null, ':m' => $movil,
             ':l' => $logo, ':f' => $firma, ':id' => $id]
        );

        return $n >= 0
            ? $this->alerta('recargar', 'Organización actualizada',
                "«{$nombre}» es ahora el nombre que aparecerá en recibos, facturas y reportes.")
            : $this->alerta('simple', 'Error', 'No fue posible guardar la organización.', 'error');
    }

    /*======================================================================
      Facturación electrónica: parámetros del emisor
      ====================================================================*/

    /**
     * Configuración del SRI tal como está guardada.
     *
     * Core sólo lee y presenta la fila: el motor de facturación —firma,
     * certificado, envío a los webservices— sigue viviendo en Basketball,
     * y es ahí donde el formulario escribe. Duplicar aquí la criptografía
     * sería copiar la parte más delicada del sistema.
     */
    public function configuracionSri(): array
    {
        $vacia = [
            'ambiente' => '1', 'tipo_emision' => '1', 'iva_tarifa_default' => '0.00',
            'forma_pago_default' => '20', 'valores_incluyen_iva' => 1, 'secuencial_inicio' => 1,
            'ruc' => '', 'razon_social' => '', 'nombre_comercial' => '',
            'direccion_matriz' => '', 'direccion_establecimiento' => '',
            'codigo_establecimiento' => '001', 'punto_emision' => '001',
            'obligado_contabilidad' => 'NO', 'contribuyente_especial' => '',
            'agente_retencion' => '', 'contribuyente_rimpe' => '',
        ];

        try {
            $filas = $this->filas("SELECT * FROM facturas_electronicas_config ORDER BY id LIMIT 1");
            return $filas ? array_merge($vacia, $filas[0]) : $vacia;
        } catch (\Throwable $e) {
            return $vacia;
        }
    }

    /** Catálogo de formas de pago del SRI. */
    public function formasPagoSri(): array
    {
        return [
            '01' => 'SIN UTILIZACION DEL SISTEMA FINANCIERO',
            '15' => 'COMPENSACION DE DEUDAS',
            '16' => 'TARJETA DE DEBITO',
            '17' => 'DINERO ELECTRONICO',
            '18' => 'TARJETA PREPAGO',
            '19' => 'TARJETA DE CREDITO',
            '20' => 'OTROS CON UTILIZACION DEL SISTEMA FINANCIERO',
            '21' => 'ENDOSO DE TITULOS',
        ];
    }

    /*======================================================================
      Carnets: color por mes y política de reimpresión
      ====================================================================*/

    /**
     * Color asignado a cada mes, con el aviso de si ya está bloqueado.
     *
     * Un mes se bloquea en cuanto se emite el primer carnet: cambiarle el
     * color dejaría en circulación carnets de un color que ya no coincide
     * con el del sistema.
     */
    public function coloresCarnetPorMes(): array
    {
        $asignados = [];

        try {
            $filas = $this->filas(
                "SELECT cmc.mcolor_mes            AS mes,
                        cmc.mcolor_catcolorid     AS color_id,
                        cmc.mcolor_bloqueado      AS bloqueado,
                        cc.catcolor_nombre        AS color_nombre,
                        cc.catcolor_hex           AS color_hex,
                        (SELECT COUNT(*) FROM alumno_carnet ac
                          WHERE ac.carnet_mes = cmc.mcolor_mes) AS total_carnets
                   FROM carnet_mes_color cmc
                   JOIN carnet_catcolor  cc ON cc.catcolor_id = cmc.mcolor_catcolorid
                  WHERE cmc.mcolor_activo = 1"
            );
            foreach ($filas as $f) {
                $asignados[(int)$f['mes']] = $f;
            }
        } catch (\Throwable $e) {
            // Se devuelven los doce meses sin asignar.
        }

        $meses = [];
        for ($m = 1; $m <= 12; $m++) {
            $f = $asignados[$m] ?? null;
            $meses[$m] = [
                'color_id'      => (int)($f['color_id'] ?? 0),
                'color_hex'     => (string)($f['color_hex'] ?? '#FFFFFF'),
                'color_nombre'  => (string)($f['color_nombre'] ?? 'Sin asignar'),
                'total_carnets' => (int)($f['total_carnets'] ?? 0),
                'bloqueado'     => (int)($f['bloqueado'] ?? 0) === 1
                                   || (int)($f['total_carnets'] ?? 0) > 0,
            ];
        }

        return $meses;
    }

    /** Catálogo de colores activos, con las veces que ya está asignado cada uno. */
    public function catalogoColoresCarnet(): array
    {
        try {
            return $this->filas(
                "SELECT cc.catcolor_id, cc.catcolor_nombre, cc.catcolor_hex,
                        (SELECT COUNT(*) FROM carnet_mes_color cmc
                          WHERE cmc.mcolor_catcolorid = cc.catcolor_id
                            AND cmc.mcolor_activo = 1) AS asignado_a
                   FROM carnet_catcolor cc
                  WHERE cc.catcolor_activo = 1
                  ORDER BY cc.catcolor_nombre"
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Política de cobro por reimpresión de carnet. */
    public function configuracionCarnet(): array
    {
        $valores = ['cobrar_reimpresion' => '1', 'valor_reimpresion' => '3.00'];

        try {
            foreach ($this->filas("SELECT config_clave, config_valor FROM carnet_configuracion") as $f) {
                $valores[$f['config_clave']] = $f['config_valor'];
            }
        } catch (\Throwable $e) {
            // Quedan los valores por defecto.
        }

        $valor = (float)str_replace(',', '.', (string)$valores['valor_reimpresion']);

        return [
            'cobrar' => (string)$valores['cobrar_reimpresion'] === '1',
            'valor'  => $valor > 0 ? round($valor, 2) : 3.00,
        ];
    }

    /*======================================================================
      Catálogos del sistema
      ====================================================================*/

    public function catalogos(): array
    {
        return $this->filas(
            "SELECT t.tabla_id, t.tabla_nombre, t.tabla_estado,
                    COUNT(c.catalogo_valor) AS valores,
                    SUM(c.catalogo_estado = 'A') AS activos
               FROM general_tabla t
               LEFT JOIN general_tabla_catalogo c ON c.catalogo_tablaid = t.tabla_id
              WHERE t.tabla_estado <> 'E'
              GROUP BY t.tabla_id, t.tabla_nombre, t.tabla_estado
              ORDER BY t.tabla_nombre"
        );
    }

    public function valoresCatalogo(int $tablaid): array
    {
        return $this->filas(
            "SELECT * FROM general_tabla_catalogo
              WHERE catalogo_tablaid = :t ORDER BY catalogo_descripcion",
            [':t' => $tablaid]
        );
    }

    public function guardarValorCatalogo(): string
    {
        if (!puede_crear('catalogoList') && !puede_editar('catalogoList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede administrar catálogos.', 'error');
        }

        $tabla       = (int)($_POST['catalogo_tablaid'] ?? 0);
        $valor       = strtoupper(trim((string)($_POST['catalogo_valor'] ?? '')));
        $descripcion = trim((string)($_POST['catalogo_descripcion'] ?? ''));
        $estado      = ($_POST['catalogo_estado'] ?? 'A') === 'A' ? 'A' : 'I';
        $esNuevo     = ($_POST['es_nuevo'] ?? '0') === '1';

        if ($tabla <= 0 || $descripcion === '') {
            return $this->alerta('simple', 'Faltan datos', 'Indique catálogo y descripción.', 'error');
        }

        /* El código es la clave primaria de la tabla: exactamente 3
           caracteres, como el resto del catálogo del sistema. */
        if (!preg_match('/^[A-Z0-9]{3}$/', $valor)) {
            return $this->alerta('simple', 'Código no válido',
                'El código debe tener exactamente 3 caracteres alfanuméricos en mayúscula.', 'error');
        }

        if ($esNuevo) {
            if (!puede_crear('catalogoList')) {
                return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede crear valores.', 'error');
            }

            $existe = (int)$this->escalar(
                "SELECT COUNT(1) FROM general_tabla_catalogo WHERE catalogo_valor = :v", [':v' => $valor]);
            if ($existe > 0) {
                return $this->alerta('simple', 'Código repetido',
                    "El código {$valor} ya está en uso. Los códigos son únicos en todo el sistema.", 'error');
            }

            $n = $this->escribir(
                "INSERT INTO general_tabla_catalogo
                    (catalogo_valor, catalogo_tablaid, catalogo_descripcion, catalogo_estado)
                 VALUES (:v, :t, :d, :e)",
                [':v' => $valor, ':t' => $tabla, ':d' => $descripcion, ':e' => $estado]);
        } else {
            if (!puede_editar('catalogoList')) {
                return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede editar valores.', 'error');
            }

            $n = $this->escribir(
                "UPDATE general_tabla_catalogo
                    SET catalogo_descripcion = :d, catalogo_estado = :e
                  WHERE catalogo_valor = :v",
                [':d' => $descripcion, ':e' => $estado, ':v' => $valor]);
        }

        return $n >= 0
            ? $this->alerta('recargar', 'Catálogo actualizado', "El valor {$valor} quedó guardado.")
            : $this->alerta('simple', 'Error', 'No fue posible guardar el valor.', 'error');
    }

    public function eliminarValorCatalogo(): string
    {
        if (!puede_eliminar('catalogoList')) {
            return $this->alerta('simple', 'Acceso denegado', 'Su rol no puede eliminar valores.', 'error');
        }

        $valor = strtoupper(trim((string)($_POST['catalogo_valor'] ?? '')));

        /* Los tipos de sede sostienen la operación de Arena: si se borra el
           código que una sede tiene asignado, esa sede deja de resolverse. */
        $enUso = (int)$this->escalar(
            "SELECT COUNT(1) FROM general_sede WHERE sede_tipoingreso = :v", [':v' => $valor]);
        if ($enUso > 0) {
            return $this->alerta('simple', 'Valor en uso',
                "No se puede eliminar: {$enUso} sede(s) usan ese tipo. Cámbielas primero.", 'error');
        }

        $n = $this->escribir(
            "DELETE FROM general_tabla_catalogo WHERE catalogo_valor = :v", [':v' => $valor]);

        return $n > 0
            ? $this->alerta('recargar', 'Valor eliminado', "El valor {$valor} fue eliminado del catálogo.")
            : $this->alerta('simple', 'Error', 'No fue posible eliminar el valor.', 'error');
    }

    /*======================================================================
      Menu lateral del propio modulo Core
      ====================================================================*/

    public function menuLateral(string $vistaActual): string
    {
        $sql = "SELECT m.menu_vista, m.menu_nombre, m.menu_icono
                  FROM seguridad_menu m
                 WHERE m.menu_estado = 'A' AND m.menu_modulo = 'core'";

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

    /*======================================================================
      Puntos de emisión por módulo

      El SRI numera los comprobantes por (tipo, establecimiento, punto de
      emisión) y exige que el secuencial no se repita dentro de esa terna.
      Dar a cada módulo su propio punto hace imposible la colisión; esta
      pantalla es donde se asigna.
      ====================================================================*/

    /** Puntos configurados, con el uso real de cada uno. */
    public function puntosEmision(): array
    {
        /* Se cuenta desde la vista consolidada y no desde una tabla
           concreta: es lo que permite ver que un punto ya fue usado,
           venga el comprobante del módulo que venga. */
        return $this->filas(
            "SELECT P.*,
                    COALESCE(S.secuencial_actual, 0) AS contador,
                    (SELECT COUNT(*) FROM v_comprobante_emitido V
                      WHERE V.establecimiento = P.punto_establecimiento
                        AND V.punto_emision   = P.punto_codigo) AS emitidos
               FROM facturas_electronicas_punto_emision P
               LEFT JOIN facturas_electronicas_secuenciales S
                      ON S.establecimiento   = P.punto_establecimiento
                     AND S.punto_emision     = P.punto_codigo
                     AND S.tipo_comprobante  = '01'
              ORDER BY P.punto_establecimiento, P.punto_codigo"
        );
    }

    /** Módulos del ecosistema que todavía no tienen punto asignado. */
    public function modulosSinPunto(): array
    {
        $asignados = array_column($this->puntosEmision(), 'punto_modulo');
        $libres    = [];

        foreach (ds_modulos() as $clave => $m) {
            /* Core no factura: administra. */
            if ($clave === 'core') { continue; }
            if (!in_array($clave, $asignados, true)) {
                $libres[$clave] = $m['nombre'];
            }
        }

        return $libres;
    }

    /**
     * Da de alta o modifica un punto de emisión.
     *
     * Sólo el superadministrador. No es una restricción de comodidad: de
     * aquí depende con qué numeración se emiten documentos con validez
     * tributaria.
     */
    public function guardarPuntoEmision(): string
    {
        if (!es_superadministrador()) {
            return $this->alerta('simple', 'Acceso denegado',
                'Sólo el superadministrador puede configurar puntos de emisión.', 'error');
        }

        $id      = (int)($_POST['punto_id'] ?? 0);
        $modulo  = strtolower(trim((string)($_POST['punto_modulo'] ?? '')));
        $estab   = trim((string)($_POST['punto_establecimiento'] ?? ''));
        $codigo  = trim((string)($_POST['punto_codigo'] ?? ''));
        $inicio  = (int)($_POST['punto_secuencialinicio'] ?? 1);
        $desc    = trim((string)($_POST['punto_descripcion'] ?? ''));
        $estado  = ($_POST['punto_estado'] ?? 'I') === 'A' ? 'A' : 'I';

        /*----------  Forma de los códigos  ----------*/
        /* El SRI los define como tres dígitos exactos. Se valida aquí y no
           sólo en el navegador, porque este endpoint es alcanzable sin
           pasar por el formulario. */
        if (!preg_match('/^\d{3}$/', $estab)) {
            return $this->alerta('simple', 'Establecimiento no válido',
                'El código de establecimiento son tres dígitos, por ejemplo 001.', 'error');
        }
        if (!preg_match('/^\d{3}$/', $codigo)) {
            return $this->alerta('simple', 'Punto de emisión no válido',
                'El punto de emisión son tres dígitos, por ejemplo 002.', 'error');
        }
        if ($inicio < 1 || $inicio > 999999999) {
            return $this->alerta('simple', 'Secuencial no válido',
                'El número inicial debe estar entre 1 y 999999999.', 'error');
        }

        /*----------  El módulo tiene que existir  ----------*/
        $conocidos = array_keys(ds_modulos());
        if (!in_array($modulo, $conocidos, true) || $modulo === 'core') {
            return $this->alerta('simple', 'Módulo no válido',
                'Seleccione un módulo del ecosistema que pueda emitir comprobantes.', 'error');
        }

        /*----------  No retroceder por debajo de lo ya emitido  ----------*/
        /* La base impide duplicar el número de un comprobante, pero no
           impide dejar el punto apuntando a un secuencial ya usado: eso se
           descubriría al emitir, con el documento a medio hacer. Se
           comprueba aquí, que es donde el aviso todavía sirve. */
        $emitido = (int)$this->escalar(
            "SELECT COALESCE(MAX(CAST(secuencial AS UNSIGNED)), 0)
               FROM v_comprobante_emitido
              WHERE establecimiento = :e AND punto_emision = :p",
            [':e' => $estab, ':p' => $codigo]
        );

        if ($emitido > 0 && $inicio <= $emitido) {
            return $this->alerta('simple', 'Numeración ya utilizada',
                "Desde {$estab}-{$codigo} ya se emitió hasta el número {$emitido}. "
                . 'El número inicial debe ser mayor.', 'error');
        }

        /*----------  Escritura  ----------*/
        try {
            $con = $this->con();
            if ($con === null) {
                return $this->alerta('simple', 'Sin conexión',
                    'No fue posible conectar con la base de datos.', 'error');
            }

            if ($id > 0) {
                $sql = "UPDATE facturas_electronicas_punto_emision
                           SET punto_modulo = :m, punto_establecimiento = :e,
                               punto_codigo = :c, punto_secuencialinicio = :i,
                               punto_descripcion = :d, punto_estado = :s,
                               punto_usuarioid = :u
                         WHERE punto_id = :id";
                $par = [':id' => $id];
            } else {
                $sql = "INSERT INTO facturas_electronicas_punto_emision
                               (punto_modulo, punto_establecimiento, punto_codigo,
                                punto_secuencialinicio, punto_descripcion,
                                punto_estado, punto_usuarioid)
                        VALUES (:m, :e, :c, :i, :d, :s, :u)";
                $par = [];
            }

            $st = $con->prepare($sql);
            $st->execute($par + [
                ':m' => $modulo, ':e' => $estab, ':c' => $codigo, ':i' => $inicio,
                ':d' => $desc,   ':s' => $estado, ':u' => usuario_actual_id() ?: null,
            ]);

            $accion = $id > 0 ? 'actualizó' : 'creó';
            return $this->alerta('recargar', 'Punto de emisión guardado',
                "Se {$accion} el punto {$estab}-{$codigo} para el módulo {$modulo}.", 'success');

        } catch (\PDOException $e) {
            /* Hay dos claves únicas en la tabla, y decir cuál se violó es
               la diferencia entre un aviso útil y «error de base de datos». */
            if ((int)$e->getCode() === 23000) {
                $msg = strpos($e->getMessage(), 'uk_fepe_punto') !== false
                    ? "El punto {$estab}-{$codigo} ya está asignado a otro módulo. "
                      . 'Un punto de emisión pertenece a uno solo: es lo que impide '
                      . 'que dos módulos generen el mismo número.'
                    : "El módulo «{$modulo}» ya tiene un punto asignado en el "
                      . "establecimiento {$estab}.";
                return $this->alerta('simple', 'Asignación duplicada', $msg, 'error');
            }

            return $this->alerta('simple', 'No se pudo guardar',
                'La base de datos rechazó el cambio.', 'error');
        }
    }

    /*======================================================================
      Segundo factor de autenticación

      CADA UNO CONFIGURA EL SUYO, Y NADIE CONFIGURA EL DE OTRO

      Un administrador no puede activar el segundo factor de otra persona:
      necesitaría su teléfono, y si pudiera hacerlo sin él tendría también
      la forma de generar sus códigos. Lo único que puede hacer sobre una
      cuenta ajena es RESTABLECER —quitarlo— cuando alguien pierde el
      teléfono y se queda fuera, y eso queda registrado con nombre y fecha
      porque es exactamente el movimiento que haría quien quiere entrar en
      una cuenta que no es suya.
      ====================================================================*/

    /**
     * Quita el segundo factor de OTRA persona.
     *
     * Es la salida cuando alguien pierde el teléfono y se queda fuera. Sólo
     * el superadministrador, con motivo obligatorio, y queda anotado quién
     * lo hizo y sobre quién: sin ese rastro, esta función es una puerta
     * trasera a cualquier cuenta.
     */
    public function restablecerSegundoFactor(): string
    {
        if (!es_superadministrador()) {
            return $this->alerta('simple', 'Acceso denegado',
                'Sólo el superadministrador puede restablecer la verificación de otra '
                . 'persona.', 'error');
        }

        $usuarioid = (int)($_POST['usuario_id'] ?? 0);
        $motivo    = trim((string)($_POST['motivo'] ?? ''));

        if ($usuarioid <= 0) {
            return $this->alerta('simple', 'Falta el usuario', 'No se indicó a quién.', 'error');
        }
        if ($motivo === '') {
            return $this->alerta('simple', 'Falta el motivo',
                'Quitarle a alguien su segundo factor necesita una justificación escrita.',
                'error');
        }

        $quien = $this->fila("SELECT usuario_usuario, usuario_2fa_estado
                                FROM seguridad_usuario WHERE usuario_id = :id",
                             [':id' => $usuarioid]);

        if (!$quien) {
            return $this->alerta('simple', 'No encontrado', 'Ese usuario no existe.', 'error');
        }
        if ($quien['usuario_2fa_estado'] === 'N') {
            return $this->alerta('simple', 'No tiene verificación',
                'Esa cuenta no tiene el segundo factor configurado.', 'error');
        }

        if (!dosf_desactivar($usuarioid, 'RESTABLECER', $motivo)) {
            return $this->alerta('simple', 'No se pudo restablecer',
                'La base de datos rechazó el cambio.', 'error');
        }

        return $this->alerta('recargar', 'Verificación restablecida',
            'La cuenta «' . $quien['usuario_usuario'] . '» vuelve a entrar sólo con su '
            . 'contraseña. Pídale que la configure de nuevo.');
    }

}
