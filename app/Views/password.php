<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Cambiar contraseña</title>
</head>
<body>
  <h1>Cambiar contraseña</h1>

  <p>Usuario: <strong><?= esc($usuario['email'] ?? '-') ?></strong></p>

  <?php $errors = session()->getFlashdata('validation') ?? []; ?>

  <form method="post" action="<?= site_url('usuarios/'.$usuario['id'].'/password') ?>">
    <?= csrf_field() ?>

    <label>Nueva contraseña</label><br>
    <input type="password" name="password" required minlength="8">
    <?php if (isset($errors['password'])): ?>
      <div style="color:red;"><?= esc($errors['password']) ?></div>
    <?php endif; ?>
    <br><br>

    <label>Confirmar contraseña</label><br>
    <input type="password" name="password_confirm" required minlength="8">
    <?php if (isset($errors['password_confirm'])): ?>
      <div style="color:red;"><?= esc($errors['password_confirm']) ?></div>
    <?php endif; ?>
    <br><br>

    <button type="submit">Guardar</button>
    <a href="<?= site_url('usuarios') ?>">Volver</a>
  </form>
</body>
</html>
