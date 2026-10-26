/// Config returned by `GET /public/widget/{key}/bootstrap`.
class TeamzoWidgetConfig {
  final String agentName;
  final String greeting;
  final bool hasFlow;
  final String? primaryColorHex;
  final String launcherText;
  final bool liveChat;
  final int liveWaitSeconds;

  const TeamzoWidgetConfig({
    this.liveChat = false,
    this.liveWaitSeconds = 90,
    required this.agentName,
    required this.greeting,
    required this.hasFlow,
    this.primaryColorHex,
    this.launcherText = 'Chat with us',
  });

  factory TeamzoWidgetConfig.fromJson(Map<String, dynamic> j) {
    final b = (j['branding'] is Map) ? Map<String, dynamic>.from(j['branding']) : <String, dynamic>{};
    return TeamzoWidgetConfig(
      agentName: (j['agent_name'] ?? 'Assistant').toString(),
      greeting: (j['greeting'] ?? 'Hi! How can I help?').toString(),
      hasFlow: j['has_flow'] == true,
      primaryColorHex: b['primary_color']?.toString(),
      launcherText: (b['launcher_text'] ?? 'Chat with us').toString(),
      liveChat: j['live_chat'] == true,
      liveWaitSeconds: (j['live_wait_seconds'] as num?)?.toInt() ?? 90,
    );
  }
}

/// A Back / Main menu / Reset chip sent by the server.
class TeamzoControl {
  final String action; // back | menu | reset
  final String label;
  const TeamzoControl(this.action, this.label);

  factory TeamzoControl.fromJson(Map<String, dynamic> j) =>
      TeamzoControl((j['action'] ?? '').toString(), (j['label'] ?? '').toString());
}

/// One server answer to a visitor turn.
class TeamzoReply {
  final String? sessionToken;
  final String? text;
  final List<String> options;
  final List<TeamzoControl> controls;
  final bool closed;
  final String? liveStatus; // set when the visitor's text went to staff instead of the bot

  const TeamzoReply({
    this.liveStatus,
    this.sessionToken,
    this.text,
    this.options = const [],
    this.controls = const [],
    this.closed = false,
  });

  factory TeamzoReply.fromJson(Map<String, dynamic> j) => TeamzoReply(
        sessionToken: j['session_token']?.toString(),
        text: j['reply']?.toString(),
        options: (j['options'] as List? ?? const []).map((e) => e.toString()).toList(),
        controls: (j['controls'] as List? ?? const [])
            .whereType<Map>()
            .map((e) => TeamzoControl.fromJson(Map<String, dynamic>.from(e)))
            .toList(),
        closed: j['closed'] == true,
        liveStatus: j['live_status']?.toString(),
      );
}

/// A message from the live-chat poll (staff or system) — may carry a photo / file and a quoted message.
class TeamzoLiveMessage {
  final int id;
  final String role; // staff | system
  final String type; // text | image | audio | file
  final String text;
  final String? url;
  final String? fileName;
  final String? replyToText;

  const TeamzoLiveMessage({
    required this.id,
    required this.role,
    required this.type,
    required this.text,
    this.url,
    this.fileName,
    this.replyToText,
  });

  factory TeamzoLiveMessage.fromJson(Map<String, dynamic> j) => TeamzoLiveMessage(
        id: (j['id'] as num).toInt(),
        role: (j['role'] ?? 'staff').toString(),
        type: (j['type'] ?? 'text').toString(),
        text: (j['text'] ?? '').toString(),
        url: j['url']?.toString(),
        fileName: j['file_name']?.toString(),
        replyToText: j['reply_to'] is Map ? (j['reply_to']['text'] ?? '').toString() : null,
      );
}

/// Live-chat state: waiting | live | missed | ended | null, plus any new messages.
class TeamzoLiveState {
  final String? status;
  final String? staffName;
  final String? name;
  final String? phone;
  final bool callbackConfirmed;
  final List<TeamzoLiveMessage> messages;

  const TeamzoLiveState({
    this.status,
    this.staffName,
    this.name,
    this.phone,
    this.callbackConfirmed = false,
    this.messages = const [],
  });

  factory TeamzoLiveState.fromJson(Map<String, dynamic> j) => TeamzoLiveState(
        status: j['live_status']?.toString(),
        staffName: j['staff_name']?.toString(),
        name: j['name']?.toString(),
        phone: j['phone']?.toString(),
        callbackConfirmed: j['callback_confirmed'] == true,
        messages: (j['messages'] as List? ?? const [])
            .whereType<Map>()
            .map((e) => TeamzoLiveMessage.fromJson(Map<String, dynamic>.from(e)))
            .toList(),
      );
}

class TeamzoException implements Exception {
  final String message;
  final int? statusCode;
  TeamzoException(this.message, [this.statusCode]);
  @override
  String toString() => 'TeamzoException($statusCode): $message';
}
