<table class="table table-bordered table-striped align-middle text-center">
    <thead class="table-dark">
        <tr>
            <th>#</th>
            <th>Pos Name</th>
            <th>Total Bookings</th>
            <th>UPI Bookings</th>
            <th>Cash Bookings</th>
            <th>NetBanking Bookings</th>
            <th>UPI Amount</th>
            <th>Cash Amount</th>
            <th>NetBanking Amount</th>
            <th>Total Amount</th>
            <th>Total Discount</th>
            <th>Today Total Amount</th>
            <th>Today Booking Count</th>
        </tr>
    </thead>
    <tbody>
        @forelse($report as $index => $row)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $row['pos_user_name'] }}</td>
                <td>{{ $row['total_bookings'] }}</td>
                <td>{{ $row['total_UPI_bookings'] }}</td>
                <td>{{ $row['total_Cash_bookings'] }}</td>
                <td>{{ $row['total_Net_Banking_bookings'] }}</td>
                <td>{{ $row['total_UPI_amount'] }}</td>
                <td>{{$row['total_Cash_amount'] }}</td>
                <td>{{$row['total_Net_Banking_amount'] }}</td>
                <td>{{$row['total_amount'] }}</td>
                <td>{{$row['total_discount'] }}</td>
                <td>{{$row['today_total_amount'] }}</td>
                <td>{{ $row['today_booking_count'] }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="14" class="text-center text-muted">No records found</td>
            </tr>
        @endforelse
    </tbody>
</table>
