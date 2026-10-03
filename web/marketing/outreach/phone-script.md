# Phone script: corporate, office and event catering

DRAFT. No call has been made. Calls are made by a named person from the business's own phone line, with caller identification switched on.

How to read this file. Fenced blocks tagged `phone` are customer-facing spoken text. The unit test checks each one against the banned-terms list and fails on any `[CONFIRM` marker inside a block. Everything outside the blocks is internal planning and may carry `[CONFIRM]`.

## Before dialling (business hours and Do Not Call considerations)

1. Calling hours. The Do Not Call Register's industry-standards page (OBSERVED, `https://www.donotcall.gov.au/consumers/consumer-overview/industry-standards/`, retrieved 2026-10-02) lists permitted telemarketing call times as weekdays between 9am and 8pm and Saturdays between 9am and 5pm. That page does not say anything about Sundays or public holidays, so the working rule is: weekdays only, inside normal business hours, and never on a Sunday or public holiday. `[CONFIRM: ACMA Telemarketing and Research Calls Industry Standard text; not read directly]`.
2. Caller identification. The same page says calling line identification must be enabled and a contact number displayed, not "private". Use a number that can be called back.
3. Ending the call. Callers must end a call when the person asks or indicates they do not want it to continue. Do so politely and at once.
4. Do Not Call Register. The Register covers numbers used primarily for private or domestic purposes; business numbers cannot be registered (OBSERVED, `https://www.donotcall.gov.au/consumers/faqs-for-consumers`, via `docs/marketing/research/compliance-au.md` section 5). A published office landline is a business number. Mobiles and sole-trader numbers can be mixed-use. Wash any mobile or sole-trader number before dialling. Never call a household, a parent committee member's personal number or a personal mobile for catering without washing it. `[CONFIRM: the washing service process and fees]`.
5. Whether the Industry Standard binds calls to business numbers is not stated on the page I read; ACMA's position is an EXTRACT only. Follow its requirements anyway.
6. A call is not an email. Do not email a recipient after a call unless they agreed to receive an email, or you hold a documented basis under the Spam Act (see `sequence-email.md`). Ask: "May I send a short overview to your work address?"
7. Do not record the call unless the law and the other party's permission are settled. `[CONFIRM: lawyer on call recording and notice]`.
8. Log every call in the CRM: date, time, who answered by role, outcome, any `guestBand` and event detail, and any stop request.
9. Target list: P1 and P2 segments in `segments.csv` with a published office line. Skip hospitals until the allergen paperwork exists.

## Opening

Identify yourself and the business at once, say why you are calling, and ask for time.

```phone
Hello, it's {SENDER_NAME} from {BUSINESS_NAME}. We have shops in Revesby, Bankstown and Roselands Centro. I'm calling about food for meetings and events at {ORGANISATION}. Is now a good time for a quick question?
```

If no: "No problem. When would suit you, or is there a better person to call?" Note the answer and stop.

## Discovery questions

Ask one at a time. Listen. Write down the answers in the customer's words. Do not pitch while asking.

```phone
How does your team usually organise food for meetings or events at the moment?
```

```phone
Roughly how many people are at a typical meeting or event, and how often does it come up?
```

```phone
Are there any dietary needs in the group that you usually have to plan around?
```

```phone
Who decides or approves it, and what do they need to see before they say yes?
```

```phone
Is there anything coming up in the next little while that you are planning food for?
```

Record `guestBand` (`UP_TO_25`, `FROM_26_TO_50`, `FROM_51_TO_100`, `FROM_101_TO_250`, `OVER_250`) and whether they want an account or invoice (`wantsCorporateAccount`). Score with the rubric in `docs/marketing/04-corporate-outreach.md` section 6.

## Handling a gatekeeper

Be polite and short. Ask for the role, not a personal name or a personal number.

```phone
Hello, it's {SENDER_NAME} from {BUSINESS_NAME}. Could you tell me who looks after food for meetings and events at {ORGANISATION}? I'd like to ask them one quick question.
```

```phone
That's fine. Is there a good time to call back, or an address I can use to send a short note to that team? I will only send it if you're happy for me to.
```

If they decline to share anything: "Thank you for your help." End the call and log it.

## Voicemail

Under twenty seconds. No price, no offer, one reason to call back.

```phone
Hello, this is {SENDER_NAME} from {BUSINESS_NAME}. We have shops in Revesby, Bankstown and Roselands Centro, and I'm calling about food for meetings and events at {ORGANISATION}. If that's something you organise, please call me on {SENDER_PHONE}. That's {SENDER_PHONE}. Thank you.
```

Leave one voicemail per prospect. Do not leave a second.

## Handling a "no" or "send me something"

```phone
Of course. If you'd like a one-page overview of how an enquiry works, I can send it. May I use your work address for that?
```

```phone
Understood. Thank you for your time, and I won't call again.
```

## Close

One clear next step: details for one event, or permission to send the overview.

```phone
Thank you. Could you send me the date, the number of people and any dietary needs for the next event, and where it is happening? We will come back with a written quote. I'll note your details and follow up as we agreed.
```

```phone
If you would rather I didn't call again, just say so and I will make a note of it.
```

## Stop rules

- Stop the call the moment the person asks.
- Add the number to the internal do-not-contact list the same day.
- Never call the same number more than the agreed cadence. `[CONFIRM: call cadence; proposal is at most two attempts per number per cycle]`.
- Never state a price, lead time, minimum, delivery area, capacity or halal status on a call. Say you will confirm in writing, and ask for the details to quote against.
