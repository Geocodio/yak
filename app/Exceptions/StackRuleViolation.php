<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;

/**
 * A stack rule refused a spawn or a message. The message is the text a tool
 * call returns to Claude. A spawn refusal may also carry a code and a sentence
 * for a person: a state refusal has an `errorCode` (409), an input refusal has
 * a `field` (422), never both.
 */
class StackRuleViolation extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly ?string $personMessage = null,
        public readonly array $details = [],
        public readonly ?string $field = null,
    ) {
        parent::__construct($message);
    }

    public function toRefusal(): ThreadActionRefused
    {
        if ($this->errorCode === null) {
            throw new LogicException('This violation has no code; check errorCode first.');
        }

        return ThreadActionRefused::because($this->errorCode, $this->personMessage ?? $this->getMessage(), $this->details);
    }

    public function toValidationException(): ValidationException
    {
        return ValidationException::withMessages([
            $this->field ?? 'stack' => $this->personMessage ?? 'Yak could not add this sub-thread. Try again.',
        ]);
    }
}
