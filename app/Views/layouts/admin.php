<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <title><?= esc($title ?? 'Panel') ?></title>

  <!-- Shepherd CSS -->
  <link rel="stylesheet" href="<?= base_url('css/vendor/shepherd/shepherd.css') ?>">
  <!-- Tu tema -->
  <link rel="stylesheet" href="<?= base_url('css/tour.css') ?>">

  <?= $this->renderSection('styles') ?>
</head>
<body>

  <?= $this->renderSection('content') ?>

  <?= $this->renderSection('scripts') ?>
</body>
</html>
