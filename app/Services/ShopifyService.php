<?php

namespace App\Services;

class ShopifyService
{
    public function getOrders($queryParams)
{
    $domain = getenv('SHOPIFY_STORE_DOMAIN');
    $token  = getenv('SHOPIFY_ADMIN_TOKEN');

    $url = "https://{$domain}/admin/api/2023-10/orders.json?" . $queryParams;

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "X-Shopify-Access-Token: {$token}",
        "Content-Type: application/json"
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        return ["error" => curl_error($ch)];
    }

    curl_close($ch);

    return json_decode($response, true);
}


    // -----------------------------
    // EXTRAE next y previous
    // -----------------------------
    private function parseLinkHeaders($headers)
    {
        $links = [];

        if (preg_match_all('/<(.*?)>; rel="(.*?)"/', $headers, $matches)) {
            foreach ($matches[2] as $i => $rel) {
                $url = $matches[1][$i];

                // buscar page_info
                if (strpos($url, "page_info=") !== false) {
                    parse_str(parse_url($url, PHP_URL_QUERY), $query);

                    if (isset($query['page_info'])) {
                        $links[$rel] = $query['page_info'];
                    }
                }
            }
        }

        return $links;
    }

    public function graphql(string $query, array $variables = []): array
{
    $shop  = getenv('SHOPIFY_SHOP');   // ej: midominio.myshopify.com
    $token = getenv('SHOPIFY_TOKEN');  // Admin API token
    $ver   = getenv('SHOPIFY_VER') ?: '2024-04';

    $url = "https://{$shop}/admin/api/{$ver}/graphql.json";

    $payload = json_encode(['query' => $query, 'variables' => $variables]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "X-Shopify-Access-Token: {$token}",
        ],
    ]);

    $out = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($out === false) throw new \RuntimeException("Shopify GraphQL error: {$err}");

    $json = json_decode($out, true);
    if (!empty($json['errors'])) {
        throw new \RuntimeException("Shopify GraphQL errors: " . json_encode($json['errors']));
    }

    return $json['data'] ?? [];
}

public function searchOrdersByTag(string $tag, int $max = 300): array
{
    $query = <<<'GQL'
query($q: String!, $first: Int!, $after: String) {
  orders(query: $q, first: $first, after: $after, sortKey: CREATED_AT, reverse: false) {
    edges {
      cursor
      node {
        id
        name
        createdAt
        displayFinancialStatus
        displayFulfillmentStatus
        tags
        totalPriceSet { shopMoney { amount currencyCode } }
        customer { firstName lastName }
        lineItems(first: 1) { totalCount }
      }
    }
    pageInfo { hasNextPage endCursor }
  }
}
GQL;

    $q = 'tag:"' . $tag . '"';
    $first = min(100, $max);

    $after = null;
    $all = [];

    while (count($all) < $max) {
        $data = $this->graphql($query, [
            'q' => $q,
            'first' => $first,
            'after' => $after,
        ]);

        $orders = $data['orders']['edges'] ?? [];
        foreach ($orders as $e) {
            $n = $e['node'];

            $all[] = [
                'id' => $n['id'], // gid
                'id_num' => $this->gidToId($n['id']), // num
                'name' => $n['name'],
                'created_at' => $n['createdAt'],
                'financial_status' => $n['displayFinancialStatus'],
                'fulfillment_status' => $n['displayFulfillmentStatus'],
                'tags' => $n['tags'] ?? [],
                'total' => (float)($n['totalPriceSet']['shopMoney']['amount'] ?? 0),
                'currency' => $n['totalPriceSet']['shopMoney']['currencyCode'] ?? 'EUR',
                'cliente_nombre' => trim(($n['customer']['firstName'] ?? '') . ' ' . ($n['customer']['lastName'] ?? '')) ?: '—',
                'items_count' => (int)($n['lineItems']['totalCount'] ?? 0),
            ];
            if (count($all) >= $max) break;
        }

        $pi = $data['orders']['pageInfo'] ?? [];
        if (empty($pi['hasNextPage'])) break;
        $after = $pi['endCursor'] ?? null;
        if (!$after) break;
    }

    return $all;
}

public function getOrdersByIds(array $ids): array
{
    // ids pueden venir como numéricos (order_id) -> convertimos a gid
    $gids = [];
    foreach ($ids as $id) {
        $id = (string)$id;
        if (str_starts_with($id, 'gid://')) $gids[] = $id;
        else $gids[] = "gid://shopify/Order/{$id}";
    }

    $query = <<<'GQL'
query($ids: [ID!]!) {
  nodes(ids: $ids) {
    ... on Order {
      id
      name
      createdAt
      displayFinancialStatus
      displayFulfillmentStatus
      tags
      totalPriceSet { shopMoney { amount currencyCode } }
      customer { firstName lastName }
      lineItems(first: 1) { totalCount }
    }
  }
}
GQL;

    $data = $this->graphql($query, ['ids' => $gids]);
    $nodes = $data['nodes'] ?? [];

    $out = [];
    foreach ($nodes as $n) {
        if (!$n) continue;
        $out[] = [
            'id' => $n['id'],
            'id_num' => $this->gidToId($n['id']),
            'name' => $n['name'],
            'created_at' => $n['createdAt'],
            'financial_status' => $n['displayFinancialStatus'],
            'fulfillment_status' => $n['displayFulfillmentStatus'],
            'tags' => $n['tags'] ?? [],
            'total' => (float)($n['totalPriceSet']['shopMoney']['amount'] ?? 0),
            'currency' => $n['totalPriceSet']['shopMoney']['currencyCode'] ?? 'EUR',
            'cliente_nombre' => trim(($n['customer']['firstName'] ?? '') . ' ' . ($n['customer']['lastName'] ?? '')) ?: '—',
            'items_count' => (int)($n['lineItems']['totalCount'] ?? 0),
        ];
    }

    return $out;
}

private function gidToId(string $gid): string
{
    // gid://shopify/Order/1234567890 -> 1234567890
    $parts = explode('/', $gid);
    return end($parts) ?: $gid;
}

}
