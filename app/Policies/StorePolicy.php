<?php

namespace App\Policies;

use App\Models\Store;
use App\Models\User;

/**
 * Stores are shared by all users. Any user can view, create and edit any store.
 * Only an admin can delete a store, because a delete removes every user's URLs and
 * prices for that store.
 */
class StorePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Store $store): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Store $store): bool
    {
        return true;
    }

    public function delete(User $user, Store $store): bool
    {
        return $user->isAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
