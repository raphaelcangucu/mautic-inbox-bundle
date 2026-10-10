# Facebook replies rejected by Meta

If Meta returns error 10 or 200 with the message that `pages_messaging` must be
reviewed and the app must be live, sending to customers outside the app test team
is blocked by Meta. A token containing `pages_messaging` and a published app do
not establish that App Review approved customer access.

Check the **App Review** page in Meta for Developers for the app used by the
Page connection. A request under **Not submitted** has not been sent for review.
Complete the required review before retrying customer messages. Publishing the
app alone does not remove this restriction. Do not add customers as app testers
to work around the restriction.

The durable outbound request remains in the conversation with status `failed`.
The Inbox web UI and mobile API now provide the localized explanation and
`failure_code: meta_messaging_review_required`; raw provider responses, tokens,
and endpoints stay on the server. Existing failed requests receive the same
diagnostic when read; no data migration or automatic resend is needed.

After approval and connection validation, an operator may use the existing
retry action. Preserve the request IDs and check the history before retrying.
Transport timeouts still follow the existing uncertain-delivery handling.

Validate classification without a database or Mautic kernel:

```sh
php Tests/Standalone/outbound-failure.php
```
