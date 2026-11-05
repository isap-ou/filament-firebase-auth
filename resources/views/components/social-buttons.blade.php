@php
    use Illuminate\Support\Js;
@endphp

{{--
    The social sign-in buttons under Filament's own login and registration forms.
    `x-load-js` loads the bundle only on a page that shows the buttons. The bundle registers the
    Alpine data `firebaseSignIn`, then the template below renders the buttons with it.
    `wire:ignore` keeps the buttons when Livewire renders the page again.
--}}
<div
    class="fi-firebase-auth-social"
    wire:ignore
    x-data="{ loaded: false }"
    x-load-js="[{{ Js::from($scriptSrc) }}]"
    data-dispatch="filament-firebase-auth"
    x-on:filament-firebase-auth-js.window="loaded = true"
    style="display: grid; gap: 1rem; margin-top: 1.5rem"
>
    <div style="display: flex; align-items: center; gap: 0.75rem; font-size: 0.875rem; line-height: 1.25rem">
        <span style="flex: 1 1 0%; height: 1px; background-color: currentColor; opacity: 0.2"></span>
        <span style="opacity: 0.7">{{ __('filament-firebase-auth::social.divider') }}</span>
        <span style="flex: 1 1 0%; height: 1px; background-color: currentColor; opacity: 0.2"></span>
    </div>

    <template x-if="loaded">
        <div
            x-data="firebaseSignIn({{ Js::from($options) }})"
            style="display: grid; gap: 0.75rem"
        >
            @foreach ($buttons as $button)
                <x-filament::button
                    color="gray"
                    outlined
                    :icon="$button['icon']"
                    data-provider="{{ $button['id'] }}"
                    x-on:click="signIn({{ Js::from($button['id']) }})"
                    x-bind:disabled="busy"
                >
                    {{ $button['label'] }}
                </x-filament::button>
            @endforeach

            <p
                x-show="error"
                x-text="error"
                role="alert"
                class="fi-fo-field-wrp-error-message"
            ></p>
        </div>
    </template>
</div>
