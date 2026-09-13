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

namespace DavServices\Http;

/**
 * One answer, on its way back.
 *
 * A response passes through every plugin that has something to add to it, so
 * it is immutable in the same way the headers are: each `with…` hands back a
 * new response and leaves this one as it was. Whoever runs the chain keeps the
 * latest, and an answer can always be accounted for.
 *
 * The body is either a string or a stream, and the difference is kept rather
 * than levelled out: a `PROPFIND` answer is built in memory, a `GET` of a file
 * is not, and reading a file into a string to send it would defeat the
 * streaming that R-TREE-02 asks for.
 */
final class Response
{
    /**
     * The phrases of the statuses a DAV server sends.
     *
     * `207` and `423` come from RFC 4918, `507` from RFC 4331; `413` and `422`
     * carry the names of RFC 9110 §15 rather than those of its predecessors.
     *
     * @var array<int, string>
     */
    private const REASONS = [
        100 => 'Continue',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        204 => 'No Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',
        301 => 'Moved Permanently',
        302 => 'Found',
        304 => 'Not Modified',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Content Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        422 => 'Unprocessable Content',
        423 => 'Locked',
        424 => 'Failed Dependency',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
    ];

    private readonly Headers $headers;

    private readonly string $reason;

    /**
     * @param resource|string|null $body A stream is handed on as one
     * @param string|null $reason Null for the phrase that belongs to
     *                            the status, which is what a server
     *                            wants in all but the odd case
     * @param int|null $bodyLength How much of the body belongs to this
     *                             answer, where it is only part of a
     *                             stream; null sends all of it
     *
     * @throws MalformedResponse If the status is not a three-digit one
     */
    public function __construct(
        private readonly int $status = 200,
        ?Headers $headers = null,
        private readonly mixed $body = null,
        ?string $reason = null,
        private readonly string $protocolVersion = '1.1',
        private readonly ?int $bodyLength = null,
    ) {
        self::checkedStatus($status);

        $this->headers = $headers ?? new Headers();
        $this->reason = $reason ?? self::REASONS[$status] ?? '';
    }

    /**
     * The status this answer carries.
     */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * The reason phrase, which may be empty (RFC 9112 §4).
     */
    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * The header fields of the answer.
     */
    public function headers(): Headers
    {
        return $this->headers;
    }

    /**
     * The body: a string, a stream, or null where there is nothing to send.
     *
     * @return resource|string|null
     */
    public function body(): mixed
    {
        return $this->body;
    }

    /**
     * How many bytes of the body belong to this answer, or null for all of it.
     *
     * A `206` serves part of a file from the file's own stream rather than
     * from a copy of the part that was asked for, and this is how the SAPI
     * knows where that part ends (R-HTTP-05, R-TREE-02).
     */
    public function bodyLength(): ?int
    {
        return $this->bodyLength;
    }

    /**
     * The HTTP version the answer is to be written with.
     */
    public function protocolVersion(): string
    {
        return $this->protocolVersion;
    }

    /**
     * The same answer under a different status.
     *
     * @param string|null $reason Null takes the phrase belonging to the status,
     *                            rather than keeping the one that belonged to
     *                            the old one
     *
     * @throws MalformedResponse If the status is not a three-digit one
     */
    public function withStatus(int $status, ?string $reason = null): self
    {
        return new self($status, $this->headers, $this->body, $reason, $this->protocolVersion, $this->bodyLength);
    }

    /**
     * The same answer carrying this body instead.
     *
     * @param resource|string|null $body
     */
    public function withBody(mixed $body, ?int $bodyLength = null): self
    {
        return new self($this->status, $this->headers, $body, $this->reason, $this->protocolVersion, $bodyLength);
    }

    /**
     * The same answer with this field carrying these values and no others.
     *
     * @throws MalformedHeader
     */
    public function withHeader(string $name, string ...$values): self
    {
        return $this->withHeaders($this->headers->with($name, ...$values));
    }

    /**
     * The same answer with this value added to the field rather than replacing it.
     *
     * @throws MalformedHeader
     */
    public function withAddedHeader(string $name, string $value): self
    {
        return $this->withHeaders($this->headers->withAdded($name, $value));
    }

    /**
     * The same answer without this field.
     */
    public function withoutHeader(string $name): self
    {
        return $this->withHeaders($this->headers->without($name));
    }

    /**
     * @throws MalformedResponse If the status is outside the three digits of
     *                           RFC 9110 §15
     */
    private static function checkedStatus(int $status): void
    {
        if ($status < 100 || $status > 599) {
            throw new MalformedResponse(sprintf('%d is not an HTTP status code.', $status));
        }
    }

    /**
     * The same answer carrying these fields, which is what every header
     * change above comes down to.
     */
    private function withHeaders(Headers $headers): self
    {
        return new self($this->status, $headers, $this->body, $this->reason, $this->protocolVersion, $this->bodyLength);
    }
}
