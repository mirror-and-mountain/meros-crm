<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
 
        <title>{{ $title ?? config('app.name') }}</title> 
        @viteAssets('meros_crm')
        @livewireStyles
    </head>
    <body class="font-sans">
        {{ $slot }}
        <footer>
            @livewireScripts
        </footer>
    </body>
</html>