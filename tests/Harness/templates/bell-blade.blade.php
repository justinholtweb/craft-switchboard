{{-- Switchboard harness: the front-end bell (Blade). --}}
<p id="unread">{{ $switchboard->unreadCount() }}</p>
<ul id="items">@foreach ($switchboard->notifications(20) as $n)<li data-id="{{ $n['id'] }}" data-read="{{ $n['read'] ? 1 : 0 }}">{{ $n['title'] }}|{{ $n['message'] }}</li>@endforeach</ul>
<p id="mark">{{ $switchboard->markReadUrl() }}</p>
<p id="feed">{{ $switchboard->feedUrl() }}</p>
