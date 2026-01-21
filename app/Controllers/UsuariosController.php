<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use App\Models\PedidoImagenModel;
use App\Models\PedidosEstadoModel;

class UsuariosController extends Controller
{
    private string $shop = '';
    private string $token = '';
    private string $apiVersion = '2025-10';

    // ✅ Estados permitidos (los del modal)
    private array $allowedEstados = [
        'Por preparar',
        'Faltan archivos',
        'Confirmado',
        'Diseñado',
        'Por producir',
        'Enviado',
        'Repetir',
    ];


    public function __construct()
    {
        // 1) Config/Shopify.php $estadoModel
        $this->loadShopifyFromConfig();

        // 2) archivo fuera del repo
        if (!$this->shop || !$this->token) {
            $this->loadShopifySecretsFromFile();
        }

        // 3) env() (fallback)
        if (!$this->shop || !$this->token) {
            $this->loadShopifyFromEnv();
        }

        // Normalizar dominio
        $this->shop = trim($this->shop);
        $this->shop = preg_replace('#^https?://#', '', $this->shop);
        $this->shop = preg_replace('#/.*$#', '', $this->shop);
        $this->shop = rtrim($this->shop, '/');

        $this->token = trim($this->token);
        $this->apiVersion = trim($this->apiVersion ?: '2025-10');
    }

    // =====================================================
    // CONFIG LOADERS  dashboard
    // =====================================================

    private function loadShopifyFromConfig(): void
    {
        try {
            $cfg = config('Shopify');
            if (!$cfg) return;

            $this->shop       = (string) ($cfg->shop ?? $cfg->SHOP ?? $this->shop);
            $this->token      = (string) ($cfg->token ?? $cfg->TOKEN ?? $this->token);
            $this->apiVersion = (string) ($cfg->apiVersion ?? $cfg->version ?? $cfg->API_VERSION ?? $this->apiVersion);
        } catch (\Throwable $e) {
            log_message('error', 'DRepetirController loadShopifyFromConfig ERROR: ' . $e->getMessage());
        }
    }

    private function loadShopifyFromEnv(): void
    {
        try {
            $shop  = (string) env('SHOPIFY_STORE_DOMAIN');
            $token = (string) env('SHOPIFY_ADMIN_TOKEN');
            $ver   = (string) (env('SHOPIFY_API_VERSION') ?: '2025-10');

            if (!empty(trim($shop)))  $this->shop = $shop;
            if (!empty(trim($token))) $this->token = $token;
            if (!empty(trim($ver)))   $this->apiVersion = $ver;
        } catch (\Throwable $e) {
            log_message('error', 'UsuariosController loadShopifyFromEnv ERROR: ' . $e->getMessage());
        }
    }

    private function loadShopifySecretsFromFile(): void
    {
        try {
            $path = '/home/u756064303/.secrets/shopify.php';
            if (!is_file($path)) return;

            $cfg = require $path;
            if (!is_array($cfg)) return;

            $this->shop       = (string) ($cfg['shop'] ?? $this->shop);
            $this->token      = (string) ($cfg['token'] ?? $this->token);
            $this->apiVersion = (string) ($cfg['apiVersion'] ?? $cfg['version'] ?? $this->apiVersion);
        } catch (\Throwable $e) {
            log_message('error', 'UsuariosController loadShopifySecretsFromFile ERROR: ' . $e->getMessage());
        }
    }

    // =====================================================
    // HELPERS    
    // =====================================================

    private function parseLinkHeaderForPageInfo(?string $linkHeader): array
    {
        $next = null;
        $prev = null;

        if (!$linkHeader) return [$next, $prev];

        if (preg_match('/<[^>]*[?&]page_info=([^&>]+)[^>]*>; rel="next"/', $linkHeader, $m)) {
            $next = urldecode($m[1]);
        }
        if (preg_match('/<[^>]*[?&]page_info=([^&>]+)[^>]*>; rel="previous"/', $linkHeader, $m2)) {
            $prev = urldecode($m2[1]);
        }
        return [$next, $prev];
    }

    private function curlShopify(string $url, string $method = 'GET', ?array $payload = null): array
    {
        $headers = [];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                "Accept: application/json",
                "Content-Type: application/json",
                "X-Shopify-Access-Token: {$this->token}",
            ],
            CURLOPT_HEADERFUNCTION => function ($curl, $headerLine) use (&$headers) {
                $len = strlen($headerLine);
                $headerLine = trim($headerLine);
                if ($headerLine === '' || strpos($headerLine, ':') === false) return $len;

                [$name, $value] = explode(':', $headerLine, 2);
                $name = strtolower(trim($name));
                $value = trim($value);

                if (!isset($headers[$name])) $headers[$name] = $value;
                else {
                    if (is_array($headers[$name])) $headers[$name][] = $value;
                    else $headers[$name] = [$headers[$name], $value];
                }
                return $len;
            },
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        return [
            'status'  => $status,
            'body'    => is_string($body) ? $body : '',
            'headers' => $headers,
            'error'   => $err ?: null,
        ];
    }

    // ============================================================
    // ✅ NORMALIZAR ESTADOS (viejos -> nuevos)
    // ============================================================

    private function normalizeEstado(?string $estado): string
    {
        $s = trim((string)($estado ?? ''));
        if ($s === '') return 'Por preparar';

        $lower = mb_strtolower($s);

        $map = [
            // base
            'por preparar'     => 'Por preparar',
            'pendiente'        => 'Por preparar',

            // faltan archivos
            'faltan archivos'  => 'Faltan archivos',
            'faltan archivo'   => 'Faltan archivos',
            'archivos faltan'  => 'Faltan archivos',
            'sin archivos'     => 'Faltan archivos',

            // confirmado
            'confirmado'       => 'Confirmado',
            'confirmada'       => 'Confirmado',

            // diseñado
            'diseñado'         => 'Diseñado',
            'disenado'         => 'Diseñado',
            'diseño'           => 'Diseñado',
            'ddiseño'          => 'Diseñado',
            'd.diseño'         => 'Diseñado',

            // por producir
            'por producir'     => 'Por producir',
            'produccion'       => 'Por producir',
            'producción'       => 'Por producir',
            'p.produccion'     => 'Por producir',
            'p.producción'     => 'Por producir',
            'fabricando'       => 'Por producir',
            'en produccion'    => 'Por producir',
            'en producción'    => 'Por producir',
            'a medias'         => 'Por producir',
            'produccion '      => 'Por producir',

            // enviado
            'enviado'          => 'Enviado',
            'entregado'        => 'Enviado',

            // repetir
            'repetir'          => 'Repetir',
            'reimpresion'      => 'Repetir',
            'reimpresión'      => 'Repetir',
            'rehacer'          => 'Repetir',
        ];

        if (isset($map[$lower])) return $map[$lower];

        foreach ($this->allowedEstados as $ok) {
            if (mb_strtolower($ok) === $lower) return $ok;
        }

        return 'Por preparar';
    }

   

    // ============================================================
    // VISTA PRINCIPAL 
    // ============================================================

    public function index()
    {
        if (!session()->get('logged_in')) {
            return redirect()->to('/');
        }

        return view('usuarios');
    }


    



    public function cambiarClave(): ResponseInterface
{
    if (!session()->get('logged_in')) {
        return $this->response->setStatusCode(401)->setJSON([
            'ok' => false,
            'message' => 'No autenticado',
            'csrf' => csrf_hash(),
        ]);
    }

    $userId = (int) (session()->get('user_id') ?? 0);
    if ($userId <= 0) {
        return $this->response->setStatusCode(401)->setJSON([
            'ok' => false,
            'message' => 'Sesión inválida (sin user_id).',
            'csrf' => csrf_hash(),
        ]);
    }

    $data = $this->request->getJSON(true);
    if (!is_array($data)) $data = [];

    $currentPassword = trim((string)($data['currentPassword'] ?? ''));
    $newPassword     = trim((string)($data['newPassword'] ?? ''));

    if ($currentPassword === '' || $newPassword === '') {
        return $this->response->setStatusCode(400)->setJSON([
            'ok' => false,
            'message' => 'Completa todos los campos.',
            'csrf' => csrf_hash(),
        ]);
    }

    if (strlen($newPassword) < 8) {
        return $this->response->setStatusCode(400)->setJSON([
            'ok' => false,
            'message' => 'La nueva clave debe tener al menos 8 caracteres.',
            'csrf' => csrf_hash(),
        ]);
    }

    if ($currentPassword === $newPassword) {
        return $this->response->setStatusCode(400)->setJSON([
            'ok' => false,
            'message' => 'La nueva clave no puede ser igual a la actual.',
            'csrf' => csrf_hash(),
        ]);
    }

    try {
        $db = \Config\Database::connect();

        // ✅ AJUSTA AQUÍ: tabla + campo de password
        $table = $db->table('usuarios');

        // Opción 1 (recomendada): password_hash
        $user = $table->select('id, password_hash')
                      ->where('id', $userId)
                      ->get()
                      ->getRowArray();

        // --- Si tu campo se llama "password" en vez de password_hash, usa esto:
        // $user = $table->select('id, password')
        //               ->where('id', $userId)
        //               ->get()
        //               ->getRowArray();

        if (!$user) {
            return $this->response->setStatusCode(404)->setJSON([
                'ok' => false,
                'message' => 'Usuario no encontrado.',
                'csrf' => csrf_hash(),
            ]);
        }

        $hashActual = (string)($user['password_hash'] ?? '');
        // --- Si usas "password":
        // $hashActual = (string)($user['password'] ?? '');

        if ($hashActual === '' || !password_verify($currentPassword, $hashActual)) {
            return $this->response->setStatusCode(401)->setJSON([
                'ok' => false,
                'message' => 'La clave actual no es correcta.',
                'csrf' => csrf_hash(),
            ]);
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

        // ✅ Guarda en BD (tiempo real)
        $ok = $table->where('id', $userId)->update([
            'password_hash' => $newHash,
            'password_changed_at' => date('Y-m-d H:i:s'),
        ]);

        // --- Si tu campo es "password":
        // $ok = $table->where('id', $userId)->update([
        //     'password' => $newHash,
        //     'password_changed_at' => date('Y-m-d H:i:s'),
        // ]);

        if (!$ok) {
            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'message' => 'No se pudo guardar la nueva clave.',
                'csrf' => csrf_hash(),
            ]);
        }

        return $this->response->setJSON([
            'ok' => true,
            'message' => 'Clave actualizada correctamente.',
            'csrf' => csrf_hash(),
        ]);

    } catch (\Throwable $e) {
        log_message('error', 'cambiarClave ERROR: ' . $e->getMessage());

        return $this->response->setStatusCode(500)->setJSON([
            'ok' => false,
            'message' => 'Error interno actualizando clave.',
            'csrf' => csrf_hash(),
        ]);
    }
}

}
