<?php

namespace App\Models;

class User
{
    protected $fillable = ['name', 'email'];
    protected $hidden = ['password', 'remember_token'];
}
