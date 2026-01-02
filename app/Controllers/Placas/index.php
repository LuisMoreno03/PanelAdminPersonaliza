<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Placas</title>
  <style>
    body{font-family:Arial; margin:20px;}
    .row{display:flex; gap:10px; margin-bottom:12px; flex-wrap:wrap;}
    input, select, button{padding:10px; font-size:14px;}
    table{width:100%; border-collapse:collapse; margin-top:14px;}
    th, td{border:1px solid #ddd; padding:10px; text-align:left;}
    th{background:#f5f5f5;}
    .badge{padding:4px 10px; border-radius:999px; font-size:12px; font-weight:bold;}
    .pendiente{background:#fff4cc; color:#7a5a00;}
    .listo{background:#d9fbe0; color:#0b6b2b;}
  </style>
</head>
<body>

<h1>📌 Apartado: Placas</h1>

<div class="row">
  <input id="q" placeholder="Buscar por código o cliente">
  <select id="estado">
    <option value="">Todos</option>
    <option value="pendiente">Pendiente</option>
    <option value="listo">Listo</option>
  </select>
  <button onclick="cargar()">Filtrar</button>
</div>

<div class="row">
  <input id="codigo" placeholder="Nueva placa: Código (ABC-123)">
  <input id="cliente" placeholder="Cliente">
  <button onclick="guardar()">Guardar</button>
</div>

<div id="meta"></div>

<table>
  <thead>
    <tr>
      <th>ID</th>
      <th>Código</th>
      <th>Cliente</th>
      <th>Estado</th>
      <th>Fecha</th>
    </tr>
  </thead>
  <tbody id="tbody">
    <tr><td colspan="5">Cargando...</td></tr>
  </tbody>
</table>

<script>
async function cargar() {
  const q = document.getElementById('q').value.trim();
  const estado = document.getElementById('estado').value;

  const res = await fetch(`/placas/filter?q=${encodeURIComponent(q)}&estado=${encodeURIComponent(estado)}`);
  const data = await res.json();

  const tbody = document.getElementById('tbody');
  const meta = document.getElementById('meta');

  if (!data.success) {
    meta.textContent = 'Error cargando datos';
    tbody.innerHTML = `<tr><td colspan="5">Error</td></tr>`;
    return;
  }

  meta.textContent = `Total: ${data.total}`;
  tbody.innerHTML = '';

  if (data.items.length === 0) {
    tbody.innerHTML = `<tr><td colspan="5">Sin resultados</td></tr>`;
    return;
  }

  data.items.forEach(p => {
    const badgeClass = p.estado === 'listo' ? 'listo' : 'pendiente';
    tbody.innerHTML += `
      <tr>
        <td>${p.id}</td>
        <td>${p.codigo}</td>
        <td>${p.cliente}</td>
        <td><span class="badge ${badgeClass}">${p.estado}</span></td>
        <td>${p.fecha}</td>
      </tr>
    `;
  });
}

async function guardar() {
  const codigo = document.getElementById('codigo').value.trim();
  const cliente = document.getElementById('cliente').value.trim();

  const form = new FormData();
  form.append('codigo', codigo);
  form.append('cliente', cliente);

  const res = await fetch('/placas/guardar', { method: 'POST', body: form });
  const data = await res.json();

  alert(data.message || (data.success ? 'OK' : 'Error'));
  if (data.success) {
    document.getElementById('codigo').value = '';
    document.getElementById('cliente').value = '';
    cargar();
  }
}

cargar();
</script>

</body>
</html>
