@if($post->allowsComments())
    <section class="cms-comments mt-12 space-y-6" aria-labelledby="post-comments-title">
        <h2 id="post-comments-title" class="text-xl font-semibold">{{ __('Comentarios') }} ({{ $post->comments->where('is_approved', true)->count() }})</h2>
        <div class="space-y-4">
            @forelse($post->comments->where('is_approved', true) as $comment)
                <article class="cms-comment rounded border p-4">
                    <p class="font-semibold">{{ $comment->author_name ?? __('Anónimo') }}</p>
                    <p class="text-sm">{{ $comment->created_at?->diffForHumans() }}</p>
                    <p>{{ $comment->content }}</p>
                </article>
            @empty
                <p>{{ __('Todavía no hay comentarios.') }}</p>
            @endforelse
        </div>
        <div class="space-y-4">
            <h3 class="font-semibold">{{ __('Enviar comentario') }}</h3>
            <x-cms::privacy-notice owner="blog_comments" />
            @if(app(\DbCms\Core\Services\Privacy\LegalPageService::class)->canCollect('blog_comments'))
                <form method="POST" action="{{ $commentSubmissionUrl }}" class="space-y-4">
                    @csrf
                    <x-cms-form.fields
                        :schema="app(\DbCms\Core\Forms\FormSchemas::class)->blogComment(withCaptcha: (bool) module_setting('blog', 'comments_captcha_enabled', false))"
                        variant="comment"
                        layout="grid"
                        honeypot="_hp_security_check"
                    />
                    <button type="submit" class="rounded border px-4 py-2">{{ __('Publicar comentario') }}</button>
                </form>
            @endif
        </div>
    </section>
@else
    <p class="cms-comments-disabled mt-12">{{ __('Los comentarios están desactivados para este artículo.') }}</p>
@endif
