@if ($paginator->hasPages())
<nav>
    <ul class="pagination pagination-sm mb-0" style="gap:.2rem">
        @if ($paginator->onFirstPage())
            <li class="page-item disabled"><span class="page-link" style="background:var(--bg-panel);border-color:var(--bg-border);color:#555;border-radius:6px">‹</span></li>
        @else
            <li class="page-item"><a class="page-link" href="{{ $paginator->previousPageUrl() }}" style="background:var(--bg-panel);border-color:var(--bg-border);color:#8d97b0;border-radius:6px">‹</a></li>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <li class="page-item disabled"><span class="page-link" style="background:var(--bg-panel);border-color:var(--bg-border);color:#555;border-radius:6px">…</span></li>
            @endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <li class="page-item active"><span class="page-link" style="background:var(--gold);border-color:var(--gold);color:#000;font-weight:700;border-radius:6px">{{ $page }}</span></li>
                    @else
                        <li class="page-item"><a class="page-link" href="{{ $url }}" style="background:var(--bg-panel);border-color:var(--bg-border);color:#8d97b0;border-radius:6px">{{ $page }}</a></li>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <li class="page-item"><a class="page-link" href="{{ $paginator->nextPageUrl() }}" style="background:var(--bg-panel);border-color:var(--bg-border);color:#8d97b0;border-radius:6px">›</a></li>
        @else
            <li class="page-item disabled"><span class="page-link" style="background:var(--bg-panel);border-color:var(--bg-border);color:#555;border-radius:6px">›</span></li>
        @endif
    </ul>
</nav>
@endif
