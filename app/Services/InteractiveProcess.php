<?php

namespace App\Services;

/**
 * A running, interactively-driven process: output is read incrementally
 * and input can be written while it is still running. Abstracts Symfony's
 * Process + InputStream pairing so McpLoginJob can be tested against a
 * scripted fake instead of spawning a real `script`/`claude` process.
 */
interface InteractiveProcess
{
    /**
     * Output (stdout + stderr) produced since the last call to this
     * method.
     */
    public function incrementalOutput(): string;

    public function isRunning(): bool;

    /**
     * Write to the process's stdin. Only meaningful while the input stream
     * is still open (see closeInput()).
     */
    public function write(string $data): void;

    /** Closes stdin, signalling no more input is coming. */
    public function closeInput(): void;

    /** Sends a termination signal and gives the process a moment to exit. */
    public function stop(): void;

    /** Blocks until the process exits, returning its exit code. */
    public function wait(): int;

    public function isSuccessful(): bool;
}
