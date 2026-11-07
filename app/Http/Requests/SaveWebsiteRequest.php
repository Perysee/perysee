<?php

namespace App\Http\Requests;

use App\Models\Website;
use App\Support\Currencies;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveWebsiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $website = $this->route('website');

        return $website instanceof Website ? $this->user()->can('update', $website) : $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $domains = $this->input('allowed_domains', '');
        if ($domains === null) {
            $domains = [];
        }
        if (is_string($domains)) {
            $domains = array_values(array_filter(array_map(fn (string $domain) => strtolower(trim($domain)), preg_split('/\r\n|\r|\n/', $domains))));
        }
        $excluded = $this->input('excluded_paths');
        if (is_string($excluded)) {
            $this->merge(['excluded_paths' => array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $excluded))))]);
        }
        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => strtoupper(trim($this->input('currency'))) ?: null]);
        }
        $this->merge(['domain' => is_string($this->domain) ? strtolower(trim($this->domain)) : $this->domain, 'allowed_domains' => $domains]);
    }

    public function rules(): array
    {
        $domain = ['required', 'string', 'max:253', 'regex:/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/'];

        return [
            'tracking_mode' => ['sometimes', 'required', 'in:pageviews,sessions'],
            'consent_required' => ['sometimes', 'required', 'boolean'],
            'excluded_paths' => ['sometimes', 'nullable', 'array', 'max:50'],
            'excluded_paths.*' => ['required', 'string', 'max:512', 'regex:/^\\/(?!\\/)[^?#]*$/D'],
            'name' => ['required', 'string', 'max:150'],
            'domain' => $domain,
            'allowed_domains' => ['present', 'array', 'max:20'],
            'allowed_domains.*' => $domain,
            'timezone' => ['required', 'string', 'timezone:all', 'max:64'],
            'currency' => ['sometimes', 'nullable', 'string', Rule::in(Currencies::CODES)],
        ];
    }

    public function siteAttributes(): array
    {
        $data = $this->validated();
        $data['allowed_domains'] = array_values(array_unique([$data['domain'], ...$data['allowed_domains']]));

        return $data;
    }
}
