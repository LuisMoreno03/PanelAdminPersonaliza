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

 <?= $this->extend('layout/panel') ?> 
<?= $this->section('content') ?>

<div class="card" style="border-radius:16px;">
  <div class="card-body">
    <h2 class="mb-1" style="font-weight:800;">Usuarios</h2>
    <p class="text-muted mb-4">Gestión de contraseña</p>

    <?php if (session()->getFlashdata('success')): ?>
      <div class="alert alert-success"><?= esc(session()->getFlashdata('success')) ?></div>
    <?php endif; ?>

    <a href="<?= site_url('usuarios/password') ?>" class="btn btn-primary" style="border-radius:999px; padding:.6rem 1.2rem; font-weight:700;">
      Cambiar contraseña
    </a>
  </div>
</div>

<?= $this->endSection() ?>
