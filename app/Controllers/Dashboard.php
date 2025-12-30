<?php

namespace App\Controllers;

class Dashboard extends BaseController
{
    public function index()
    {
        if (!session()->get('logged_in')) {
            return redirect()->to('/dasboard');
        }

            }return view('dashboard');
    }

 public function confirmados()
{
    return view('confirmados');}