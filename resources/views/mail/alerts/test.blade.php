<x-mail::message>
<table class="alert-card" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="alert-bar" width="4" style="background-color: {{ $color }};">&nbsp;</td>
<td class="alert-body">
<p class="alert-headline">{{ $headline }}</p>
<p class="alert-detail">{{ $body }}</p>
</td>
</tr>
</table>

<x-mail::button :url="$url">
{{ $action }}
</x-mail::button>
</x-mail::message>
