<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ApiController
{
    public function index()
    {
        return response()->json([]);
    }

    public function store(Request $request)
    {
        return response()->json(['status' => 'ok']);
    }
}
