<?php

namespace App\Http\Resources;

use App\Models\SshKey;

/**
 * One key row on Settings > SSH keys. Mirrored by resources/js/types/sshKeys.ts.
 */
final class SshKeyData
{
    /**
     * @return array{id: int, name: string, type: string, fingerprint: string, source: string, addedAt: string}
     */
    public static function from(SshKey $key): array
    {
        return [
            'id' => $key->id,
            'name' => $key->name,
            'type' => $key->type,
            'fingerprint' => $key->fingerprint,
            'source' => $key->source->value,
            'addedAt' => ($key->github_added_at ?? $key->created_at)?->toIso8601String() ?? '',
        ];
    }
}
