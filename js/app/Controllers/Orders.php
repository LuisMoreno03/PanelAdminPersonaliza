<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class Orders extends Controller
{
    // =============================
    // 1. FUNCIÓN QUE LISTA PEDIDOS
    // =============================
    public function todos($page = 1)
    {
        // ... tu código de obtener pedidos de Shopify ...

        $resultado[] = [
            "id"           => $order["id"],
            "fecha"        => substr($order["created_at"], 0, 10),
            "cliente"      => $cliente,
            "total"        => $order["total_price"] . "€",

            // 👇 AQUÍ MOSTRAMOS EL ESTADO INTERNO
            "estado"       => $this->obtenerEstadoInterno($order["id"]),
            
            "etiquetas"    => implode(", ", $order["tags"] ?? []),
            "articulos"    => count($order["line_items"] ?? []),
            "estado_envio" => $order["fulfillment_status"] ?? "-",
            "forma_envio"  => $order["shipping_lines"][0]["title"] ?? "-"
        ];

        return $this->response->setJSON([
            "orders" => $resultado,
            "total"  => count($resultado)
        ]);
    }

    // ==========================================
    // 2. FUNCIÓN PARA LEER EL ESTADO INTERNO
    // ==========================================
    private function obtenerEstadoInterno($orderId)
    {
        $db = \Config\Database::connect();

        $row = $db->table("pedidos_estado")
                  ->where("id", $orderId)
                  ->get()
                  ->getRow();

        return $row ? $row->estado : "Por preparar";
    }

    // ===============================================
    // 3. AQUÍ VA EL PASO 4 — GUARDAR EL ESTADO
    // ===============================================
    public function actualizarEstado()
{
    $data = $this->request->getJSON(true);

    $id = $data["id"];
    $estado = $data["estado"];

    $db = \Config\Database::connect();

    $exists = $db->table("pedidos_estado")->where("id", $id)->countAllResults();

    if ($exists) {
        $db->table("pedidos_estado")
            ->where("id", $id)
            ->update(["estado" => $estado]);
    } else {
        $db->table("pedidos_estado")->insert([
            "id" => $id,
            "estado" => $estado
        ]);
    }

    return $this->response->setJSON(["success" => true]);
}

}
