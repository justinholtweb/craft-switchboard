{{-- A rule body template (Blade): same variables as Twig. --}}
BLADE BODY {{ $element['title'] }} #{{ $object->id }} for {{ $recipient['name'] ?? 'anyone' }} [{{ $element['fields']['switchboardSummary'] ?? '' }}] {{ \CraftCms\Cms\t('Published: {title}', ['title' => $element['title']], category: 'switchboard') }}
