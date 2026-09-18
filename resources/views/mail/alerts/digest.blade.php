<x-mail::message>
<table class="alert-card" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="alert-bar" width="4" style="background-color: {{ $color }};">&nbsp;</td>
<td class="alert-body">
<p class="alert-headline">{{ $headline }}</p>
<p class="alert-sub">{{ $sub }}</p>
<table class="alert-rows" width="100%" cellpadding="0" cellspacing="0" role="presentation">
@foreach ($rows as $row)
<tr>
<td class="alert-state" style="color: {{ $row['color'] }};">{{ $row['state'] }}</td>
<td class="alert-value">{{ $row['where'] }}<br>{{ $row['rule'] }} · {{ $row['value'] }}</td>
</tr>
@endforeach
</table>
</td>
</tr>
</table>

<x-mail::button :url="$url">
{{ $action }}
</x-mail::button>
</x-mail::message>
