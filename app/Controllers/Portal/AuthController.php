<?php

declare(strict_types=1);

namespace App\Controllers\Portal;

use App\Controllers\Controller;
use App\Core\Response;

/**
 * Visitor authentication. Batch 1 ships placeholder pages only; batch 2 implements
 * register -> set-password email -> login -> profile/KYC wizard (spec §4.1).
 */
final class AuthController extends Controller
{
    public function showLogin(): Response
    {
        return $this->view('portal/coming-soon', [
            'title' => 'Visitor sign in',
            'heading' => 'Visitor sign in',
            'lead' => 'Online visitor accounts are coming soon. Meanwhile, our front desk can register you and book a seat in minutes.',
        ]);
    }

    public function showRegister(): Response
    {
        return $this->view('portal/coming-soon', [
            'title' => 'Create an account',
            'heading' => 'Create your Commune account',
            'lead' => 'Self-registration for individuals and institutions opens soon. You will register with your email, set a password and complete your KYC online.',
        ]);
    }
}
