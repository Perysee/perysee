@props(['href', 'icon', 'label', 'active' => false, 'future' => false])
<a href="{{ $href }}" @class(['nav-item', 'is-active' => $active]) @if($active) aria-current="page" @endif><x-icon :name="$icon" /><span>{{ __($label) }}</span>@if($future)<span class="nav-soon">{{ __('Soon') }}</span>@endif</a>
