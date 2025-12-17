<table>
    <thead>
        <tr>
            <th>#</th>
            <th>Event</th>
            <th>Organizer</th>
            <th>Available Tickets</th>
            <th>Online Bookings</th>
            <th>Agent Bookings</th>
            <th>POS Bookings</th>
            <th>Total Tickets</th>
            <th>Check-ins</th>
            <th>Online Sale</th>
            <th>Agent Sale</th>
            <th>Agent + POS Sale</th>
            <th>Discount</th>
            <th>Convenience Fees</th>
        </tr>
    </thead>
    <tbody>
        @forelse($eventReport as $index => $event)
            <tr @if($event['parent']) style="font-weight: bold; background-color: #f0f0f0;" @endif>
                <td>{{ $index + 1 }}</td>
                <td>{{ $event['event_name'] ?? 'N/A' }}</td>
                <td>{{ $event['organizer'] ?? '-' }}</td>
                <td>{{ $event['available_tickets'] ?? 0 }}</td>
                <td>{{ $event['non_agent_bookings'] ?? 0 }}</td>
                <td>{{ $event['agent_bookings'] ?? 0 }}</td>
                <td>{{ $event['pos_bookings_quantity'] ?? 0 }}</td>
                <td>{{ $event['ticket_quantity'] ?? 0 }}</td>
                <td>{{ $event['total_ins'] ?? 0 }}</td>
                <td>₹ {{ number_format(
                    ($event['online_base_amount'] ?? 0) + ($event['online_convenience_fee'] ?? 0),
                2) }}</td>
                <td>₹ {{ number_format(
                    ($event['agent_base_amount'] ?? 0) + ($event['agent_convenience_fee'] ?? 0),
                2) }}</td>
                <td>₹ {{ number_format(
                    ($event['agent_base_amount'] ?? 0) + ($event['agent_convenience_fee'] ?? 0) + ($event['pos_base_amount'] ?? 0),
                2) }}</td>
                <td>₹ {{ number_format(
                    ($event['online_discount'] ?? 0) + ($event['agent_discount'] ?? 0) + ($event['pos_discount'] ?? 0),
                2) }}</td>
                <td>₹ {{ number_format(
                    ($event['online_convenience_fee'] ?? 0) + ($event['agent_convenience_fee'] ?? 0) + ($event['pos_convenience_fee'] ?? 0),
                2) }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="14" style="text-align: center; color: red;">No data available</td>
            </tr>
        @endforelse
    </tbody>
</table>
