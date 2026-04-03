<?php

namespace App\Exceptions;

use InvalidArgumentException;

class ScrapeSchemaValidationException extends InvalidArgumentException
{
    /**
     * @param  array<int, string>  $errors
     */
    public function __construct(
        protected array $errors,
    ) {
        parent::__construct(implode(' ', $errors));
    }

    /**
     * @return array<int, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
