<?php

namespace App\Actions\Application\Auth\Exceptions;

use RuntimeException;

/**
 * The provider returned an email that already belongs to an existing account
 * but flagged it as unverified. Linking it would let anybody who can register
 * that address at the provider sign in as the account owner, so the sign-in is
 * refused and the owner must verify the address through the normal flow.
 */
class UnverifiedProviderEmailException extends RuntimeException
{
    //
}
