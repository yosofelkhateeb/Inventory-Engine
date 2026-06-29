<x-mail::message>
# {{ $alert->title }}

**Severity:** {{ ucfirst($alert->severity) }}
**Type:** `{{ $alert->type }}`
**Detected:** {{ $alert->created_at->toDayDateTimeString() }}

@if (! empty($alert->payload))
## Details

@foreach ($alert->payload as $key => $value)
- **{{ str_replace('_', ' ', ucfirst($key)) }}:** {{ is_scalar($value) ? $value : json_encode($value) }}
@endforeach
@endif

@if (! empty($alert->payload['recommendation']))
<x-mail::panel>
{{ $alert->payload['recommendation'] }}
</x-mail::panel>
@endif

This is a system-health notification, not an end-user alert. It was sent because `notifications.system_owner_email` is configured for this tenant.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
