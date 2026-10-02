# Cold outreach email sequence: corporate, office and event catering

DRAFT. Nothing in this file has been sent. Send only after Elie's explicit yes, one message at a time from a named inbox, after the checks in `compliance-checklist.md`. No email service provider is connected.

How to read this file. Fenced blocks tagged `subject`, `preview`, `body` and `store-line` are customer-facing text. The unit test checks every one of them against the banned-terms list, the subject length (60 characters or fewer), the sender-identification placeholders, the unsubscribe line, the route contract and the UTM rules, and it fails on any `[CONFIRM` marker inside a block. Everything outside those blocks is internal planning and may carry `[CONFIRM]`.

## Placeholders

| Placeholder | Fill with |
|---|---|
| `{SENDER_NAME}` | The named person sending. `[CONFIRM: sender]` |
| `{SENDER_ROLE}` | Their role. `[CONFIRM: role]` |
| `{BUSINESS_NAME}` | Trading name as used on shop signs. `[CONFIRM: exact wording]` |
| `{BUSINESS_ADDRESS}` | Street or postal address of the operating business. `[CONFIRM: address and legal entity]` |
| `{BUSINESS_LEGAL_ENTITY_AND_ABN}` | Legal entity name and ABN. `[CONFIRM]` |
| `{SENDER_PHONE}` | A phone number that is answered. `[CONFIRM]` |
| `{UNSUBSCRIBE_ADDRESS}` | A monitored address that will receive unsubscribe replies for at least 30 days after each send |
| `{RECIPIENT_NAME}` | The person or role the address belongs to (for a role address, use "team" or the role) |
| `{ORGANISATION}` | The public organisation's name |
| `{PUBLISHED_SOURCE}` | Where the address was published, in words (for example "your website's contact page") |
| `{NEAREST_STORE_LINE}` | One of the three `store-line` blocks below |
| `{OPTIONAL_TASTING_LINE}` | Empty unless Elie has approved a tasting and its terms (see `tasting-offer.md`). Delete the line break if empty |
| `{SEGMENT}`, `{OFFER}`, `{AREA}` in links | Lower-case, hyphenated values for `utm_campaign`, for example `corporate`, `office-breakfast`, `bankstown` |

## Store lines (from `src/lib/data/catalogue.ts`, typed store data)

These are the only shop facts used in outreach. They are shop details, not catering promises.

```store-line
Revesby: 12/25 Selems Parade, Revesby NSW 2212. Phone (02) 9774 2286. Shop open 7 days, 6:30am to 2:30pm.
```

```store-line
Bankstown: 462 Chapel Rd, Little Saigon Plaza, cnr Kitchener Pde and French Ave, Bankstown NSW 2200. Phone (02) 8764 6783. Shop open Monday to Friday, 7:00am to 2:00pm.
```

```store-line
Roselands Centro: Shop MM03, Roselands Dr, Roselands NSW 2196. Phone 0466 353 133. Shop open daily, 8:00am to 3:00pm.
```

## Shared footer (appears at the end of every email body)

Sender identification (Spam Act s 17) and the unsubscribe statement (s 18) sit in the same footer so an edit to one email cannot drop them.

```
{SENDER_NAME}
{SENDER_ROLE}, {BUSINESS_NAME}
{BUSINESS_LEGAL_ENTITY_AND_ABN}
{BUSINESS_ADDRESS}
Phone {SENDER_PHONE}

I found this address on {PUBLISHED_SOURCE}. If you would rather not hear from us, reply with the word unsubscribe or write to {UNSUBSCRIBE_ADDRESS}, and I will stop.
```

## Touch 1 (day 0): who looks after team food orders

Ask: a name or a referral. No pitch, no price, no tasting.

```subject
Who looks after team food orders?
```

```preview
A short question for whoever organises meetings and events.
```

```body
Hello {RECIPIENT_NAME},

I'm {SENDER_NAME} from {BUSINESS_NAME}. We have shops in Revesby, Bankstown and Roselands Centro, and we take catering enquiries from offices and event organisers.

I'm writing to the role that handles meetings and events at {ORGANISATION}. If your team ever orders food for a meeting, a breakfast or an event, could you point me to the right person?

A one-line reply is plenty. If this isn't relevant, tell me and I won't write again.

Thank you,

{SENDER_NAME}
{SENDER_ROLE}, {BUSINESS_NAME}
{BUSINESS_LEGAL_ENTITY_AND_ABN}
{BUSINESS_ADDRESS}
Phone {SENDER_PHONE}

I found this address on {PUBLISHED_SOURCE}. If you would rather not hear from us, reply with the word unsubscribe or write to {UNSUBSCRIBE_ADDRESS}, and I will stop.
```

## Touch 2 (day 4): a one-page overview

Ask: reply "yes" to receive the overview. The overview is `offer-one-pager.md`. Sending the overview is only done to people who said yes.

```subject
A one-page catering overview, if useful
```

```preview
How an enquiry works: what to tell us and what comes back.
```

```body
Hello {RECIPIENT_NAME},

I wrote a few days ago about catering for {ORGANISATION}. I haven't heard back, which is fine.

I have a one-page overview of how an enquiry works with us: what to tell us, and what you get back. It takes a minute to read. Would you like me to send it? Reply "yes" and I will.

Your nearest shop is:
{NEAREST_STORE_LINE}

Thank you,

{SENDER_NAME}
{SENDER_ROLE}, {BUSINESS_NAME}
{BUSINESS_LEGAL_ENTITY_AND_ABN}
{BUSINESS_ADDRESS}
Phone {SENDER_PHONE}

I found this address on {PUBLISHED_SOURCE}. If you would rather not hear from us, reply with the word unsubscribe or write to {UNSUBSCRIBE_ADDRESS}, and I will stop.
```

## Touch 3 (day 9): date, headcount and dietary needs

Ask: the details for one upcoming meeting or event. The enquiry form link carries the UTM convention. Use the interim `/catering/` path until `/catering/corporate` is live.

```subject
Your next team event: date, headcount, dietary needs
```

```preview
Send three details and we will come back with a written quote.
```

```body
Hello {RECIPIENT_NAME},

If {ORGANISATION} has a meeting, breakfast or event coming up, send me three things: the date, the number of people, and any dietary needs in the group. Tell me where it is happening too.

We will come back with a written quote you can pass to whoever approves it.
{OPTIONAL_TASTING_LINE}
You can reply to this email, or use the enquiry form:
https://doughboss.com.au/catering/corporate?utm_source=email&utm_medium=email&utm_campaign={SEGMENT}-{OFFER}-{AREA}&utm_content=touch-3-form

Thank you,

{SENDER_NAME}
{SENDER_ROLE}, {BUSINESS_NAME}
{BUSINESS_LEGAL_ENTITY_AND_ABN}
{BUSINESS_ADDRESS}
Phone {SENDER_PHONE}

I found this address on {PUBLISHED_SOURCE}. If you would rather not hear from us, reply with the word unsubscribe or write to {UNSUBSCRIBE_ADDRESS}, and I will stop.
```

## Touch 4 (day 16): closing note

Ask: name a month, or do nothing. After this the recipient hears nothing more unless they reply.

```subject
Closing the loop on catering
```

```preview
My last note unless you tell me otherwise.
```

```body
Hello {RECIPIENT_NAME},

This is my last note about catering for {ORGANISATION}. If it isn't something you need, you don't have to reply and I won't write again.

If it might be useful later, reply with a month and I will get in touch then. Or send the date, headcount and dietary needs for any event whenever it suits:
https://doughboss.com.au/catering?utm_source=email&utm_medium=email&utm_campaign={SEGMENT}-{OFFER}-{AREA}&utm_content=touch-4-form

Thank you,

{SENDER_NAME}
{SENDER_ROLE}, {BUSINESS_NAME}
{BUSINESS_LEGAL_ENTITY_AND_ABN}
{BUSINESS_ADDRESS}
Phone {SENDER_PHONE}

I found this address on {PUBLISHED_SOURCE}. If you would rather not hear from us, reply with the word unsubscribe or write to {UNSUBSCRIBE_ADDRESS}, and I will stop.
```

## Re-engagement note

For contacts who have already replied, asked for the overview or sent an enquiry, and then gone quiet for a few months `[CONFIRM: how long]`. Never for a cold prospect who did not reply to touches 1 to 4. One note only. If it gets no reply, stop.

Who may receive it (audit rule, 2026-10-02): only (a) an outbound contact whose role address has a complete evidence record under the conspicuous-publication test and who replied, or (b) an enquirer who ticked a separate, unticked marketing-consent box that is recorded with time and wording. An enquiry-form lead without recorded marketing consent does not get this note: an enquiry alone does not establish consent to promotional follow-up (`docs/marketing/04-corporate-outreach.md` section 7, and the live form captures no consent today). `[CONFIRM: lawyer view on how long a reply or an enquiry supports a follow-up]`

```subject
Still thinking about catering?
```

```preview
A quick check-in from {BUSINESS_NAME}.
```

```body
Hello {RECIPIENT_NAME},

You were in touch with us about catering for {ORGANISATION}, and I wanted to check in. Is there a meeting or event coming up?

If there is, send me the date, headcount and dietary needs and we will come back with a written quote. If the timing isn't right, just say so and I will leave it with you.

Thank you,

{SENDER_NAME}
{SENDER_ROLE}, {BUSINESS_NAME}
{BUSINESS_LEGAL_ENTITY_AND_ABN}
{BUSINESS_ADDRESS}
Phone {SENDER_PHONE}

You are receiving this because you contacted {BUSINESS_NAME} about catering. If you would rather not hear from us, reply with the word unsubscribe or write to {UNSUBSCRIBE_ADDRESS}, and I will stop.
```

## Link table (ready values)

| Touch | Final route | Interim route | utm_content |
|---|---|---|---|
| 3 | `/catering/corporate` | `/catering/` | `touch-3-form` |
| 4 | `/catering` | `/catering/` | `touch-4-form` |

Example `utm_campaign` values: `corporate-office-breakfast-bankstown`, `corporate-office-breakfast-revesby`, `corporate-team-lunch-bankstown`, `events-event-platters-roselands`.

## Inferred-consent rationale (internal, per recipient type)

Basis: this is B2B outreach to role addresses under the conspicuous-publication exception, not consent from a list. It is narrow and the evidence has to be recorded per recipient.

1. Law read: Spam Act 2003 (Cth), as-made text, Schedule 2 clause 4, read at `https://www.legislation.gov.au/C2004A01214/asmade/2003-12-12/text/original/pdf` on 2026-10-02. Clause 4(1): publication alone does not imply consent. Clause 4(2): consent is taken to exist when the address enables the public to message a particular officer, a person in a role or a group performing a function; it has been conspicuously published; it is reasonable to assume publication was agreed (by the organisation, for a role); there is no "no unsolicited commercial messages" statement; and the message is relevant to that function or role. Section 17 requires accurate sender identification. Section 18 requires a clear, conspicuous unsubscribe statement to an address likely to be able to receive unsubscribe messages for at least 30 days after sending.
2. Why it applies here (INFERRED): a catering message to an events, facilities, administration or office-manager role at a workplace is arguably relevant to that role's function. The same message to an unrelated role is weaker and must not be sent.
3. What the footer does: names the sender and entity, gives contact details, states where the address was found, and gives a working unsubscribe route.
4. Evidence kept: the evidence record in `docs/marketing/04-corporate-outreach.md` section 5 (URL, date, role, no-unsolicited notice, checker).
5. Not read: ACMA's current guidance (www.acma.gov.au returned HTTP 503 on 2026-10-02), the current Act compilation and the Spam Regulations. `[CONFIRM: lawyer or ACMA check before the first send]`.
6. Honouring unsubscribes: ACMA's 5 business day timeframe is an EXTRACT only. The working rule is to suppress the address the same day. `[CONFIRM: ACMA timeframe]`.

## Deliverability and sending notes (third-party guidance, not Dough Boss results)

- Source: Email Marketing Bible v2.7 (8 Sep 2026), sections 3, 11, 13 and 14, `https://emailmarketingskill.com`.
- Send one at a time from a named inbox. Keep daily volume small. Do not send from a domain used for transactional mail or orders `[CONFIRM: sending domain, SPF, DKIM, DMARC]`.
- Plain text or very light HTML, one link per email, no images, no tracking pixels. Decode and check each link before sending.
- Judge on replies, not opens.
- Pause on any spam complaint.
- Body length: each body stays short on purpose (the test caps it).
- Writing check: the humanizer skill was applied to the narrative sentences only. No fact, placeholder or compliance line was changed by it.
