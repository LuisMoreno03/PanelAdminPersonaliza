<?php

namespace App\Models;

use CodeIgniter\Model;

class PedidoModel extends Model
{
    protected $table = 'pedidos';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    protected $allowedFields = [
        'numero_pedido', 'created_at', 'cliente_nombre', 'total',
        'estado', 'etapa', 'estado_envio',
        'asignado_a', 'asignado_en', 'ultimo_cambio',
        'items_json'
    ];

    /**
     * Mi cola actual: por_producir, asignado a mí, y NO enviado
     */
    public function getMinePorProducir(string $user): array
    {
        return $this->select('id, numero_pedido, created_at, cliente_nombre, total, estado, ultimo_cambio, items_json, estado_envio')
            ->where('etapa', 'por_producir')
            ->where('asignado_a', $user)
            ->groupStart()
                ->where('estado_envio IS NULL', null, false)
                ->orWhere('estado_envio !=', 'enviado')
            ->groupEnd()
            ->orderBy('created_at', 'ASC')
            ->findAll();
    }

    /**
     * Claim 50/100 sin pisarse entre usuarios:
     * usamos transacción + SELECT ... FOR UPDATE (MySQL/InnoDB).
     */
    public function claimPorProducir(string $user, int $limit): array
    {
        $db = $this->db;
        $now = date('Y-m-d H:i:s');

        $db->transStart();

        // OJO: ajusta nombres/condiciones si tu lógica de "por_producir" es diferente
        $sql = "
            SELECT id
            FROM {$this->table}
            WHERE etapa = 'por_producir'
              AND asignado_a IS NULL
              AND (estado_envio IS NULL OR estado_envio <> 'enviado')
            ORDER BY created_at ASC
            LIMIT ?
            FOR UPDATE
        ";
        $idsRows = $db->query($sql, [$limit])->getResultArray();
        $ids = array_map(fn($r) => (int)$r['id'], $idsRows);

        if ($ids) {
            $db->table($this->table)
                ->whereIn('id', $ids)
                ->update([
                    'asignado_a' => $user,
                    'asignado_en' => $now,
                    'ultimo_cambio' => $now,
                ]);
        }

        $db->transComplete();

        if (!$ids) return [];

        return $this->select('id, numero_pedido, created_at, cliente_nombre, total, estado, ultimo_cambio, items_json, estado_envio')
            ->whereIn('id', $ids)
            ->orderBy('created_at', 'ASC')
            ->findAll();
    }

    public function returnAllPorProducir(string $user): int
    {
        $builder = $this->db->table($this->table);

        $builder->where('etapa', 'por_producir')
            ->where('asignado_a', $user);

        $builder->update([
            'asignado_a' => null,
            'asignado_en' => null,
            'ultimo_cambio' => date('Y-m-d H:i:s'),
        ]);

        return $this->db->affectedRows();
    }

    /**
     * Dado un set de IDs actualmente en UI, devolvemos los IDs que deben salir:
     * - estado_envio = enviado
     * - o etapa ya no es por_producir
     */
    public function getIdsToRemoveFromPorProducir(array $ids): array
    {
        $rows = $this->select('id, etapa, estado_envio')
            ->whereIn('id', $ids)
            ->findAll();

        $keep = [];
        foreach ($rows as $r) {
            $isPorProducir = ($r['etapa'] ?? '') === 'por_producir';
            $isEnviado = ($r['estado_envio'] ?? null) === 'enviado';
            if ($isPorProducir && !$isEnviado) $keep[] = (int)$r['id'];
        }

        $removed = array_values(array_diff($ids, $keep));
        sort($removed);
        return $removed;
    }
}
