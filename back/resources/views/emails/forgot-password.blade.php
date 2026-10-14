@extends('emails.layouts.base')
@section('title', 'Recuperación de contraseña')
@section('preheader', 'Tu código para recuperar la contraseña')
@section('content')
    <p style="margin:0 0 12px 0;">Hola {{ $firstName }},</p>
    <p style="margin:0 0 12px 0;">Recibimos una solicitud para recuperar la contraseña de tu cuenta en {{ config('app.name') }}.</p>
    <p style="margin:0 0 12px 0;">Ingresá el siguiente código para continuar:</p>
    @include('emails.components.code', ['code' => $code])
    <p style="margin:0 0 12px 0;">Este código expira en {{ $expirationMinutes }} minutos.</p>
    <p style="margin:0 0 12px 0;color:#6b7280;">Si no solicitaste este cambio, podés ignorar este mensaje. Tu contraseña no será modificada.</p>
@endsection
