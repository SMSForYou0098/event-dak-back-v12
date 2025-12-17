<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Database Connection Monitor
 * 
 * Monitors and logs database query routing between read and write connections.
 * Useful for verifying read replica configuration and debugging connection issues.
 */
class DatabaseConnectionMonitor
{
    /**
     * Enable query logging to monitor read/write split
     */
    public static function enable()
    {
        DB::listen(function ($query) {
            $connection = $query->connectionName;
            $sql = $query->sql;
            $time = $query->time;

            // Determine if this is a read or write query
            $queryType = self::getQueryType($sql);

            // Get the actual connection used (read or write)
            $connectionType = self::getConnectionType();

            Log::channel('daily')->info('DB Query', [
                'type' => $queryType,
                'connection' => $connectionType,
                'time_ms' => $time,
                'sql' => substr($sql, 0, 100), // First 100 chars
            ]);
        });
    }

    /**
     * Determine query type (SELECT, INSERT, UPDATE, DELETE)
     */
    private static function getQueryType(string $sql): string
    {
        $sql = strtoupper(trim($sql));

        if (str_starts_with($sql, 'SELECT')) {
            return 'READ';
        } elseif (str_starts_with($sql, 'INSERT')) {
            return 'WRITE (INSERT)';
        } elseif (str_starts_with($sql, 'UPDATE')) {
            return 'WRITE (UPDATE)';
        } elseif (str_starts_with($sql, 'DELETE')) {
            return 'WRITE (DELETE)';
        }

        return 'OTHER';
    }

    /**
     * Get the current connection type being used
     */
    private static function getConnectionType(): string
    {
        try {
            $pdo = DB::connection()->getPdo();
            $host = $pdo->getAttribute(\PDO::ATTR_CONNECTION_STATUS);

            // Check if using write or read connection
            $writeHost = env('DB_WRITE_HOST', env('DB_HOST'));

            if (str_contains($host, $writeHost)) {
                return 'MASTER (Write)';
            }

            return 'REPLICA (Read)';
        } catch (\Exception $e) {
            return 'UNKNOWN';
        }
    }

    /**
     * Get connection statistics
     */
    public static function getStats(): array
    {
        return [
            'write_host' => env('DB_WRITE_HOST'),
            'read_hosts' => [
                env('DB_READ_HOST_1'),
                env('DB_READ_HOST_2'),
                env('DB_READ_HOST_3'),
            ],
            'sticky_enabled' => env('DB_STICKY', true),
            'current_connection' => DB::connection()->getDatabaseName(),
        ];
    }

    /**
     * Test read/write routing
     */
    public static function test(): array
    {
        $results = [];

        // Test READ query
        DB::enableQueryLog();
        \App\Models\User::first();
        $readQuery = DB::getQueryLog();
        DB::disableQueryLog();

        $results['read_test'] = [
            'query' => $readQuery[0]['query'] ?? 'N/A',
            'time' => $readQuery[0]['time'] ?? 0,
            'expected' => 'Should use READ connection',
        ];

        // Test WRITE query (in transaction to rollback)
        DB::beginTransaction();
        DB::enableQueryLog();
        \App\Models\User::where('id', 1)->update(['updated_at' => now()]);
        $writeQuery = DB::getQueryLog();
        DB::rollBack();
        DB::disableQueryLog();

        $results['write_test'] = [
            'query' => $writeQuery[0]['query'] ?? 'N/A',
            'time' => $writeQuery[0]['time'] ?? 0,
            'expected' => 'Should use WRITE connection',
        ];

        return $results;
    }
}
