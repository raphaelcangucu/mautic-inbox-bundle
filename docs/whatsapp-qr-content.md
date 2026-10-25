# WhatsApp QR content in the Inbox

QR messages that cannot be decoded are displayed as a synchronization notice,
not as an assertion that WhatsApp omitted the message. This text is generated
by the Inbox and is never sent to the customer. The original WhatsApp message
may still be visible on the paired phone. View-once content is intentionally
not archived and has a distinct notice.

The QR connector can now provide text summaries for contacts, location
snapshots, polls and interactive replies; the Inbox labels those summaries in
English or Brazilian Portuguese. Attachments still use the authenticated,
account-scoped media provider. WhatsApp Cloud API error handling remains
unchanged.

The connector owns parsing and recovery; see its
[content recovery documentation](https://github.com/raphaelcangucu/mautic-whatsqr-bundle/blob/main/docs/message-content-recovery.md).
When the primary phone returns a readable copy of a previously unsupported
message, it replaces that message's content without creating another bubble,
changing assignment/unread state or triggering an AI response. Existing
messages keep their original timestamps.

`php Tests/Standalone/qr-message-presentation.php` validates both locales,
supported summary labels and Cloud API parity without a kernel or database.
