import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:teamzo_flutter_conversation/teamzo_flutter_conversation.dart';

void main() {
  test('send keeps the session token and parses controls / closed', () async {
    late http.Request seen;
    final client = TeamzoConversationClient(
      widgetKey: 'k',
      apiBase: 'https://x.test/api',
      origin: 'https://site.com',
      httpClient: MockClient((req) async {
        seen = req;
        return http.Response(
            jsonEncode({
              'session_token': 'tok',
              'reply': 'Hi',
              'options': ['A'],
              'controls': [
                {'action': 'back', 'label': 'Back'}
              ],
              'closed': true,
            }),
            200);
      }),
    );

    final r = await client.send(message: 'hello');
    expect(client.sessionToken, 'tok');
    expect(r.options, ['A']);
    expect(r.controls.single.action, 'back');
    expect(r.closed, isTrue);
    expect(seen.headers['Origin'], 'https://site.com');
  });
}
