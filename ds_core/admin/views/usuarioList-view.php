<?php
/* Listado de usuarios del ecosistema. */

use admin\controllers\coreController;

$insCore = new coreController();

$tituloVista = 'Usuarios';
$vistaActual = 'usuarioList';

$usuarios = $insCore->usuarios();

$puedeCrear    = puede_crear('usuarioList');
$puedeEditar   = puede_editar('usuarioList');
$puedeEliminar = puede_eliminar('usuarioList');

require_once __DIR__ . "/inc/layout-top.php";
?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title mb-0"><?php echo count($usuarios); ?> usuario<?php echo count($usuarios) === 1 ? '' : 's'; ?></h3>
        <?php if ($puedeCrear): ?>
            <a href="<?php echo APP_URL; ?>usuarioForm/" class="btn btn-primary btn-sm">
                <i class="fas fa-plus mr-1"></i> Nuevo usuario
            </a>
        <?php endif; ?>
    </div>

    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Usuario</th>
                        <th>Persona</th>
                        <th>Rol</th>
                        <th>Sede</th>
                        <th>Estado</th>
                        <th class="text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$usuarios): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No hay usuarios registrados.</td></tr>
                <?php endif; ?>

                <?php foreach ($usuarios as $u):
                    $esSuper = (int)$u['usuario_rolid'] === self_rol_superadmin();
                    $esYo    = (int)$u['usuario_id'] === (int)($_SESSION['usuarioid'] ?? 0);
                ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($u['usuario_usuario'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            <?php if ($esYo): ?><span class="badge badge-info ml-1">Usted</span><?php endif; ?>
                            <?php if ($u['usuario_tienebloqueo'] === 'S'): ?>
                                <span class="badge badge-danger ml-1">Bloqueado</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars(trim((string)$u['empleado']) ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <?php echo htmlspecialchars((string)$u['rol_nombre'], ENT_QUOTES, 'UTF-8'); ?>
                            <?php if ($esSuper): ?><span class="badge badge-warning ml-1">Acceso total</span><?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars((string)($u['sede_nombre'] ?? '') ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <span class="badge badge-<?php echo $u['usuario_estado'] === 'A' ? 'success' : 'secondary'; ?>">
                                <?php echo $u['usuario_estado'] === 'A' ? 'Activo' : 'Inactivo'; ?>
                            </span>
                        </td>
                        <td class="ds-tabla-acciones">
                            <?php
                            /* La cuenta con acceso total no se da de baja, y
                               sólo otro Super Administrador puede tocarla:
                               cambiarle la clave equivaldría a entrar como él. */
                            $editable = $puedeEditar && (!$esSuper || es_superadministrador());
                            $bajable  = $puedeEliminar && !$esYo && !$esSuper;
                            ?>

                            <?php if ($editable): ?>
                                <a href="<?php echo APP_URL; ?>usuarioForm/?id=<?php echo (int)$u['usuario_id']; ?>"
                                   class="btn btn-sm btn-outline-secondary" title="Editar"><i class="fas fa-pen"></i></a>
                            <?php endif; ?>

                            <?php if ($esSuper): ?>
                                <?php echo ds_hueco('La cuenta con acceso total no se puede dar de baja'); ?>
                            <?php endif; ?>

                            <?php if ($bajable): ?>
                                <form class="FormularioAjax d-inline" method="POST"
                                      action="<?php echo APP_URL; ?>ajax/coreAjax.php"
                                      data-confirmar="El usuario quedará dado de baja y no podrá iniciar sesión.">
                                    <input type="hidden" name="modulo_core" value="eliminarUsuario">
                                    <input type="hidden" name="usuario_id" value="<?php echo (int)$u['usuario_id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Dar de baja">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . "/inc/layout-bottom.php"; ?>
