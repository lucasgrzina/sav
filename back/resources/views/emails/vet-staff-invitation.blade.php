@extends('emails.layouts.base')
@section('title', 'Invitación al equipo')
@section('preheader', 'Fuiste invitado/a a ' . $vetName)
@section('content')
    <p style="margin:0 0 12px 0;">Hola {{ $firstName }},</p>
    <p style="margin:0 0 12px 0;">Fuiste invitado/a a formar parte del equipo de {{ $vetName }} en {{ config('app.name') }}.</p>
    <p style="margin:0 0 12px 0;">Para activar tu cuenta y establecer tu contraseña, hacé clic en el siguiente botón:</p>
    @include('emails.components.button', ['url' => $invitationUrl, 'label' => 'Activar mi cuenta'])
    <p style="margin:0 0 12px 0;">Este enlace expira en {{ $expirationHours }} horas.</p>
    <p style="margin:0 0 12px 0;color:#6b7280;">Si no esperabas esta invitación, podés ignorar este mensaje.</p>
@endsection
