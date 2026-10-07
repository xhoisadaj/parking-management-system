<!doctype html>
<html lang="sq" class="h-full bg-slate-100">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light">
    <title>Hyrja · Operatori</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex h-full items-center justify-center px-4 py-10 text-slate-900 antialiased">
    <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-lg">
        <h1 class="text-xl font-semibold">Hyrja e operatorit</h1>
        <p class="mt-1 text-sm text-slate-500">{{ \App\Models\Setting::current()->parking_name }}</p>

        <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <label for="email" class="block text-sm font-medium">Email-i</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                    class="mt-1 block min-h-[48px] w-full rounded-lg border border-slate-300 text-base focus:border-slate-900 focus:ring-slate-900">
            </div>
            <div>
                <label for="password" class="block text-sm font-medium">Fjalëkalimi</label>
                <input id="password" name="password" type="password" required autocomplete="current-password"
                    class="mt-1 block min-h-[48px] w-full rounded-lg border border-slate-300 text-base focus:border-slate-900 focus:ring-slate-900">
            </div>
            <label class="flex min-h-[44px] items-center gap-2 text-sm">
                <input type="checkbox" name="remember" value="1" class="h-5 w-5 rounded border border-slate-300">
                Më mbaj të futur në këtë pajisje
            </label>

            @if ($errors->any())
                <p role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>
            @endif

            <button type="submit" class="min-h-[52px] w-full rounded-xl bg-slate-900 text-base font-semibold text-white hover:bg-slate-800">
                Hyr
            </button>
        </form>
    </div>
</body>
</html>
