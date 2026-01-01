<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        // Si no está logueado:
        if (!session()->get('logged_in')) {

            // Si es llamada AJAX/JSON, responde 401 JSON
            $accept = strtolower((string) $request->getHeaderLine('Accept'));
            $isAjax = $request->isAJAX() || str_contains($accept, 'application/json');

            if ($isAjax) {
                return service('response')
                    ->setStatusCode(401)
                    ->setJSON(['success' => false, 'message' => 'No autenticado']);
            }

            // Si es vista normal:
            return redirect()->to('/');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // nada
    }
}
