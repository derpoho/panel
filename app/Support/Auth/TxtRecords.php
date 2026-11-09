<?php

namespace App\Support\Auth;

/**
 * A hostname's DNS TXT records, looked up fresh every time (verifying a
 * domain should see a record the moment it's added). Tests swap this
 * out, so they never touch the network.
 */
class TxtRecords
{
    /**
     * The TXT strings at the host, or null when the lookup itself failed
     * (no answer from DNS), which is not the same as no records.
     *
     * @return list<string>|null
     */
    public function for(string $host): ?array
    {
        $records = @dns_get_record(rtrim($host, '.').'.', DNS_TXT);
        if ($records === false) {
            return null;
        }

        $out = [];
        foreach ($records as $record) {
            // Long values come split into 255-byte strings: join them back.
            $entries = $record['entries'] ?? null;
            $out[] = is_array($entries) ? implode('', $entries) : (string) ($record['txt'] ?? '');
        }

        return $out;
    }
}
