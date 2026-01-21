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

  <?= $this->extend('layout/panel') ?>
<?= $this->section('content') ?>

<div class="card" style="border-radius:16px;">
  <div class="card-body">
    <div class="d-flex align-items-center justify-content-between mb-3">
      <div>
        <h2 class="mb-1" style="font-weight:800;">Cambiar contraseña</h2>
        <div class="text-muted">Usuario: <?= esc($usuario['nombre'] ?? '-') ?> (<?= esc($usuario['email'] ?? '-') ?>)</div>
      </div>
      <a href="<?= site_url('usuarios') ?>" class="btn btn-outline-secondary" style="border-radius:999px;">Volver</a>
    </div>

    <?php $errors = session()->getFlashdata('validation') ?? []; ?>

    <form method="post" action="<?= site_url('usuarios/password') ?>">
      <?= csrf_field() ?>

      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Nueva contraseña</label>
          <input type="password" name="password"
                 class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>"
                 minlength="8" required>
          <?php if (isset($errors['password'])): ?>
            <div class="invalid-feedback"><?= esc($errors['password']) ?></div>
          <?php endif; ?>
        </div>

        <div class="col-md-6">
          <label class="form-label">Confirmar contraseña</label>
          <input type="password" name="password_confirm"
                 class="form-control <?= isset($errors['password_confirm']) ? 'is-invalid' : '' ?>"
                 minlength="8" required>
          <?php if (isset($errors['password_confirm'])): ?>
            <div class="invalid-feedback"><?= esc($errors['password_confirm']) ?></div>
          <?php endif; ?>
        </div>
      </div>

      <div class="mt-4 d-flex gap-2">
        <button class="btn btn-primary" style="border-radius:999px; padding:.6rem 1.2rem; font-weight:700;" type="submit">
          Guardar
        </button>
        <a class="btn btn-outline-secondary" style="border-radius:999px;" href="<?= site_url('usuarios') ?>">Cancelar</a>
      </div>
    </form>
  </div>
</div>

<?= $this->endSection() ?>
</div>
</body>
</html>

