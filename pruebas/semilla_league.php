<?php
/*
|--------------------------------------------------------------------------
| Semilla de datos de prueba para DigiSports League
|--------------------------------------------------------------------------
| League tiene 29 tablas y sólo los catálogos poblados: 0 partidos, 0
| estadísticas, 0 obligaciones, 0 abonos. Sin eso no hay tabla de
| posiciones, ni ingresos por torneo, ni nada que Insights pueda analizar.
|
| Uso:
|     php semilla_league.php            genera
|     php semilla_league.php --limpiar  borra SOLO lo generado aquí
|
|
| EL CALENDARIO CRUZA EL DÍA DE HOY, Y NO POR ADORNO
|
| La liguilla es de ida y vuelta y se reparte de febrero a octubre, de modo
| que la primera vuelta ya se jugó —con resultados y estadísticas, que es lo
| que permite probar series temporales, tabla de posiciones y recaudación— y
| la segunda está por jugarse. Sin partidos por delante, «próximos partidos»
| y «partidos pendientes» darían cero aunque hubiera noventa sembrados.
|
|
| LO MARCADO SE PUEDE BORRAR, LO EXISTENTE NO SE TOCA
|
| Equipos y personas llevan identificación que empieza por 99 —código de
| provincia inexistente en Ecuador, así que no puede coincidir con nadie
| real— y el torneo un slug «qa-». La limpieza se guía por esas marcas y
| deja intacto el «Torneo Rey de copas» que ya estaba.
|
|
| NO SE TOCA dsl_concepto
|
| Seis de los siete conceptos están a 0,00 y ponerles precio es una decisión
| de negocio del usuario, no de una semilla de pruebas. Las obligaciones
| guardan su propio obligacion_valor, así que se les da importe explícito
| sin alterar la configuración real del sistema.
|
|
| RESPETA LA MÁQUINA DE ESTADOS
|
| Los estados salen de dsl_estado, no de literales inventados: FINALIZADO
| para lo jugado, PROGRAMADO y CONFIRMADO para lo que viene, y algún
| SUSPENDIDO y WALKOVER, que existen y conviene que aparezcan en los
| informes.
*/

require_once __DIR__ . '/conexion.php';

const CEDULA_BASE = '99';
const SLUG        = 'qa-copa-apertura';

$db = qa_conexion();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/*==============  Limpieza  ==============*/

function limpiar(PDO $db): array
{
    $b = [];
    $marca = CEDULA_BASE . '%';

    /* De las hojas hacia la raíz. */
    $b['dsl_partido_stat'] = $db->exec(
        "DELETE s FROM dsl_partido_stat s
           JOIN dsl_persona p ON p.persona_id = s.stat_personaid
          WHERE p.persona_identificacion LIKE '$marca'");

    $b['dsl_abono'] = $db->exec(
        "DELETE a FROM dsl_abono a
           JOIN dsl_obligacion o ON o.obligacion_id = a.abono_obligacionid
           JOIN dsl_equipo     e ON e.equipo_id     = o.obligacion_equipoid
          WHERE e.equipo_identificacion LIKE '$marca'");

    $b['dsl_obligacion'] = $db->exec(
        "DELETE o FROM dsl_obligacion o
           JOIN dsl_equipo e ON e.equipo_id = o.obligacion_equipoid
          WHERE e.equipo_identificacion LIKE '$marca'");

    $b['dsl_partido'] = $db->exec(
        "DELETE pa FROM dsl_partido pa
           JOIN dsl_inscripcion i ON i.inscripcion_id = pa.partido_localid
           JOIN dsl_equipo      e ON e.equipo_id      = i.inscripcion_equipoid
          WHERE e.equipo_identificacion LIKE '$marca'");

    $b['dsl_plantilla'] = $db->exec(
        "DELETE pl FROM dsl_plantilla pl
           JOIN dsl_persona p ON p.persona_id = pl.plantilla_personaid
          WHERE p.persona_identificacion LIKE '$marca'");

    $b['dsl_grupo_equipo'] = $db->exec(
        "DELETE ge FROM dsl_grupo_equipo ge
           JOIN dsl_inscripcion i ON i.inscripcion_id = ge.ge_inscripcionid
           JOIN dsl_equipo      e ON e.equipo_id      = i.inscripcion_equipoid
          WHERE e.equipo_identificacion LIKE '$marca'");

    $b['dsl_inscripcion'] = $db->exec(
        "DELETE i FROM dsl_inscripcion i
           JOIN dsl_equipo e ON e.equipo_id = i.inscripcion_equipoid
          WHERE e.equipo_identificacion LIKE '$marca'");

    $b['dsl_persona'] = $db->exec("DELETE FROM dsl_persona WHERE persona_identificacion LIKE '$marca'");
    $b['dsl_equipo']  = $db->exec("DELETE FROM dsl_equipo  WHERE equipo_identificacion  LIKE '$marca'");

    /* Jornadas, grupos, fases, categorías y torneo del torneo sembrado. */
    $torneo = $db->query("SELECT torneo_id FROM dsl_torneo WHERE torneo_slug = '" . SLUG . "'")->fetchColumn();
    if ($torneo) {
        $t = (int) $torneo;
        $b['dsl_jornada'] = $db->exec(
            "DELETE j FROM dsl_jornada j JOIN dsl_fase f ON f.fase_id = j.jornada_faseid
               JOIN dsl_categoria c ON c.categoria_id = f.fase_categoriaid
              WHERE c.categoria_torneoid = $t");
        $b['dsl_grupo'] = $db->exec(
            "DELETE g FROM dsl_grupo g JOIN dsl_fase f ON f.fase_id = g.grupo_faseid
               JOIN dsl_categoria c ON c.categoria_id = f.fase_categoriaid
              WHERE c.categoria_torneoid = $t");
        $b['dsl_fase'] = $db->exec(
            "DELETE f FROM dsl_fase f JOIN dsl_categoria c ON c.categoria_id = f.fase_categoriaid
              WHERE c.categoria_torneoid = $t");
        $b['dsl_categoria'] = $db->exec("DELETE FROM dsl_categoria WHERE categoria_torneoid = $t");
        $temp = $db->query("SELECT torneo_temporadaid FROM dsl_torneo WHERE torneo_id = $t")->fetchColumn();
        $b['dsl_torneo'] = $db->exec("DELETE FROM dsl_torneo WHERE torneo_id = $t");
        if ($temp) {
            $b['dsl_temporada'] = $db->exec(
                "DELETE FROM dsl_temporada WHERE temporada_id = " . (int) $temp .
                " AND temporada_nombre LIKE 'Temporada QA%'");
        }
    }
    return $b;
}

if (in_array('--limpiar', $argv, true)) {
    foreach (limpiar($db) as $t => $n) { printf("  %-20s %5d borrados\n", $t, $n); }
    exit(0);
}

limpiar($db);
mt_srand(20260828);

/* Estados, leídos del catálogo. Nunca literales. */
$est = [];
foreach ($db->query("SELECT estado_id, estado_entidad, estado_codigo FROM dsl_estado") as $e) {
    $est[$e['estado_entidad']][$e['estado_codigo']] = (int) $e['estado_id'];
}

/*==============  Temporada y torneo pasados  ==============*/
$db->prepare("INSERT INTO dsl_temporada (temporada_escuelaid, temporada_nombre,
                temporada_desde, temporada_hasta, temporada_estado, temporada_usuarioid)
              VALUES (0, 'Temporada QA 2026', '2026-01-15', '2026-08-31', 'A', 1)")->execute();
$temporada = (int) $db->lastInsertId();

$db->prepare("INSERT INTO dsl_torneo (torneo_temporadaid, torneo_nombre, torneo_deporte,
                torneo_sedeid, torneo_desde, torneo_hasta, torneo_estado, torneo_publico,
                torneo_slug, torneo_usuarioid)
              VALUES (:t, 'Copa QA Apertura', 'baloncesto', 1, '2026-02-01', '2026-08-15',
                      'A', 'S', :slug, 1)")->execute([':t' => $temporada, ':slug' => SLUG]);
$torneo = (int) $db->lastInsertId();

/*==============  Categorías, fases y grupos  ==============*/
$sqlCat = $db->prepare(
    "INSERT INTO dsl_categoria (categoria_torneoid, categoria_nombre, categoria_genero,
        categoria_edadmin, categoria_edadmax, categoria_fechacorte, categoria_estado)
     VALUES (:t, :n, :g, :emin, :emax, '2026-01-01', 'A')");
$sqlFase = $db->prepare(
    "INSERT INTO dsl_fase (fase_categoriaid, fase_orden, fase_nombre, fase_tipo,
        fase_idavuelta, fase_clasifican, fase_estado)
     VALUES (:c, 1, 'Fase de grupos', 'G', 'N', 4, 'A')");
$sqlGrupo = $db->prepare(
    "INSERT INTO dsl_grupo (grupo_faseid, grupo_nombre, grupo_orden) VALUES (:f, 'Grupo único', 1)");

$categorias = [
    ['n' => 'Sub 14 Masculino', 'g' => 'M', 'emin' => 12, 'emax' => 14],
    ['n' => 'Sub 16 Masculino', 'g' => 'M', 'emin' => 15, 'emax' => 16],
    ['n' => 'Sub 16 Femenino',  'g' => 'F', 'emin' => 15, 'emax' => 16],
];
$estructura = [];
foreach ($categorias as $c) {
    $sqlCat->execute([':t' => $torneo, ':n' => $c['n'], ':g' => $c['g'],
                      ':emin' => $c['emin'], ':emax' => $c['emax']]);
    $cid = (int) $db->lastInsertId();
    $sqlFase->execute([':c' => $cid]);
    $fid = (int) $db->lastInsertId();
    $sqlGrupo->execute([':f' => $fid]);
    $estructura[] = ['cat' => $cid, 'fase' => $fid, 'grupo' => (int) $db->lastInsertId(),
                     'nombre' => $c['n'], 'genero' => $c['g'], 'edad' => $c['emax']];
}

/*==============  Equipos, inscripciones y plantillas  ==============*/
$clubes = ['Cóndores', 'Jaguares', 'Halcones', 'Titanes', 'Pumas', 'Lobos',
           'Águilas', 'Delfines', 'Tigres', 'Ciclones', 'Centauros', 'Dragones'];
$nombresM = ['Adrián','Bruno','Carlos','Diego','Emilio','Fabio','Gonzalo','Héctor',
             'Iván','Joaquín','Kevin','Leonardo','Mateo','Nicolás','Óscar','Pedro'];
$nombresF = ['Ana','Bianca','Camila','Daniela','Emilia','Fernanda','Gabriela','Helena',
             'Isabel','Julia','Karla','Lucía','Micaela','Noelia','Paula','Renata'];
$apellidos = ['Andrade','Bermeo','Carrión','Dávila','Espinosa','Fierro','Guamán','Hidalgo',
              'Iñiguez','Jaramillo','Loaiza','Molina','Naranjo','Ochoa','Paredes','Quezada'];

$sqlEq = $db->prepare(
    "INSERT INTO dsl_equipo (equipo_escuelaid, equipo_nombre, equipo_corto, equipo_idtipo,
        equipo_identificacion, equipo_razonsocial, equipo_direccion, equipo_sedeid,
        equipo_contacto, equipo_telefono, equipo_email, equipo_estado)
     VALUES (0, :n, :c, '04', :ident, :razon, :dir, 1, :cont, :tel, :mail, 'A')");
$sqlPer = $db->prepare(
    "INSERT INTO dsl_persona (persona_tipoid, persona_identificacion, persona_nombres,
        persona_apellidos, persona_fechanac, persona_genero, persona_nacionalidad,
        persona_publicarfoto, persona_estado)
     VALUES ('CED', :ident, :nom, :ape, :nac, :gen, 'ECU', 'N', 'A')");
$sqlIns = $db->prepare(
    "INSERT INTO dsl_inscripcion (inscripcion_equipoid, inscripcion_categoriaid,
        inscripcion_estadoid, inscripcion_fecha,
        inscripcion_valor, inscripcion_descuento, inscripcion_recargo,
        inscripcion_observacion, inscripcion_usuarioid)
     VALUES (:e, :c, :est, :f, 150.00, 0.00, 0.00, 'Semilla de prueba', 1)");
$sqlGE = $db->prepare(
    "INSERT INTO dsl_grupo_equipo (ge_grupoid, ge_inscripcionid, ge_orden) VALUES (:g, :i, :o)");
$sqlPl = $db->prepare(
    "INSERT INTO dsl_plantilla (plantilla_inscripcionid, plantilla_personaid, plantilla_rol,
        plantilla_dorsal, plantilla_alta, plantilla_habilitado, plantilla_motivo, plantilla_usuarioid)
     VALUES (:i, :p, :rol, :d, :alta, :hab, :mot, 1)");

$ced = 500000;
$nEq = 0; $nPer = 0; $nPl = 0;
$inscripciones = [];

foreach ($estructura as $ei => $e) {
    $porCat = 6;                                   /* liguilla de 6 → 15 partidos */
    for ($k = 0; $k < $porCat; $k++) {
        $nombreClub = 'Club ' . $clubes[($ei * $porCat + $k) % count($clubes)] . ' QA';
        $ced++;
        $identEq = CEDULA_BASE . str_pad((string) $ced, 8, '0', STR_PAD_LEFT);
        $sqlEq->execute([
            ':n' => $nombreClub . ' ' . $e['edad'],
            ':c' => strtoupper(substr($clubes[($ei * $porCat + $k) % count($clubes)], 0, 3)) . $e['edad'],
            ':ident' => $identEq,
            ':razon' => $nombreClub . ' (dato de prueba)',
            ':dir'   => 'Dirección de prueba ' . ($k + 1),
            ':cont'  => 'Contacto de prueba',
            ':tel'   => '09' . str_pad((string) (20000000 + $ced), 8, '0', STR_PAD_LEFT),
            ':mail'  => 'club' . $ced . '@example.com',
        ]);
        $eqid = (int) $db->lastInsertId();
        $nEq++;

        /* Casi todas habilitadas; una retirada por categoría, para que los
           informes tengan que distinguir estados y no todos sean iguales. */
        $estadoIns = ($k === $porCat - 1 && $ei === 0)
            ? $est['inscripcion']['RETIRADA']
            : $est['inscripcion']['HABILITADA'];

        $sqlIns->execute([':e' => $eqid, ':c' => $e['cat'], ':est' => $estadoIns,
                          ':f' => '2026-01-2' . (($k % 8) + 1)]);
        $insid = (int) $db->lastInsertId();
        $sqlGE->execute([':g' => $e['grupo'], ':i' => $insid, ':o' => $k + 1]);

        /* Plantilla: 10 jugadores y 1 entrenador. */
        $jugadores = [];
        for ($j = 0; $j < 11; $j++) {
            $ced++;
            $pila = $e['genero'] === 'F' ? $nombresF : $nombresM;
            $anio = 2026 - $e['edad'] - ($j % 2);
            $sqlPer->execute([
                ':ident' => CEDULA_BASE . str_pad((string) $ced, 8, '0', STR_PAD_LEFT),
                ':nom'   => $pila[$j % count($pila)],
                ':ape'   => $apellidos[($j + $k) % count($apellidos)] . ' ' . $apellidos[($j + 3) % count($apellidos)],
                ':nac'   => sprintf('%04d-%02d-%02d', $anio, mt_rand(1, 12), mt_rand(1, 28)),
                ':gen'   => $e['genero'],
            ]);
            $pid = (int) $db->lastInsertId();
            $nPer++;
            $esJugador = $j < 10;
            $sqlPl->execute([
                ':i' => $insid, ':p' => $pid, ':rol' => $esJugador ? 'J' : 'E',
                ':d' => $esJugador ? ($j + 4) : null, ':alta' => '2026-01-25',
                ':hab' => $estadoIns === $est['inscripcion']['HABILITADA'] ? 'S' : 'N',
                ':mot' => 'Semilla de prueba',
            ]);
            $nPl++;
            if ($esJugador) { $jugadores[] = $pid; }
        }

        $inscripciones[$ei][] = ['id' => $insid, 'equipo' => $eqid, 'nombre' => $nombreClub,
                                 'jugadores' => $jugadores, 'activa' => $estadoIns === $est['inscripcion']['HABILITADA']];
    }
}

/*==============  Jornadas y partidos  ==============*/
$sqlJor = $db->prepare(
    "INSERT INTO dsl_jornada (jornada_faseid, jornada_numero, jornada_nombre, jornada_desde, jornada_hasta)
     VALUES (:f, :n, :nom, :d, :h)");
$sqlPar = $db->prepare(
    "INSERT INTO dsl_partido (partido_faseid, partido_grupoid, partido_jornadaid,
        partido_localid, partido_visitanteid, partido_instalacionid, partido_fecha,
        partido_hora, partido_duracion, partido_estadoid,
        partido_puntoslocal, partido_puntosvisitante, partido_observacion,
        partido_motivo, partido_usuarioid)
     VALUES (:f, :g, :j, :l, :v, :ins, :fecha, :hora, 60, :est, :pl, :pv,
             'Semilla de prueba', '', 1)");
$sqlStat = $db->prepare(
    "INSERT INTO dsl_partido_stat (stat_partidoid, stat_personaid, stat_inscripcionid, stat_tipoid, stat_valor)
     VALUES (:p, :per, :i, :t, :v)");

/* Instalaciones sembradas por semilla_arena, si están. El escenario es
   opcional en el esquema, así que si no hay Arena el partido va sin él. */
$escenarios = $db->query(
    "SELECT instalacion_id FROM dsa_instalacion
      WHERE instalacion_clase = 'C' AND instalacion_codigo LIKE 'QA-%'")->fetchAll(PDO::FETCH_COLUMN);

$tipoPTS = (int) $db->query("SELECT tipo_id FROM dsl_estadistica_tipo WHERE tipo_codigo='PTS'")->fetchColumn();
$tipoREB = (int) $db->query("SELECT tipo_id FROM dsl_estadistica_tipo WHERE tipo_codigo='REB'")->fetchColumn();
$tipoAST = (int) $db->query("SELECT tipo_id FROM dsl_estadistica_tipo WHERE tipo_codigo='AST'")->fetchColumn();
$tipoFAL = (int) $db->query("SELECT tipo_id FROM dsl_estadistica_tipo WHERE tipo_codigo='FAL'")->fetchColumn();

$nPar = 0; $nStat = 0; $nJor = 0;

foreach ($estructura as $ei => $e) {
    $eq = $inscripciones[$ei];
    $n  = count($eq);
    /* Liguilla completa: todos contra todos, una vuelta. */
    $cruces = [];
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) { $cruces[] = [$i, $j]; }
    }
    /* Vuelta: los mismos cruces con los papeles cambiados. Duplica las
       jornadas y es lo que hace que el calendario llegue al futuro. */
    foreach (array_reverse($cruces) as [$i, $j]) { $cruces[] = [$j, $i]; }

    $fecha = new DateTimeImmutable('2026-02-07');
    $jornada = 0;
    foreach (array_chunk($cruces, 3) as $bloque) {
        $jornada++;
        $sqlJor->execute([':f' => $e['fase'], ':n' => $jornada,
                          ':nom' => 'Jornada ' . $jornada,
                          ':d' => $fecha->format('Y-m-d'),
                          ':h' => $fecha->modify('+1 day')->format('Y-m-d')]);
        $jid = (int) $db->lastInsertId();
        $nJor++;

        $hora = 9;
        foreach ($bloque as [$a, $b]) {
            $pasado = $fecha < new DateTimeImmutable('today');

            if (!$pasado) {
                $estado = mt_rand(1, 100) <= 50 ? $est['partido']['PROGRAMADO'] : $est['partido']['CONFIRMADO'];
                $pl = $pv = null;
            } elseif (mt_rand(1, 100) <= 4) {
                $estado = $est['partido']['SUSPENDIDO'];  $pl = $pv = null;
            } elseif (mt_rand(1, 100) <= 3) {
                $estado = $est['partido']['WALKOVER'];    $pl = 20; $pv = 0;
            } else {
                $estado = $est['partido']['FINALIZADO'];
                $pl = mt_rand(38, 92);
                $pv = mt_rand(38, 92);
                if ($pl === $pv) { $pl += 2; }            /* el baloncesto no empata */
            }

            $sqlPar->execute([
                ':f' => $e['fase'], ':g' => $e['grupo'], ':j' => $jid,
                ':l' => $eq[$a]['id'], ':v' => $eq[$b]['id'],
                ':ins' => $escenarios ? $escenarios[mt_rand(0, count($escenarios) - 1)] : null,
                ':fecha' => $fecha->format('Y-m-d'),
                ':hora' => sprintf('%02d:00:00', $hora),
                ':est' => $estado, ':pl' => $pl, ':pv' => $pv,
            ]);
            $pid = (int) $db->lastInsertId();
            $nPar++;
            $hora += 2;

            /* Estadísticas sólo de lo jugado, y repartiendo los puntos del
               marcador entre los jugadores: si no cuadraran, cualquier
               informe que los sume daría una cifra distinta al resultado. */
            if ($estado === $est['partido']['FINALIZADO']) {
                foreach ([[$eq[$a], $pl], [$eq[$b], $pv]] as [$equipo, $puntos]) {
                    $enCancha = array_slice($equipo['jugadores'], 0, 8);
                    $resto = $puntos;
                    foreach ($enCancha as $idx => $per) {
                        $ultimo = $idx === count($enCancha) - 1;
                        $p = $ultimo ? $resto : min($resto, mt_rand(0, (int) ceil($puntos / 4)));
                        $resto -= $p;
                        $sqlStat->execute([':p' => $pid, ':per' => $per, ':i' => $equipo['id'], ':t' => $tipoPTS, ':v' => $p]);
                        $sqlStat->execute([':p' => $pid, ':per' => $per, ':i' => $equipo['id'], ':t' => $tipoREB, ':v' => mt_rand(0, 12)]);
                        $sqlStat->execute([':p' => $pid, ':per' => $per, ':i' => $equipo['id'], ':t' => $tipoAST, ':v' => mt_rand(0, 8)]);
                        $sqlStat->execute([':p' => $pid, ':per' => $per, ':i' => $equipo['id'], ':t' => $tipoFAL, ':v' => mt_rand(0, 5)]);
                        $nStat += 4;
                        if ($resto <= 0) { break; }
                    }
                }
            }
        }
        $fecha = $fecha->modify('+4 weeks');
    }
}

/*==============  Obligaciones y abonos  ==============*/
/*
| Importe explícito en la obligación: no se altera dsl_concepto, que es
| configuración real del usuario.
*/
$conceptoInsc = (int) $db->query("SELECT concepto_id FROM dsl_concepto WHERE concepto_codigo='INSC_EQUIPO'")->fetchColumn();
$conceptoMulta = (int) $db->query("SELECT concepto_id FROM dsl_concepto WHERE concepto_codigo='MULTA'")->fetchColumn();

$sqlObl = $db->prepare(
    "INSERT INTO dsl_obligacion (obligacion_conceptoid, obligacion_origentipo, obligacion_origenid,
        obligacion_categoriaid, obligacion_equipoid, obligacion_deudor, obligacion_detalle,
        obligacion_fecha, obligacion_vence, obligacion_valor, obligacion_descuento,
        obligacion_recargo, obligacion_estado, obligacion_usuarioid)
     VALUES (:c, 'INSCRIPCION', :orig, :cat, :eq, :deudor, :det, :f, :vence, :val, 0.00, 0.00, :est, 1)");
$sqlAb = $db->prepare(
    "INSERT INTO dsl_abono (abono_obligacionid, abono_fecha, abono_valor, abono_forma,
        abono_referencia, abono_observacion, abono_anulado, abono_motivoanula, abono_usuarioid)
     VALUES (:o, :f, :v, '01', '', 'Semilla de prueba', 'N', '', 1)");

$nObl = 0; $nAb = 0; $cobrado = 0.0; $pendiente = 0.0;
foreach ($estructura as $ei => $e) {
    foreach ($inscripciones[$ei] as $ins) {
        $valor = 150.00;
        /* Tres cuartas partes pagadas del todo, el resto a medias o nada:
           sin variedad no habría cartera que analizar. */
        $suerte = mt_rand(1, 100);
        $pagado = $suerte <= 70 ? $valor : ($suerte <= 90 ? round($valor / 2, 2) : 0.00);
        $estado = $pagado >= $valor ? 'PAGADA' : ($pagado > 0 ? 'PARCIAL' : 'PENDIENTE');

        $sqlObl->execute([
            ':c' => $conceptoInsc, ':orig' => $ins['id'], ':cat' => $e['cat'], ':eq' => $ins['equipo'],
            ':deudor' => $ins['nombre'], ':det' => 'Inscripción ' . $e['nombre'],
            ':f' => '2026-01-25', ':vence' => '2026-02-15', ':val' => $valor, ':est' => $estado,
        ]);
        $oid = (int) $db->lastInsertId();
        $nObl++;
        $pendiente += ($valor - $pagado);

        if ($pagado > 0) {
            $sqlAb->execute([':o' => $oid, ':f' => '2026-02-0' . mt_rand(1, 9), ':v' => $pagado]);
            $nAb++;
            $cobrado += $pagado;
        }

        /* Alguna multa suelta, para que no todo el dinero venga del mismo concepto. */
        if (mt_rand(1, 100) <= 15) {
            $multa = 25.00;
            $sqlObl->execute([
                ':c' => $conceptoMulta, ':orig' => $ins['id'], ':cat' => $e['cat'], ':eq' => $ins['equipo'],
                ':deudor' => $ins['nombre'], ':det' => 'Multa por conducta antideportiva',
                ':f' => '2026-04-10', ':vence' => '2026-04-30', ':val' => $multa, ':est' => 'PENDIENTE',
            ]);
            $nObl++;
            $pendiente += $multa;
        }
    }
}

/*==============  Resumen  ==============*/
printf("  torneo «Copa QA Apertura» · %d categorías · %d equipos · %d personas · %d fichas\n",
    count($estructura), $nEq, $nPer, $nPl);
printf("  jornadas %2d · partidos %3d · estadísticas %4d\n", $nJor, $nPar, $nStat);
printf("  obligaciones %2d · abonos %2d · cobrado %.2f · pendiente %.2f\n",
    $nObl, $nAb, $cobrado, $pendiente);

foreach ($db->query(
    "SELECT e.estado_codigo c, COUNT(*) n FROM dsl_partido p
       JOIN dsl_estado e ON e.estado_id = p.partido_estadoid
      GROUP BY e.estado_codigo ORDER BY n DESC") as $f) {
    printf("     %-14s %3d partidos\n", $f['c'], $f['n']);
}
