<?php
/* Panel de entrada del módulo Core. */

use admin\controllers\coreController;

$insCore = new coreController();

$tituloVista = 'Panel';
$vistaActual = 'panel';

$resumen = $insCore->resumen();
$roles   = $insCore->roles();
$modulos = ds_modulos_conocidos();

require_once __DIR__ . "/inc/layout-top.php";
?>

<div class="row">
    <?php foreach ($resumen as $r): ?>
        <div class="col-lg-3 col-6 mb-3">
            <div class="ds-kpi">
                <span class="ds-kpi__icono bg-<?php echo $r['color']; ?> text-white">
                    <i class="<?php echo $r['icono']; ?>"></i>
                </span>
                <span>
                    <span class="ds-kpi__valor"><?php echo (int)$r['valor']; ?></span>
                    <span class="ds-kpi__label"><?php echo $r['etiqueta']; ?></span>
                </span>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Roles del ecosistema</h3>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Rol</th>
                        <th>Usuarios</th>
                        <th>Vistas con permiso</th>
                        <th>Módulos</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($roles as $r): $esSuper = (int)$r['rol_id'] === self_rol_superadmin(); ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($r['rol_nombre'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            <?php if ($esSuper): ?>
                                <span class="badge text-bg-warning ms-1">Acceso total</span>
                            <?php endif; ?>
                            <?php if ($r['rol_detalle']): ?>
                                <small class="d-block text-muted"><?php echo htmlspecialchars($r['rol_detalle'], ENT_QUOTES, 'UTF-8'); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo (int)$r['usuarios']; ?></td>
                        <td><?php echo $esSuper ? '<span class="text-muted">todas</span>' : (int)$r['permisos']; ?></td>
                        <td>
                            <?php
                            $asignados = $esSuper ? array_keys($modulos) : array_filter(explode(',', (string)$r['modulos']));
                            if (!$asignados) {
                                echo '<span class="text-muted small">ninguno</span>';
                            }
                            foreach ($asignados as $mod):
                                if (!isset($modulos[$mod])) continue;
                            ?>
                                <span class="badge-modulo badge-modulo--<?php echo $mod; ?>"><?php echo $modulos[$mod]; ?></span>
                            <?php endforeach; ?>
                        </td>
                        <td class="text-end">
                            <a href="<?php echo APP_URL; ?>permisoRol/?rol=<?php echo (int)$r['rol_id']; ?>"
                               class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-key me-1"></i> Permisos
                            </a>
                            <a href="<?php echo APP_URL; ?>moduloRol/?rol=<?php echo (int)$r['rol_id']; ?>"
                               class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-th-large me-1"></i> Módulos
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . "/inc/layout-bottom.php"; ?>
