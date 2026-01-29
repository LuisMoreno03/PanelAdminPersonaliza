<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PorProducir extends Controller
{
    /**
     * Vista principal
     */
    public function index(Request $request)
    {
        // Solo renderiza la vista; la data la trae el pull por AJAX
        return view('porproducir.porProducir');
    }

    /**
     * Pull: trae 5 o 10 pedidos en estado "Diseñado"
     */
    public function pull(Request $request)
    {
        $limit = (int) $request->get('limit', 10);
        if (!in_array($limit, [5, 10], true)) $limit = 10;

        // Ajusta nombres de tabla/campos a tu DB real
        $pedidos = DB::table('pedidos')
            ->where('estado', 'Diseñado')
            ->orderBy('updated_at', 'asc') // o created_at, o prioridad
            ->limit($limit)
            ->get([
                'id',
                'numero_pedido',
                'cliente',
                'metodo_entrega',
                'estado',
                'total',
                'updated_at',
            ]);

        return response()->json([
            'ok' => true,
            'limit' => $limit,
            'data' => $pedidos,
        ]);
    }

    /**
     * Actualiza método de entrega.
     * Si método_entrega pasa a "Enviado", cambia estado a "Enviado" automáticamente.
     * Devuelve si debe removerse de la lista.
     */
    public function updateMetodoEntrega($id, Request $request)
    {
        $request->validate([
            'metodo_entrega' => 'required|string|max:50',
        ]);

        $metodo = trim($request->metodo_entrega);

        // Normaliza por si llega "enviado", "Enviado", etc.
        $metodoNorm = mb_strtolower($metodo);
        $esEnviado = ($metodoNorm === 'enviado');

        DB::beginTransaction();
        try {
            // Traemos el pedido para validar estado actual
            $pedido = DB::table('pedidos')->where('id', $id)->lockForUpdate()->first();

            if (!$pedido) {
                DB::rollBack();
                return response()->json(['ok' => false, 'message' => 'Pedido no encontrado'], 404);
            }

            // Solo trabajamos en esta sección con pedidos Diseñado
            // (si ya no está en Diseñado, igual actualizamos método, pero lo removemos de la UI)
            $nuevoEstado = $pedido->estado;

            if ($esEnviado) {
                $nuevoEstado = 'Enviado';
            }

            DB::table('pedidos')
                ->where('id', $id)
                ->update([
                    'metodo_entrega' => $metodo,
                    'estado' => $nuevoEstado,
                    'updated_at' => now(),
                ]);

            DB::commit();

            return response()->json([
                'ok' => true,
                'id' => (int)$id,
                'metodo_entrega' => $metodo,
                'estado' => $nuevoEstado,
                // si ya no es Diseñado, se debe quitar de la lista Por Producir
                'remove_from_list' => ($nuevoEstado !== 'Diseñado'),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['ok' => false, 'message' => 'Error al actualizar'], 500);
        }
    }
}
