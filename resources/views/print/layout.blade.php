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
        body { width: {{ $width }}mm; padding: 4mm 3mm; font-family: "Helvetica Neue", Arial, sans-serif; font-size: 12px; line-height: 1.35; }
        .center { text-align: center; }
        .title { font-size: 15px; font-weight: 700; margin: 2mm 0; }
        .muted { color: #333; }
        .rule { border-top: 1px dashed #000; margin: 3mm 0; }
        .row { display: flex; justify-content: space-between; gap: 2mm; }
        .big { font-size: 20px; font-weight: 700; letter-spacing: 1px; }
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
