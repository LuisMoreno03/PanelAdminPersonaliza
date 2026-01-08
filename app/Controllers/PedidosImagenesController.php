<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class PedidosImagenesController extends BaseController
{
    public function subir()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'No autenticado',
            ]);
        }

        $orderId = trim((string) $this->request->getPost('order_id'));
        $index   = (int) $this->request->getPost('index');

        $file = $this->request->getFile('file');
        if (!$orderId || !$file || !$file->isValid()) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'Faltan datos (order_id / file)',
            ]);
        }

        // --------- guardar en carpeta por fecha + pedido
        $datePath = date('Y/m/d');
        $baseDir  = WRITEPATH . "uploads/pedidos/$datePath/$orderId";
        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0775, true);
        }

        $ext = $file->getClientExtension() ?: 'jpg';
        $name = "modificada_{$index}_" . time() . "." . $ext;

        $file->move($baseDir, $name);

        // URL pública (ajusta si sirves WRITEPATH con symlink)
        // Recomendado: crear symlink desde public/uploads -> WRITEPATH/uploads
        $publicPath = "uploads/pedidos/$datePath/$orderId/$name";
        $url = base_url($publicPath);

        // --------- guardar/actualizar DB en placas_archivos
        $db = db_connect();

        // buscamos si ya existe imagen modificada de ese index
        $exists = $db->table('placas_archivos')
            ->where('lote_id', $orderId)          // reutilizamos lote_id para orderId (porque ya lo tienes)
            ->where('tipo', 'modificada')         // necesitas esta columna; si NO existe, usa "nombre" o crea tipo
            ->where('indice', $index)             // necesitas esta columna; si NO existe, usa "conjunto_id" o crea indice
            ->get()->getRowArray();

        $data = [
            'lote_id'    => $orderId,
            'tipo'       => 'modificada',
            'indice'     => $index,
            'ruta'       => $publicPath,
            'original'   => $file->getClientName(),
            'nombre'     => pathinfo($file->getClientName(), PATHINFO_FILENAME),
            'usuario'    => session()->get('nombre') ?? 'Sistema', // si tienes user_id mejor
            'created_at' => date('Y-m-d H:i:s'),
        ];

        if ($exists) {
            $db->table('placas_archivos')->where('id', $exists['id'])->update($data);
        } else {
            $db->table('placas_archivos')->insert($data);
        }

        // --------- recalcular si faltan imágenes requeridas (desde Shopify details)
        // Aquí llamas a tu mismo método que ya trae order desde Shopify
        // Para simplificar: reutiliza tu endpoint /dashboard/detalles/{id}
        // y cuenta line_items que tengan imagen original en properties.
        $detalles = $this->fetchDetallesPedido($orderId); // 👇 implementación abajo
        $lineItems = $detalles['line_items'] ?? [];

        $requeridas = [];
        foreach ($lineItems as $i => $it) {
            $props = $it['properties'] ?? [];
            $tieneOriginal = false;

            foreach ($props as $p) {
                $v = isset($p['value']) ? (string)$p['value'] : '';
                if (preg_match('/https?:\/\/.*\.(jpeg|jpg|png|gif|webp|svg)(\?.*)?$/i', $v)) {
                    $tieneOriginal = true;
                    break;
                }
            }

            if ($tieneOriginal) $requeridas[] = (int)$i;
        }

        // leemos qué indices ya tienen modificada guardada
        $rows = $db->table('placas_archivos')
            ->select('indice,ruta')
            ->where('lote_id', $orderId)
            ->where('tipo', 'modificada')
            ->get()->getResultArray();

        $mapLocales = [];
        foreach ($rows as $row) {
            $mapLocales[(int)$row['indice']] = base_url((string)$row['ruta']);
        }

        // faltan?
        $faltan = [];
        foreach ($requeridas as $iReq) {
            if (empty($mapLocales[$iReq])) $faltan[] = $iReq;
        }

        $estadoAuto = count($requeridas) === 0
            ? null
            : (count($faltan) === 0 ? 'Producción' : 'A medias');

        // --------- actualizar estado en tu sistema (pedidos_estado + history)
        if ($estadoAuto) {
            $this->setEstadoPedidoLocal($orderId, $estadoAuto);
        }

        return $this->response->setJSON([
            'success'         => true,
            'url'             => $url,
            'imagenes_locales'=> $mapLocales,
            'estado_auto'     => $estadoAuto,
            'requeridas'      => $requeridas,
            'faltan'          => $faltan,
        ]);
    }

    /**
     * Trae detalles del pedido desde Shopify usando tu lógica existente.
     * 👉 Aquí debes poner TU llamada real (la misma que usas en Dashboard::detalles).
     */
    private function fetchDetallesPedido(string $orderId): array
    {
        // ✅ OPCIÓN 1 (rápida): duplicar tu lógica de DashboardController::detalles
        // ✅ OPCIÓN 2 (mejor): mover la lógica Shopify a un Service y usarlo aquí.
        //
        // Aquí te dejo un placeholder para que lo conectes:
        //
        // return [
        //   'line_items' => $order['line_items'] ?? []
        // ];

        return ['line_items' => []]; // <-- cámbialo por tu implementación real
    }

    /**
     * Actualiza tu estado local del pedido.
     * Ajusta nombres de tabla/columnas según tu DB real.
     */
    private function setEstadoPedidoLocal(string $orderId, string $estado): void
    {
        $db = db_connect();

        // tabla pedidos_estado (según tu captura)
        // asumo columnas: order_id / estado / updated_at
        $ex = $db->table('pedidos_estado')->where('order_id', $orderId)->get()->getRowArray();

        $row = [
            'order_id'   => $orderId,
            'estado'     => $estado,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($ex) $db->table('pedidos_estado')->where('order_id', $orderId)->update($row);
        else     $db->table('pedidos_estado')->insert($row);

        // history
        $db->table('pedidos_estado_history')->insert([
            'order_id'    => $orderId,
            'estado'      => $estado,
            'user_name'   => session()->get('nombre') ?? 'Sistema',
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }
}
