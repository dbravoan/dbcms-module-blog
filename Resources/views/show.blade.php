<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $translation->meta_title ?? $translation->title }} | {{ config('app.name') }}</title>
    <meta name="description" content="{{ $translation->meta_description ?? $translation->summary }}">

    {{-- Automated Multi-Directional Hreflang Cluster --}}
    @foreach($hreflangs as $hreflangTag => $url)
        <link rel="alternate" hreflang="{{ $hreflangTag }}" href="{{ $url }}" />
    @endforeach
    <link rel="canonical" href="{{ url()->current() }}" />

    {{-- Schema.org JSON-LD --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-50 text-slate-900 font-sans antialiased">
    <article class="max-w-4xl mx-auto px-4 py-12">
        <nav class="mb-8 flex items-center gap-2 text-sm text-slate-500">
            <a href="{{ url($currentMarket->code . '/' . app()->getLocale() . '/blog') }}" class="hover:text-indigo-600">{{ __('Blog') }}</a>
            <span>/</span>
            <span>{{ public_translation($post->category)?->name ?? 'General' }}</span>
        </nav>

        <header class="mb-10">
            <div class="flex items-center gap-3 text-sm text-indigo-600 font-semibold mb-3">
                <span>{{ public_translation($post->category)?->name ?? 'Article' }}</span>
                <span>•</span>
                <span>{{ $post->reading_time_minutes }} min read</span>
                <span>•</span>
                <span>Market: {{ strtoupper($currentMarket->code) }}</span>
            </div>
            <h1 class="text-4xl font-extrabold tracking-tight text-slate-900 sm:text-5xl mb-4">{{ $translation->title }}</h1>
            <div class="text-sm text-slate-400">Published by {{ $post->author->name ?? 'Staff' }} on {{ $post->published_at?->copy()->locale(app()->getLocale())->isoFormat('LL') }}</div>
        </header>

        @if($post->featured_image)
            <img src="{{ $post->featured_image }}" alt="{{ $translation->title }}" class="rounded-3xl shadow-lg mb-10 w-full h-96 object-cover">
        @endif

        @if(isset($renderedSections) && count($renderedSections) > 0)
            <div class="space-y-8">
                @foreach($renderedSections as $sectionHtml)
                    {!! $sectionHtml !!}
                @endforeach
            </div>
        @else
            <div class="prose prose-lg prose-slate max-w-none bg-white p-8 md:p-12 rounded-3xl shadow-sm border border-slate-100">
                {!! app(\DbCms\Core\Services\Media\MarkdownRendererService::class)->render((string) $translation->content) !!}
            </div>
        @endif

        @include('blog::partials.comments')
    </article>
</body>
</html>
