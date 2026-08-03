<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- An installation that uploaded its own logo gets it as the tab icon
             too; the bundled neutral mark is the fallback. --}}
        @php($brandLogo = app(\App\Services\BrandingService::class)->forFrontend()['logoUrl'] ?? null)
        <link rel="icon" href="{{ $brandLogo ?: '/favicon.svg' }}">

        <title inertia>{{ app(\App\Services\BrandingService::class)->get('site_name') }}</title>

        {{-- WebSocket endpoint handed to Echo at runtime, so no deployment's own
             host is baked into the built bundle. Read in resources/js/bootstrap.js. --}}
        @php($broadcasting = [
            'key' => config('broadcasting.connections.reverb.key'),
            'host' => config('broadcasting.connections.reverb.options.host'),
            'port' => (int) config('broadcasting.connections.reverb.options.port'),
            'scheme' => config('broadcasting.connections.reverb.options.scheme'),
        ])
        <script>
            window.__broadcasting = @json($broadcasting);
        </script>

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead

        {{-- Accent ramp derived from the configured brand colour. Only emitted
             when an installation has set one, otherwise app.css stays in charge. --}}
        @php($brandPalette = app(\App\Services\BrandingService::class)->paletteVariables())
        @if (! empty($brandPalette))
            {{-- Identified so the settings screen can switch it off while it
                 previews a colour that has not been saved yet. --}}
            <style id="brand-palette">
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
