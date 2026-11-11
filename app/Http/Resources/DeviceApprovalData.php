<?php

namespace App\Http\Resources;

use App\Models\DeviceAuthorization;

/**
 * Props for the device approval page. `os` and `arch` are folded into one system label by the page.
 */
final class DeviceApprovalData
{
    /**
     * @return array{state: string, code: ?string, name: ?string, os: ?string, arch: ?string, cli_version: ?string, requested_at: ?string, email: string}
     */
    public static function make(string $state, ?DeviceAuthorization $authorization, ?string $typedCode, string $email, ?string $name = null): array
    {
        return [
            'state' => $state,
            'code' => $authorization !== null ? $authorization->user_code : $typedCode,
            'name' => $name ?? $authorization?->name,
            'os' => $authorization?->os,
            'arch' => $authorization?->arch,
            'cli_version' => $authorization?->cli_version,
            'requested_at' => $authorization?->created_at?->toIso8601String(),
            'email' => $email,
        ];
    }
}
