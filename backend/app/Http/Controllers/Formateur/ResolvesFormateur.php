<?php

namespace App\Http\Controllers\Formateur;

use App\Models\Formateur;

trait ResolvesFormateur
{
    /**
     * Resolve the Formateur profile linked to the authenticated user.
     *
     * A user account with the "formateur" role can exist without a linked
     * Formateur record (incomplete account creation, or a broken link).
     * Dereferencing that null relation used to raise a 500 error and break
     * every formateur screen, so it is rejected explicitly instead.
     */
    protected function currentFormateur(): Formateur
    {
        $formateur = auth()->user()?->formateur;

        abort_if(
            $formateur === null,
            403,
            "Aucun profil formateur n'est associe a ce compte."
        );

        return $formateur;
    }
}
