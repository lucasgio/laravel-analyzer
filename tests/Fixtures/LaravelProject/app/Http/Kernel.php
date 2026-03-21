<?php

namespace App\Http;

class Kernel
{
    protected $middleware = [];
    protected $middlewareGroups = ['web' => [], 'api' => []];
}
