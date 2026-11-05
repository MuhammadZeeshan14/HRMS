@php
$paths = [
'dashboard'=>'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
'users'=>'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M22 21v-2a4 4 0 0 0-3-3.87 M16 3.13a4 4 0 0 1 0 7.75',
'database'=>'M20 6c0 2-3.6 3-8 3S4 8 4 6s3.6-3 8-3 8 1 8 3z M4 6v12c0 2 3.6 3 8 3s8-1 8-3V6 M4 12c0 2 3.6 3 8 3s8-1 8-3',
'chevron'=>'m9 5 7 7-7 7', 'down'=>'m6 9 6 6 6-6',
'menu'=>'M3 6h14 M3 12h10 M3 18h14 m17-12-5 6 5 6',
'bell'=>'M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9 M10 21h4',
'logout'=>'M9 4H4v16h5 M9 12h12 m-4-4 4 4-4 4',
'search'=>'M21 21l-6-6 M17 9a8 8 0 1 0-16 0 8 8 0 0 0 16 0',
'add-user'=>'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M20 8v6 M17 11h6',
'eye'=>'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z M15 12a3 3 0 1 0-6 0 3 3 0 0 0 6 0',
'edit'=>'m16 3 5 5-12 12-6 1 1-6z M14 5l5 5',
'pin'=>'M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0z M15 10a3 3 0 1 0-6 0 3 3 0 0 0 6 0',
'check'=>'m8 12 3 3 5-6 M22 12a10 10 0 1 0-20 0 10 10 0 0 0 20 0',
'save'=>'M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h12l4 4v12a2 2 0 0 1-2 2z M7 3v6h10V3 M7 21v-8h10v8',
'list'=>'M4 3h16v18H4z M7 7h10 M7 12h10 M7 17h10',
'upload'=>'M12 16V4 m-4 4 4-4 4 4 M4 16v4h16v-4',
'cloud'=>'M7 18a5 5 0 1 1 0-10 6 6 0 0 1 12-1 5 5 0 0 1-1 11H7 m2-5 2 2 4-4',
'id'=>'M4 6h16v15H4z M9 6V3h6v3 M8 11h3v3H8z M14 11h3 M14 15h3 M8 18h9',
'shield'=>'m12 2 9 4v6c0 6-9 10-9 10S3 18 3 12V6z m-4 10 3 3 5-6',
];
@endphp
<svg {{ $attributes->merge(['class'=>'ui-icon']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $paths[$name] ?? $paths['dashboard'] }}"/></svg>
