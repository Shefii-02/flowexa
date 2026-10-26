import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

import 'models.dart';

/// Talks to the public widget API. Access is by the widget's public key plus — when the
/// widget is locked to specific domains in TeamZo — an [origin] matching one of them
/// (a mobile app has no browser origin, so it is sent explicitly as the `Origin` header).
class TeamzoConversationClient {
  final String widgetKey;

  /// e.g. `https://app.teamzo.io/api/v1/public/widget`
  final String apiBase;

  /// One of the widget's "Allowed website domains", e.g. `https://pachavellam.com`.
  /// Leave null when the widget accepts any domain.
  final String? origin;

  final http.Client _http;
  String? sessionToken;

  TeamzoConversationClient({
    required this.widgetKey,
    required this.apiBase,
    this.origin,
    this.sessionToken,
    http.Client? httpClient,
  }) : _http = httpClient ?? http.Client();

  Map<String, String> get _headers => {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        if (origin != null) 'Origin': origin!,
      };

  Uri _uri(String path) => Uri.parse('$apiBase/$widgetKey/$path');

  Future<TeamzoWidgetConfig> bootstrap() async {
    final r = await _http.get(_uri('bootstrap'), headers: _headers);
    return TeamzoWidgetConfig.fromJson(_decode(r));
  }

  /// A visitor turn: free [message] text (or a tapped option label), or an [action]
  /// (`back`, `menu`, `yes`, `no`).
  Future<TeamzoReply> send({String? message, String? action, String? pageUrl, int? replyToId}) async {
    final r = await _http.post(
      _uri('chat'),
      headers: _headers,
      body: jsonEncode({
        'session_token': sessionToken,
        'message': message,
        'action': action,
        'page_url': pageUrl,
        'reply_to_id': replyToId,
      }),
    );
    final reply = TeamzoReply.fromJson(_decode(r));
    if (reply.sessionToken != null) sessionToken = reply.sessionToken;
    return reply;
  }

  // ── Live chat with staff ───────────────────────────────────────────────

  /// Asks for a live agent (name + mobile required). Also edits the details while waiting.
  Future<TeamzoLiveState> liveStart({required String name, required String phone, String? pageUrl}) async {
    final r = await _http.post(_uri('live/start'),
        headers: _headers,
        body: jsonEncode({'session_token': sessionToken, 'name': name, 'phone': phone, 'page_url': pageUrl}));
    final d = _decode(r);
    if (d['session_token'] != null) sessionToken = d['session_token'].toString();
    return TeamzoLiveState.fromJson(d);
  }

  Future<TeamzoLiveState> livePoll({int after = 0}) async {
    final r = await _http.get(
        Uri.parse('$apiBase/$widgetKey/live/poll?session_token=${Uri.encodeComponent(sessionToken ?? '')}&after=$after'),
        headers: _headers);
    return TeamzoLiveState.fromJson(_decode(r));
  }

  /// After a missed request: confirm / edit name + number so staff can contact the visitor.
  Future<TeamzoLiveState> liveConfirm({required String name, required String phone, int after = 0}) async {
    final r = await _http.post(_uri('live/confirm'),
        headers: _headers,
        body: jsonEncode({'session_token': sessionToken, 'name': name, 'phone': phone, 'after': after}));
    return TeamzoLiveState.fromJson(_decode(r));
  }

  /// Sends a photo or file (max 2 MB; voice is staff-only).
  Future<void> liveUpload(File file, {String? fileName, int? replyToId}) async {
    if (await file.length() > 2 * 1024 * 1024) {
      throw TeamzoException('File is too large — the limit is 2 MB.', 422);
    }
    final req = http.MultipartRequest('POST', _uri('live/upload'))
      ..headers.addAll({'Accept': 'application/json', if (origin != null) 'Origin': origin!})
      ..fields['session_token'] = sessionToken ?? ''
      ..files.add(await http.MultipartFile.fromPath('file', file.path, filename: fileName));
    if (replyToId != null) req.fields['reply_to_id'] = '$replyToId';
    _decode(await http.Response.fromStream(await _http.send(req)));
  }

  /// Ends the current conversation server-side and forgets the session.
  Future<void> reset() async {
    final token = sessionToken;
    sessionToken = null;
    if (token == null) return;
    try {
      await _http.post(_uri('chat'),
          headers: _headers, body: jsonEncode({'session_token': token, 'action': 'reset'}));
    } catch (_) {/* best effort — the next message starts a fresh session anyway */}
  }

  void dispose() => _http.close();

  Map<String, dynamic> _decode(http.Response r) {
    Map<String, dynamic> body = {};
    try {
      final d = jsonDecode(r.body);
      if (d is Map) body = Map<String, dynamic>.from(d);
    } catch (_) {}
    if (r.statusCode < 200 || r.statusCode >= 300) {
      throw TeamzoException((body['message'] ?? 'Request failed').toString(), r.statusCode);
    }
    return body;
  }
}
