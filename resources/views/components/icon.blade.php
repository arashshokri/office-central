@props(['name' => 'grid'])
@php($paths = [
    'grid'=>'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
    'users'=>'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M16 3a4 4 0 0 1 0 8 M22 21v-2a4 4 0 0 0-3-3.87 M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0',
    'box'=>'m12 3 9 5-9 5-9-5 9-5z M3 8v9l9 5 9-5V8 M12 13v9 M7.5 5.5l9 5',
    'upload'=>'M12 16V3 m-5 5 5-5 5 5 M3 16v5h18v-5',
    'key'=>'M15 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0 M11 11v10 m0-4h4 m-4 4h3',
    'server'=>'M3 3h18v7H3z M3 14h18v7H3z M7 6.5h.01 M7 17.5h.01 M11 6.5h6 M11 17.5h6',
    'shield'=>'m12 2 9 4v6c0 5-9 10-9 10S3 17 3 12V6l9-4z m-4 10 3 3 5-5',
    'list'=>'M8 6h13 M8 12h13 M8 18h13 M3 6h.01 M3 12h.01 M3 18h.01',
    'code'=>'m8 7-5 5 5 5 m8-10 5 5-5 5 M14 3l-4 18',
    'menu'=>'M4 6h16 M4 12h16 M4 18h16',
    'sun'=>'M12 8a4 4 0 1 1 0 8 4 4 0 0 1 0-8 M12 2v2 M12 20v2 M2 12h2 M20 12h2 M5 5l1.5 1.5 M17.5 17.5 19 19 M5 19l1.5-1.5 M17.5 6.5 19 5',
    'logout'=>'M9 21H3V3h6 M9 12h12 m-4-4 4 4-4 4',
    'chevron'=>'m9 5 7 7-7 7',
])
<svg {{ $attributes->merge(['class'=>'ui-icon']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $paths[$name] ?? $paths['grid'] }}"/></svg>
