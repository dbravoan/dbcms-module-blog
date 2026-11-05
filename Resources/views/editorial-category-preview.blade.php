@extends(app(\DbCms\Core\Services\Cms\FrontViewResolver::class)->layout())
@section('content')
<main class="max-w-7xl mx-auto px-6 py-12">
    <h1>{{ $translation->name }}</h1>
    <p>{{ $translation->description }}</p>
</main>
@endsection
