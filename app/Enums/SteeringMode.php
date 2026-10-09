<?php

namespace App\Enums;

/**
 * How a message sent while a task is busy reaches the agent. A queued
 * message waits for the run to finish and starts a follow-up; a steered
 * one is handed to the running agent at its next tool call.
 */
enum SteeringMode: string
{
    case Queue = 'queue';
    case Steer = 'steer';
}
