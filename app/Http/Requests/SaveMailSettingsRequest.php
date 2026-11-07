<?php

namespace App\Http\Requests;

use App\Mail\MailSettings;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/** The Mail screen form, for "Save" and for "Send a test e-mail". Administrators only. */
class SaveMailSettingsRequest extends FormRequest
{
    private const NO_CONTROL = 'not_regex:/[\x00-\x1F\x7F]/';

    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    private function testing(): bool
    {
        return $this->routeIs('settings.mail.test');
    }

    public function rules(): array
    {
        // A test needs a complete server; a save may keep an unfinished form that is switched off.
        $required = $this->testing() || $this->boolean('enabled') ? 'required' : 'nullable';

        return [
            'enabled' => ['sometimes', 'boolean'],
            'host' => [$required, 'string', 'max:253', 'regex:/^[A-Za-z0-9._:\[\]-]+$/'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'encryption' => ['required', 'in:'.implode(',', MailSettings::ENCRYPTIONS)],
            'username' => ['nullable', 'string', 'max:255', self::NO_CONTROL],
            'password' => ['nullable', 'string', 'max:1000', self::NO_CONTROL],
            'remove_password' => ['sometimes', 'boolean'],
            'from_address' => [$required, 'string', 'max:254', 'email:rfc', self::NO_CONTROL],
            'from_name' => ['nullable', 'string', 'max:100', self::NO_CONTROL],
            'recipient' => [$this->testing() ? 'required' : 'prohibited', 'string', 'max:254', 'email:rfc', self::NO_CONTROL],
        ];
    }

    /** The stored (or environment) password never follows a change of server: it has to be typed again. */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $validator->errors()->any() && app(MailSettings::class)->passwordWouldTravel($this->all())) {
                $validator->errors()->add('password', 'Enter the password again: the host, port, user or encryption changed, and the stored password is never sent to another server. Tick “Remove the password” to connect without one.');
            }
        }];
    }
}
