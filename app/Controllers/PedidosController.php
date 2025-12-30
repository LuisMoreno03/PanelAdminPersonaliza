public function listarPedidos()
{
    // 1️⃣ Obtienes los pedidos (como ya lo haces hoy)
    // EJEMPLO:
    $pedidos = $this->obtenerPedidos(); 
    // $pedidos debe ser un array de pedidos con ['id'] y ['created_at']

    // 2️⃣ AGREGAR INFO DE ÚLTIMO CAMBIO DE ESTADO
    $db = \Config\Database::connect();

    foreach ($pedidos as &$p) {
        $row = $db->table('pedidos_estado pe')
            ->select('pe.created_at as changed_at, u.nombre as user_name')
            ->join('users u', 'u.id = pe.user_id', 'left')
            ->where('pe.pedido_id', $p['id'])
            ->orderBy('pe.created_at', 'DESC')
            ->get()
            ->getRowArray();

        $p['last_status_change'] = [
            'user_name'  => $row['user_name'] ?? 'Shopify',
            'changed_at' => $row['changed_at'] ?? ($p['created_at'] ?? null),
        ];
    }
    unset($p); // buena práctica

    // 3️⃣ DEVOLVER AL FRONT
    return $this->response->setJSON([
        'success' => true,
        'pedidos' => $pedidos
    ]);
}
