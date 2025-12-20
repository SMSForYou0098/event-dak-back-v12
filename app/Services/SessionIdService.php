<?php

namespace App\Services;

use Illuminate\Support\Str;

class SessionIdService
{
    /**
     * Generate an encrypted session ID.
     *
     * @return array
     */
    public function generateEncryptedSessionId(): array
    {
        // Generate a random session ID
        $originalSessionId = Str::random(32);
        // Encrypt it
        $encryptedSessionId = encrypt($originalSessionId);

        return [
            'original' => $originalSessionId,
            'encrypted' => $encryptedSessionId
        ];
    }
}
