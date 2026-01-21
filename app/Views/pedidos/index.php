<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<button id="btn-ayuda" class="btn btn-light" type="button">
  ¿Cómo funciona?
</button>

<h1 data-tour="page-title">Pedidos</h1>

<div data-tour="filters">
  <input data-tour="search-input" type="text" placeholder="Buscar pedido, cliente, ID...">
  <button data-tour="search-button" type="button">BUSCAR</button>
  <button data-tour="filter-button" type="button">FILTRO</button>
</div>

<table data-tour="orders-table">
  <!-- tu tabla -->
</table>

<!-- Si tienes chips/estados en la tabla, marca al menos uno -->
<span data-tour="estado-produccion" class="badge">POR PREPARAR</span>
<span data-tour="estado-entrega" class="badge">PENDIENTE</span>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
  <script src="<?= base_url('js/vendor/shepherd/shepherd.min.js') ?>"></script>

  <script>
    // clave por usuario (si tienes session user_id)
    window.TOUR_CONTEXT = {
      key: "pedidos",
      userId: <?= (int) (session('user_id') ?? 0) ?>
    };
  </script>

  <script src="<?= base_url('js/vendor/shepherd/shepherd.min.js') ?>"></script>
<script src="<?= base_url('js/tours/pedidos.tour.js') ?>"></script>

  
<?= $this->endSection() ?>
