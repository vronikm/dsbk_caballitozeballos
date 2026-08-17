<?php
/*
|--------------------------------------------------------------------------
| Helpers de seguridad compartidos
|--------------------------------------------------------------------------
| Se carga desde session_start.php, por lo que queda disponible tanto en
| index.php (capa de vistas) como en cualquier endpoint de app/ajax/.
|
| Convencion de roles: 1 = Super Administrador y 2 = Administrador tienen
| acceso total, igual que en app/views/inc/main-sidebar.php. El resto de
| roles se rige por la tabla seguridad_permiso.
*/

if (!function_exists('usuario_autenticado')) {

    /*----------  Sesion  ----------*/

    function usuario_autenticado(): bool
    {
        return isset($_SESSION['usuario']) && $_SESSION['usuario'] !== '';
    }

    function rol_actual(): int
    {
        return (int)($_SESSION['rol'] ?? 0);
    }

    /**
     * Super Administrador: el UNICO rol que pasa por encima del control de
     * acceso. Cualquier otro rol, incluido el Administrador, se rige por lo
     * que tenga concedido en seguridad_rol_modulo y seguridad_permiso.
     */
    function es_superadministrador(): bool
    {
        return rol_actual() === self_rol_superadmin();
    }

    /** Id del rol con acceso total. Centralizado para no repetir el numero. */
    function self_rol_superadmin(): int
    {
        return 1;
    }

    /*----------  Anti-CSRF por validacion de origen  ----------*/

    /**
     * Comprueba que una peticion POST provenga del propio sitio.
     *
     * Se apoya en las cabeceras Origin / Referer: el navegador las envia
     * en toda peticion POST y una pagina atacante no puede falsificarlas
     * por JavaScript. Combinado con la cookie de sesion SameSite=Lax que
     * fija session_start.php, cubre el vector CSRF sin necesidad de
     * insertar un token en las ~100 vistas existentes.
     *
     * Las peticiones GET no se validan: no deben producir efectos.
     */
    function origen_es_valido(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return true;
        }

        $host_app = parse_url(APP_URL, PHP_URL_HOST);

        $origen = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origen === '') {
            $origen = $_SERVER['HTTP_REFERER'] ?? '';
        }

        // POST sin Origin ni Referer: no se puede acreditar la procedencia.
        if ($origen === '') {
            return false;
        }

        return parse_url($origen, PHP_URL_HOST) === $host_app;
    }

    /*----------  Acceso a datos de seguridad  ----------*/

    /** Conexion propia y reutilizable para las consultas de seguridad. */
    function seguridad_conexion(): ?PDO
    {
        static $con = null;
        static $intentado = false;

        if ($intentado) {
            return $con;
        }
        $intentado = true;

        try {
            $con = new PDO(
                "mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME . ";charset=utf8",
                DB_USER,
                DB_PASS
            );
            $con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (\Throwable $e) {
            $con = null;
        }

        return $con;
    }

    /**
     * Modulo del contexto en ejecucion. Cada aplicacion lo declara con la
     * constante DS_MODULO en su config; el Hub no pertenece a ninguno.
     */
    function modulo_actual(): string
    {
        return defined('DS_MODULO') ? DS_MODULO : '';
    }

    /*----------  Nivel 1: acceso a modulos  ----------*/

    /**
     * Modulos habilitados para el rol de la sesion.
     * El Super Administrador (rol 1) los tiene todos por definicion.
     */
    function modulos_permitidos(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        if (es_superadministrador()) {
            $cache = array_keys(ds_modulos_conocidos());
            return $cache;
        }

        $cache = [];
        $con   = seguridad_conexion();
        if ($con === null) {
            return $cache;
        }

        try {
            $sql = $con->prepare(
                "SELECT rolmod_modulo
                   FROM seguridad_rol_modulo
                  WHERE rolmod_rolid  = :rol
                    AND rolmod_estado = 'A'"
            );
            $sql->execute([':rol' => rol_actual()]);
            $cache = $sql->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            $cache = [];
        }

        return $cache;
    }

    function usuario_tiene_modulo(string $modulo): bool
    {
        return es_superadministrador() || in_array($modulo, modulos_permitidos(), true);
    }

    /** Claves de modulo que el ecosistema reconoce. */
    function ds_modulos_conocidos(): array
    {
        return [
            'core'       => 'Core',
            'basketball' => 'Basketball',
            'arena'      => 'Arena',
            'league'     => 'League',
            'insights'   => 'Insights',
        ];
    }

    /*----------  Nivel 2 y 3: vistas y acciones  ----------*/

    /**
     * Carga, una vez por modulo y peticion, las vistas registradas como
     * item de menu de ese modulo y las que el rol tiene habilitadas, con
     * sus acciones.
     *
     * Devuelve:
     *   [ 'ok' => bool,
     *     'registradas' => string[],
     *     'permitidas'  => [vista => ['ver'=>bool,'crear'=>bool,'editar'=>bool,'eliminar'=>bool]] ]
     */
    function permisos_de_la_sesion(string $modulo = ''): array
    {
        static $cache = [];

        $modulo = $modulo !== '' ? $modulo : modulo_actual();
        if (isset($cache[$modulo])) {
            return $cache[$modulo];
        }

        $datos = ['ok' => false, 'registradas' => [], 'permitidas' => []];
        $con   = seguridad_conexion();

        if ($con === null) {
            // Si la BD no responde no se amplian privilegios: 'ok' queda en
            // false y usuario_tiene_permiso() niega el acceso.
            $cache[$modulo] = $datos;
            return $datos;
        }

        try {
            /* Vistas que son item de menu del modulo: las sujetas a permiso. */
            $sql = $con->prepare(
                "SELECT menu_vista
                   FROM seguridad_menu
                  WHERE menu_estado = 'A'
                    AND menu_vista NOT IN ('', 'No')
                    AND (:modulo = '' OR menu_modulo = :modulo2)"
            );
            $sql->execute([':modulo' => $modulo, ':modulo2' => $modulo]);
            $datos['registradas'] = $sql->fetchAll(PDO::FETCH_COLUMN);

            /* Vistas habilitadas para el rol, con el alcance de cada una. */
            $sql = $con->prepare(
                "SELECT m.menu_vista,
                        p.permiso_ver, p.permiso_crear,
                        p.permiso_editar, p.permiso_eliminar
                   FROM seguridad_permiso p
                   JOIN seguridad_menu    m ON m.menu_id = p.permiso_menuid
                  WHERE p.permiso_rolid  = :rol
                    AND p.permiso_estado = 'A'
                    AND m.menu_estado    = 'A'
                    AND (:modulo = '' OR m.menu_modulo = :modulo2)"
            );
            $sql->execute([':rol' => rol_actual(), ':modulo' => $modulo, ':modulo2' => $modulo]);

            foreach ($sql->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $datos['permitidas'][$f['menu_vista']] = [
                    'ver'      => $f['permiso_ver']      === 'S',
                    'crear'    => $f['permiso_crear']    === 'S',
                    'editar'   => $f['permiso_editar']   === 'S',
                    'eliminar' => $f['permiso_eliminar'] === 'S',
                ];
            }

            $datos['ok'] = true;

        } catch (\Throwable $e) {
            $datos = ['ok' => false, 'registradas' => [], 'permitidas' => []];
        }

        $cache[$modulo] = $datos;
        return $datos;
    }

    /**
     * Indica si el rol de la sesion puede abrir una vista.
     *
     * Regla deliberada: una vista que NO esta registrada en seguridad_menu
     * no se restringe. Son vistas de apoyo (formularios de alta, PDF,
     * perfiles, recibos) que no tienen entrada de menu propia y cuyo
     * control efectivo esta en la vista de listado desde la que se abren.
     */
    function usuario_tiene_permiso(string $vista, string $modulo = ''): bool
    {
        if ($vista === '' || es_superadministrador()) {
            return true;
        }

        $permisos = permisos_de_la_sesion($modulo);

        if (!$permisos['ok']) {
            return false;
        }

        if (!in_array($vista, $permisos['registradas'], true)) {
            return true;
        }

        return !empty($permisos['permitidas'][$vista]['ver']);
    }

    /**
     * Comprueba una accion concreta sobre una vista.
     * $accion: 'ver' | 'crear' | 'editar' | 'eliminar'
     *
     * Las vistas de apoyo (no registradas como menu) heredan el alcance de
     * quien las abre, por lo que no se restringen aqui.
     */
    function puede(string $accion, string $vista, string $modulo = ''): bool
    {
        if (es_superadministrador()) {
            return true;
        }

        $permisos = permisos_de_la_sesion($modulo);

        if (!$permisos['ok']) {
            return false;
        }

        if (!in_array($vista, $permisos['registradas'], true)) {
            return true;
        }

        return !empty($permisos['permitidas'][$vista][$accion]);
    }

    function puede_crear(string $vista, string $modulo = ''): bool    { return puede('crear', $vista, $modulo); }
    function puede_editar(string $vista, string $modulo = ''): bool   { return puede('editar', $vista, $modulo); }
    function puede_eliminar(string $vista, string $modulo = ''): bool { return puede('eliminar', $vista, $modulo); }

    /*----------  Accion que exige cada operacion AJAX  ----------*/

    /**
     * Operaciones que cualquier usuario autenticado puede ejecutar sobre si
     * mismo, con independencia de los permisos de la vista donde viven.
     *
     * CAMBIAR_CLAVE es el caso claro: el formulario esta en la barra
     * superior de todas las pantallas, pero su endpoint pertenece a
     * usuarioAjax (vista userList). Sin esta excepcion, quien no administre
     * usuarios no podria cambiar su propia contrasena.
     */
    function operacion_es_autoservicio(string $operacion): bool
    {
        return in_array(strtoupper($operacion), ['CAMBIAR_CLAVE'], true);
    }

    /**
     * Deduce la accion (ver / crear / editar / eliminar) que exige una
     * operacion AJAX a partir de su nombre.
     *
     * El despacho de los endpoints usa nombres muy regulares
     * (registrar / actualizar / eliminar...), asi que una sola regla
     * central evita tener que instrumentar los controladores uno a uno.
     *
     * Una operacion que no encaje en ningun patron se trata como
     * ESCRITURA: es la opcion prudente, porque dejar pasar una operacion
     * destructiva sin clasificar seria peor que exigir un permiso de mas.
     */
    function accion_de_operacion(string $operacion): string
    {
        $op = strtolower(trim($operacion));

        $patrones = [
            'eliminar' => '^(eliminar|borrar|anular|quitar|remover|desvincular)',
            'ver'      => '^(consultar|buscar|cargar|listar|ver|historial|probar|descargar|estadistica|reporte|obtener|generar_pdf)',
            'crear'    => '^(registrar|crear|agregar|asignar|generar|subir|emitir|importar|analizar|nuevo|asistencia)',
            'editar'   => '^(actualizar|editar|modificar|cambiar|vincular|estado|guardar|descuento|pago|regenerar|descargo|coordenadas|enviar)',
        ];

        foreach ($patrones as $accion => $patron) {
            if (preg_match('~' . $patron . '~', $op)) {
                return $accion;
            }
        }

        return 'editar';
    }

    /**
     * Operacion solicitada en la peticion actual.
     *
     * Los endpoints declaran la operacion en un campo POST cuyo nombre
     * empieza por "modulo_" (modulo_alumno, modulo_pagos...). Se busca asi
     * para no tener que enumerar los 22 endpoints.
     */
    function operacion_de_peticion(): string
    {
        foreach ($_POST as $clave => $valor) {
            if (is_string($valor) && strpos($clave, 'modulo_') === 0) {
                return $valor;
            }
        }

        return '';
    }

    /**
     * Primera vista permitida del modulo, util como destino de aterrizaje.
     * Devuelve '' si el rol no tiene ninguna.
     */
    function primera_vista_permitida(string $modulo = ''): string
    {
        $permisos = permisos_de_la_sesion($modulo);

        foreach ($permisos['permitidas'] as $vista => $acciones) {
            if (!empty($acciones['ver'])) {
                return $vista;
            }
        }

        return '';
    }

    /*----------  Medios con datos personales  ----------*/

    /**
     * Carpetas cuyo contenido son datos personales y por tanto NO pueden
     * servirse directamente por HTTP: fotos de alumnos (menores de edad),
     * cedulas escaneadas, fotos de empleados y comprobantes de pago.
     *
     * Cada clave es el tipo publico que viaja en la URL; el valor es la
     * carpeta real bajo app/views/. Se sirven a traves de app/media.php,
     * que exige sesion activa.
     *
     * El material de marca (iconos, plantillas de carnet, logos de sede,
     * rubricas, fotos de equipos y torneos) NO figura aqui: no contiene
     * datos personales y sigue siendo publico.
     */
    function medios_catalogo(): array
    {
        return [
            'alumno'   => 'imagenes/fotos/alumno/',
            'cedula'   => 'imagenes/cedulas/',
            'empleado' => 'imagenes/fotos/empleado/',
            'usuario'  => 'fotos/usuario/',
            'pago'     => 'imagenes/pagos/',
            'ingreso'  => 'imagenes/ingresos/',
            'fingreso' => 'imagenes/fotos/ingresos/',
            'fegreso'  => 'imagenes/fotos/egresos/',
        ];
    }

    /**
     * URL de un medio protegido.
     *
     * Los medios personales del ecosistema viven hoy en el modulo
     * Basketball, que es quien expone el proxy app/media.php. Por eso se
     * ancla a DS_BASKETBALL_URL y no a APP_URL: asi la funcion devuelve la
     * misma URL tanto si la llama el Hub como si la llama el propio modulo.
     *
     * Cuando Arena o League tengan medios propios, cada uno expondra su
     * proxy y este helper recibira el modulo como parametro.
     *
     * Si el archivo viene vacio o el tipo no existe devuelve la imagen
     * generica, evitando una peticion que igualmente fallaria.
     */
    function media_url(string $tipo, ?string $archivo): string
    {
        $archivo = basename(trim((string)$archivo));

        if ($archivo === '' || !isset(medios_catalogo()[$tipo])) {
            return DS_BASKETBALL_URL . 'app/views/dist/img/foto.jpg';
        }

        return DS_BASKETBALL_URL . 'app/media.php?t=' . rawurlencode($tipo)
                                 . '&f=' . rawurlencode($archivo);
    }
}
