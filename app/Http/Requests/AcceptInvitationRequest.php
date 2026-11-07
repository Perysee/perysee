<?php

namespace App\Http\Requests;

use App\Models\SiteInvitation;
use App\Rules\BcryptPassword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class AcceptInvitationRequest extends FormRequest
{
    /** An unusable token is answered before anything else, so that validation errors cannot tell it apart. */
    public function authorize(): bool
    {
        abort_unless(SiteInvitation::findPending((string) $this->route('token')), 404);

        return true;
    }

    /** A signed-in person only confirms; a new account needs a name and a password. */
    public function rules(): array
    {
        if ($this->user()) {
            return [];
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', Password::min(12), new BcryptPassword],
        ];
    }
}
