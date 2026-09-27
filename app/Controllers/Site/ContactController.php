<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use Psr\Log\LoggerInterface;

/**
 * Contact page. The enquiry is validated and logged; emailing it to the centre
 * is wired up with Symfony Mailer in a later batch.
 */
final class ContactController extends Controller
{
    public function show(): Response
    {
        return $this->view('site/contact', ['title' => 'Contact us']);
    }

    public function submit(Request $request, LoggerInterface $logger): Response
    {
        $data = $this->validate($request, [
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190',
            'mobile' => 'nullable|mobile_in',
            'topic' => 'required|in:booking,pricing,visit,other',
            'message' => 'required|string|min:10|max:2000',
        ], [], ['mobile' => 'mobile number']);

        $logger->info('Contact enquiry from {email}', ['email' => $data['email'], 'topic' => $data['topic']]);

        return redirect(url('contact'))->with('success', 'Thanks, ' . $data['name'] . '! We have received your message and will get back to you within one working day.');
    }
}
