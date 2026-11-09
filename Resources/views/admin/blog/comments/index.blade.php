@extends('admin.layout')

@section('title', __('admin.nav.items.comments'))

@section('content')
<div class="admin-page space-y-6">
    <x-admin.form-header :title="__('admin.nav.items.comments')" description="Aprueba, rechaza o elimina los comentarios enviados desde el blog." :back-route="route('admin.blog.posts.index')" back-label="Volver a artículos" />

    @if(session('success'))
        <div class="rounded-lg border border-emerald-500/40 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300">
            {{ session('success') }}
        </div>
    @endif

    <div class="overflow-hidden rounded-xl border border-[color:var(--admin-border)] bg-[color:var(--admin-surface)]">
        <table class="w-full text-left text-xs">
            <thead class="border-b border-[color:var(--admin-border)] text-[color:var(--admin-muted)] uppercase tracking-wide">
                <tr>
                    <th class="px-4 py-3">Estado</th>
                    <th class="px-4 py-3">Artículo</th>
                    <th class="px-4 py-3">Autor</th>
                    <th class="px-4 py-3">Comentario</th>
                    <th class="px-4 py-3 text-right">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[color:var(--admin-border)]">
                @forelse($comments as $comment)
                    <tr>
                        <td class="px-4 py-3">
                            @if($comment->is_approved)
                                <span class="rounded border border-emerald-500/30 bg-emerald-500/10 px-2 py-1 text-[10px] font-bold text-emerald-300">Publicado</span>
                            @else
                                <span class="rounded border border-amber-500/30 bg-amber-500/10 px-2 py-1 text-[10px] font-bold text-amber-300">Pendiente</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-[color:var(--admin-text)]">
                            {{ $comment->post?->translations->firstWhere('locale', 'es')?->title ?? $comment->post?->translations->first()?->title ?? 'Artículo #'.$comment->post_id }}
                        </td>
                        <td class="px-4 py-3 text-[color:var(--admin-muted)]">
                            <div class="font-bold text-[color:var(--admin-text)]">{{ $comment->author_name ?: 'Anónimo' }}</div>
                            <div>{{ $comment->author_email }}</div>
                        </td>
                        <td class="max-w-xl px-4 py-3 text-[color:var(--admin-text)]">{{ $comment->content }}</td>
                        <td class="px-4 py-3 text-right">
                            <div class="inline-flex items-center gap-2">
                                @unless($comment->is_approved)
                                    <form method="POST" action="{{ route('admin.blog.comments.approve', $comment) }}">
                                        @csrf
                                        <button type="submit" class="rounded border border-emerald-500/40 px-3 py-1.5 font-bold text-emerald-300 hover:bg-emerald-500/10">Aprobar</button>
                                    </form>
                                @endunless
                                <form method="POST" action="{{ route('admin.blog.comments.destroy', $comment) }}" onsubmit="return confirm('¿Eliminar este comentario?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rounded border border-red-500/40 px-3 py-1.5 font-bold text-red-300 hover:bg-red-500/10">Eliminar</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-[color:var(--admin-muted)]">No hay comentarios.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $comments->links() }}
</div>
@endsection
