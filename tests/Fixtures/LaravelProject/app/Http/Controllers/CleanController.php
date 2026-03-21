<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class CleanController
{
    public function index()
    {
        $this->authorize('viewAny');
        return response()->json([]);
    }

    public function store(Request $request)
    {
        $request->validate(['name' => 'required|string|max:255']);
        return response()->json(['status' => 'created'], 201);
    }
}
