@php
    /** @var \App\Domain\Products\Models\Product $product */
    /** @var array<int,string> $gallery */
    $sku = (string) ($product->sku ?? '');
    $status = (string) ($product->auto_create_status ?? '');
    $sell = $product->sell_price !== null ? (float) $product->sell_price : null;
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $product->name }} — Draft preview</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700;12..96,800&family=Hanken+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root{
            --ink:#14213d; --muted:#5b6478; --line:#e7e6e1; --bg:#f7f6f3;
            --card:#ffffff; --accent:#2f6fed; --accent-ink:#1b4bc4; --ok:#15803d;
        }
        *{box-sizing:border-box}
        body{margin:0;background:var(--bg);color:var(--ink);
            font-family:"Hanken Grotesk",system-ui,sans-serif;line-height:1.6;-webkit-font-smoothing:antialiased}
        a{color:var(--accent-ink);text-decoration:none}

        /* Draft banner */
        .draft{position:sticky;top:0;z-index:50;background:#fff7ed;border-bottom:1px solid #fed7aa;
            color:#9a3412;font-weight:600;font-size:.82rem;letter-spacing:.02em}
        .draft .in{max-width:1140px;margin:0 auto;padding:.6rem 1.5rem;display:flex;gap:1rem;flex-wrap:wrap;align-items:center}
        .draft .dot{display:inline-block;width:.5rem;height:.5rem;border-radius:50%;background:#ea580c;margin-right:.45rem}
        .draft .chip{background:#fff;border:1px solid #fed7aa;border-radius:999px;padding:.1rem .6rem;font-family:ui-monospace,monospace;font-weight:600;color:#9a3412}

        .wrap{max-width:1140px;margin:0 auto;padding:2.4rem 1.5rem 4rem}
        .crumbs{font-size:.8rem;color:var(--muted);margin-bottom:1.6rem}

        .top{display:grid;grid-template-columns:minmax(0,1.05fr) minmax(0,1fr);gap:2.8rem;align-items:start}
        @media(max-width:820px){.top{grid-template-columns:1fr;gap:1.8rem}}

        /* Gallery */
        .stage{background:var(--card);border:1px solid var(--line);border-radius:18px;
            padding:1.4rem;box-shadow:0 18px 40px -28px rgba(20,33,61,.4)}
        .stage img{width:100%;height:auto;aspect-ratio:1/1;object-fit:contain;display:block}
        .thumbs{display:flex;gap:.6rem;margin-top:.9rem;flex-wrap:wrap}
        .thumbs button{border:1px solid var(--line);background:#fff;border-radius:12px;padding:.35rem;
            width:74px;height:74px;cursor:pointer;transition:border-color .15s,transform .15s}
        .thumbs button:hover{transform:translateY(-2px)}
        .thumbs button[aria-current="true"]{border-color:var(--accent);box-shadow:0 0 0 2px rgba(47,111,237,.2)}
        .thumbs img{width:100%;height:100%;object-fit:contain}

        /* Summary */
        .brandline{font-size:.78rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--accent-ink)}
        h1{font-family:"Bricolage Grotesque",sans-serif;font-weight:800;font-size:clamp(1.6rem,3.2vw,2.3rem);
            line-height:1.12;margin:.5rem 0 .7rem;letter-spacing:-.01em}
        .sku{font-size:.85rem;color:var(--muted)}
        .sku b{font-family:ui-monospace,monospace;color:var(--ink);font-weight:600}
        .short{margin:1.4rem 0}
        .short ul{margin:0;padding:0;list-style:none;display:grid;gap:.6rem}
        .short li{position:relative;padding-left:1.7rem;color:#26304a}
        .short li::before{content:"";position:absolute;left:0;top:.55em;width:.7rem;height:.7rem;border-radius:50%;
            background:radial-gradient(circle at 30% 30%,var(--accent),var(--accent-ink))}
        .short p{margin:0}

        .buybox{margin-top:1.6rem;background:var(--card);border:1px solid var(--line);border-radius:16px;padding:1.2rem 1.3rem}
        .price{font-family:"Bricolage Grotesque",sans-serif;font-weight:700;font-size:1.8rem}
        .price small{font-size:.8rem;color:var(--muted);font-weight:500}
        .poa{font-weight:700;font-size:1.15rem;color:var(--ink)}
        .cta{margin-top:.9rem;display:flex;gap:.7rem;flex-wrap:wrap}
        .btn{border:0;border-radius:11px;padding:.8rem 1.3rem;font:inherit;font-weight:700;cursor:not-allowed;opacity:.92}
        .btn.primary{background:linear-gradient(180deg,var(--accent),var(--accent-ink));color:#fff}
        .btn.ghost{background:#fff;border:1px solid var(--line);color:var(--ink)}
        .note{font-size:.74rem;color:var(--muted);margin-top:.7rem}

        /* Description sections */
        .desc{margin-top:3rem;background:var(--card);border:1px solid var(--line);border-radius:18px;padding:2rem 2.2rem}
        @media(max-width:820px){.desc{padding:1.4rem}}
        .desc h2{font-family:"Bricolage Grotesque",sans-serif;font-size:1rem;letter-spacing:.12em;text-transform:uppercase;
            color:var(--muted);margin:0 0 1.4rem;font-weight:700}
        .desc :is(h3){font-family:"Bricolage Grotesque",sans-serif;font-weight:700;font-size:1.22rem;
            margin:1.9rem 0 .6rem;padding-top:1.4rem;border-top:1px solid var(--line);letter-spacing:-.01em}
        .desc :is(h3):first-of-type{border-top:0;padding-top:0;margin-top:0}
        .desc p{margin:.5rem 0;color:#26304a}
        .desc ul{margin:.5rem 0 .5rem;padding-left:1.2rem}
        .desc li{margin:.32rem 0;color:#26304a}
        .desc table{border-collapse:collapse;width:100%;margin:1rem 0}
        .desc td,.desc th{border:1px solid var(--line);padding:.5rem .7rem;text-align:left;font-size:.92rem}

        .foot{max-width:1140px;margin:0 auto;padding:0 1.5rem 3rem;color:var(--muted);font-size:.78rem}
    </style>
</head>
<body>
    <div class="draft">
        <div class="in">
            <span><span class="dot"></span>DRAFT PREVIEW — not live on meetingstore.co.uk</span>
            <span class="chip">SKU {{ $sku !== '' ? $sku : '—' }}</span>
            <span class="chip">status: {{ $status !== '' ? $status : '—' }}</span>
            @if($product->requires_manual_image_review)<span class="chip">image review</span>@endif
            <span class="chip">#{{ $product->id }}</span>
        </div>
    </div>

    <div class="wrap">
        <div class="crumbs">Home / Shop / <strong>{{ $product->name }}</strong></div>

        <div class="top">
            {{-- Gallery --}}
            <div>
                <div class="stage">
                    <img id="mainImg" src="{{ $gallery[0] }}" alt="{{ $product->name }}">
                </div>
                @if(count($gallery) > 1)
                    <div class="thumbs">
                        @foreach($gallery as $i => $url)
                            <button type="button" aria-current="{{ $i === 0 ? 'true' : 'false' }}"
                                    onclick="swap(this,'{{ $url }}')">
                                <img src="{{ $url }}" alt="View {{ $i + 1 }}">
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Summary --}}
            <div>
                @if($product->brand_id)<div class="brandline">Brand #{{ $product->brand_id }}</div>@endif
                <h1>{{ $product->name }}</h1>
                <div class="sku">SKU: <b>{{ $sku !== '' ? $sku : '—' }}</b></div>

                <div class="short">
                    @if($product->short_description)
                        {!! $product->short_description !!}
                    @else
                        <p style="color:var(--muted)">No short description.</p>
                    @endif
                </div>

                <div class="buybox">
                    @if($sell !== null && $sell > 0)
                        <div class="price">£{{ number_format($sell, 2) }} <small>ex VAT</small></div>
                    @else
                        <div class="poa">Price on application</div>
                    @endif
                    <div class="cta">
                        <button class="btn primary" disabled>Add to basket</button>
                        <button class="btn ghost" disabled>Request a quote</button>
                    </div>
                    <div class="note">Preview only — buttons are inactive. Pricing is set by the pricing engine at publish time.</div>
                </div>
            </div>
        </div>

        {{-- Long description --}}
        <div class="desc">
            <h2>Product details</h2>
            @if($product->long_description)
                {!! $product->long_description !!}
            @else
                <p style="color:var(--muted)">No long description.</p>
            @endif
        </div>
    </div>

    <div class="foot">
        Rendered from local draft data in MeetingStore Ops · meta: {{ \Illuminate\Support\Str::limit((string) $product->meta_description, 160) ?: '—' }}
    </div>

    <script>
        function swap(btn, url){
            document.getElementById('mainImg').src = url;
            document.querySelectorAll('.thumbs button').forEach(function(b){ b.setAttribute('aria-current','false'); });
            btn.setAttribute('aria-current','true');
        }
    </script>
</body>
</html>
