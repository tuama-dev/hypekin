<?php

namespace App\Http\Requests;

use App\Models\Workspace;
use Illuminate\Foundation\Http\FormRequest;

abstract class WorkspaceAbilityRequest extends FormRequest
{
    /**
     * The workspace ability this request needs. A subclass that forgets to
     * declare it throws when authorize() runs rather than granting access.
     */
    protected string $ability;

    /**
     * Determine if the user is authorized to make this request.
     *
     * The route's `can:` middleware already applies this ability, so this is a
     * second line of defence: a route added later without that middleware
     * still refuses a member who does not hold the ability.
     */
    public function authorize(): bool
    {
        $workspace = $this->route('workspace');

        return $workspace instanceof Workspace
            && $this->user()?->can($this->ability, $workspace) === true;
    }
}
