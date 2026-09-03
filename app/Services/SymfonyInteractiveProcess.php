<?php

namespace App\Services;

use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * Real InteractiveProcess backed by Symfony Process + InputStream. Started
 * eagerly in the constructor so InteractiveProcessFactory::start() hands
 * back an already-running process.
 */
class SymfonyInteractiveProcess implements InteractiveProcess
{
    private readonly InputStream $input;

    private readonly SymfonyProcess $process;

    public function __construct(string $shellCommand, int $timeout)
    {
        $this->input = new InputStream;
        $this->process = SymfonyProcess::fromShellCommandline($shellCommand);
        $this->process->setTimeout($timeout);
        $this->process->setInput($this->input);
        $this->process->start();
    }

    public function incrementalOutput(): string
    {
        $this->process->checkTimeout();

        return $this->process->getIncrementalOutput() . $this->process->getIncrementalErrorOutput();
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    public function write(string $data): void
    {
        $this->input->write($data);
    }

    public function closeInput(): void
    {
        $this->input->close();
    }

    public function stop(): void
    {
        $this->process->stop(5);
    }

    public function wait(): int
    {
        return $this->process->wait();
    }

    public function isSuccessful(): bool
    {
        return $this->process->isSuccessful();
    }
}
