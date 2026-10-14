@extends('emails.layouts.base')
@section('title', $alertSubject ?? config('app.name'))
@section('content')
    @foreach (preg_split('/\R/', $body) as $line)
        @if (trim($line) !== '')
            <p style="margin:0 0 12px 0;">{{ $line }}</p>
        @endif
    @endforeach
    @if (!empty($actionUrl))
        @include('emails.components.button', ['url' => $actionUrl, 'label' => $actionLabel ?: 'Abrir enlace'])
    @endif
@endsection
