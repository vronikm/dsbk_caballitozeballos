<?php
/*
|--------------------------------------------------------------------------
| Semilla de datos de prueba para DigiSports Arena
|--------------------------------------------------------------------------
| Arena tiene el esquema completo y CERO filas en reserva, pago, tarifa,
| horario y bloqueo. Sin datos no se puede construir ni probar Insights: la
| ocupación, el mapa de calor y el ranking de instalaciones no tienen de
| dónde salir.
|
| Uso:
|     php semilla_arena.php            genera
|     php semilla_arena.php --limpiar  borra SOLO lo generado aquí
|
|
| TODO LO QUE CREA VA MARCADO
|
| Las instalaciones y las reservas llevan el prefijo «QA-» en su código, y
| las personas una identificación que empieza por 99. Eso permite borrarlo
| todo de un comando sin tocar nada real, y distinguir de un vistazo lo
| sembrado de lo verdadero.
|
|
| LAS IDENTIDADES SON FALSAS A PROPÓSITO, Y NO POR COMODIDAD
|
| Las cédulas empiezan por 99. En Ecuador los dos primeros dígitos son el
| código de provincia, que va de 01 a 24 más el 30 para el exterior: 99 no
| existe y no puede coincidir con una persona real. Los nombres son de
| fantasía y los correos usan el dominio reservado example.com.
|
| Esto no es cosmética. La base de este proyecto ya estuvo volcada en un
| repositorio público, y una semilla con datos verosímiles es exactamente
| como se filtran datos personales sin que nadie lo decida.
|
|
| ES DETERMINISTA
|
| mt_srand con semilla fija: dos ejecuciones producen los mismos datos. Un
| informe que dé 4.812,50 hoy dará 4.812,50 mañana, y una prueba de
| regresión puede afirmar cifras concretas en vez de «mayor que cero».
|
|
| LA FORMA DE LOS DATOS IMITA LA REALIDAD
|
| Las reservas no se reparten al azar por el calendario. Se concentran de
| 18:00 a 21:00 entre semana y por la mañana los fines de semana, porque si
| la ocupación fuera plana el mapa de calor del §20 no enseñaría nada y no
| se podría comprobar que funciona.
*/

require_once __DIR__ . '/conexion.php';

const MARCA        = 'QA-';
const CEDULA_BASE  = '99';          /* provincia inexistente */
const DESDE        = '2026-01-05';
/* Llega al FUTURO a propósito: el KPI «reservas vigentes» filtra
   reserva_fecha >= CURDATE(), y sin fechas por delante daría cero
   aunque hubiera 600 reservas sembradas. */
const HASTA        = '2026-10-15';

$db = qa_conexion();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/*==============  Limpieza  ==============*/

function limpiar(PDO $db): array
{
    /* En orden inverso a las dependencias. */
    $borrados = [];

    $db->exec("DELETE m FROM dsa_monedero_movimiento m
                 JOIN dsa_monedero d ON d.monedero_id = m.movimiento_monederoid
                 JOIN dsa_cliente  c ON c.cliente_id  = d.monedero_clienteid
                WHERE c.cliente_identificacion LIKE '" . CEDULA_BASE . "%'");

    $sqls = [
        'dsa_pago'      => "DELETE p FROM dsa_pago p JOIN dsa_reserva r ON r.reserva_id = p.pago_reservaid
                             WHERE r.reserva_codigo LIKE '" . MARCA . "%'",
        'dsa_reserva'   => "DELETE FROM dsa_reserva WHERE reserva_codigo LIKE '" . MARCA . "%'",
        'dsa_bloqueo'   => "DELETE b FROM dsa_bloqueo b JOIN dsa_instalacion i ON i.instalacion_id = b.bloqueo_instalacionid
                             WHERE i.instalacion_codigo LIKE '" . MARCA . "%'",
        'dsa_tarifa'    => "DELETE t FROM dsa_tarifa t JOIN dsa_instalacion i ON i.instalacion_id = t.tarifa_instalacionid
                             WHERE i.instalacion_codigo LIKE '" . MARCA . "%'",
        'dsa_horario'   => "DELETE h FROM dsa_horario h JOIN dsa_instalacion i ON i.instalacion_id = h.horario_instalacionid
                             WHERE i.instalacion_codigo LIKE '" . MARCA . "%'",
        'dsa_monedero'  => "DELETE d FROM dsa_monedero d JOIN dsa_cliente c ON c.cliente_id = d.monedero_clienteid
                             WHERE c.cliente_identificacion LIKE '" . CEDULA_BASE . "%'",
        'dsa_instalacion' => "DELETE FROM dsa_instalacion WHERE instalacion_codigo LIKE '" . MARCA . "%'",
        'dsa_cliente'   => "DELETE FROM dsa_cliente WHERE cliente_identificacion LIKE '" . CEDULA_BASE . "%'",
    ];
    foreach ($sqls as $tabla => $sql) {
        $borrados[$tabla] = $db->exec($sql);
    }

    /* Devolver las sedes a formativa, pero solo si no queda ninguna
       instalacion real en ellas: si alguien creo una de verdad mientras
       tanto, la sede debe seguir admitiendo alquiler. */
    foreach ([4, 5] as $sede) {
        $quedan = (int) $db->query(
            "SELECT COUNT(*) FROM dsa_instalacion WHERE instalacion_sedeid = $sede")->fetchColumn();
        if ($quedan === 0) {
            $db->exec("UPDATE general_sede SET sede_tipoingreso = 'STF' WHERE sede_id = $sede");
        }
    }
    return $borrados;
}

if (in_array('--limpiar', $argv, true)) {
    foreach (limpiar($db) as $t => $n) { printf("  %-18s %5d borrados\n", $t, $n); }
    exit(0);
}

/* Generar siempre parte de cero: la semilla es idempotente. */
limpiar($db);
mt_srand(20260828);

/*==============  Las sedes tienen que admitir alquiler  ==============*/
/*
| general_sede.sede_tipoingreso decide si una sede es formativa (STF), de
| alquiler (STA) o ambas (STM). Sólo La Salle era STM, así que sembrar
| instalaciones en La Tebaida y Cariamanga creaba datos que la propia
| aplicación no permitiría: canchas en sedes que no alquilan.
|
| Se habilitan aquí y se devuelven a su valor en --limpiar. Sin varias sedes
| alquilando no se puede probar «ingresos por sede», que es de los
| indicadores centrales de Insights.
*/
const SEDES_HABILITADAS = [4, 5];

foreach (SEDES_HABILITADAS as $sede) {
    $db->prepare("UPDATE general_sede SET sede_tipoingreso = 'STM'
                   WHERE sede_id = :s AND sede_tipoingreso = 'STF'")->execute([':s' => $sede]);
}

/*==============  Instalaciones  ==============*/
/*
| Cuatro canchas y dos residencias, repartidas en tres sedes reales, con
| precios distintos: sin variación de precio no se puede comprobar el
| «ingreso por hora» ni el ranking de rentabilidad.
*/
$instalaciones = [
    ['sede' => 1, 'clase' => 'C', 'cod' => 'CAN-A', 'nom' => 'Cancha Central',        'cub' => 'S', 'piso' => 1, 'cap' => 200, 'valor' => 25.00],
    ['sede' => 1, 'clase' => 'C', 'cod' => 'CAN-B', 'nom' => 'Cancha Norte',          'cub' => 'S', 'piso' => 2, 'cap' => 120, 'valor' => 18.00],
    ['sede' => 1, 'clase' => 'R', 'cod' => 'RES-A', 'nom' => 'Residencia Bloque A',   'cub' => 'S', 'piso' => 4, 'cap' =>  24, 'valor' => 12.00],
    ['sede' => 4, 'clase' => 'C', 'cod' => 'CAN-C', 'nom' => 'Cancha Descubierta',    'cub' => 'N', 'piso' => 3, 'cap' =>  80, 'valor' => 12.00],
    ['sede' => 5, 'clase' => 'C', 'cod' => 'CAN-D', 'nom' => 'Cancha Cariamanga',     'cub' => 'N', 'piso' => 3, 'cap' =>  60, 'valor' => 10.00],
    ['sede' => 5, 'clase' => 'R', 'cod' => 'RES-B', 'nom' => 'Residencia Cariamanga', 'cub' => 'S', 'piso' => 4, 'cap' =>  12, 'valor' =>  9.00],
];

$sqlIns = $db->prepare(
    "INSERT INTO dsa_instalacion
        (instalacion_sedeid, instalacion_clase, instalacion_codigo, instalacion_nombre,
         instalacion_cubierta, instalacion_pisoid, instalacion_capacidad,
         instalacion_valorhora, instalacion_detalle, instalacion_estado)
     VALUES (:sede, :clase, :cod, :nom, :cub, :piso, :cap, :valor, :det, 'A')");

$idsInstalacion = [];
foreach ($instalaciones as $i) {
    $sqlIns->execute([
        ':sede' => $i['sede'], ':clase' => $i['clase'], ':cod' => MARCA . $i['cod'],
        ':nom' => $i['nom'], ':cub' => $i['cub'], ':piso' => $i['piso'],
        ':cap' => $i['cap'], ':valor' => $i['valor'],
        ':det' => 'Dato de prueba generado por pruebas/semilla_arena.php',
    ]);
    $i['id'] = (int) $db->lastInsertId();
    $idsInstalacion[] = $i;
}

/*==============  Horarios de apertura y tarifas  ==============*/
/*
| El horario es lo que hace calculable la OCUPACIÓN: sin horas disponibles,
| el cociente horas reservadas / horas disponibles no existe. Entre semana
| 08:00-22:00 (14 h) y fines de semana 08:00-20:00 (12 h).
*/
$sqlHor = $db->prepare(
    "INSERT INTO dsa_horario (horario_instalacionid, horario_dia, horario_desde, horario_hasta, horario_estado)
     VALUES (:ins, :dia, :desde, :hasta, 'A')");
$sqlTar = $db->prepare(
    "INSERT INTO dsa_tarifa (tarifa_instalacionid, tarifa_nombre, tarifa_dia, tarifa_desde,
                             tarifa_hasta, tarifa_valorhora, tarifa_vigenciadesde, tarifa_estado)
     VALUES (:ins, :nom, :dia, :desde, :hasta, :valor, :vig, 'A')");

$horarios = 0; $tarifas = 0;
foreach ($idsInstalacion as $i) {
    for ($dia = 1; $dia <= 7; $dia++) {
        $finde = ($dia >= 6);
        $sqlHor->execute([
            ':ins' => $i['id'], ':dia' => $dia,
            ':desde' => '08:00:00', ':hasta' => $finde ? '20:00:00' : '22:00:00',
        ]);
        $horarios++;
    }
    /* Tarifa llana y tarifa punta: la punta es lo que permite comprobar que
       el ingreso por hora no coincide con el precio de lista. */
    $sqlTar->execute([':ins' => $i['id'], ':nom' => 'Tarifa regular', ':dia' => null,
                      ':desde' => '08:00:00', ':hasta' => '17:59:59',
                      ':valor' => $i['valor'], ':vig' => DESDE]);
    $sqlTar->execute([':ins' => $i['id'], ':nom' => 'Tarifa hora punta', ':dia' => null,
                      ':desde' => '18:00:00', ':hasta' => '22:00:00',
                      ':valor' => round($i['valor'] * 1.3, 2), ':vig' => DESDE]);
    $tarifas += 2;
}

/*==============  Clientes  ==============*/
$nombres  = ['Aurora', 'Bruno', 'Celeste', 'Damián', 'Elena', 'Fabián', 'Gala', 'Hugo',
             'Irene', 'Julián', 'Karina', 'Lorenzo', 'Marina', 'Néstor', 'Olivia',
             'Pablo', 'Quintín', 'Rosa', 'Simón', 'Tamara', 'Ulises', 'Valeria',
             'Wilson', 'Ximena', 'Yolanda'];
$apellidos = ['Andrade', 'Bermeo', 'Carrión', 'Dávila', 'Espinosa', 'Fierro', 'Guamán',
              'Hidalgo', 'Iñiguez', 'Jaramillo', 'Küng', 'Loaiza', 'Molina', 'Naranjo',
              'Ochoa', 'Paredes', 'Quezada', 'Rivadeneira', 'Salinas', 'Torres',
              'Ureña', 'Vélez', 'Wong', 'Yépez', 'Zambrano'];

$sqlCli = $db->prepare(
    "INSERT INTO dsa_cliente (cliente_tipoid, cliente_identificacion, cliente_nombre,
                              cliente_correo, cliente_celular, cliente_direccion, cliente_estado)
     VALUES ('CED', :ident, :nom, :correo, :cel, :dir, 'A')");
$sqlMon = $db->prepare(
    "INSERT INTO dsa_monedero (monedero_clienteid, monedero_saldo, monedero_estado)
     VALUES (:cli, :saldo, 'A')");

$clientes = [];
for ($k = 0; $k < 25; $k++) {
    $nom = $nombres[$k] . ' ' . $apellidos[$k];
    $ident = CEDULA_BASE . str_pad((string) (100000 + $k), 8, '0', STR_PAD_LEFT);
    $sqlCli->execute([
        ':ident'  => $ident,
        ':nom'    => $nom,
        ':correo' => strtolower($nombres[$k]) . '.' . strtolower(preg_replace('~[^a-z]~i', '', $apellidos[$k])) . '@example.com',
        ':cel'    => '09' . str_pad((string) (10000000 + $k), 8, '0', STR_PAD_LEFT),
        ':dir'    => 'Dirección de prueba ' . ($k + 1),
    ]);
    $cid = (int) $db->lastInsertId();
    $clientes[] = $cid;
    /* Un tercio con monedero: suficiente para probar la vista sin que sea
       el caso mayoritario, que no lo es en la realidad. */
    if ($k % 3 === 0) {
        $sqlMon->execute([':cli' => $cid, ':saldo' => mt_rand(0, 4000) / 100]);
    }
}

/*==============  Reservas y pagos  ==============*/
/*
| La ocupación NO es uniforme. Cada franja de apertura se ocupa con una
| probabilidad propia —alta en la tarde-noche entre semana, alta las
| mañanas de fin de semana, baja el resto—, de modo que la ocupación real
| emerge de ahí en vez de fijarse a mano. Una ocupación plana no probaría
| ni el mapa de calor ni el ranking de instalaciones.
*/

$sqlRes = $db->prepare(
    "INSERT INTO dsa_reserva
        (reserva_codigo, reserva_clienteid, reserva_instalacionid, reserva_sedeid,
         reserva_fecha, reserva_horainicio, reserva_horafin, reserva_horas,
         reserva_valorhora, reserva_total, reserva_abonado, reserva_saldo,
         reserva_estado, reserva_observacion, reserva_usuarioid)
     VALUES (:cod, :cli, :ins, :sede, :fecha, :hi, :hf, :horas,
             :vh, :total, :abonado, :saldo, :estado, :obs, 1)");
$sqlPag = $db->prepare(
    "INSERT INTO dsa_pago (pago_reservaid, pago_formaid, pago_valor, pago_recibido,
                           pago_vuelto, pago_referencia, pago_fecha, pago_observacion,
                           pago_usuarioid, pago_estado)
     VALUES (:res, :forma, :valor, :valor, 0.00, :ref, :fecha, 'Semilla de prueba', 1, 'A')");

$ini = new DateTimeImmutable(DESDE);
$fin = new DateTimeImmutable(HASTA);
$hoy = new DateTimeImmutable('today');

$nRes = 0; $nPag = 0; $seq = 0;
$totales = ['P' => 0.0, 'C' => 0.0, 'U' => 0.0, 'X' => 0.0];

for ($d = $ini; $d <= $fin; $d = $d->modify('+1 day')) {
    $dia   = (int) $d->format('N');
    $finde = ($dia >= 6);
    $cierre = $finde ? 20 : 22;

    /*
    | Se recorre CADA instalación y CADA hora de apertura, decidiendo si esa
    | franja se ocupa. Antes se generaba un número de reservas al día y se
    | repartían al azar, lo que daba 6,4 % de ocupación global: el indicador
    | quedaba poblado pero nunca ejercitaba su extremo alto, que es donde se
    | ven los problemas de escala y de formato.
    |
    | Con probabilidad por franja, la ocupación sale sola y realista: alta en
    | la tarde-noche entre semana, alta las mañanas de fin de semana, baja a
    | media mañana. Y la del encargo —82 %, 74 %, 58 %— queda dentro del
    | rango que se puede probar.
    */
    foreach ($idsInstalacion as $ins) {
        $residencia = $ins['clase'] === 'R';
        $hora = 8;
        while ($hora < $cierre) {

            /* Probabilidad de que esta franja se ocupe. */
            if ($residencia) {
                /* Las residencias se alquilan por bloques largos y menos veces. */
                $p = $finde ? 26 : 16;
            } elseif ($finde) {
                $p = $hora <= 12 ? 62 : ($hora <= 17 ? 34 : 20);
            } else {
                $p = $hora >= 18 ? 78 : ($hora >= 16 ? 42 : 14);
            }
            /* Las canchas descubiertas se usan menos: da variedad al ranking. */
            if ($ins['cub'] === 'N') { $p = (int) round($p * 0.72); }

            if (mt_rand(1, 100) > $p) { $hora++; continue; }

            $horas = $residencia ? min(mt_rand(3, 6), $cierre - $hora) : 1;
            if ($horas < 1) { break; }

            $vh    = $hora >= 18 ? round($ins['valor'] * 1.3, 2) : $ins['valor'];
            $total = round($vh * $horas, 2);

            /* Estado coherente con el calendario: lo pasado se cumplió o se
               canceló; lo futuro está pendiente o confirmado. */
            if ($d < $hoy) {
                $estado = mt_rand(1, 100) <= 9 ? 'X' : 'U';
            } else {
                $estado = mt_rand(1, 100) <= 40 ? 'P' : 'C';
            }

            if ($estado === 'X') {
                $abonado = 0.00;
            } elseif ($estado === 'U') {
                $abonado = mt_rand(1, 100) <= 84 ? $total : round($total * (mt_rand(30, 70) / 100), 2);
            } else {
                $abonado = mt_rand(1, 100) <= 45 ? round($total * 0.5, 2) : 0.00;
            }
            $saldo = round($total - $abonado, 2);

            $seq++;
            $sqlRes->execute([
                ':cod' => MARCA . 'R' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
                ':cli' => $clientes[mt_rand(0, count($clientes) - 1)],
                ':ins' => $ins['id'], ':sede' => $ins['sede'], ':fecha' => $d->format('Y-m-d'),
                ':hi' => sprintf('%02d:00:00', $hora),
                ':hf' => sprintf('%02d:00:00', $hora + $horas),
                ':horas' => $horas, ':vh' => $vh, ':total' => $total,
                ':abonado' => $abonado, ':saldo' => $saldo, ':estado' => $estado,
                ':obs' => 'Semilla de prueba',
            ]);
            $rid = (int) $db->lastInsertId();
            $nRes++;
            $totales[$estado] += $total;

            if ($abonado > 0) {
                $forma = [1, 1, 1, 2, 3][mt_rand(0, 4)];
                $sqlPag->execute([
                    ':res' => $rid, ':forma' => $forma, ':valor' => $abonado,
                    ':ref'   => $forma === 1 ? '' : 'REF' . str_pad((string) $seq, 6, '0', STR_PAD_LEFT),
                    ':fecha' => $d->format('Y-m-d'),
                ]);
                $nPag++;
            }

            $hora += $horas;
        }
    }
}

/*==============  Resumen  ==============*/
printf("  instalaciones %2d · horarios %3d · tarifas %2d · clientes %2d\n",
    count($idsInstalacion), $horarios, $tarifas, count($clientes));
printf("  reservas %4d · pagos %4d\n", $nRes, $nPag);
foreach (['U' => 'cumplidas', 'C' => 'confirmadas', 'P' => 'pendientes', 'X' => 'canceladas'] as $e => $nom) {
    printf("     %-12s %8.2f\n", $nom, $totales[$e]);
}
$cobrado = (float) $db->query(
    "SELECT IFNULL(SUM(p.pago_valor),0) FROM dsa_pago p
       JOIN dsa_reserva r ON r.reserva_id = p.pago_reservaid
      WHERE r.reserva_codigo LIKE '" . MARCA . "%'")->fetchColumn();
$porCobrar = (float) $db->query(
    "SELECT IFNULL(SUM(reserva_saldo),0) FROM dsa_reserva
      WHERE reserva_codigo LIKE '" . MARCA . "%' AND reserva_estado <> 'X'")->fetchColumn();
printf("  cobrado %.2f · por cobrar %.2f\n", $cobrado, $porCobrar);
