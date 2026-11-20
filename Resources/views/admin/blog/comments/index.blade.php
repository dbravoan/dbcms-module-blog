@extends('admin.layout')
@section('title', __('admin.nav.items.comments'))
@section('content')
<div class="admin-page space-y-6">
    <x-admin.form-header :title="__('admin.nav.items.comments')" description="Aprueba o elimina los comentarios enviados desde el blog." :back-route="route('admin.blog.posts.index')" back-label="Volver a artículos" />
    @if(session('success'))<div class="admin-alert" role="status">{{ session('success') }}</div>@endif
    <x-admin.table :empty="$comments->isEmpty()" emptyTitle="No hay comentarios">
        <x-slot:header>
            <th scope="col" class="px-4 py-3">{{ __('admin.common.status') }}</th>
            <th scope="col" class="px-4 py-3">Artículo</th>
            <th scope="col" class="px-4 py-3">Autor</th>
            <th scope="col" class="px-4 py-3">Comentario</th>
            <th scope="col" class="px-4 py-3 text-right">{{ __('admin.common.actions') }}</th>
        </x-slot:header>
        @foreach($comments as $comment)
            <tr>
                <td class="px-4 py-3"><x-admin.status-badge :status="$comment->is_approved ? 'published' : 'pending'" /></td>
                <td class="px-4 py-3">{{ $comment->getAttribute('admin_post_title') }}</td>
                <td class="px-4 py-3"><div class="font-bold">{{ $comment->author_name ?: 'Anónimo' }}</div><div>{{ $comment->author_email }}</div></td>
                <td class="px-4 py-3 max-w-xl">{{ $comment->content }}</td>
                <td class="px-4 py-3 text-right"><div class="inline-flex items-center gap-1.5">
                    @unless($comment->is_approved)
                        <form method="POST" action="{{ route('admin.blog.comments.approve', $comment) }}">
                            @csrf
                            <x-admin.action-button type="submit" icon="fa-solid fa-check" tooltip="Aprobar comentario" variant="primary" />
                        </form>
                    @endunless
                    <x-admin.action-button icon="fa-solid fa-trash-can" tooltip="Eliminar comentario" variant="danger" @click="$dispatch('confirm-delete', {{ Illuminate\Support\Js::from(['action' => route('admin.blog.comments.destroy', $comment), 'title' => 'Eliminar comentario', 'message' => '¿Confirmas que deseas eliminar este comentario?']) }})" />
                </div></td>
            </tr>
        @endforeach
        <x-slot:pagination><x-admin.datatable-pagination :paginator="$comments" /></x-slot:pagination>
    </x-admin.table>
</div>
@endsection
