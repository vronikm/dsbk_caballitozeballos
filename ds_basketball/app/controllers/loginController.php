<?php

	namespace app\controllers;
	use app\models\mainModel;
	
	class loginController extends mainModel{

		/*----------  Controlador iniciar sesion  ----------*/
		/**
		 * Inicia sesión del usuario.
		 *  - Valida formato de usuario y contraseña.
		 *  - Usa consulta preparada (evita SQL-Injection).
		 *  - Verifica estado y bloqueo.
		 *  - Regenera el ID de sesión.
		 *  - Redirige según el rol.
		 */
		public function iniciarSesionControlador(): void
		{
			// La sesión y los parámetros de su cookie (HttpOnly, SameSite=Lax,
			// Secure sólo bajo HTTPS) se establecen de forma centralizada en
			// app/views/inc/session_start.php, que ya se cargó desde index.php.
			// El require_once lo deja explícito y es idempotente. No duplicar
			// aquí la configuración de la cookie.
			require_once __DIR__ . "/../views/inc/session_start.php";

			/* ----------  1. Validación básica de entrada  ---------- */
			$usuario = $_POST['login_usuario'] ?? '';
			$clave   = $_POST['login_clave']   ?? '';

			if ($usuario === '' || $clave === '') {
				$this->showError('Debes rellenar todos los campos.');
			}

			if (!preg_match('/^[a-zA-Z0-9]{4,20}$/', $usuario)) {
				$this->showError('El usuario no cumple el formato solicitado.');
			}

			/*
			| La contrasena NO se valida por formato al entrar.
			|
			| Antes se exigia ^[a-zA-Z0-9$@.\-]{7,100}$, lo que provocaba dos
			| problemas: rechazaba claves perfectamente validas por llevar un
			| simbolo, y no coincidia con lo que aceptaba Core al crearlas, de
			| modo que se podia fijar una contrasena con la que despues era
			| imposible entrar.
			|
			| Filtrar aqui tampoco protege de nada: la consulta va con
			| parametros ligados y la comprobacion es con password_verify().
			| Solo se acota el tamano para no procesar entradas absurdas.
			*/
			if (strlen($clave) > 200) {
				$this->showError('Usuario o contraseña incorrectos.');
			}

			/* ----------  1 bis. Freno a la fuerza bruta  ---------- */
			/* Sin esto se midieron 25 intentos fallidos seguidos sin una
			   sola negativa, a unos 500 por minuto. El freno mira los
			   fallos recientes ANTES de comprobar la clave, para que el
			   coste de bcrypt tampoco se pueda usar como ariete. */
			$espera = 0; $porQue = '';
			if (intentos_frenado($usuario, $espera, $porQue)) {
				intentos_registrar($usuario, false);

				$minutos = (int)ceil($espera / 60);
				$this->showError(
					'Demasiados intentos fallidos. Vuelva a probar en '
					. ($minutos > 1 ? "$minutos minutos." : 'un minuto.')
				);
			}

			/* ----------  2. Consulta preparada  ---------- */
			try {
				$sql = "
					SELECT  usuario_empleadoid,
							empleado_identificacion,
							empleado_nombre,
							empleado_correo,
							empleado_celular,
							empleado_foto,
							sede_nombre AS sede,
							usuario_estado,
							usuario_tienebloqueo,
							usuario_usuario,
							usuario_rolid,
							usuario_clave,
							usuario_id
					FROM    seguridad_usuario
					LEFT    JOIN sujeto_empleado ON empleado_id   = usuario_empleadoid
					LEFT    JOIN general_sede    ON empleado_sedeid = sede_id
					WHERE   usuario_usuario = :usuario
					LIMIT   1";

				$stmt = $this->ejecutarConsulta($sql, ['usuario' => $usuario]);

				if ($stmt->rowCount() !== 1) {
					/* El mensaje genérico no bastaba: sin llegar a bcrypt,
					   una cuenta inexistente respondía en ~9 ms frente a
					   los ~121 ms de una real, y esa diferencia se mide
					   desde fuera. Verificando contra un hash señuelo, los
					   dos caminos cuestan lo mismo. */
					password_verify($clave, intentos_hash_senuelo());
					intentos_registrar($usuario, false);
					$this->showError('Usuario o contraseña incorrectos.');
				}

				$user = $stmt->fetch();

			} catch (\Throwable $e) {
				/* Aquí podrías loguear $e->getMessage() */
				$this->showError('Ocurrió un error inesperado. Inténtalo de nuevo.');
			}

			/* ----------  3. Comprobaciones de estado ---------- */
			/* La clave se comprueba ANTES que el estado. Al revés, "usuario
			   inactivo" se obtenía sin acertar la contraseña y volvía a
			   delatar qué cuentas existen: justo lo que se acaba de cerrar
			   por el lado del tiempo. */
			if (!password_verify($clave, $user['usuario_clave'])) {
				intentos_registrar($usuario, false);
				$this->showError('Usuario o contraseña incorrectos.');
			}
			if ($user['usuario_estado'] === 'I') {
				intentos_registrar($usuario, false);
				$this->showError('Usuario inactivo. Contacte al administrador.');
			}
			if ($user['usuario_tienebloqueo'] === 'S') {
				intentos_registrar($usuario, false);
				$this->showError('Usuario bloqueado. Contacte al administrador.');
			}

			/* ----------  4. Login correcto ---------- */
			/* Queda anotado: además de servir de auditoría, el acierto
			   pone a cero el contador de fallos de esta cuenta. */
			intentos_registrar($usuario, true);

			session_regenerate_id(true);        // Previene Session Fixation

			$_SESSION = [
				'usuario'        => $user['usuario_usuario'],
				'usuarioid'      => $user['usuario_id'],
				'rol'            => (int)$user['usuario_rolid'],
				'foto'           => $user['empleado_foto'],
				'sede'           => $user['sede'],
				'identificacion' => $user['empleado_identificacion'],
				'usuario_id'     => $user['usuario_empleadoid'],
				/* Sin ficha de empleado se cae al nombre de usuario, NO al id
				   del rol: eso era lo que hacia antes y por eso la cabecera
				   saludaba con un "1" a quien no tuviera empleado asociado. */
				'nombre'         => $user['empleado_nombre'] ?: $user['usuario_usuario'],
			];

			/* ----------  5. Redirección ---------- */
			/* Todo usuario aterriza en el Hub del ecosistema, que vive en la
			   raíz DigiSports. Desde allí elige el módulo; cada tarjeta ya
			   resuelve su propia vista de entrada según los permisos del rol
			   (ver ds_core/hub/hubController.php). */
			$this->redirect(DS_HUB_URL);
		}

		/* ========== Helpers ========== */

		/**
		 * Guarda el aviso y vuelve al formulario.
		 *
		 * Antes imprimía el <script> aquí mismo. Ahora el controlador corre
		 * ANTES de la página, así que no hay ni Swal cargado ni sitio donde
		 * escribir: el mensaje se deja en la sesión y lo pinta la vista.
		 *
		 * De paso se gana el patrón POST-Redirect-GET: al recargar no se
		 * reenvía el formulario con la contraseña dentro.
		 */
		private function showError(string $mensaje): void
		{
			$_SESSION['login_aviso'] = $mensaje;
			$this->redirect(APP_URL . 'login/');
		}

		/** Redirige de forma segura, compatible con cabeceras ya enviadas. */
		private function redirect(string $url): void
		{
			if (headers_sent()) {
				echo "<script>window.location.href='{$url}';</script>";
				echo "<noscript><meta http-equiv='refresh' content='0;url={$url}'></noscript>";
			} else {
				header("Location: {$url}");
			}
			exit;
		}



		/*----------  Controlador cerrar sesion  ----------*/
		public function cerrarSesionControlador() {
			// Inicia la sesión si aún no está activa
			if (session_status() === PHP_SESSION_NONE) {
				session_start();
			}

			// Limpia las variables de sesión
			session_unset();

			// Destruye la sesión
			session_destroy();

			// Asegura que no quede nada en memoria
			$_SESSION = [];    

			// Borra manualmente la cookie de sesión
			if (ini_get("session.use_cookies")) {
				$params = session_get_cookie_params();
				setcookie(session_name(), '', time() - 42000,
					$params["path"], $params["domain"],
					$params["secure"], $params["httponly"]
				);
			}

			// Redirige al login
			$urlLogin = APP_URL . "login/";

			if (headers_sent()) {
				echo "<script>window.location.href='" . $urlLogin . "';</script>";
				echo "<noscript><meta http-equiv='refresh' content='0;url=$urlLogin'></noscript>";
			} else {
				header("Location: $urlLogin");
			}

			exit();
		}


	}