<?php

namespace App\Controllers;

use Config\Database;

class PedidosController extends BaseController
{
    public function listarPedidos()
    {
        // 1) Obtienes los pedidos (como ya lo haces hoy)
        $pedidos = $this->obtenerPedidos(); // <-- asegúrate que este método exista

        // 2) AGREGAR INFO DE ÚLTIMO CAMBIO DE ESTADO
        $db = Database::connect();

        foreach ($pedidos as &$p) {
            $row = $db->table('pedidos_estado pe')
                ->select('pe.created_at as changed_at, u.nombre as user_name')
                ->join('usuarios u', 'u.id = pe.user_id', 'left')
                ->where('pe.pedido_id', $p['id'])
                ->orderBy('pe.created_at', 'DESC')
                ->get()
                ->getRowArray();

            $p['last_estado_changed_at'] = $row['changed_at'] ?? null;
            $p['last_estado_user_name']  = $row['user_name'] ?? null;
        }

        return view('pedidos', 'PedidosController::listarPedidos', ['pedidos' => $pedidos]);
    }

    // EJEMPLO: si no existe aún, crea este método o reemplázalo por tu lógica real
    private function obtenerPedidos(): array
    {
        return [];
    }
}
