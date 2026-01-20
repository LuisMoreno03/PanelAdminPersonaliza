<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Usuarios</title>
</head>
<body>
  <h1>Usuarios</h1>

  <?php if (!empty($usuarios)): ?>
    <ul>
      <?php foreach ($usuarios as $u): ?>
        <li>
          <?= esc($u['email'] ?? 'sin email') ?>
          - <a href="<?= site_url('usuarios/'.$u['id'].'/password') ?>">Cambiar contraseña</a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p>No hay usuarios.</p>
  <?php endif; ?>
</body>
</html>
