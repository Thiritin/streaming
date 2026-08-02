<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <!-- Favicon -->
        <link rel="icon" type="image/svg" href="/favicon.svg">

        <title inertia>{{ app(\App\Services\BrandingService::class)->get('site_name') }}</title>
        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead

        {{-- Accent ramp derived from the configured brand colour. Only emitted
             when an installation has set one, otherwise app.css stays in charge. --}}
        @php($brandPalette = app(\App\Services\BrandingService::class)->paletteVariables())
        @if (! empty($brandPalette))
            <style>
                :root {
                    @foreach ($brandPalette as $property => $value)
                        {{ $property }}: {{ $value }};
                    @endforeach
                }
            </style>
        @endif
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
