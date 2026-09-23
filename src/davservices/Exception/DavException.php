<?php

/*
 * This file is part of davServices.
 *
 * (c) Felix Böck <https://dav.services>
 *
 * Licensed under the Apache License, Version 2.0.
 * For the full copyright and license information, see the LICENSE file.
 */

declare(strict_types=1);

namespace DavServices\Exception;

use DavServices\Xml\Element;
use RuntimeException;
use Throwable;

/**
 * The failure every refusal of this library derives from.
 *
 * A subclass fixes its status in a constant rather than in a method, so that
 * adding one costs a single line and carries no boilerplate to read past. The
 * status is handed on as the exception code as well, because a handler that
 * knows nothing of this library still prints and logs that code.
 */
abstract class DavException extends RuntimeException implements IHttpFailure
{
    /**
     * Overridden by every subclass; the base value is never reported.
     *
     * The type is stated here because PHP 8.2 has no typed constants and a
     * late static binding is otherwise `mixed` to a static analyser.
     *
     * @var int
     */
    protected const STATUS = 500;

    /**
     * @param string $message For the log and for `DAV:responsedescription`
     * @param Element|string|null $errorElement Condition of RFC 4918 §16: its
     *                                          name where it is empty, the
     *                                          element itself where it carries
     *                                          detail of its own
     * @param Throwable|null $previous The cause, which a log needs to stay useful
     */
    public function __construct(
        string $message = '',
        private readonly Element|string|null $errorElement = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, static::STATUS, $previous);
    }

    /**
     * The HTTP status the failure is reported with.
     */
    final public function status(): int
    {
        return static::STATUS;
    }

    /**
     * The precondition to name in a `DAV:error` body, or null.
     */
    final public function errorElement(): Element|string|null
    {
        return $this->errorElement;
    }
}
