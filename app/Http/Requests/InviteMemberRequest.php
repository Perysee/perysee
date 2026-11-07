<?php

namespace App\Http\Requests;

use App\Models\Website;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $website = $this->route('website');

        return $website instanceof Website && $this->user()->can('manageMembers', $website);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge(['email' => mb_strtolower(trim($this->email))]);
        }
    }

    public function rules(): array
    {
        $website = $this->route('website');

        return [
            'email' => ['required', 'string', 'email', 'max:255', Rule::notIn($website->members()->pluck('users.email')->map(fn (string $email) => mb_strtolower($email))->all())],
            'role' => ['required', 'in:owner,viewer'],
        ];
    }

    public function messages(): array
    {
        return ['email.not_in' => 'This person already has access to the site.'];
    }
}
