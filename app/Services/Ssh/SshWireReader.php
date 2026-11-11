<?php

namespace App\Services\Ssh;

use InvalidArgumentException;

/**
 * Reads the length-prefixed strings of an SSH public key blob (RFC 4251 section 5).
 */
final class SshWireReader
{
    private int $offset = 0;

    public function __construct(private readonly string $blob) {}

    public function atEnd(): bool
    {
        return $this->offset === strlen($this->blob);
    }

    /**
     * @phpstan-impure
     *
     * @throws InvalidArgumentException when the blob ends inside a field
     */
    public function string(): string
    {
        if ($this->offset + 4 > strlen($this->blob)) {
            throw new InvalidArgumentException('Truncated key blob.');
        }

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', substr($this->blob, $this->offset, 4));
        $length = $unpacked[1];
        $this->offset += 4;

        if ($this->offset + $length > strlen($this->blob)) {
            throw new InvalidArgumentException('Truncated key blob.');
        }

        $value = substr($this->blob, $this->offset, $length);
        $this->offset += $length;

        return $value;
    }
}
