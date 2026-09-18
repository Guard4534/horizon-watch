<x-mail::message>
<table class="alert-card" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="alert-bar" width="4" style="background-color: {{ $color }};">&nbsp;</td>
<td class="alert-body">
<p class="alert-headline">{{ $headline }}</p>
<p class="alert-sub">{{ $sub }}</p>
@if ($detail !== null)
<p class="alert-detail">{{ $detail }}</p>
@endif
<table class="alert-rows" width="100%" cellpadding="0" cellspacing="0" role="presentation">
@foreach ($rows as $row)
<tr>
<td class="alert-key">{{ $row['label'] }}</td>
<td class="alert-value">{{ $row['value'] }}</td>
</tr>
@endforeach
</table>
</td>
</tr>
</table>

@if ($note !== null)
<p class="alert-detail">{{ $note }}</p>
@endif

@if ($url !== null)
<x-mail::button :url="$url">
{{ $action }}
</x-mail::button>
@endif
</x-mail::message>
