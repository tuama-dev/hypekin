<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        @livewireStyles
        @vite(['resources/css/marketing.css', 'resources/js/marketing.ts'])
    </head>
    <body>
        <livewire:landing-page />
    </body>
</html>