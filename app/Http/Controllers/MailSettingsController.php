<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveMailSettingsRequest;
use App\Mail\InstanceMailer;
use App\Mail\MailSettings;
use App\Support\Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * The instance SMTP server, managed by administrators. The server connects to the host an administrator chooses,
 * which is the same level of trust as the database host given to the installer.
 */
class MailSettingsController extends Controller
{
    public function show(Request $request, MailSettings $settings): Response
    {
        abort_unless($request->user()->is_admin, 403);

        return Ui::render('settings.mail', ['mail' => $settings->get(), 'recipient' => $request->user()->email, 'testResult' => $request->session()->pull('mail_test')]);
    }

    public function update(SaveMailSettingsRequest $request, MailSettings $settings): RedirectResponse
    {
        $settings->save($request->validated());

        return redirect()->route('settings.mail')->with('status', 'Mail settings saved.');
    }

    public function reset(Request $request, MailSettings $settings): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);
        $settings->reset();

        return redirect()->route('settings.mail')->with('status', 'Mail settings removed. The environment configuration is used again.');
    }

    public function test(SaveMailSettingsRequest $request, MailSettings $settings, InstanceMailer $mailer): RedirectResponse
    {
        $result = $mailer->test($request->validated('recipient'), $settings->build($request->validated()));

        return redirect()->route('settings.mail')->with('mail_test', $result + ['to' => $request->validated('recipient')]);
    }
}
