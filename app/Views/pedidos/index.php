<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<!-- Ejemplo: botón ayuda para relanzar tour -->
<button id="btn-ayuda" type="button" class="btn btn-light">
  ¿Cómo funciona?
</button>

<h1 data-tour="page-title">Pedidos</h1>

<div data-tour="filters">
  <input data-tour="search-input" type="text" placeholder="Buscar pedido, cliente, ID..." />
  <button data-tour="search-button" type="button">BUSCAR</button>
  <button data-tour="filter-button" type="button">FILTRO</button>
</div>

<table data-tour="orders-table">
  <!-- tu tabla -->
</table>

<!-- Si tienes chips/estados en la tabla, marca al menos uno -->
<span class="badge" data-tour="estado-produccion">POR PREPARAR</span>
<span class="badge" data-tour="estado-entrega">PENDIENTE</span>

<?= $this->endSection() ?>


<?= $this->section('scripts') ?>

<!-- Shepherd (JS vendor local) -->
<script src="<?= base_url('assets/vendor/shepherd/shepherd.min.js') ?>"></script>

<script>
  // Si quieres que el tour se guarde por usuario, mete el userId del session.
  // Ajusta la key a tu sesión real.
  window.TOUR_CONTEXT = {
    key: 'pedidos',
    userId: <?= (int) (session('user_id') ?? 0) ?>
  };
</script>

<script src="<?= base_url('assets/js/tours/pedidos.tour.js') ?>"></script>

<?= $this->endSection() ?>
