{{-- Phase 6 Plan 01 — SEO template (D-01, D-02).
     Sections consumed by ProductContentBuilder::compile() via renderSections():
       - title              → Woo product.title (raw — no HTML)
       - short_description  → Woo product.short_description (HTML <ul>)
       - long_description   → Woo product.description (HTML multi-<h2>)

     Shortcodes (all optional except brand/model/product_type):
       $brand_name            string
       $model_name            string
       $product_type          string
       $supplier_overview     string (optional)
       $supplier_features     array<string> (optional)
       $supplier_specs        array<string, string> (optional, label → value)
       $supplier_box_contents array<string> (optional)

     @if(!empty(...)) guards ensure missing supplier data produces ZERO output
     — not an empty header. D-01 requirement. --}}

@section('title'){{ trim($brand_name.' '.$model_name.' '.$product_type) }}@endsection

@section('short_description')
@if(!empty($supplier_features))
<ul>
@foreach(array_slice($supplier_features, 0, 5) as $feat)
<li>{{ $feat }}</li>
@endforeach
</ul>
@endif
@endsection

@section('long_description')
@if(!empty($supplier_overview))
<h2>Overview</h2>
<p>{{ $supplier_overview }}</p>
@endif
@if(!empty($supplier_features))
<h2>Key Features</h2>
<ul>
@foreach($supplier_features as $f)
<li>{{ $f }}</li>
@endforeach
</ul>
@endif
@if(!empty($supplier_specs))
<h2>Technical Specifications</h2>
<table>
@foreach($supplier_specs as $label => $val)
<tr><th>{{ $label }}</th><td>{{ $val }}</td></tr>
@endforeach
</table>
@endif
@if(!empty($supplier_box_contents))
<h2>What's in the Box</h2>
<ul>
@foreach($supplier_box_contents as $item)
<li>{{ $item }}</li>
@endforeach
</ul>
@endif
@endsection
