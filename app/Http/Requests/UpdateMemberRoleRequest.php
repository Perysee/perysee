<?php

namespace App\Http\Requests;

use App\Models\Website;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMemberRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $website = $this->route('website');

        return $website instanceof Website && $this->user()->can('manageMembers', $website);
    }

    public function rules(): array
    {
        return ['role' => ['required', 'in:owner,viewer']];
    }
}
