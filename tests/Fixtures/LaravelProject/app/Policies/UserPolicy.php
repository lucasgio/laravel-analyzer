<?php

namespace App\Policies;

class UserPolicy
{
    public function view($user, $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function create($user): bool
    {
        return true;
    }

    public function update($user, $model): bool
    {
        return $user->id === $model->user_id;
    }
}
