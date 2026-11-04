# WhatsApp booking agent — plan (NOT started)

**Owner decision (4 Nov 2026):** go straight to the WhatsApp agent (option B), skipping
the manual "log a WhatsApp booking" tool (option A). **Do not build until the owner says start.**

## Problem
Most clients book in WhatsApp DMs. Those bookings never reach the website, so the
admin, accounts and reminders don't know about them, and the website can sell the same slot.

## What the agent does
- Answers on the business WhatsApp (07312017135) 24/7 from live data: services,
  prices, options, prep notes, location, policies.
- Offers real free times (same scheduling code as the website), so no double-booking.
- Creates the booking in the database as **pending** and sends the Stripe deposit
  link; payment confirms it through the existing webhook.
- Owner approves with one tap (admin, app); can take over any chat at any time.
- Hands to the owner: custom styles, complaints, anything it is unsure of.
- Tells clients they are talking to an assistant.

## Needs (owner)
- Meta Business verification + WhatsApp Business Platform (Cloud API) access.
- Confirm whether 07312017135 can use "coexistence" (stay in the WhatsApp Business
  app while connected to the API); otherwise a second number.
- Accept running costs (confirm current Meta pricing at setup; AI cost ~pennies/chat).

## Build outline (when started)
1. Webhook endpoint on the website for incoming WhatsApp messages (signature-checked).
2. Conversation store + owner takeover flag; admin/app inbox view.
3. Agent (Claude API) with tools: list services, check free slots, create pending
   booking, create payment link, hand off to owner.
4. Phone notifications to the owner (also fixes the "no push alerts" audit item).
5. Test on test chats before going live; then staged rollout.
