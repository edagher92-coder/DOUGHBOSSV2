# Review response policy, templates and escalation path

DRAFT. Prepared 2026-10-02 for Elie. This is practical guidance, not legal advice. Items marked LAWYER or REGULATOR need a check by a lawyer or the named regulator before they are relied on.

How to read this file. Fenced blocks tagged `review-reply-positive`, `review-reply-neutral`, `review-reply-negative`, `review-reply-allergen` and `review-reply-urgent` are customer-facing public replies. The unit test checks each one against the banned-terms list. Placeholders in double braces are filled in by the person replying. Text outside the blocks is internal.

## 1. Policy

1. Reply to every review on the three Google profiles, positive and negative, in the voice of the shop. A reply is public and permanent.
2. Target: a reply within two business days. [CONFIRM: Elie to agree the target. It is an internal service level, not a promise to customers, and is never published.]
3. Never offer a refund, a discount or anything of value in a public reply. Move the conversation to the shop phone number or catering email. A public offer in exchange for a changed review breaches Google's policy (https://support.google.com/business/answer/3474122, retrieved 2026-10-02).
4. Never ask a reviewer to change or remove a review. Never edit, hide or suppress reviews. The ACCC treats suppressing reviews as misleading conduct, and cites a $2.9 million penalty in one matter (https://www.accc.gov.au/business/advertising-and-promotions/online-reviews, retrieved 2026-10-02).
5. Report a review to Google only when it breaks Google's content policy (for example it is clearly fake, off-topic or abusive), never because it is negative. Keep a note of why. Google's policy: https://support.google.com/contributionpolicy/answer/7400114.
6. Do not confirm or deny that a person was a customer if you cannot tell from your own records, and never publish a customer's name, order details, phone number or email in a reply.
7. Do not argue, correct the reviewer's tone, or explain at length. Acknowledge, state what you will do, give the contact route.
8. Plain Australian English. Short sentences. No emojis, no exclamation marks, no corporate jargon, no promises about what the shop will do in future that Elie has not approved.
9. Sign as the shop team and a first name only if the person agrees: "Dough Boss {{store_name}} team". [CONFIRM: who replies at each shop.]
10. Never reply to a review by naming a staff member who is criticised. Deal with staff matters privately.
11. Never use a customer's review in marketing without their permission, and never quote a rating without the real count (ACCC, same page).
12. No review of Dough Boss is ever written by staff, family or friends, or by anyone on the team under another name.

## 2. Templates

Fill in the placeholders. Edit the wording so no two replies read identically. Do not add facts that are not in the claims ledger.

### Positive review

```review-reply-positive
Hello {{reviewer_name}}, thank you for taking the time to write this. We have passed it on to the team at Dough Boss {{store_name}}. We hope to see you again soon.
```

Variant, when the reviewer names a menu item that is on the menu (use the exact item name from the menu, nothing more):

```review-reply-positive
Thank you, {{reviewer_name}}. We are glad you enjoyed the {{item_name}}. The team at Dough Boss {{store_name}} will be pleased to read this.
```

### Neutral or mixed review

```review-reply-neutral
Hello {{reviewer_name}}, thank you for the honest feedback. We have shared it with the team at Dough Boss {{store_name}}. If you would like to tell us more, please call the shop on {{store_phone}}.
```

### Negative review (service, wait, order mistake)

```review-reply-negative
Hello {{reviewer_name}}, we are sorry your visit did not go as it should have. Thank you for telling us. We would like to look into it properly. Please call the Dough Boss {{store_name}} shop on {{store_phone}}, or email {{contact_email}}, and tell us the day and time of your order so we can follow it up.
```

Rules for negative replies:

- Reply even if the review is unfair or inaccurate. Do not accuse the reviewer of lying.
- If you believe the review is not from a real customer (no order matches), say only: "We cannot find a matching order. Please call the shop so we can look into it." Then report it to Google only if it breaks the content policy.
- If the review contains abuse, hate, personal information or a threat, report it to Google and escalate to Elie. Do not reply until Elie decides.

### Allergen or dietary review (no reaction reported)

Examples: a customer says an item contained an ingredient they were not told about, or asks whether the shop is safe for an allergy.

```review-reply-allergen
Hello {{reviewer_name}}, thank you for raising this. Allergies and dietary needs matter to us. Our kitchen handles common allergens, so we cannot promise a meal without traces of them. Please call the Dough Boss {{store_name}} shop on {{store_phone}} so we can understand what happened and talk through the ingredients.
```

Rules:

- Never state in a public reply that an item is or is not safe for an allergy, and never say it is free of any allergen. Allergen information must come from the confirmed allergen matrix (compliance-au.md section 7), given directly to the customer.
- Never describe the food as gluten-free, vegan, dairy-free or halal in a reply unless the claim is confirmed in the ledger.
- Pass the review to Elie the same day (see the escalation path).

### Urgent: a reaction, illness or injury is reported

If a review says a customer or a guest had an allergic reaction, got sick, or was hurt, do not use a standard template. Send this short reply only after Elie has been told, and only if Elie agrees the reply goes out at all.

```review-reply-urgent
Hello {{reviewer_name}}, we are very sorry to read this and we want to understand what happened. Please call us as soon as you can on {{store_phone}}. We will take your call and treat it as a priority.
```

## 3. Escalation path

| Level | Trigger | Who | Time |
|---|---|---|---|
| 1 | Positive, neutral, or a simple service complaint | Shop manager replies using the templates | Within two business days (target) |
| 2 | Negative review with a staff conduct, pricing, refund, delivery or catering order dispute | Elie decides the reply and any private follow-up. The shop manager prepares the order facts first | Same business day |
| 3 | Allergen or dietary complaint, with no reaction reported | Elie, with the confirmed allergen matrix in hand. Review the shop's allergen procedure | Same day |
| 4 | A reaction, illness or injury is reported, or a customer mentions a doctor, hospital, lawyer, insurer or regulator | Elie immediately. Phone the customer. Keep every record (order, receipt, ingredient labels, staff roster, photos of labels). Notify the shop's insurer. Take advice on any reporting duty to the NSW Food Authority or the local council | Immediately. REGULATOR and LAWYER: this research did not read NSW Food Act material, and the reporting duties are not verified here |
| 5 | Abuse, hate, threats, personal information, extortion, or clearly fake or competitor reviews | Elie. Screenshot the review. Report it to Google under its content policy. Do not reply until Elie decides. If there is a threat to safety, call police | Same business day |

Records: keep a simple log per review (date, store, what was said, who replied, what was offered privately, outcome). Keep the log out of the public repository because it can hold customer details.

## 4. Done when

- [ ] Elie has agreed the reply owner at each shop and the reply target.
- [ ] The three managers have read this file and the allergen rule.
- [ ] A named person has the escalation contacts: Elie, the insurer, and a lawyer. [CONFIRM: names and numbers, which are not in any source we hold.]
- [ ] A test reply has been drafted for each template and checked against the compliance checklist (compliance-au.md section 11).
