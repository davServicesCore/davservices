# RFC compliance

Section by section, what this library keeps and where that is tried.

## How to read this

One row per section of the memo, taken from the memo itself. `bin/check-rfc-matrix.php`
reads the headings out of `docs/referenzen/rfc/` and fails the build when a
section has no row, when a row names a section that does not exist, or when
one section has two rows. **A matrix with gaps is worse than none**: it reads
as a statement about every section and would be one about some of them.

| State | Meaning |
|---|---|
| ✔ | Kept, with the case named beside it |
| ◻ | Nothing for an implementation to do — prose, a registration, an example, a reference |
| ⚠ | Open, and the row says what is missing |

**Each memo is a chunk of its own**, because a row is a claim and a claim is
checked against the code before it is written down. RFC 6638 is here; RFC
4918, 3744, 4791, 6352, 6578 and 5545 follow, and `COVERED` in the gate is the
record of which ones this document already speaks for.

Where a row says ⚠, the reason is kept in `.TODO/anforderungen/DONE.md` beside
the chunk that found it.

## RFC 6638 — CalDAV Scheduling

| § | What it asks | State | Where | Case |
|---|---|---|---|---|
| 1 | Introduction | ◻ | — | — |
| 1.1 | Terminology | ◻ | — | — |
| 1.2 | Notational conventions | ◻ | — | — |
| 1.3 | XML namespaces, and the `urn:ietf:params:xml:ns:caldav` prefix | ✔ | `Xml\Writer` | `WriterTest` |
| 2 | A server announces `calendar-auto-schedule` in `DAV` | ✔ | `Plugin\Scheduling` | `SchedulingTest` |
| 2.1 | Scheduling Outbox collection: resource type, what it may hold, not under a calendar | ✔ | `CalDav\Schedule\Outbox` | `OutboxTest` |
| 2.1.1 | `CALDAV:schedule-outbox-URL` on a principal | ✔ | `Plugin\Scheduling::describe()` | `SchedulingTest` |
| 2.2 | Scheduling Inbox collection: resource type, only calendar object resources, no collections, not under a calendar — and the eight `calendar-access` properties that "MAY also be defined" on one | ✔ | `CalDav\Schedule\Inbox`; `Plugin\Scheduling::publishTheLimits()` answers five of the eight, §11.1's three on the Inbox and the two date limits on the Outbox, the other three being a MAY and the deployment's to store | `InboxTest`, `SchedulingLimitsTest` |
| 2.2.1 | `CALDAV:schedule-inbox-URL` on a principal | ✔ | `Plugin\Scheduling::describe()` | `SchedulingTest` |
| 2.3 | `calendar-query` and `calendar-multiget` on an Inbox; a `VEVENT` with no `DTSTART` MUST always match a time-range test; `free-busy-query` is not supported there | ⚠ | Open: the two reports are not kept from an Inbox, but neither of the two sentences about one is kept — a component with no `DTSTART` does not match, and `free-busy-query` is not refused | — |
| 2.4 | New principal properties, "likely to be protected" | ✔ | read through `Dav\Property\Answers`, so a store answers them | `SchedulingTest` |
| 2.4.1 | `CALDAV:calendar-user-address-set` | ✔ | read, not answered: §2.4 has a server "MAY allow them to be written", so the value is the deployment's | `SchedulingTest` |
| 2.4.2 | `CALDAV:calendar-user-type` | ◻ | Deliberately not answered: a MAY whose value "is the same as the iCalendar CUTYPE", so answering it would make it unwritable — said out loud in `Plugin\Scheduling` | — |
| 3 | A write to a calendar collection is examined, and a scheduling message follows | ✔ | `Plugin\Scheduling::deliverOnWrite()` | `SchedulingDeliveryTest` |
| 3.1 | Which of the two kinds a resource is, by the owner's addresses | ✔ | `CalDav\Schedule\SchedulingObject` | `SchedulingObjectTest` |
| 3.2 | Handling scheduling object resources | ✔ | `Plugin\Scheduling::deliverOnWrite()` | `SchedulingDeliveryTest` |
| 3.2.1 | The organizer's side, and the MUST NOT about their own `ATTENDEE` | ✔ | `Plugin\Scheduling::tellTheAttendeesOf()` | `SchedulingDeliveryTest` |
| 3.2.1.1 | Create: a `REQUEST` to every attendee | ✔ | `CalDav\Schedule\SchedulingMessage::requesting()` | `SchedulingDeliveryTest` |
| 3.2.1.2 | Modify: the table of what each calendar user is told | ✔ | `CalDav\Schedule\Modification` | `ModificationTest`, `SchedulingModifyTest` |
| 3.2.1.3 | Remove: a `CANCEL` to everybody on the list | ✔ | `Plugin\Scheduling::theCancellationsOf()` | `SchedulingCancelTest` |
| 3.2.2 | The attendee's side | ✔ | `Plugin\Scheduling::tellTheOrganizerOf()` | `SchedulingReplyTest` |
| 3.2.2.1 | The eleven changes a server MUST **allow** an attendee to make | ✔ | Nothing on the attendee's side is refused, which is what the MUST asks for; eleven cases, one per item | `SchedulingAttendeeChangesTest` |
| 3.2.2.2 | Create: a `REPLY` for every instance answered | ✔ | `CalDav\Schedule\Answer` | `AnswerTest`, `SchedulingReplyTest` |
| 3.2.2.3 | Modify: a `REPLY` where the answer changed | ✔ | `CalDav\Schedule\Answer` | `SchedulingReplyTest` |
| 3.2.2.4 | Remove: the attendee's copy goes | ⚠ | Open, with §8.1: it turns on the `Schedule-Reply` request header, which is not read | — |
| 3.2.3 | Which HTTP methods the operations hang on | ✔ | `Plugin\Scheduling::register()` | `SchedulingTest` |
| 3.2.3.1 | `PUT` | ✔ | `BeforeCreateFile`, `BeforeWriteContent` | `SchedulingDeliveryTest` |
| 3.2.3.2 | `DELETE` | ✔ | `BeforeUnbind`, `AfterUnbind` | `SchedulingCancelTest` |
| 3.2.3.3 | `COPY` | ⚠ | Open: a copy of a scheduling object resource sends nothing | — |
| 3.2.3.4 | `MOVE` | ⚠ | Open: a move of a scheduling object resource sends nothing | — |
| 3.2.4 | The four preconditions of this section | ✔ | `Plugin\CalDav`, `Plugin\Scheduling` | `SchedulingModifyTest` |
| 3.2.4.1 | `CALDAV:unique-scheduling-object-resource` | ✔ | `Plugin\CalDav` (RFC 4791 §4.1's one `UID` per collection) | `CalDavTest` |
| 3.2.4.2 | `CALDAV:same-organizer-in-all-components` | ✔ | `CalDav\Schedule\SchedulingObject::in()` reads it; the precondition is `Plugin\Scheduling`'s | `SchedulingObjectTest` |
| 3.2.4.3 | `CALDAV:allowed-organizer-scheduling-object-change` | ✔ | `Plugin\Scheduling::mustLeaveTheirAnswersAlone()` | `SchedulingModifyTest` |
| 3.2.4.4 | `CALDAV:allowed-attendee-scheduling-object-change` | ◻ | A MAY: "Servers MAY impose restrictions on modifications allowed by an 'Attendee', subject to the allowed changes specified in Section 3.2.2.1". This server imposes none, so there is nothing to report it for — the same shape §2.4.2 has | — |
| 3.2.5 | `DTSTAMP` and `SEQUENCE` | ✔ | `CalDav\Schedule\SchedulingMessage` | `SchedulingMessageTest` |
| 3.2.6 | An attendee is told only of the instances that include them | ✔ | `CalDav\Schedule\Restriction` | `RestrictionTest` |
| 3.2.7 | `SCHEDULE-FORCE-SEND` | ✔ | `CalDav\Schedule\ForceSend` | `ForceSendTest` |
| 3.2.8 | A reschedule takes the answers back | ✔ | `CalDav\Schedule\Reschedule` | `RescheduleTest` |
| 3.2.9 | The delivery status values | ✔ | `CalDav\Schedule\DeliveryStatus` | `SchedulingDeliveryTest` |
| 3.2.9.1 | `1.0`, pending | ◻ | Never answered: this server delivers within the request, so nothing is ever pending | — |
| 3.2.9.2 | `1.1`, sent without knowing | ◻ | Never answered: there is no store-and-forward transport yet, so a delivery is known either way | — |
| 3.2.9.3 | `1.2`, delivered | ✔ | `DeliveryStatus::Delivered` | `SchedulingDeliveryTest` |
| 3.2.10 | `CALDAV:schedule-tag`, and avoiding conflicts | ⚠ | Deferred with reasons: it needs a place for per-resource **server** state, and it does not slice into shippable parts — a tag that changed on an inconsequential update would be worse than none | — |
| 3.2.10.1 | `PUT` with `If-Schedule-Tag-Match` | ⚠ | Open, with §3.2.10 | — |
| 3.2.10.2 | `DELETE`, `COPY` or `MOVE` with it | ⚠ | Open, with §3.2.10 | — |
| 4 | Incoming messages are processed before they appear in the Inbox | ✔ | `Plugin\Scheduling::processedForTheRecipient()` | `SchedulingProcessingTest` |
| 4.1 | An organizer's message updates the recipient's copy | ✔ | `Plugin\Scheduling::copyForTheRecipient()` | `SchedulingProcessingTest` |
| 4.2 | An attendee's reply updates the organizer's copy. **Its last SHOULD is open** — telling the other attendees of a reply, which brings RFC 5546 §3.2.2.5 with it | ⚠ | `CalDav\Schedule\Reply` keeps the MUSTs | `ReplyTest`, `SchedulingAnsweredTest` |
| 4.3 | The default calendar collection | ✔ | `Plugin\Scheduling::defaultCalendarAt()` | `SchedulingProcessingTest` |
| 4.3.1 | The two preconditions of this section | ✔ | `Plugin\Scheduling::guardTheDefaultCalendar()` — the first of them | `SchedulingTest` |
| 4.3.1.1 | `CALDAV:default-calendar-needed` | ✔ | `Plugin\Scheduling::guardTheDefaultCalendar()` | `SchedulingTest` |
| 4.3.1.2 | `CALDAV:valid-schedule-default-calendar-URL` | ⚠ | Open: the property is read, never written, so nothing validates a `PROPPATCH` of it | — |
| 5 | A `POST` on an Outbox answers busy time | ✔ | `Plugin\Scheduling::busyTime()` | `SchedulingFreeBusyTest` |
| 5.1 | Its status codes | ✔ | `Plugin\Scheduling::busyTime()` | `SchedulingFreeBusyTest`, `SchedulingFreeBusyPrivilegesTest` |
| 5.2 | The reused and the new preconditions | ✔ | `Plugin\Scheduling::busyTime()` | `SchedulingFreeBusyTest` |
| 5.2.1 | `CALDAV:valid-scheduling-message` | ✔ | `CalDav\Schedule\FreeBusyRequest` | `FreeBusyRequestTest` |
| 5.2.2 | `CALDAV:valid-organizer` | ✔ | `Plugin\Scheduling::busyTime()` | `SchedulingFreeBusyTest` |
| 6 | Eight privileges, all non-abstract, on the collections they are defined on | ✔ | `CalDav\Schedule\Privileges` | `PrivilegesTest`, `SchedulingPrivilegesTest` |
| 6.1 | What an Inbox's privileges decide, and whose grant that is | ✔ | `Plugin\Scheduling::deliver()` | `SchedulingDeliveryPrivilegesTest` |
| 6.1.1 | `CALDAV:schedule-deliver` | ✔ | `Privileges::anInboxDefines()` | `SchedulingDeliveryPrivilegesTest` |
| 6.1.2 | `CALDAV:schedule-deliver-invite` | ✔ | `SchedulingMessage::deliveryPrivilege()` | `SchedulingDeliveryPrivilegesTest` |
| 6.1.3 | `CALDAV:schedule-deliver-reply` | ✔ | `SchedulingMessage::deliveryPrivilege()` | `SchedulingDeliveryPrivilegesTest` |
| 6.1.4 | `CALDAV:schedule-query-freebusy` | ✔ | `Plugin\Scheduling::mayBeAskedBy()` | `SchedulingFreeBusyPrivilegesTest` |
| 6.2 | What an Outbox's privileges decide | ✔ | `Plugin\Scheduling::mustBeAllowedToSend()` | `SchedulingSendPrivilegesTest` |
| 6.2.1 | `CALDAV:schedule-send` | ✔ | `Privileges::anOutboxDefines()` | `PrivilegesTest` |
| 6.2.2 | `CALDAV:schedule-send-invite` | ✔ | `Plugin\Scheduling::mustBeAllowedToSend()` | `SchedulingSendPrivilegesTest` |
| 6.2.3 | `CALDAV:schedule-send-reply` | ✔ | `Plugin\Scheduling::tellTheOrganizerOf()` | `SchedulingSendPrivilegesTest` |
| 6.2.4 | `CALDAV:schedule-send-freebusy` | ✔ | `Plugin\Scheduling::mustHold()` | `SchedulingFreeBusyPrivilegesTest` |
| 6.3 | The aggregation of the two branches under `DAV:all` | ✔ | `CalDav\Schedule\Privileges` | `PrivilegesTest` |
| 7 | The three new property parameters | ✔ | `CalDav\Schedule\` | `ScheduleAgentTest`, `ForceSendTest` |
| 7.1 | `SCHEDULE-AGENT` | ✔ | `CalDav\Schedule\ScheduleAgent` | `ScheduleAgentTest` |
| 7.2 | `SCHEDULE-FORCE-SEND` | ✔ | `CalDav\Schedule\ForceSend` | `ForceSendTest` |
| 7.3 | `SCHEDULE-STATUS` | ✔ | `CalDav\Schedule\DeliveryStatus::NAME` | `SchedulingDeliveryTest` |
| 8 | The three new header fields | ⚠ | One of three: `Schedule-Reply`, `Schedule-Tag` and `If-Schedule-Tag-Match` are all open | — |
| 8.1 | `Schedule-Reply` request header | ⚠ | Open, with §3.2.2.4 | — |
| 8.2 | `Schedule-Tag` response header | ⚠ | Open, with §3.2.10 | — |
| 8.3 | `If-Schedule-Tag-Match` request header | ⚠ | Open, with §3.2.10 | — |
| 9 | The three new WebDAV properties | ✔ | two of three; §9.3 is open with §3.2.10 | `SchedulingTest` |
| 9.1 | `CALDAV:schedule-calendar-transp`, including its `SHOULD NOT` for allprop and its MAY to be protected | ✔ | `Plugin\Scheduling::WITHHELD_FROM_ALLPROP`, `Plugin\DeadProperties` | `DeadPropertiesTest`, `SchedulingFreeBusyTest` |
| 9.2 | `CALDAV:schedule-default-calendar-URL` | ✔ | read through `Answers`, so a store answers it | `SchedulingProcessingTest` |
| 9.3 | `CALDAV:schedule-tag` | ⚠ | Open, with §3.2.10 | — |
| 10 | The four XML elements of the `POST` response | ✔ | `CalDav\Schedule\ScheduleResponse` | `ScheduleResponseTest` |
| 10.1 | `CALDAV:schedule-response` | ✔ | `ScheduleResponse::NAME` | `ScheduleResponseTest` |
| 10.2 | `CALDAV:response` | ✔ | `ScheduleResponse::asElement()`; its optional `DAV:error` is deliberately left out — see the §6.1.4 chunk | `ScheduleResponseTest` |
| 10.3 | `CALDAV:recipient` | ✔ | `ScheduleResponse::asElement()` | `ScheduleResponseTest` |
| 10.4 | `CALDAV:request-status` | ✔ | `ScheduleResponse` | `ScheduleResponseTest`, `SchedulingFreeBusyPrivilegesTest` |
| 11 | Security considerations; the TLS and authentication MUSTs are a deployment's | ◻ | A library that neither opens sockets nor authenticates cannot keep them; said out loud rather than claimed | — |
| 11.1 | Preventing denial of service, by the three preconditions and the walk's limit | ✔ | `CalDav\Limits`, `Plugin\Scheduling` | `SchedulingLimitsTest`, `SchedulingFreeBusyTest` |
| 11.2 | Verifying scheduling operations | ✔ | §6.2 refuses a write nobody granted the sending of | `SchedulingSendPrivilegesTest` |
| 11.3 | Verifying busy time information requests | ✔ | §6.2.4 and §6.1.4 | `SchedulingFreeBusyPrivilegesTest` |
| 11.4 | Privacy: busy time says when, not what | ✔ | `CalDav\FreeBusy` reports periods alone | `SchedulingFreeBusyTest` |
| 11.5 | Mitigation of iTIP threats | ◻ | Prose pointing at RFC 5546 §6; what it asks of a server is §6's privileges and §11.1's limits, both above | — |
| 12 | IANA considerations | ◻ | — | — |
| 12.1 | Message header field registrations | ◻ | — | — |
| 12.1.1 | `Schedule-Reply` | ◻ | A registration; the header itself is §8.1 | — |
| 12.1.2 | `Schedule-Tag` | ◻ | A registration; the header itself is §8.2 | — |
| 12.1.3 | `If-Schedule-Tag-Match` | ◻ | A registration; the header itself is §8.3 | — |
| 12.2 | iCalendar property parameter registrations | ◻ | — | — |
| 12.3 | `REQUEST-STATUS` value registrations | ◻ | The values themselves are §3.2.9 and §5 | — |
| 12.4 | Additional iCalendar element registries | ◻ | — | — |
| 12.4.1 | Schedule agent values registry | ◻ | The values themselves are §7.1 | — |
| 12.4.2 | Schedule force send values registry | ◻ | The values themselves are §7.2 | — |
| 13 | Acknowledgements | ◻ | — | — |
| 14 | References | ◻ | — | — |
| 14.1 | Normative references | ◻ | — | — |
| 14.2 | Informative references | ◻ | — | — |
| A | Scheduling privileges summary | ✔ | The two tables are what `Privileges` and the three enforcement sites were read against | `SchedulingSendPrivilegesTest`, `SchedulingDeliveryPrivilegesTest`, `SchedulingFreeBusyPrivilegesTest` |
| A.1 | Inbox privileges, per component and `METHOD` | ✔ | `SchedulingMessage::deliveryPrivilege()`, `Plugin\Scheduling::mayBeAskedBy()` | `SchedulingDeliveryPrivilegesTest`, `SchedulingFreeBusyPrivilegesTest` |
| A.2 | Outbox privileges, per component and `METHOD` | ✔ | `Plugin\Scheduling::mustBeAllowedToSend()`, `mustHold()` | `SchedulingSendPrivilegesTest`, `SchedulingFreeBusyPrivilegesTest` |
| B | Example scheduling operations | ◻ | Not normative, but read: B.5's request and response, and B.6's refusal, are what two chunks were checked against | — |
| B.1 | An organizer inviting several attendees | ◻ | `SchedulingDeliveryTest` follows it | — |
| B.2 | An attendee receiving an invitation | ◻ | `SchedulingProcessingTest` follows it | — |
| B.3 | An attendee replying | ◻ | `SchedulingReplyTest` follows it | — |
| B.4 | An organizer receiving a reply | ◻ | `SchedulingAnsweredTest` follows it | — |
| B.5 | An organizer requesting busy time | ◻ | `SchedulingFreeBusyTest` follows it, down to `3.7;Invalid calendar user` | — |
| B.6 | A user inviting on an organizer's behalf, and refused | ◻ | `SchedulingSendPrivilegesTest` follows it, down to the Outbox href | — |
| B.7 | An attendee declining one instance | ◻ | `SchedulingReplyTest` follows it | — |
| B.8 | An attendee removing one instance | ◻ | Open with §3.2.2.4, which it turns on | — |
