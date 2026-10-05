# Native iOS push — Mautic Inbox

Direct delivery: Inbox inbound message → protected queue → APNs HTTP/2 → iOS app. Existing browser Web Push stays independent. Android native delivery is outside this release.

## Apple and signing

Team: `SB6QYUH97U`. Topic: `com.distributionmachine.mauticinbox.demo` (preserved to keep current app sessions). Register this explicit App ID with Push Notifications. Create topic-restricted APNs authentication keys for this topic, separately for development and production. Use production for TestFlight/App Store and development for developer builds. A provider `.p8` key replaces per-app APNs TLS certificates; do not put it in the app, repository, Expo config, artifacts, or logs.

Build with `MAUTIC_APNS_ENVIRONMENT=development` or `production`. The build config sets both the native `aps-environment` entitlement and the app runtime environment. Check the **signed exported application's** entitlement against the embedded provisioning profile. An unsigned archive or ad-hoc simulator build does not prove physical-device APNs readiness.

## Provider configuration (outside public root)

`dirname(realpath(kernel.project_dir))/inbox-mobile-private/apns.json`, directory 0700, config/key files 0600 owned by the Mautic worker user. Example placeholders only:

```json
{
  "development": {"team_id":"SB6QYUH97U","key_id":"APPLEKEYID","bundle_id":"com.distributionmachine.mauticinbox.demo","key_file":"AuthKey_APPLEKEYID.p8"},
  "production": {"team_id":"SB6QYUH97U","key_id":"OTHERKEYID","bundle_id":"com.distributionmachine.mauticinbox.demo","key_file":"AuthKey_OTHERKEYID.p8"}
}
```

Each key file must be a real Apple-issued P-256 private key; never generate an arbitrary key as a substitute. The app cannot choose a provider host or another topic. Delivery uses verified TLS, fixed Apple hosts, HTTP/2, bounded timeouts and redirects disabled. No database migration or push table is needed.

## Authenticated mobile API

* `GET push/device?installation=<UUID>`: own session's status, never token/key.
* `PUT push/device`: installation UUID, native APNs token, environment, fixed bundle, accountId, name, preferences, openConversation, foreground. Same operator session survives token rotation.
* `DELETE push/device`: unregister own installation/session.
* `POST push/test`: queue a generic test for this session only, 30-second throttle; requires configured provider.

Existing `/inbox/mobile/api/{resource}` bearer authentication validates the actual published Mautic operator and current password fingerprint and checks inbox permissions. Refresh/session expiry or logout stops delivery. The app acquires a fresh token each launch/resume and listens for token changes. Only the installation UUID persists in SecureStore. Native push is enabled explicitly per connection; permission withdrawal unregisters it on the next app sync. iOS uses remote APNs only, avoiding duplicate polling-generated local alerts.

## Delivery worker

After provider configuration and signed device registration:

```sh
php bin/console mautic:inbox:mobile-push --limit=50 --watch=55 --env=prod --no-debug
```

Run each minute as the same Mautic user; do not add duplicate scheduler entries. Protected global worker lock prevents concurrent senders. Private atomic registry/queue survives process restarts. Queues store IDs, not customer message bodies. Message/device/session IDs deduplicate repeated enqueue. Normal jobs expire after one hour. APNs 429, 5xx and network failures retry with bounded backoff; 410 retires the token. BadDeviceToken/topic mismatch are visible in device status. APNs credential errors do not remove all devices.

Delivery rechecks session, active user/permissions, current assignment, unread state, snoozing, spam/blocked author, enabled/quiet settings, and open-chat presence (expires after 60 seconds). Unassigned conversations notify authorized registered operators; assigned conversations notify only the assignee. Grouped delivery drops obsolete jobs and uses APNs collapse and thread IDs. Preview is off by default: customer name/content stays off the Apple payload unless explicitly enabled. iOS controls vibration. Quiet is an on/off setting, not an implemented time schedule.

A 200 from APNs means accepted, **not proof that the phone displayed the alert**. Retries/interruptions are at-least-once: a crash after APNs acceptance but before saving the acknowledgement can repeat delivery. The hook queues after request/console termination or each handled Messenger message, preserving existing incoming-message performance; an abrupt process death before that hook can lose the notification while retaining the message.

## Verification and remaining activation

The current ad-hoc simulator binary runs and preserves the operator session but has no signed APNs entitlement; adding that restricted entitlement manually caused launch denial and was reverted. Its APNs registration is not validated. Use an Apple-signed build for the delivery test.

Local standalone checks: `php Tests/Standalone/native-push.php` (temporary files and ephemeral test key, no Mautic kernel/DB). App typecheck and 37 existing checks passed. Production runtime supports HTTP/2. Source backup verified before deployment, no DB/schema operation. Mobile discovery advertises APNs but `push_remote=false` until a provider key exists; unauthenticated registry returns 401.

Pending: approve/register Apple App ID; create/download the scoped Apple keys; save them privately on the server; obtain signing profile; install signed app; register actual token; enable worker; verify a real inbound WebChat message with app closed and notification tap opening the right account. Physical-device visibility has not been verified yet. Do not describe a local or simulated notification as an APNs delivery.

Sources: [Apple device registration](https://developer.apple.com/documentation/usernotifications/registering-your-app-with-apns), [Apple token provider connection](https://developer.apple.com/documentation/UserNotifications/establishing-a-token-based-connection-to-apns), [Expo Notifications SDK 55](https://docs.expo.dev/versions/v55.0.0/sdk/notifications/).
