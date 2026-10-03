# Review request scripts (SMS, email, in-store card, counter wording)

DRAFT. Nothing here has been sent or printed. Prepared 2026-10-02 for Elie.

How to read this file. Fenced blocks tagged `review-sms`, `review-email`, `review-card` and `review-counter` are customer-facing text. The unit test checks each one against the banned-terms list and the length limit. Text outside those blocks is internal planning.

## 1. The rules these scripts are built on

These come from the compliance guide (docs/marketing/research/compliance-au.md, sections 3 and 4) and from Google's own pages. This is practical guidance, not legal advice.

1. No incentives. Never offer a discount, a free item, a prize or a loyalty point for a review. Google treats offering incentives in exchange for posting, changing or removing a review as fake and misleading content (https://support.google.com/business/answer/3474122, retrieved 2026-10-02). The ACCC says an incentive is only acceptable if it applies equally to positive and negative reviews and is clearly disclosed (https://www.accc.gov.au/business/advertising-and-promotions/online-reviews, retrieved 2026-10-02). Our rule is simpler and safer: no incentive of any kind, so the test never arises. The student voucher scheme and any catering rebate must never mention reviews.
2. No review gating. Ask every customer in the same way. Do not ask "was everything good?" first and send only happy customers to Google. Every message here gives the same review link to everyone.
3. No fake or solicited-only-positive reviews. Never write a review for a customer, never ask staff, family or friends to review, and never ask a customer to change or remove a review.
4. Real customers only. Ask people who actually bought from or were served by that shop.
5. Ask in a way that is not pressuring. Google bars pressuring users to leave reviews on the premises and asking staff to solicit a certain number of reviews (https://support.google.com/contributionpolicy/answer/7400114, retrieved 2026-10-02). Staff mention the QR code at most once, and there are no quotas or targets per person.
6. Show real numbers. If a rating is ever quoted on the website or in an ad, show the real count with it, and only use ratings that are real and current.
7. Electronic messages follow the Spam Act 2003 (see section 3 below). The in-store card and counter wording involve no electronic message, so they carry no Spam Act risk. They are the default channel.

## 2. In-store card and counter wording (default channel)

Print on a small card handed over with the order or placed in a catering box, and on a counter sign beside the till. The QR code points to the store's short link (marketing/reviews/short-links-and-qr.md). Use the same wording for every customer.

```review-card
Dough Boss {{store_name}}

Tell others what you thought. Scan the code to write a Google review of this shop. Honest views are welcome, good or bad.

Questions or a problem with your order? Call us on {{store_phone}}.
```

```review-counter
Scan to write a Google review of Dough Boss {{store_name}}.
```

Staff wording, spoken at most once and only if the customer asks or the moment is natural: "There is a QR code on the card if you would like to leave a review." Staff never ask for a particular rating, never hold the customer's phone, and never ask a customer to delete or change a review.

## 3. SMS and email: consent first

The Spam Act says a commercial electronic message needs the recipient's consent, a clear sender identification, and a working unsubscribe (compliance-au.md section 4; the Act text was read as made in 2003, so check the current version: REGULATOR, ACMA). A review request that promotes the business can count as commercial, so treat it as commercial.

Consent rules for Dough Boss:

- Use SMS or email for review requests only for customers who ticked an express opt-in box when they ordered or enquired. The box is not pre-ticked.
- ACMA treats inferred consent as covering an ongoing relationship, not a message sent just because someone bought something (compliance-au.md section 4, EXTRACT: re-check on acma.gov.au, which was not readable). So a purchase alone is not enough.
- Keep a record of the consent: date and time, source, the wording shown, and the address or number.
- Honour unsubscribe requests promptly. The research guide reports ACMA expects this within five business days (EXTRACT).
- Send each person at most one review request per order, and no reminders.
- No purchased lists, no scraped lists.

Opt-in wording for the order or enquiry form (not pre-ticked):

```review-card
Tick to let Dough Boss {{store_name}} message you once after your order to ask for a Google review. You can opt out at any time.
```

### SMS (one message, plain text)

The sender name must identify Dough Boss. Template length is kept at 150 characters or fewer so the message fits one SMS once the short link is added. The short link is on our own domain.

```review-sms
Dough Boss {{store_name}}: thanks for your order. Tell others what you thought: {{review_link}} Reply STOP to opt out.
```

### Email

```review-email
Subject: Your order from Dough Boss {{store_name}}

Hello {{first_name}},

Thank you for your order from Dough Boss {{store_name}}.

If you would like to tell other people what you thought, you can write a Google review here:
{{review_link}}

Any view is welcome. If something was not right with your order, please call the shop on {{store_phone}} and we will look into it.

This message is from {{legal_entity}} (ABN {{abn}}), trading as Dough Boss.
Contact: {{contact_email}}
You agreed to receive one message like this when you ordered. To stop these messages, click here: {{unsubscribe_link}}
```

Notes on the email:

- The sender block names the legal entity and gives a working contact route, as section 17 of the Spam Act requires. [CONFIRM: legal entity name, ABN and a monitored contact address. They are not in any source we hold.]
- The line about calling the shop is offered to everyone. It is not a filter, and the review link is shown to everyone first.
- The link must be the same for every recipient.

## 4. Corporate and catering customers

For a catering order, send the same email, once, after the event date, to the person who placed the order and who ticked the opt-in. Never send to a generic company inbox without consent. Never ask the customer's staff to review. Do not offer anything for a review, including a "thank-you" tray.

## 5. What not to do

- No "5-star", "five star" or rating requests of any kind.
- No review-for-reward posters, loyalty tie-ins or raffle entries.
- No "tell us first and we will fix it" step that sits in front of the review link.
- No requests inside the ordering flow before the order is complete.
- No using a customer's review in ads or on the site without their permission and without the real rating and count alongside it.

## 6. Done when

- [ ] The card and counter sign are printed with the short link and tested on an iPhone and an Android phone.
- [ ] The opt-in box is live on the order and catering enquiry forms, unticked, with consent records stored.
- [ ] The SMS and email are loaded into a tool that adds the sender identification and a working unsubscribe, and a test message has been sent to Elie.
- [ ] A second person has read the scripts against the compliance checklist (compliance-au.md section 11).
