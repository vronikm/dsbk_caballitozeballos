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
     * Empleado vinculado al usuario de la sesion, o 0 si no tiene ninguno.
     *
     * Es lo que permite a una pantalla saber "de quien" son los datos: los
     * horarios de un profesor cuelgan de su empleado_id, no de su usuario.
     *
     * Ojo con el nombre de la clave: el login guarda usuario_empleadoid en
     * $_SESSION['usuario_id'], que suena a id de usuario y no lo es. El id
     * del usuario esta en $_SESSION['usuarioid'], sin guion bajo.
     */
    function empleado_actual(): int
    {
        return (int)($_SESSION['usuario_id'] ?? 0);
    }

    /** Id del usuario de la sesion (seguridad_usuario.usuario_id). */
    function usuario_actual_id(): int
    {
        return (int)($_SESSION['usuarioid'] ?? 0);
    }

    /**
     * Como se nombra al usuario en pantalla.
     *
     * Manda el nombre de la persona; si no hay ficha de empleado detras, el
     * nombre de usuario. Nunca un numero.
     *
     * Se comprueba que no sea numerico a proposito: el login guardaba antes
     * el id del rol cuando faltaba el empleado, asi que las sesiones ya
     * abiertas siguen arrastrando ese valor. Descartarlo aqui las arregla
     * sin obligar a nadie a volver a entrar.
     */
    function ds_nombre_usuario(): string
    {
        $nombre = trim((string)($_SESSION['nombre'] ?? ''));

        if ($nombre !== '' && !is_numeric($nombre)) {
            return $nombre;
        }

        return trim((string)($_SESSION['usuario'] ?? ''));
    }

    /*----------  Politica de contrasenas  ----------*/

    /**
     * Longitud minima al FIJAR una contrasena.
     *
     * Solo se exige al establecerla, nunca al iniciar sesion: comprobar el
     * minimo en el login dejaria fuera a quien tenga una clave anterior mas
     * corta, aunque la escriba correctamente.
     */
    function clave_longitud_minima(): int
    {
        return 8;
    }

    /**
     * Limite real de bcrypt: password_hash() ignora en silencio lo que pase
     * de 72 bytes, asi que dos claves largas que difieran solo al final se
     * volverian equivalentes. Mejor rechazarlo que aceptarlo a medias.
     */
    function clave_longitud_maxima(): int
    {
        return 72;
    }

    /**
     * Deshace las sustituciones tipograficas mas usadas.
     *
     * "P@ssw0rd" y "password" son la misma contrasena para quien ataca: los
     * diccionarios prueban las dos. Comparando tambien la version deshecha,
     * una sola entrada de la lista cubre todas sus variantes.
     */
    function clave_sin_disfraz(string $clave): string
    {
        return strtr(strtolower($clave), [
            '@' => 'a', '4' => 'a', '8' => 'b', '(' => 'c', '3' => 'e',
            '6' => 'g', '1' => 'i', '!' => 'i', '|' => 'i', '0' => 'o',
            '$' => 's', '5' => 's', '7' => 't', '+' => 't', '2' => 'z',
        ]);
    }

    /**
     * Las contrasenas mas usadas, en un conjunto listo para consultar.
     *
     * El archivo solo guarda las de 8 caracteres o mas: las mas cortas ya
     * las rechaza la regla de longitud. Se lee una vez por peticion, y solo
     * ocurre al FIJAR una contrasena, que es una operacion rara.
     */
    function claves_comunes(): array
    {
        static $lista = null;
        if ($lista !== null) { return $lista; }

        $lista   = [];
        $archivo = __DIR__ . '/../data/claves-comunes.txt';

        if (is_file($archivo)) {
            foreach (file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
                $l = trim($l);
                if ($l !== '') { $lista[$l] = true; }
            }
        }
        return $lista;
    }

    /**
     * Valida una contrasena que se va a guardar.
     *
     * No se restringen los CARACTERES a proposito. Limitarlos no aporta
     * seguridad —la consulta va con parametros ligados y la comparacion es
     * con password_verify()— y si empobrece las claves. Lo que se mira es
     * si la contrasena es ADIVINABLE, que es lo que de verdad la rompe:
     * con el freno de fuerza bruta se admiten 20 intentos por hora, y a ese
     * ritmo una clave de diccionario cae.
     *
     * Solo se aplica al FIJAR una contrasena. El login no llama a esta
     * funcion, asi que ninguna clave ya en uso deja de funcionar.
     *
     * $usuario permite rechazar la clave que repite el nombre de la cuenta.
     * $motivo recibe el texto que se le puede mostrar al usuario.
     */
    function clave_valida(string $clave, ?string &$motivo = null, string $usuario = ''): bool
    {
        $minimo = clave_longitud_minima();
        $maximo = clave_longitud_maxima();

        if (strlen($clave) < $minimo) {
            $motivo = "La contraseña debe tener al menos {$minimo} caracteres.";
            return false;
        }
        if (strlen($clave) > $maximo) {
            $motivo = "La contraseña no puede superar {$maximo} caracteres.";
            return false;
        }
        if (strpos($clave, "\0") !== false) {
            $motivo = 'La contraseña contiene un carácter no admitido.';
            return false;
        }

        $normal   = strtolower(trim($clave));
        $sinDisfraz = clave_sin_disfraz($clave);
        $comunes  = claves_comunes();

        if (isset($comunes[$normal]) || isset($comunes[$sinDisfraz])) {
            $motivo = 'Esa contraseña aparece en las listas que se usan para atacar '
                    . 'cuentas. Elija otra que no sea una palabra ni una fecha.';
            return false;
        }

        /* La clave no puede ser el propio nombre de usuario ni contenerlo:
           es lo primero que prueba cualquiera. */
        $u = strtolower(trim($usuario));
        if ($u !== '' && strlen($u) >= 4 && str_contains($normal, $u)) {
            $motivo = 'La contraseña no puede contener el nombre de usuario.';
            return false;
        }

        /* Un solo caracter repetido, aunque llegue a los 8. */
        if (preg_match('/^(.)\1+$/u', $normal)) {
            $motivo = 'La contraseña no puede ser un solo carácter repetido.';
            return false;
        }

        /* Secuencias del teclado o del alfabeto: "12345678", "abcdefgh". */
        $seguidos = 1;
        $largo    = strlen($normal);
        for ($i = 1; $i < $largo; $i++) {
            $paso = ord($normal[$i]) - ord($normal[$i - 1]);
            $seguidos = ($paso === 1 || $paso === -1) ? $seguidos + 1 : 1;
            if ($seguidos >= 6) {
                $motivo = 'La contraseña no puede ser una secuencia seguida '
                        . 'como 12345678 o abcdefgh.';
                return false;
            }
        }

        $motivo = '';
        return true;
    }

    /** Texto de ayuda, para que las pantallas no lo escriban cada una. */
    function clave_regla_texto(): string
    {
        return 'Mínimo ' . clave_longitud_minima() . ' caracteres. Se admite cualquier '
             . 'carácter, incluidos espacios y símbolos. No se aceptan contraseñas '
             . 'de uso común, secuencias, ni el propio nombre de usuario.';
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

    /*==================================================================
      Freno a los intentos de acceso
      ==================================================================
      Se midió el login sin freno: 25 intentos fallidos seguidos, ninguno
      rechazado, a unos 500 por minuto sostenidos. Contra una clave débil
      eso son horas, no años.

      El freno es por VENTANA DE TIEMPO y no una marca permanente. Un
      bloqueo que hubiera que levantar a mano sería un arma en manos del
      atacante: bastaría con fallar cinco veces contra la cuenta del
      administrador para dejarlo fuera. Pasada la ventana, se reabre solo.

      Se cuenta por DOS vías porque cada una tapa el hueco de la otra:
        · por usuario, contra quien insiste sobre una cuenta concreta
          desde muchas IP;
        · por IP, contra quien barre muchos usuarios desde un sitio.
    */

    /** Fallos tolerados sobre una misma cuenta antes de frenar. */
    function intentos_max_usuario(): int { return 5; }

    /** Fallos tolerados desde una misma IP, sumando todas las cuentas. */
    function intentos_max_ip(): int { return 20; }

    /** Minutos que dura la ventana y, por tanto, la espera máxima. */
    function intentos_ventana_minutos(): int { return 15; }

    /** Días que se conserva la bitácora antes de borrarse sola. */
    function intentos_dias_retencion(): int { return 90; }

    /**
     * IP de origen en binario, lista para VARBINARY(16).
     *
     * Sólo se mira REMOTE_ADDR. Las cabeceras X-Forwarded-For las pone
     * quien hace la petición y se falsean sin esfuerzo: darles crédito
     * convertiría el freno por IP en un adorno. Si algún día hay un proxy
     * delante, hay que resolver la IP real en el servidor web, no aquí.
     */
    function intentos_ip_binaria(): ?string
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $bin = @inet_pton($ip);
        return $bin === false ? null : $bin;
    }

    /** Deja constancia del intento, haya salido bien o mal. */
    function intentos_registrar(string $usuario, bool $exito): void
    {
        $con = seguridad_conexion();
        if ($con === null) { return; }

        try {
            $st = $con->prepare(
                "INSERT INTO seguridad_intento_acceso
                    (intento_usuario, intento_ip, intento_exito)
                 VALUES (:u, :ip, :e)"
            );
            $st->bindValue(':u',  mb_substr($usuario, 0, 20));
            $st->bindValue(':ip', intentos_ip_binaria(), PDO::PARAM_LOB);
            $st->bindValue(':e',  $exito ? 1 : 0, PDO::PARAM_INT);
            $st->execute();

            intentos_purgar($con);

        } catch (\Throwable $e) {
            /* Que la bitácora falle no puede impedir entrar a quien tiene
               credenciales correctas. */
        }
    }

    /**
     * Borra lo que ya no sirve de la bitácora.
     *
     * Va aquí, de propina en algunos registros, y NO en una tarea
     * programada: el despliegue puede no tener cron, o tenerlo y que nadie
     * se acuerde de darlo de alta, y entonces la tabla crece para siempre.
     * Enganchada al propio login, se mantiene sola esté donde esté.
     *
     * Se lanza una vez de cada 200 aproximadamente. Con un ataque en curso
     * eso son varias limpiezas por hora; en uso normal, uno de cada tantos
     * inicios de sesión paga un DELETE que no borra nada y que el índice
     * por fecha resuelve al instante.
     */
    function intentos_purgar(PDO $con, bool $ahora = false): int
    {
        if (!$ahora && random_int(1, 200) !== 1) { return -1; }

        try {
            $st = $con->prepare(
                "DELETE FROM seguridad_intento_acceso
                  WHERE intento_fecha < NOW() - INTERVAL :d DAY"
            );
            $st->bindValue(':d', intentos_dias_retencion(), PDO::PARAM_INT);
            $st->execute();
            return $st->rowCount();
        } catch (\Throwable $e) {
            return -1;
        }
    }

    /**
     * Retrato de los intentos fallidos recientes.
     *
     * Sirve para avisar: hasta ahora el sistema anotaba el ataque y no se
     * lo contaba a nadie. Devuelve los numeros y, sobre todo, si lo visto
     * merece que alguien lo mire.
     */
    function intentos_resumen(int $horas = 24): array
    {
        $vacio = ['fallos' => 0, 'usuarios' => 0, 'ips' => 0,
                  'inexistentes' => 0, 'horas' => $horas, 'alarma' => false];

        $con = seguridad_conexion();
        if ($con === null) { return $vacio; }

        try {
            $st = $con->prepare(
                "SELECT COUNT(*) fallos,
                        COUNT(DISTINCT intento_usuario) usuarios,
                        COUNT(DISTINCT intento_ip) ips,
                        /* Tanteos contra cuentas que NO existen: es la
                           firma de un barrido, no de alguien que olvido
                           su clave. */
                        COUNT(DISTINCT CASE WHEN u.usuario_id IS NULL
                                            THEN i.intento_usuario END) inexistentes
                   FROM seguridad_intento_acceso i
                   LEFT JOIN seguridad_usuario u ON u.usuario_usuario = i.intento_usuario
                  WHERE i.intento_exito = 0
                    AND i.intento_fecha >= NOW() - INTERVAL :h HOUR"
            );
            $st->bindValue(':h', $horas, PDO::PARAM_INT);
            $st->execute();
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return $vacio;
        }

        $resumen = [
            'fallos'       => (int)($r['fallos'] ?? 0),
            'usuarios'     => (int)($r['usuarios'] ?? 0),
            'ips'          => (int)($r['ips'] ?? 0),
            'inexistentes' => (int)($r['inexistentes'] ?? 0),
            'horas'        => $horas,
        ];

        /* Se avisa cuando el patron no se parece a un despiste. Un usuario
           que se equivoca tres veces no debe generar ninguna alarma; que
           alguien tantee cuentas inexistentes, si. */
        $resumen['alarma'] = $resumen['inexistentes'] >= 3
                          || $resumen['fallos']       >= 30
                          || $resumen['usuarios']     >= 5;

        return $resumen;
    }

    /**
     * ¿Hay que frenar este intento?
     *
     * Devuelve true y, por referencia, los segundos que faltan para que
     * la ventana se abra de nuevo, y por cuál de las dos vías se frenó.
     */
    function intentos_frenado(string $usuario, ?int &$segundos = null, ?string &$motivo = null): bool
    {
        $segundos = 0;
        $motivo   = '';

        $con = seguridad_conexion();
        if ($con === null) { return false; }

        $ventana = intentos_ventana_minutos();

        try {
            /* Sólo cuentan los fallos POSTERIORES al último acierto: quien
               ya entró bien parte de cero, y no arrastra los tropiezos de
               antes de acordarse de su clave. */
            $st = $con->prepare(
                "SELECT COUNT(*) fallos, TIMESTAMPDIFF(SECOND, NOW(), MIN(intento_fecha) + INTERVAL :v2 MINUTE) espera
                   FROM seguridad_intento_acceso
                  WHERE intento_usuario = :u
                    AND intento_exito   = 0
                    AND intento_fecha  >= NOW() - INTERVAL :v1 MINUTE
                    AND intento_fecha  > COALESCE((
                            SELECT MAX(intento_fecha) FROM seguridad_intento_acceso
                             WHERE intento_usuario = :u2 AND intento_exito = 1
                        ), '1000-01-01')"
            );
            $st->execute([':u' => $usuario, ':u2' => $usuario, ':v1' => $ventana, ':v2' => $ventana]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];

            if ((int)($r['fallos'] ?? 0) >= intentos_max_usuario()) {
                $segundos = max(1, (int)$r['espera']);
                $motivo   = 'usuario';
                return true;
            }

            $ip = intentos_ip_binaria();
            if ($ip === null) { return false; }

            $st = $con->prepare(
                "SELECT COUNT(*) fallos, TIMESTAMPDIFF(SECOND, NOW(), MIN(intento_fecha) + INTERVAL :v2 MINUTE) espera
                   FROM seguridad_intento_acceso
                  WHERE intento_ip     = :ip
                    AND intento_exito  = 0
                    AND intento_fecha >= NOW() - INTERVAL :v1 MINUTE"
            );
            $st->bindValue(':ip', $ip, PDO::PARAM_LOB);
            $st->bindValue(':v1', $ventana, PDO::PARAM_INT);
            $st->bindValue(':v2', $ventana, PDO::PARAM_INT);
            $st->execute();
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];

            if ((int)($r['fallos'] ?? 0) >= intentos_max_ip()) {
                $segundos = max(1, (int)$r['espera']);
                $motivo   = 'ip';
                return true;
            }
        } catch (\Throwable $e) {
            /* Ante un fallo de la bitácora no se cierra el paso: se deja
               pasar a la comprobación de contraseña, que es la barrera
               que de verdad importa. */
            return false;
        }

        return false;
    }

    /**
     * Hash de descarte contra el que verificar cuando el usuario no
     * existe.
     *
     * Sin esto, una cuenta inexistente responde en ~9 ms y una real en
     * ~121 ms, porque sólo la segunda llega a ejecutar bcrypt. Esa
     * diferencia, medida desde fuera, revela qué usuarios existen por
     * mucho que el mensaje de error sea el mismo para todos. Verificando
     * siempre contra un hash real, los dos caminos cuestan lo mismo.
     */
    function intentos_hash_senuelo(): string
    {
        /* Hash real de una cadena que nadie va a teclear. Se deja escrito
           en lugar de generarlo en cada petición: password_hash() cuesta
           lo mismo que la propia verificación y duplicaría el tiempo.

           Tiene que ser un bcrypt BIEN FORMADO. Uno inventado a mano pasa
           por password_get_info() como "unknown", y aunque hoy consuma un
           tiempo parecido, no hay ninguna garantía de que siga siendo así. */
        return '$2y$10$7zxSdOMoTt4ztK41elEZg.B0DbZ9I0oBclQ8zaTnFwxdheDegW6p6';
    }
}
