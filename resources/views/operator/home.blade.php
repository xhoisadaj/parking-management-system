<x-layouts.operator title="Home">
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        @can(\App\Support\Permissions::ISSUE_TICKET)
            <a href="{{ route('operator.entry') }}" class="flex min-h-[120px] flex-col justify-center rounded-2xl bg-white p-5 shadow hover:shadow-md">
                <span class="text-lg font-semibold">Entry</span>
                <span class="text-sm text-slate-500">Issue a ticket for a car, van or motorbike</span>
            </a>
        @endcan
        @can(\App\Support\Permissions::CHECKOUT)
            <a href="{{ route('operator.checkout') }}" class="flex min-h-[120px] flex-col justify-center rounded-2xl bg-white p-5 shadow hover:shadow-md">
                <span class="text-lg font-semibold">Checkout</span>
                <span class="text-sm text-slate-500">Scan or type a ticket and take payment</span>
            </a>
            <a href="{{ route('operator.lost') }}" class="flex min-h-[120px] flex-col justify-center rounded-2xl bg-white p-5 shadow hover:shadow-md">
                <span class="text-lg font-semibold">Lost ticket</span>
                <span class="text-sm text-slate-500">Find the car and apply the lost-ticket fee</span>
            </a>
        @endcan
        <a href="{{ route('operator.shift') }}" class="flex min-h-[120px] flex-col justify-center rounded-2xl bg-white p-5 shadow hover:shadow-md">
            <span class="text-lg font-semibold">My shift</span>
            <span class="text-sm text-slate-500">Your totals and closing the shift</span>
        </a>
    </div>
</x-layouts.operator>
