@extends('emails.layouts.base')
@section('title', 'Verificá tu cuenta')
@section('preheader', 'Tu código de verificación')
@section('content')
    <p style="margin:0 0 12px 0;">Hola {{ $firstName }},</p>
    <p style="margin:0 0 12px 0;">Gracias por registrarte en {{ config('app.name') }}.</p>
    <p style="margin:0 0 12px 0;">Para verificar tu cuenta, ingresá el siguiente código:</p>
    @include('emails.components.code', ['code' => $code])
    <p style="margin:0 0 12px 0;">Este código expira en {{ $expirationMinutes }} minutos.</p>
    <p style="margin:0 0 12px 0;color:#6b7280;">Si no creaste esta cuenta, podés ignorar este mensaje.</p>
@endsection
