<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Cambiar contraseña</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container py-4">

  <div class="mb-3">
    <a href="<?= site_url('usuarios') ?>" class="btn btn-sm btn-outline-secondary">← Volver</a>
  </div>

  <h3 class="mb-2">Cambiar contraseña</h3>
  <p class="text-muted mb-4">
    Usuario: <strong><?= esc($usuario['nombre'] ?? '-') ?></strong> (<?= esc($usuario['email'] ?? '-') ?>)
  </p>

  <?php
    $validationErrors = session()->getFlashdata('validation') ?? [];
  ?>

  <?php if (session()->getFlashdata('error')): ?>
    <div class="alert alert-danger"><?= esc(session()->getFlashdata('error')) ?></div>
  <?php endif; ?>

  <div class="card">
    <div class="card-body">
      <form method="post" action="<?= site_url('usuarios/'.$usuario['id'].'/password') ?>">
        <?= csrf_field() ?>

        <div class="mb-3">
          <label class="form-label">Nueva contraseña</label>
          <input type="password" name="password"
                 class="form-control <?= isset($validationErrors['password']) ? 'is-invalid' : '' ?>"
                 minlength="8" required>
          <?php if (isset($validationErrors['password'])): ?>
            <div class="invalid-feedback"><?= esc($validationErrors['password']) ?></div>
          <?php endif; ?>
          <div class="form-text">Mínimo 8 caracteres.</div>
        </div>

        <div class="mb-3">
          <label class="form-label">Confirmar contraseña</label>
          <input type="password" name="password_confirm"
                 class="form-control <?= isset($validationErrors['password_confirm']) ? 'is-invalid' : '' ?>"
                 minlength="8" required>
          <?php if (isset($validationErrors['password_confirm'])): ?>
            <div class="invalid-feedback"><?= esc($validationErrors['password_confirm']) ?></div>
          <?php endif; ?>
        </div>

        <div class="d-flex gap-2">
          <button class="btn btn-primary" type="submit">Guardar</button>
          <a class="btn btn-outline-secondary" href="<?= site_url('usuarios') ?>">Cancelar</a>
        </div>
      </form>
    </div>
  </div>

</div>
</body>
</html>
