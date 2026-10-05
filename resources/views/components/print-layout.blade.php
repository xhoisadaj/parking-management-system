@props(['title', 'width', 'autoprint' => false])
@include('print.layout', ['title' => $title, 'width' => $width, 'autoprint' => $autoprint, 'slot' => $slot])
