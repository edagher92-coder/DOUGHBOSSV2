# LinkedIn outreach: corporate, office and event catering

DRAFT. Nothing has been sent or posted. Every message is typed and sent by a person from their own account.

How to read this file. Fenced blocks tagged `linkedin-note` (connection requests) and `linkedin-followup` (messages after a connection is accepted) are customer-facing text. The unit test checks each block against the banned-terms list, checks that every `linkedin-note` is 300 characters or fewer, and fails on any `[CONFIRM` marker inside a block. Everything outside the blocks is internal planning.

## Platform rules (respected, no automation)

- OBSERVED, `https://www.linkedin.com/help/linkedin/answer/a1341387`, retrieved 2026-10-02: LinkedIn prohibits using "bots or other unauthorized automated methods to access the Services, add or download contacts, send or redirect messages", and prohibits software, scripts, crawlers or browser plug-ins that scrape or copy the service. Accounts that break the rules risk being restricted or shut down.
- So: no automation tools, no browser extensions that send or scrape, no bulk export of contacts, no copying profile data into a spreadsheet by tool. One person, one account, typed messages.
- Search by role and area using LinkedIn's own search. Record only what a person needs to follow up: organisation, role, the date of contact, and the outcome. Do not record personal details beyond that.
- Do not name or approach private individuals. Approach people in a business role at a public organisation, about that role.
- Connection note first, with no pitch. Message after acceptance, with one ask. Never send a second message to someone who has not replied to the first follow-up.
- No fake profile, no pretending to know the person, no misleading headline.
- Message-sending limits are set by LinkedIn and change; check the account's own prompts and stay well inside them. `[CONFIRM: current LinkedIn invitation limits for the sender's account type]`.
- Spam Act: whether a LinkedIn direct message is a commercial electronic message for the Act is not settled in what I read. Treat follow-ups as if it is: identify the sender, keep it relevant to the person's role, and stop on request. `[CONFIRM: lawyer or ACMA]`.

## Who to approach (public business roles)

Office manager, executive assistant, people and culture, events coordinator, facilities or operations manager, at organisations in `segments.csv` with priority P1 or P2. Skip hospitals and anyone in a role unrelated to meetings, events or workplace operations.

## Connection notes (300 characters or fewer)

Fill `{FIRST_NAME}` and `{SUBURB_OR_AREA}`. No offer, no link.

```linkedin-note
Hello {FIRST_NAME}, I'm {SENDER_NAME} from {BUSINESS_NAME}. We have shops in Revesby, Bankstown and Roselands Centro. I'm connecting with people who organise meetings and events around {SUBURB_OR_AREA}. Happy to be in touch if it's ever useful.
```

```linkedin-note
Hello {FIRST_NAME}, I'm {SENDER_NAME} at {BUSINESS_NAME}, with shops in Revesby, Bankstown and Roselands Centro. I saw you look after events at {ORGANISATION}, so I'd like to connect.
```

```linkedin-note
Hello {FIRST_NAME}, I'm {SENDER_NAME}, {SENDER_ROLE} at {BUSINESS_NAME}. We take catering enquiries from offices and event organisers near our Bankstown, Revesby and Roselands Centro shops. Glad to connect.
```

## Follow-up 1 (after acceptance, a few days later `[CONFIRM: interval]`)

One ask: a referral or a yes to an overview.

```linkedin-followup
Thanks for connecting, {FIRST_NAME}. I'm {SENDER_NAME} from {BUSINESS_NAME}. If you ever organise food for a meeting, breakfast or event, I can send a one-page overview of how an enquiry works: what to tell us and what comes back. Would that be useful? If this isn't your area, could you point me to the right person?
```

## Follow-up 2 (about a week after follow-up 1, only if there has been no reply)

Last message. Offers the exit.

```linkedin-followup
Hello {FIRST_NAME}, it's {SENDER_NAME} from {BUSINESS_NAME} with one last note. If you have a meeting or event coming up, send me the date, headcount and any dietary needs, and we will come back with a written quote. If it isn't relevant, no reply is needed and I won't message again.
```

## When someone replies

- Positive reply with details: move to email or phone with their agreement, create a CRM record with `origin = outbound` and `source = linkedin`, and score it with the rubric in `docs/marketing/04-corporate-outreach.md` section 6.
- "Not me, try X": thank them, and approach X by connection note, not by pasting the first message.
- "No thanks": thank them in one line, mark the CRM record `LOST` with the reason, and stop.
- A request to stop: stop immediately and note it.

## Links

Do not put a link in the connection note. If a link is needed after a reply, use the enquiry form with `utm_source=linkedin&utm_medium=organic-social&utm_campaign=<segment>-<offer>-<area>&utm_content=linkedin-followup-1`.
