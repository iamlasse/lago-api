{{-- Port of app/views/data_export_mailer/completed.slim (the Lago logo,
     greetings, intro with the resource type, the download CTA over the
     export's file_url, the fallback text and the sign-off). --}}
@extends('emails.layout')

@section('content')
    <div style="margin-bottom: 24px; font-style: normal; font-weight: 400; font-size: 16px; line-height: 24px; color: #19212E;">
        {{ __('email.data_export.completed.greetings') }}
    </div>
    <div style="margin-bottom: 32px; font-style: normal; font-weight: 400; font-size: 16px; line-height: 24px; color: #19212E;">
        {{ __('email.data_export.completed.intro', ['resource_type' => $resourceType]) }}
    </div>
    <table style="margin-bottom: 32px" width="100%" cellspacing="0" cellpadding="0">
        <tr>
            <td>
                <table cellspacing="0" cellpadding="0">
                    <tr>
                        <td style="border-radius: 12px;" bgcolor="#006CFA">
                            <a href="{{ $dataExport->fileUrl() }}" download="{{ $dataExport->filename() }}"
                               style="padding: 10px 16px; font-size: 16px; color: #ffffff; text-decoration: none; font-weight: bold; display: inline-block; font-weight: 400; font-style: normal; line-height: 24px;">
                                {{ __('email.data_export.completed.main_cta_label') }}
                            </a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
    <div style="margin-bottom: 32px; font-style: normal; font-weight: 400; font-size: 16px; line-height: 24px; color: #19212E;">
        {{ __('email.data_export.completed.fallback_text') }}
    </div>
    <div style="font-style: normal; font-weight: 400; font-size: 16px; line-height: 24px;">
        {{ __('email.data_export.completed.thanks') }}
    </div>
    <div style="margin-bottom: 32px; font-style: normal; font-weight: 400; font-size: 16px; line-height: 24px; color: #19212E;">
        {{ __('email.data_export.completed.lago_team') }}
    </div>
@endsection
