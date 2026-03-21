<?php

namespace Database\Factories;

class UserFactory
{
    public function definition(): array
    {
        return [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ];
    }
}
