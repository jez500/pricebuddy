<?php

namespace App\Policies;

use App\Models\ProductSource;
use App\Models\User;

class ProductSourcePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ProductSource $productSource): bool
    {
        return $this->userOwnsProductSource($user, $productSource);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ProductSource $productSource): bool
    {
        return $this->userOwnsProductSource($user, $productSource);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ProductSource $productSource): bool
    {
        return $this->userOwnsProductSource($user, $productSource);
    }

    /**
     * Search via a product source.
     */
    public function search(User $user, ProductSource $productSource): bool
    {
        return $this->userOwnsProductSource($user, $productSource);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ProductSource $productSource): bool
    {
        return $this->userOwnsProductSource($user, $productSource);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ProductSource $productSource): bool
    {
        return $this->userOwnsProductSource($user, $productSource);
    }

    protected function userOwnsProductSource(User $user, ProductSource $productSource): bool
    {
        return $productSource->user_id === $user->getKey();
    }
}
