<table>
    <thead>
        <tr>
            <th>#</th>
            <th>Event Name</th>
            <th>Agent Name</th>
            <th>User Name</th>
            <th>Number</th>
            <th>Ticket</th>
            <th>Qty</th>
            <th>B Amt</th>
            <th>Disc</th>
            <th>Total</th>
            <th>Status</th>
            {{-- <th>Disable</th> --}}
            <th>Purchase Date</th>
        </tr>
    </thead>
    <tbody>
            @foreach($bookings as $index => $booking)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $booking->event_name ?? 'N/A' }}</td>
                    <td>{{ $booking->agentUser->name ?? 'N/A' }}</td>
                    <td>{{ $booking->user->name ?? 'No User' }}</td>
                    <td>{{ $booking->number ?? '' }}</td>
                    <td>{{ $booking->ticket->name ?? '' }}</td>
                    <td>{{ $booking->quantity ?? 0 }}</td>
                    <td>{{ $booking->base_amount ?? 0 }}</td>
                    <td>{{ $booking->discount ?? 0 }}</td>
                    <td>{{ $booking->amount ?? 0 }}</td>
                    <td>{{ $booking->status }}</td>
                    <td>
                        <!-- If you want a checkbox for 'Disable', use this -->
                        {{-- <input type="checkbox" {{ $booking->disabled ? 'checked' : '' }} disabled> --}}
                    </td>
                    <td>{{ $booking->created_at->format('d-m-Y | h:i:s A') }}</td>
                </tr>
            @endforeach
        </tbody>
</table>
