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

namespace DavServices\Tests\Unit\CalDav\Schedule;

use DavServices\CalDav\Schedule\ScheduleResponse;
use DavServices\Xml\Element;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the answer to a busy time request. R-CAL-34, P8-05.
 *
 * RFC 6638 §5 says what the answer is, and §10 gives the four elements:
 *
 * > A response to a busy time request that indicates status for one or more
 * > calendar users MUST be an XML document with a CALDAV:schedule-response
 * > XML element as its root element. This element MUST contain one
 * > CALDAV:response element for each calendar user, with each such element in
 * > turn containing elements that indicate which calendar user they
 * > correspond to, the scheduling status for that calendar user, any error
 * > codes, and an optional description.
 *
 * §10.2 fixes the order inside each one, and the order is the whole of the
 * grammar:
 *
 *     <!ELEMENT response (recipient,
 *                         request-status,
 *                         calendar-data?,
 *                         DAV:error?,
 *                         DAV:responsedescription?)>
 *
 * **"One for each calendar user" is a count, not a set.** A request may name
 * the same address twice — RFC 5546 §3.3.2 gives `ATTENDEE | 1+` and no
 * uniqueness — and the answer then carries it twice, because a client matches
 * the answers to what it sent.
 *
 * ## Why the status is a string and not a code
 *
 * §10.4 has `request-status` be "The iTIP 'REQUEST-STATUS' property value for
 * this response", and RFC 5546 §3.6 makes that a `statcode` with a short
 * description after it — `2.0;Success`, `3.7;Invalid calendar user`.
 * Appendix B.5 writes both. So the element carries what iTIP would have
 * carried, rather than a bare number a client would have to look up.
 */
#[CoversClass(ScheduleResponse::class)]
final class ScheduleResponseTest extends TestCase
{
    private const WILFREDO = 'mailto:wilfredo@example.com';

    private const MIKE = 'mailto:mike@example.org';

    private const NS = 'urn:ietf:params:xml:ns:caldav';

    private const DATA = "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n";

    /**
     * **§10.1: the root element, and one `CALDAV:response` for each calendar
     * user** — in the order they were asked about, so that a client can line
     * the answers up against its own request.
     */
    public function testItCarriesOneResponseForEachCalendarUserInTheOrderAsked(): void
    {
        $answer = new ScheduleResponse();

        $answer->tell(self::WILFREDO, ScheduleResponse::SUCCESS, self::DATA);
        $answer->tell(self::MIKE, ScheduleResponse::NO_SUCH_USER);

        $document = $answer->asElement();

        self::assertSame('{' . self::NS . '}schedule-response', $document->name());
        self::assertSame([self::WILFREDO, self::MIKE], self::recipientsIn($document));
    }

    /**
     * **And the same address twice is answered twice.** `ATTENDEE | 1+` has
     * no uniqueness in RFC 5546 §3.3.2, and §5 wants one response "for each
     * calendar user" — a server that folded them would hand back fewer
     * answers than the client asked questions.
     */
    public function testItAnswersTheSameAddressAsOftenAsItWasAsked(): void
    {
        $answer = new ScheduleResponse();

        $answer->tell(self::WILFREDO, ScheduleResponse::SUCCESS, self::DATA);
        $answer->tell(self::WILFREDO, ScheduleResponse::SUCCESS, self::DATA);

        self::assertSame([self::WILFREDO, self::WILFREDO], self::recipientsIn($answer->asElement()));
    }

    /**
     * **§10.3: the recipient is an `href`**, not the bare address —
     * `<!ELEMENT recipient (DAV:href)>`.
     */
    public function testTheRecipientIsWrappedInAnHref(): void
    {
        $answer = new ScheduleResponse();

        $answer->tell(self::WILFREDO, ScheduleResponse::SUCCESS, self::DATA);

        $recipient = $answer->asElement()->child('{' . self::NS . '}response')
            ?->child('{' . self::NS . '}recipient');

        self::assertInstanceOf(Element::class, $recipient);
        self::assertSame(['{DAV:}href'], $recipient->childNames());
        self::assertSame(self::WILFREDO, $recipient->child('{DAV:}href')?->text());
    }

    /**
     * **§10.2 fixes the order**, and `calendar-data` comes after the status.
     * A client reading the grammar reads the status first and knows whether
     * to look for data at all.
     */
    public function testTheOrderInsideOneResponseIsTheOneTheGrammarGives(): void
    {
        $answer = new ScheduleResponse();

        $answer->tell(self::WILFREDO, ScheduleResponse::SUCCESS, self::DATA);

        self::assertSame(
            [
                '{' . self::NS . '}recipient',
                '{' . self::NS . '}request-status',
                '{' . self::NS . '}calendar-data',
            ],
            $answer->asElement()->child('{' . self::NS . '}response')?->childNames(),
        );
    }

    /**
     * **`calendar-data?` means a failed one carries none.** §5 has it "For a
     * successful busy time request" only, and Appendix B.5 shows Mike's
     * response without it — an empty element would say the server found no
     * busy time, which is a different answer from not having looked.
     */
    public function testAResponseThatFailedCarriesNoCalendarData(): void
    {
        $answer = new ScheduleResponse();

        $answer->tell(self::MIKE, ScheduleResponse::NO_SUCH_USER);

        self::assertSame(
            ['{' . self::NS . '}recipient', '{' . self::NS . '}request-status'],
            $answer->asElement()->child('{' . self::NS . '}response')?->childNames(),
        );
    }

    /**
     * **The status is iTIP's own value**, `statcode` and description, as
     * §10.4 and Appendix B.5 write it.
     *
     * @param string $status What was reported
     * @param string $written What the element then holds
     */
    #[DataProvider('theTwoStatusesThisServerReports')]
    public function testItWritesTheStatusAsITipWouldHaveWrittenIt(string $status, string $written): void
    {
        $answer = new ScheduleResponse();

        $answer->tell(self::WILFREDO, $status);

        self::assertSame(
            $written,
            $answer->asElement()->child('{' . self::NS . '}response')
                ?->child('{' . self::NS . '}request-status')
                ?->text(),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function theTwoStatusesThisServerReports(): array
    {
        return [
            'success' => [ScheduleResponse::SUCCESS, '2.0;Success'],
            'nobody at that address' => [ScheduleResponse::NO_SUCH_USER, '3.7;Invalid calendar user'],
        ];
    }

    /**
     * **An answer about nobody is still an answer.** §10.1's grammar is
     * `(response*)`, and a request whose every `ATTENDEE` this server passed
     * over would otherwise have no document to come back in.
     */
    public function testAnAnswerAboutNobodyIsStillADocument(): void
    {
        $document = (new ScheduleResponse())->asElement();

        self::assertSame('{' . self::NS . '}schedule-response', $document->name());
        self::assertSame([], $document->children());
    }

    /**
     * The addresses the responses are for, in order.
     *
     * @return list<string>
     */
    private static function recipientsIn(Element $document): array
    {
        $found = [];

        foreach ($document->children() as $response) {
            $found[] = $response->child('{' . self::NS . '}recipient')
                ?->child('{DAV:}href')?->text() ?? '';
        }

        return $found;
    }
}
