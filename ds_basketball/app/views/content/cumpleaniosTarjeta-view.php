<?php
/**
 * Genera la tarjeta de cumpleaños como imagen JPEG.
 * Ruta: cumpleaniosTarjeta/{alumno_id}/
 *
 * La foto del alumno se coloca en el área interior de la plantilla marco.png.
 * Las proporciones del rectángulo se definen abajo y pueden ajustarse si cambia la plantilla.
 */
	ob_start();
	use app\controllers\cumpleaniosController;

	$insCumple = new cumpleaniosController();

	// Validar ID
	$alumno_id = isset($url[1]) ? (int)$insCumple->limpiarCadena($url[1]) : 0;
	if ($alumno_id <= 0) {
		http_response_code(400);
		exit('ID de alumno no válido.');
	}

	// Obtener datos del alumno
	$datos = $insCumple->infoAlumno($alumno_id);
	if ($datos->rowCount() !== 1) {
		http_response_code(404);
		exit('Alumno no encontrado.');
	}
	$alumno = $datos->fetch();

	// ── Rutas físicas ──────────────────────────────────────────
	$base_path    = realpath(__DIR__ . '/../../..');
	$marco_path   = $base_path . '/app/views/imagenes/cumples/marco.png';
	$foto_dir     = $base_path . '/app/views/imagenes/fotos/alumno/';
	$default_foto = $foto_dir  . 'alumno.png';

	// ── Cargar plantilla ───────────────────────────────────────
	if (!file_exists($marco_path)) {
		http_response_code(500);
		exit('Plantilla no encontrada.');
	}
	$marco = imagecreatefrompng($marco_path);
	if (!$marco) {
		http_response_code(500);
		exit('No se pudo cargar la plantilla.');
	}
	imagesavealpha($marco, true);
	$marco_w = imagesx($marco);
	$marco_h = imagesy($marco);

	// ── Lienzo base (la foto irá aquí, el marco encima) ────────
	$canvas = imagecreatetruecolor($marco_w, $marco_h);
	$bg = imagecolorallocate($canvas, 255, 255, 255);
	imagefilledrectangle($canvas, 0, 0, $marco_w - 1, $marco_h - 1, $bg);

	// ── Proporciones del área interior en marco.png ─────────────
	// Ajustar estos valores si se cambia la plantilla.
	// Coordenadas del área interior de la foto (sin el borde del marco).
	$rect_x = (int)($marco_w * 0.218);   // borde izquierdo del área de foto
	$rect_y = (int)($marco_h * 0.176);   // borde superior del área de foto (sube la imagen)
	$rect_w = (int)($marco_w * 0.558);   // ancho del área de foto
	$rect_h = (int)($marco_h * 0.463);   // alto del área de foto

	// ── Cargar foto del alumno ─────────────────────────────────
	$foto_path = ($alumno['alumno_imagen'] !== '')
		? $foto_dir . $alumno['alumno_imagen']
		: $default_foto;

	if (!file_exists($foto_path)) {
		$foto_path = $default_foto;
	}

	$ext      = strtolower(pathinfo($foto_path, PATHINFO_EXTENSION));
	$foto_src = null;
	if      ($ext === 'jpg' || $ext === 'jpeg') { $foto_src = @imagecreatefromjpeg($foto_path); }
	elseif  ($ext === 'png')                    { $foto_src = @imagecreatefrompng($foto_path);  }
	elseif  ($ext === 'gif')                    { $foto_src = @imagecreatefromgif($foto_path);  }

	if (!$foto_src && file_exists($default_foto)) {
		$foto_src = @imagecreatefrompng($default_foto);
	}

	// ── Encajar la foto en el rectángulo blanco ────────────────
	if ($foto_src) {
		$foto_orig_w = imagesx($foto_src);
		$foto_orig_h = imagesy($foto_src);

		// Centro-recortar la foto para que coincida con la relación de aspecto del rectángulo
		$aspecto_rect = $rect_w / $rect_h;
		$aspecto_foto = $foto_orig_w / $foto_orig_h;

		if ($aspecto_foto > $aspecto_rect) {
			// La foto es más ancha que el rectángulo: recortar laterales
			$src_h = $foto_orig_h;
			$src_w = (int)($foto_orig_h * $aspecto_rect);
			$src_x = (int)(($foto_orig_w - $src_w) / 2);
			$src_y = 0;
		} else {
			// La foto es más alta que el rectángulo: recortar arriba/abajo
			$src_w = $foto_orig_w;
			$src_h = (int)($foto_orig_w / $aspecto_rect);
			$src_x = 0;
			$src_y = (int)(($foto_orig_h - $src_h) / 2);
		}

		// Dibujar la foto en el lienzo base (detrás del marco)
		imagecopyresampled(
			$canvas, $foto_src,
			$rect_x, $rect_y,       // destino: esquina superior-izquierda del rectángulo
			$src_x,  $src_y,        // origen: inicio del recorte
			$rect_w, $rect_h,       // tamaño destino
			$src_w,  $src_h         // tamaño origen (recortado)
		);

		imagedestroy($foto_src);
	}

	// ── Nombre del alumno sobre la franja inferior de la foto ──
	$nombre = trim($alumno['alumno_primernombre'] . ' ' . $alumno['alumno_segundonombre'])
		. ' ' .
		trim($alumno['alumno_apellidopaterno'] . ' ' . $alumno['alumno_apellidomaterno']);
	$edad = (int)$alumno['edad'];

	// Buscar fuente TrueType — primero fuentes incluidas en el proyecto,
	// luego rutas comunes de hosting Linux/Unix, finalmente Windows (desarrollo local).
	$font_paths = [
		// ── Fuentes del proyecto (funciona en cualquier hosting) ──
		$base_path . '/app/views/dist/fonts/OpenSans-Bold.ttf',
		$base_path . '/app/views/dist/fonts/OpenSans-Regular.ttf',
		// ── Linux hosting: DejaVu (instalado en casi todos) ──────
		'/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
		'/usr/share/fonts/dejavu-sans-fonts/DejaVuSans-Bold.ttf',
		'/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
		'/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
		// ── Linux hosting: Liberation (cPanel/CentOS/AlmaLinux) ──
		'/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
		'/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf',
		'/usr/share/fonts/liberation/LiberationSans-Bold.ttf',
		// ── Linux hosting: FreeSans ───────────────────────────────
		'/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
		'/usr/share/fonts/gnu-free/FreeSansBold.ttf',
		// ── Linux hosting: Ubuntu fonts ───────────────────────────
		'/usr/share/fonts/truetype/ubuntu-font-family/Ubuntu-B.ttf',
		'/usr/share/fonts/ubuntu/Ubuntu-B.ttf',
		// ── Windows (desarrollo local) ────────────────────────────
		'C:/Windows/Fonts/arialbd.ttf',
		'C:/Windows/Fonts/arial.ttf',
	];
	$font_file = null;
	foreach ($font_paths as $fp) {
		if (file_exists($fp)) { $font_file = $fp; break; }
	}

	// Ángulo de inclinación del marco en grados (horario).
	// Ajustar si cambia la plantilla.
	$angle_deg = -2.5;
	$tilt      = (int)($rect_w * tan(deg2rad($angle_deg))); // px que baja el lado derecho

	// Franja semitransparente como paralelogramo paralelo al borde del marco
	$franja_h = (int)($rect_h * 0.18);
	$franja_y = $rect_y + $rect_h - $franja_h;

	$color_amarillo = imagecolorallocate($canvas, 255, 224, 1);
	$color_blanco   = imagecolorallocate($canvas, 255, 255, 255);

	// Dibujar paralelogramo semi-opaco sobre un overlay con alpha
	$franja_overlay = imagecreatetruecolor($marco_w, $marco_h);
	imagealphablending($franja_overlay, false);  // directo: sin mezcla al dibujar
	imagesavealpha($franja_overlay, true);
	$clear = imagecolorallocatealpha($franja_overlay, 0, 0, 0, 127);
	imagefilledrectangle($franja_overlay, 0, 0, $marco_w - 1, $marco_h - 1, $clear);
	// alpha 38 ≈ 70% opacidad (escala GD: 0=opaco, 127=transparente)
	// Verde institucional tomado de cumpleaniosList (cumples.css: --azul #054017)
	$franja_color = imagecolorallocatealpha($franja_overlay, 5, 64, 23, 38);
	$poly_points = [
		$rect_x,            $franja_y,                     // top-left
		$rect_x + $rect_w,  $franja_y - $tilt,             // top-right (más bajo, giro horario)
		$rect_x + $rect_w,  $franja_y + $franja_h - $tilt, // bottom-right
		$rect_x,            $franja_y + $franja_h,          // bottom-left
	];
	imagefilledpolygon($franja_overlay, $poly_points, $franja_color);  // sin num_points (PHP 8.1+)
	imagealphablending($canvas, true);
	imagecopy($canvas, $franja_overlay, 0, 0, 0, 0, $marco_w, $marco_h);
	imagedestroy($franja_overlay);

	// Centro vertical de la franja en el punto medio horizontal
	$franja_cy = $franja_y - (int)($tilt / 2) + (int)($franja_h / 2); // tilt negativo = se suma

	// Texto del nombre centrado en la franja (inclinado igual que el marco)
	if ($font_file) {
		$fs_nombre = max(8, (int)($rect_w * 0.055));
		$fs_edad   = max(7, (int)($rect_w * 0.040));

		// Nombre (una línea; reducir fuente si es muy largo)
		$bbox_n = imagettfbbox($fs_nombre, $angle_deg, $font_file, $nombre);
		$tw_n   = abs($bbox_n[4] - $bbox_n[0]);
		while ($tw_n > $rect_w - 20 && $fs_nombre > 7) {
			$fs_nombre--;
			$bbox_n = imagettfbbox($fs_nombre, $angle_deg, $font_file, $nombre);
			$tw_n   = abs($bbox_n[4] - $bbox_n[0]);
		}
		$tx_n = $rect_x + (int)(($rect_w - $tw_n) / 2);
		$ty_n = $franja_cy - (int)($franja_h * 0.18); //Subir nombre dentro de la franja
		imagettftext($canvas, $fs_nombre, $angle_deg, $tx_n, $ty_n, $color_blanco, $font_file, $nombre);

		// Edad justo debajo del nombre
		$texto_edad = $edad . ' AÑOS';
		$bbox_e = imagettfbbox($fs_edad, $angle_deg, $font_file, $texto_edad);
		$tw_e   = abs($bbox_e[4] - $bbox_e[0]);
		$tx_e   = $rect_x + (int)(($rect_w - $tw_e) / 2);
		$ty_e   = $franja_cy + (int)($franja_h * 0.16); //Mover edad dentro de la franja, debajo del nombre
		imagettftext($canvas, $fs_edad, $angle_deg, $tx_e, $ty_e, $color_amarillo, $font_file, $texto_edad);

	} else {
		// Fallback: fuente de mapa de bits (sin inclinación)
		$gd_font = 4;
		$tw = strlen($nombre) * imagefontwidth($gd_font);
		imagestring($canvas, $gd_font,
			$rect_x + (int)(($rect_w - $tw) / 2),
			$franja_cy - (int)($franja_h * 0.20),
			$nombre, $color_blanco);
		$txt_e = $edad . ' ANOS';
		$tw2   = strlen($txt_e) * imagefontwidth(3);
		imagestring($canvas, 3,
			$rect_x + (int)(($rect_w - $tw2) / 2),
			$franja_cy + (int)($franja_h * 0.10),
			$txt_e, $color_amarillo);
	}

	// ── Superponer el marco PNG encima de todo lo anterior ──────
	imagealphablending($canvas, true);
	imagecopy($canvas, $marco, 0, 0, 0, 0, $marco_w, $marco_h);
	imagedestroy($marco);

	// ── Enviar imagen al navegador ─────────────────────────────
	ob_end_clean();
	header('Content-Type: image/jpeg');
	header('Content-Disposition: inline; filename="cumpleanios_' . $alumno_id . '.jpg"');
	header('Cache-Control: max-age=3600');
	imagejpeg($canvas, null, 92);
	imagedestroy($canvas);
	exit;
