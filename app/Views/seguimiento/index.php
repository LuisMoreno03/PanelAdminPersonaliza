<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= esc($title ?? 'Seguimiento') ?></title>
  <style>
    body { font-family: Arial, sans-serif; padding: 16px; }
    .row { display:flex; gap:12px; flex-wrap:wrap; align-items:end; margin-bottom: 14px; }
    label { font-size: 13px; display:block; margin-bottom: 6px; }
    input, button { padding: 8px 10px; }
    table { width:100%; border-collapse: collapse; margin-top: 10px; }
    th, td { border: 1px solid #ddd; padding: 10px; text-align:left; }
    th { background: #f5f5f5; }
    .muted { color:#666; font-size: 13px; }
    .loading { padding: 10px; background:#fff7d6; border:1px solid #ffe08a; margin-top: 10px; display:none; }
    .error { padding: 10px; background:#ffe1e1; border:1px solid #ffb3b3; margin-top: 10px; display:none; }
  </style>
</head>
<body>
    
  <?= view('layouts/menu') ?>
  
  <h2>Seguimiento</h2>
  <p class="muted">Cantidad de cambios de estado interno realizados por cada usuario.</p>

  <div class="row">
    <div>
      <label for="from">Desde</label>
      <input type="date" id="from">
    </div>

    <div>
      <label for="to">Hasta</label>
      <input type="date" id="to">
    </div>

    <div>
      <button id="btnFiltrar">Filtrar</button>
      <button id="btnLimpiar" type="button">Limpiar</button>
    </div>
  </div>

  <div id="loading" class="loading">Cargando...</div>
  <div id="error" class="error"></div>

  <table>
    <thead>
      <tr>
        <th>Usuario</th>
        <th>Email</th>
        <th>Total cambios</th>
        <th>Último cambio</th>
      </tr>
    </thead>
    <tbody id="tbodySeguimiento">
      <tr><td colspan="4" class="muted">Sin datos aún.</td></tr>
    </tbody>
  </table>

  <script>
    // Base URL para JS (evita hardcode)
    window.SEGUIMIENTO_BASE = "<?= rtrim(base_url(), '/') ?>";
  </script>
  <script src="<?= base_url('assets/js/seguimiento.js') ?>"></script>
</body>
</html>
