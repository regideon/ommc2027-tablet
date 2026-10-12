<?php

namespace App\Contracts;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Contract every per-form service implements so the Forms page can list,
 * create, edit, and adopt submissions without form-specific branching.
 */
interface FormSubmissionService
{
    /**
     * Refresh and return the form's master-data picklist (empty when the form
     * has no master data).
     *
     * @return Collection<int, Model>
     */
    public function programs(): Collection;

    public function programReadFailed(): bool;

    /**
     * The current user's submissions merged with a live Portal pull.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function submissions(): Collection;

    public function globalReadFailed(): bool;

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(User $user, array $input): Model;

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Model $submission, array $input): Model;

    /**
     * Materialize a pulled server row locally so it can be edited and pushed
     * back with its existing server id.
     *
     * @param  array<string, mixed>  $row
     */
    public function adopt(User $user, array $row): Model;
}
