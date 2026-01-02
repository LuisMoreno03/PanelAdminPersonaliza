<?php



class ProduccionController extends BaseController
{
      public function index()
    {
        if (!session()->get('logged_in')) {
            return redirect()->to('/dasboard');
        }

        return view('produccion');
    }
}
