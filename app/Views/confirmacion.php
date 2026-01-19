<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<div class="p-6">

  <h1 class="text-2xl font-bold mb-1">Confirmación</h1>
  <p class="text-sm text-gray-500 mb-4">
    Cola por usuario · Pedidos en <strong>Por preparar</strong>
  </p>

  <!-- Acciones -->
  <div class="flex gap-2 mb-4">
    <input
      id="inputBuscar"
      type="text"
      class="input input-bordered w-64"
      placeholder="Buscar pedido, cliente..."
    />

    <button id="btnLimpiarBusqueda" class="btn btn-ghost">Limpiar</button>
    <button id="btnTraer5" class="btn btn-primary">Traer 5</button>
    <button id="btnTraer10" class="btn btn-primary">Traer 10</button>
    <button id="btnDevolver" class="btn btn-outline btn-error">Devolver</button>
  </div>

  <!-- Tabla estilo Dashboard -->
  <div class="overflow-x-auto bg-white rounded-xl shadow">
    <table class="table table-zebra w-full">
      <thead>
        <tr>
          <th>PEDIDO</th>
          <th>FECHA</th>
          <th>CLIENTE</th>
          <th>TOTAL</th>
          <th>ESTADO</th>
          <th>ÚLTIMO CAMBIO</th>
          <th>ETIQUETAS</th>
          <th>ART</th>
          <th>ENTREGA</th>
          <th>MÉTODO DE ENTREGA</th>
          <th>VER</th>
        </tr>
      </thead>

      <tbody id="listaPedidos">
        <!-- JS renderiza aquí -->
      </tbody>
    </table>
  </div>

  <div class="text-right text-sm text-gray-500 mt-2">
    Total: <span id="total-pedidos">0</span>
  </div>

</div>

<!-- Modal reutilizado del Dashboard -->
<?= view('partials/pedido_modal') ?>

<script src="/js/dashboard.js"></script>
<script src="/js/confirmacion.js"></script>

<?= $this->endSection() ?>
  