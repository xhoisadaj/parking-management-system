<!doctype html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        /* Paper width comes from settings (58 or 80 mm). Height is automatic so the ticket is not cut. */
        @page { size: {{ $width }}mm auto; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #fff; color: #000; }
        /* Side padding is bigger than it looks like it needs to be on screen: thermal print heads
           have a small physical non-printable strip at each edge that no software margin setting
           can remove. Both sides get the same 8mm that cleared the left edge's dead zone — text
           values (times, plates) never reach far enough right to have proven the right edge safe
           too, only the barcode stretches that far, and a right-edge cut would corrupt exactly
           the tail characters a scan kept losing. Not worth the risk of asymmetric padding here. */
        body { width: {{ $width }}mm; padding: 4mm 8mm; font-family: "Helvetica Neue", Arial, sans-serif; font-size: 12px; line-height: 1.35; }
        .center { text-align: center; }
        .title { font-size: 15px; font-weight: 700; margin: 2mm 0; }
        .muted { color: #333; }
        .rule { border-top: 1px dashed #000; margin: 3mm 0; }
        .row { display: flex; justify-content: space-between; gap: 2mm; }
        .big { font-size: 20px; font-weight: 700; letter-spacing: 1px; }
        /* The generated barcode SVG has no quiet zone baked into itself, but it doesn't need one
           here: the body's own padding (see above) is already several times wider than Code128's
           ~2-3mm minimum quiet zone, so it already does that job. Letting the barcode use the
           full content width instead of shrinking it further matters more — an 8-character code
           is ~123 Code128 modules, and on a 58mm roll every mm given back to the bars is the
           difference between something a cheap scanner can resolve and bars that blur together. */
        .barcode { display: block; margin: 2mm auto 1mm; max-width: 100%; height: auto; }
        .code { font-family: monospace; font-size: 14px; text-align: center; letter-spacing: 2px; }
        .pre { white-space: pre-wrap; }
        /* On screen, show the ticket as a card so staff can preview it. */
        @media screen {
            body { margin: 16px auto; background: #fff; box-shadow: 0 2px 12px rgba(0,0,0,.2); }
            html { background: #e5e7eb; }
        }
    </style>
</head>
<body>
    {{ $slot }}

    @if ($autoprint)
        <script>
            // Opened in the operator's hidden print frame. Wait for the barcode to render, then print.
            window.addEventListener('load', () => setTimeout(() => window.print(), 150));
        </script>
    @endif
</body>
</html>
