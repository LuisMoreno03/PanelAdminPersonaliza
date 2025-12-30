<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pedidos Shopify</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">

  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="mb-0">Pedidos Shopify (50 por página)</h3>

    <div class="d-flex gap-2">
      <?php if (!$isFirstPage): ?>
        <?php if ($prevPageInfo): ?>
          <a class="btn btn-outline-secondary"
             href="<?= base_url('shopify/ordersView?page_info=' . urlencode($prevPageInfo)) ?>">
            ← Anterior
          </a>
        <?php else: ?>
          <a class="btn btn-outline-secondary" href="<?= base_url('shopify/ordersView') ?>">
            ← Anterior
          </a>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($nextPageInfo): ?>
        <a class="btn btn-primary"
           href="<?= base_url('shopify/ordersView?page_info=' . urlencode($nextPageInfo)) ?>">
          Siguiente →
        </a>
      <?php endif; ?>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-striped table-hover mb-0 align-middle">
          <thead class="table-dark">
            <tr>
              <th>#</th>
              <th>Cliente</th>
              <th>Email</th>
              <th>Total</th>
              <th>Estado</th>
              <th>Pago</th>
              <th>Fecha</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($orders)): ?>
            <tr>
              <td colspan="7" class="text-center py-4">No hay pedidos para mostrar.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($orders as $o): ?>
              <tr>
                <td class="fw-semibold"><?= esc($o['name'] ?? '') ?></td>
                <td><?= esc(($o['customer']['first_name'] ?? '') . ' ' . ($o['customer']['last_name'] ?? '')) ?></td>
                <td><?= esc($o['email'] ?? '') ?></td>
                <td>
                  <?= esc($o['currency'] ?? '') ?> <?= esc($o['total_price'] ?? '') ?>
                </td>
                <td><?= esc($o['fulfillment_status'] ?? 'Sin preparar') ?></td>
                <td class="py-3 px-4">
                  <div class="text-sm">
                    <div class="font-semibold">${pedido.last_status_change.user_name}</div>
                    <div class="text-gray-600">${formatDateTime(pedido.last_status_change.changed_at)}</div>
                    <div class="text-xs text-gray-500">
                      Hace ${timeAgo(pedido.last_status_change.changed_at)}
                    </div>
                  </div>
                </td>

                <td><?= esc($o['financial_status'] ?? '') ?></td>
                <td><?= esc($o['created_at'] ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>
</body>
</html>
