<?php

namespace App\Actions\Application\Auth;

use App\Actions\Application\Workspace\CreateWorkspaceAction;
use App\Models\User;
use Illuminate\Auth\Events\Registered;

class RegistrationAction
{
    public function __construct(private readonly CreateWorkspaceAction $createWorkspace) {}

    /**
     * Create a new user account with the given credentials.
     */
    public function execute(array $data): User
    {
        $user = User::create([
            'fullname' => $data['fullname'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        $this->createWorkspace->execute($user);

        event(new Registered($user));

        return $user;
    }
}
