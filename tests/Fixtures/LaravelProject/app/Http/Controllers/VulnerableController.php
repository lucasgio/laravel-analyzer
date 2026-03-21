<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class VulnerableController
{
    public function index(Request $request)
    {
        // SQL injection - concatenation into raw query
        $users = \DB::select("SELECT * FROM users WHERE id = " . $request->id);

        // Mass assignment - create with all request data
        \App\Models\User::create($request->all());

        // XSS - direct echo of user input
        echo $request->name;

        // Command injection - exec with variable
        exec("ls " . $request->path);

        // Debug leak
        dd($users);

        // Weak hash
        $hash = md5($request->password);

        // Open redirect
        return redirect($request->get('url'));
    }

    public function store(Request $request)
    {
        // No validation - OWASP A04
    }

    public function update(Request $request)
    {
        // No validation - OWASP A04
    }

    public function destroy(Request $request)
    {
    }

    public function show(Request $request)
    {
    }
}
