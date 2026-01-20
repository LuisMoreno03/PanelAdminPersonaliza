<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Usuarios</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container py-4">

  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="mb-0">Usuarios</h3>
  </div>

  <?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success"><?= esc(session()->getFlashdata('success')) ?></div>
  <?php endif; ?>

  <?php if (session()->getFlashdata('error')): ?>
    <div class="alert alert-danger"><?= esc(session()->getFlashdata('error')) ?></div>
  <?php endif; ?>

  <div class="card">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-striped align-middle">
          <thead>
            <tr>
              <th>#</th>
              <th>Nombre</th>
              <th>Email</th>
              <th>Rol</th>
              <th>Activo</th>
              <th>Creado</th>
              <th class="text-end">Acciones</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!empty($usuarios)): ?>
            <?php foreach ($usuarios as $u): ?>
              <tr>
                <td><?= esc($u['id']) ?></td>
                <td><?= esc($u['nombre'] ?? '-') ?></td>
                <td><?= esc($u['email'] ?? '-') ?></td>
                <td><?= esc($u['rol'] ?? '-') ?></td>
                <td>
                  <?php if ((int)($u['activo'] ?? 0) === 1): ?>
                    <span class="badge bg-success">Sí</span>
                  <?php else: ?>
                    <span class="badge bg-secondary">No</span>
                  <?php endif; ?>
                </td>
                <td><?= esc($u['created_at'] ?? '-') ?></td>
                <td class="text-end">
                  <a class="btn btn-sm btn-outline-primary"
                     href="<?= site_url('usuarios/'.$u['id'].'/password') ?>">
                    Cambiar contraseña
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="7" class="text-center text-muted py-4">
                No hay usuarios registrados.
              </td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>
</body>
</html>
