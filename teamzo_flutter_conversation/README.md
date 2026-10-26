# teamzo_flutter_conversation

Flutter version of the TeamZo website chat widget. Same server flow as the web embed:
flow-builder menus, **Back / Main menu / Reset**, and — on a terminal node — *"Was your issue
solved?"* → **Yes** closes the chat; **No** asks the visitor to describe the issue, tells them staff
will contact them, then closes it.

```yaml
dependencies:
  teamzo_flutter_conversation:
    path: ../teamzo_flutter_conversation
```

```dart
TeamzoChatWidget(
  widgetKey: '<widget public key>',
  apiBase: 'https://<your-host>/api/v1/public/widget',
  origin: 'https://pachavellam.com', // one of the widget's allowed domains (omit if unrestricted)
)
```

Access is by the widget's public key plus, when the widget is domain-locked, an `origin` matching
one of its allowed website domains. Use `TeamzoConversationClient` directly for a custom UI.

## Live chat with staff

When the widget has "Live chat with staff" turned on, a **💬 Chat with a person** chip appears. The visitor
confirms name + mobile (remembered on the device, editable), waits for a staff member, then chats with
photo / file attachments (max 2 MB), emoji and quote-reply (long-press a staff message). If nobody accepts in
time they are asked to confirm their details and staff call back. Voice messages are staff-only — staff
voice notes open in the system player.

### Already-logged-in users

If your app knows the user, pass their details and the widget skips the form entirely:

```dart
TeamzoChatWidget(
  widgetKey: '…', apiBase: '…',
  visitorName: user.name,
  visitorPhone: user.phone,
)
```

Anonymous website visitors (no `visitorName` / `visitorPhone`) still get the name + mobile form.
