import 'dart:async';
import 'dart:io';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';

import 'client.dart';
import 'models.dart';

const _emojis = ['😀', '😁', '😂', '🤣', '😊', '😍', '😘', '😎', '🤔', '😢', '😭', '😡', '👍', '👎', '👏', '🙏', '🙌', '💪', '🎉', '🔥', '❤️', '💯', '✅', '❌', '👋', '🤝', '😅', '😉', '🙂', '😇'];
final _phoneRe = RegExp(r'^\+?[0-9 ()-]{7,20}$');

class _Msg {
  final bool visitor;
  final bool system;
  final String text;
  final String type; // text | image | audio | file
  final String? url;
  final String? fileName;
  final String? replyText;
  final int? id; // server id (staff messages) — lets the visitor quote-reply
  _Msg(this.visitor, this.text, {this.system = false, this.type = 'text', this.url, this.fileName, this.replyText, this.id});
}

/// Drop-in chat panel — same behaviour as the website widget: flow menus with Back / Main menu /
/// Reset, "Was your issue solved?" hand-off, and live chat with staff (name + mobile remembered on
/// the device, waiting state, photo / file attachments, emoji, quote-reply). Voice is staff-only.
///
/// ```dart
/// TeamzoChatWidget(
///   widgetKey: 'abc123',
///   apiBase: 'https://app.teamzo.io/api/v1/public/widget',
///   origin: 'https://pachavellam.com',
/// )
/// ```
class TeamzoChatWidget extends StatefulWidget {
  final String widgetKey;
  final String apiBase;
  final String? origin;
  final Color? primaryColor;

  const TeamzoChatWidget({
    super.key,
    required this.widgetKey,
    required this.apiBase,
    this.origin,
    this.primaryColor,
  });

  @override
  State<TeamzoChatWidget> createState() => _TeamzoChatWidgetState();
}

class _TeamzoChatWidgetState extends State<TeamzoChatWidget> {
  late final TeamzoConversationClient _client;
  final _input = TextEditingController();
  final _scroll = ScrollController();
  final List<_Msg> _msgs = [];

  TeamzoWidgetConfig? _cfg;
  List<String> _options = [];
  List<TeamzoControl> _controls = [];
  bool _busy = false;
  String? _error;

  // live chat
  String? _liveStatus; // waiting | live | missed
  int _lastId = 0;
  Timer? _poller;
  _Msg? _replyTo;
  bool _emojiOpen = false;
  bool _showForm = false;
  bool _confirmMode = false;
  ({String name, String phone})? _savedCard; // details already collected on this device: Continue or Edit
  final _nameCtl = TextEditingController();
  final _phoneCtl = TextEditingController();
  String? _formError;

  String get _tokenKey => 'teamzo_session_${widget.widgetKey}';
  String get _visitorKey => 'teamzo_visitor_${widget.widgetKey}';
  bool get _liveOn => _liveStatus == 'waiting' || _liveStatus == 'live' || _liveStatus == 'missed';

  @override
  void initState() {
    super.initState();
    _client = TeamzoConversationClient(widgetKey: widget.widgetKey, apiBase: widget.apiBase, origin: widget.origin);
    _boot();
  }

  @override
  void dispose() {
    _poller?.cancel();
    _client.dispose();
    _input.dispose();
    _scroll.dispose();
    _nameCtl.dispose();
    _phoneCtl.dispose();
    super.dispose();
  }

  Color get _color {
    if (widget.primaryColor != null) return widget.primaryColor!;
    final hex = _cfg?.primaryColorHex?.replaceFirst('#', '');
    final v = hex == null ? null : int.tryParse(hex.length == 6 ? 'FF$hex' : hex, radix: 16);
    return v == null ? const Color(0xFF4F46E5) : Color(v);
  }

  // ── boot / greeting ───────────────────────────────────────────────────

  Future<void> _boot() async {
    try {
      final cfg = await _client.bootstrap();
      if (!mounted) return;
      setState(() => _cfg = cfg);
      _greet();
      await _resumeLive();
    } on TeamzoException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } catch (_) {
      if (mounted) setState(() => _error = 'Could not reach the server.');
    }
  }

  void _greet() {
    setState(() {
      _msgs
        ..clear()
        ..add(_Msg(false, _cfg!.greeting));
      _options = _cfg!.hasFlow ? ['Start'] : [];
      _controls = _cfg!.liveChat ? [const TeamzoControl('live', '💬 Chat with a person')] : [];
      _liveStatus = null;
      _lastId = 0;
      _replyTo = null;
      _showForm = false;
    });
  }

  Future<void> _resumeLive() async {
    if (!(_cfg?.liveChat ?? false)) return;
    final prefs = await SharedPreferences.getInstance();
    final token = prefs.getString(_tokenKey);
    if (token == null) return;
    _client.sessionToken = token;
    try {
      final st = await _client.livePoll();
      if (!mounted || !{'waiting', 'live', 'missed'}.contains(st.status)) return;
      _greetKeep();
      _applyLive(st);
      _startPolling();
    } catch (_) {}
  }

  void _greetKeep() => setState(() {
        _msgs..clear()..add(_Msg(false, _cfg!.greeting));
        _options = [];
        _controls = [];
      });

  // ── visitor details (cached on device) ─────────────────────────────────

  Future<void> _openForm({required bool confirm}) async {
    final prefs = await SharedPreferences.getInstance();
    final saved = prefs.getString(_visitorKey)?.split('\u0001') ?? const [];
    setState(() {
      _nameCtl.text = _nameCtl.text.isNotEmpty ? _nameCtl.text : (saved.isNotEmpty ? saved[0] : '');
      _phoneCtl.text = _phoneCtl.text.isNotEmpty ? _phoneCtl.text : (saved.length > 1 ? saved[1] : '');
      _showForm = true;
      _confirmMode = confirm;
      _formError = null;
      _options = [];
      _controls = [];
    });
    _toBottom();
  }

  /// Details already collected on this device → show them with Continue / Edit; otherwise ask.
  Future<void> _beginLive() async {
    final prefs = await SharedPreferences.getInstance();
    final saved = prefs.getString(_visitorKey)?.split('\u0001') ?? const [];
    if (saved.length > 1 && saved[0].trim().length >= 2 && _phoneRe.hasMatch(saved[1])) {
      setState(() {
        _msgs.add(_Msg(false, 'Continue with these details?'));
        _savedCard = (name: saved[0], phone: saved[1]);
        _options = [];
        _controls = [];
        _formError = null;
      });
      _toBottom();
      return;
    }
    setState(() => _msgs.add(_Msg(false, 'Please enter your details so our team can reach you.')));
    await _openForm(confirm: false);
  }

  Future<void> _continueWithSaved() async {
    final c = _savedCard;
    if (c == null) return;
    _nameCtl.text = c.name;
    _phoneCtl.text = c.phone;
    setState(() => _savedCard = null);
    _confirmMode = false;
    await _submitForm();
    if (mounted && !_liveOn) setState(() => _savedCard = c); // failed — show the card (with the error) again
  }

  Future<void> _editSaved() async {
    setState(() => _savedCard = null);
    await _openForm(confirm: false);
  }

  Future<void> _submitForm() async {
    final name = _nameCtl.text.trim(), phone = _phoneCtl.text.trim();
    if (name.length < 2) return setState(() => _formError = 'Please enter your name.');
    if (!_phoneRe.hasMatch(phone)) return setState(() => _formError = 'Please enter a valid mobile number.');
    setState(() { _formError = null; _busy = true; });
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString(_visitorKey, '$name\u0001$phone');
      final TeamzoLiveState st;
      if (_confirmMode) {
        st = await _client.liveConfirm(name: name, phone: phone, after: _lastId);
      } else {
        st = await _client.liveStart(name: name, phone: phone);
        if (_client.sessionToken != null) await prefs.setString(_tokenKey, _client.sessionToken!);
        _msgs.add(_Msg(false, 'Thanks $name! Waiting for your assistance — a staff member will join shortly.'));
        _controls = [const TeamzoControl('editlive', '✏️ Edit my details')];
      }
      _showForm = false;
      _applyLive(st);
      _startPolling();
    } on TeamzoException catch (e) {
      _formError = e.message;
    } catch (_) {
      _formError = 'Could not reach the server.';
    } finally {
      if (mounted) setState(() => _busy = false);
      _toBottom();
    }
  }

  // ── live state ─────────────────────────────────────────────────────────

  void _applyLive(TeamzoLiveState st) {
    final prev = _liveStatus;
    setState(() {
      for (final m in st.messages) {
        if (m.id > _lastId) _lastId = m.id;
        _msgs.add(_Msg(false, m.text,
            system: m.role == 'system', type: m.type, url: m.url, fileName: m.fileName, replyText: m.replyToText, id: m.id));
      }
      _liveStatus = st.status;
    });
    if (st.status == 'missed' && prev != 'missed' && !st.callbackConfirmed) {
      _msgs.add(_Msg(false, 'Our staff will connect with you. Please check and confirm your name and mobile number:'));
      _nameCtl.text = st.name ?? _nameCtl.text;
      _phoneCtl.text = st.phone ?? _phoneCtl.text;
      _openForm(confirm: true);
    }
    if (st.status == 'live' && prev != 'live') setState(() => _controls = []);
    if (st.status == 'ended') {
      _poller?.cancel();
      SharedPreferences.getInstance().then((p) => p.remove(_tokenKey));
      _client.sessionToken = null;
      setState(() { _liveStatus = null; _controls = []; _options = ['Start new chat']; });
    }
    _toBottom();
  }

  void _startPolling() {
    _poller?.cancel();
    _poller = Timer.periodic(const Duration(seconds: 3), (_) async {
      try {
        final st = await _client.livePoll(after: _lastId);
        if (mounted) _applyLive(st);
      } catch (_) {}
    });
  }

  // ── sending ────────────────────────────────────────────────────────────

  Future<void> _reset() async {
    if (_busy || _cfg == null) return;
    _poller?.cancel();
    await _client.reset();
    (await SharedPreferences.getInstance()).remove(_tokenKey);
    if (mounted) _greet();
  }

  Future<void> _send({String? text, String? action, String? echo}) async {
    final msg = (text ?? '').trim();
    if ((msg.isEmpty && action == null) || _busy) return;
    if (action == 'live') {
      return _beginLive();
    }
    if (action == 'editlive') {
      return _openForm(confirm: false);
    }
    _input.clear();
    final quoted = _liveOn ? _replyTo : null;
    setState(() {
      _msgs.add(_Msg(true, action != null ? (echo ?? action) : msg, replyText: quoted?.text));
      _options = [];
      _controls = [];
      _busy = true;
      _replyTo = null;
      _emojiOpen = false;
    });
    _toBottom();
    try {
      final r = await _client.send(message: action != null ? null : msg, action: action, replyToId: quoted?.id);
      if (!mounted) return;
      if (r.liveStatus != null) return; // went to staff; their answer arrives via the poll
      setState(() {
        if (r.text != null) _msgs.add(_Msg(false, r.text!));
        _options = r.closed ? ['Start new chat'] : r.options;
        _controls = r.closed ? [] : r.controls;
        if (!r.closed && _cfg!.liveChat && r.controls.isNotEmpty) _controls = [...r.controls, const TeamzoControl('live', '💬 Chat with a person')];
      });
    } catch (_) {
      if (mounted) setState(() => _msgs.add(_Msg(false, 'Sorry, something went wrong. Please try again.')));
    } finally {
      if (mounted) setState(() => _busy = false);
      _toBottom();
    }
  }

  Future<void> _attach() async {
    if (!_liveOn) return;
    final choice = await showModalBottomSheet<String>(
      context: context,
      builder: (_) => SafeArea(
        child: Wrap(children: [
          ListTile(leading: const Icon(Icons.photo_camera_rounded), title: const Text('Camera'), onTap: () => Navigator.pop(context, 'camera')),
          ListTile(leading: const Icon(Icons.image_rounded), title: const Text('Photo'), onTap: () => Navigator.pop(context, 'gallery')),
          ListTile(leading: const Icon(Icons.attach_file_rounded), title: const Text('File (max 2 MB)'), onTap: () => Navigator.pop(context, 'file')),
        ]),
      ),
    );
    if (choice == null) return;

    String? path, name;
    if (choice == 'file') {
      final r = await FilePicker.platform.pickFiles();
      path = r?.files.single.path;
      name = r?.files.single.name;
    } else {
      final x = await ImagePicker().pickImage(
          source: choice == 'camera' ? ImageSource.camera : ImageSource.gallery, maxWidth: 1600, imageQuality: 80);
      path = x?.path;
      name = x?.name;
    }
    if (path == null) return;

    final quoted = _replyTo;
    try {
      await _client.liveUpload(File(path), fileName: name, replyToId: quoted?.id);
      setState(() {
        _msgs.add(_Msg(true, '', type: choice == 'file' ? 'file' : 'image', url: path, fileName: name, replyText: quoted?.text));
        _replyTo = null;
      });
      _toBottom();
    } on TeamzoException catch (e) {
      if (mounted) setState(() => _msgs.add(_Msg(false, e.message, system: true)));
    } catch (_) {
      if (mounted) setState(() => _msgs.add(_Msg(false, 'Could not send the file.', system: true)));
    }
  }

  void _toBottom() => WidgetsBinding.instance.addPostFrameCallback((_) {
        if (_scroll.hasClients) {
          _scroll.animateTo(_scroll.position.maxScrollExtent + 120,
              duration: const Duration(milliseconds: 180), curve: Curves.easeOut);
        }
      });

  // ── UI ─────────────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    if (_error != null) return Center(child: Text(_error!));
    if (_cfg == null) return const Center(child: CircularProgressIndicator());
    final color = _color;

    return Column(children: [
      Material(
        color: color,
        child: SafeArea(
          bottom: false,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
            child: Row(children: [
              Expanded(child: Text(_cfg!.agentName, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700, fontSize: 15))),
              IconButton(tooltip: 'Reset conversation', onPressed: _reset, icon: const Icon(Icons.refresh, color: Colors.white)),
            ]),
          ),
        ),
      ),
      Expanded(
        child: Container(
          color: const Color(0xFFF6F7F9),
          child: ListView(
            controller: _scroll,
            padding: const EdgeInsets.all(14),
            children: [
              for (final m in _msgs) _bubble(m, color),
              if (_busy) const Padding(padding: EdgeInsets.all(6), child: Text('typing…', style: TextStyle(color: Colors.grey))),
              if (_savedCard != null) _savedCardView(color),
              if (_showForm) _form(color),
              if (_options.isNotEmpty)
                Wrap(spacing: 6, runSpacing: 6, children: [
                  for (final o in _options)
                    ActionChip(
                      label: Text(o, style: TextStyle(color: color)),
                      side: BorderSide(color: color),
                      backgroundColor: Colors.white,
                      onPressed: () => _send(text: o),
                    ),
                ]),
              if (_controls.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Wrap(spacing: 6, runSpacing: 6, children: [
                    for (final c in _controls)
                      ActionChip(
                        label: Text(c.label, style: const TextStyle(fontSize: 12, color: Colors.black54)),
                        backgroundColor: Colors.transparent,
                        onPressed: () => c.action == 'reset' ? _reset() : _send(action: c.action, echo: c.label),
                      ),
                  ]),
                ),
            ],
          ),
        ),
      ),
      if (_replyTo != null)
        Container(
          color: Colors.grey.shade200,
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
          child: Row(children: [
            Expanded(child: Text('↩ ${_replyTo!.text.isNotEmpty ? _replyTo!.text : (_replyTo!.type == 'image' ? '📷 Photo' : '📎 File')}', maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 12))),
            InkWell(onTap: () => setState(() => _replyTo = null), child: const Icon(Icons.close, size: 18)),
          ]),
        ),
      if (_emojiOpen && _liveOn)
        SizedBox(
          height: 150,
          child: GridView.count(
            crossAxisCount: 8,
            children: [for (final e in _emojis) InkWell(onTap: () => _input.text += e, child: Center(child: Text(e, style: const TextStyle(fontSize: 24))))],
          ),
        ),
      Material(
        color: Colors.white,
        child: SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.all(10),
            child: Row(children: [
              if (_liveOn) ...[
                IconButton(onPressed: () => setState(() => _emojiOpen = !_emojiOpen), icon: const Icon(Icons.emoji_emotions_outlined)),
                IconButton(onPressed: _attach, icon: const Icon(Icons.attach_file_rounded)),
              ],
              Expanded(
                child: TextField(
                  controller: _input,
                  textInputAction: TextInputAction.send,
                  onSubmitted: (v) => _send(text: v),
                  decoration: InputDecoration(
                    hintText: 'Type a message…',
                    isDense: true,
                    contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                    border: OutlineInputBorder(borderRadius: BorderRadius.circular(999)),
                  ),
                ),
              ),
              const SizedBox(width: 8),
              IconButton.filled(
                style: IconButton.styleFrom(backgroundColor: color),
                onPressed: _busy ? null : () => _send(text: _input.text),
                icon: const Icon(Icons.send, size: 18),
              ),
            ]),
          ),
        ),
      ),
    ]);
  }

  Widget _savedCardView(Color color) => Container(
        margin: const EdgeInsets.only(top: 8),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12), border: Border.all(color: const Color(0xFFE5E7EB))),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(_savedCard!.name, style: const TextStyle(fontWeight: FontWeight.w700)),
          Text(_savedCard!.phone),
          if (_formError != null) Padding(padding: const EdgeInsets.only(top: 6), child: Text(_formError!, style: const TextStyle(color: Colors.red, fontSize: 12))),
          const SizedBox(height: 10),
          FilledButton(style: FilledButton.styleFrom(backgroundColor: color), onPressed: _busy ? null : _continueWithSaved, child: const Text('Continue')),
          TextButton(onPressed: _editSaved, child: const Text('✏️ Edit details')),
        ]),
      );

  Widget _form(Color color) => Container(
        margin: const EdgeInsets.only(top: 8),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12), border: Border.all(color: const Color(0xFFE5E7EB))),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          TextField(controller: _nameCtl, textCapitalization: TextCapitalization.words, decoration: const InputDecoration(labelText: 'Your name', isDense: true)),
          const SizedBox(height: 8),
          TextField(controller: _phoneCtl, keyboardType: TextInputType.phone, decoration: const InputDecoration(labelText: 'Mobile number', isDense: true)),
          if (_formError != null) Padding(padding: const EdgeInsets.only(top: 6), child: Text(_formError!, style: const TextStyle(color: Colors.red, fontSize: 12))),
          const SizedBox(height: 10),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: color),
            onPressed: _busy ? null : _submitForm,
            child: Text(_confirmMode ? 'Confirm' : 'Start chat'),
          ),
        ]),
      );

  Widget _bubble(_Msg m, Color color) {
    if (m.system) {
      return Padding(padding: const EdgeInsets.symmetric(vertical: 4), child: Center(child: Text(m.text, textAlign: TextAlign.center, style: const TextStyle(fontSize: 12, color: Colors.grey))));
    }
    final fg = m.visitor ? Colors.white : const Color(0xFF1F2937);
    final staff = !m.visitor && m.id != null;
    return Align(
      alignment: m.visitor ? Alignment.centerRight : Alignment.centerLeft,
      child: GestureDetector(
        onLongPress: staff && _liveOn ? () => setState(() => _replyTo = m) : null, // long-press a staff message to quote-reply
        child: Container(
          margin: const EdgeInsets.only(bottom: 8),
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
          constraints: BoxConstraints(maxWidth: MediaQuery.of(context).size.width * 0.78),
          decoration: BoxDecoration(
            color: m.visitor ? color : (staff ? const Color(0xFFEEF2FF) : Colors.white),
            borderRadius: BorderRadius.circular(14),
            border: m.visitor ? null : Border.all(color: const Color(0xFFE5E7EB)),
          ),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
            if (m.replyText != null && m.replyText!.isNotEmpty)
              Container(
                margin: const EdgeInsets.only(bottom: 4),
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(border: Border(left: BorderSide(color: fg.withValues(alpha: .6), width: 3)), color: Colors.black12),
                child: Text(m.replyText!, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 12, color: fg)),
              ),
            if (m.type == 'image' && m.url != null)
              GestureDetector(
                onTap: () => m.url!.startsWith('http') ? launchUrl(Uri.parse(m.url!), mode: LaunchMode.externalApplication) : null,
                child: ClipRRect(
                  borderRadius: BorderRadius.circular(8),
                  child: m.url!.startsWith('http') ? Image.network(m.url!, width: 220, fit: BoxFit.cover) : Image.file(File(m.url!), width: 220, fit: BoxFit.cover),
                ),
              ),
            if (m.type == 'audio' && m.url != null)
              InkWell(
                onTap: () => launchUrl(Uri.parse(m.url!), mode: LaunchMode.externalApplication), // staff voice note — opens in the system player
                child: Row(mainAxisSize: MainAxisSize.min, children: [Icon(Icons.play_circle_fill_rounded, color: fg), const SizedBox(width: 6), Text('Voice message', style: TextStyle(color: fg))]),
              ),
            if (m.type == 'file')
              InkWell(
                onTap: () => m.url != null && m.url!.startsWith('http') ? launchUrl(Uri.parse(m.url!), mode: LaunchMode.externalApplication) : null,
                child: Text('📎 ${m.fileName ?? 'File'}', style: TextStyle(color: fg, decoration: TextDecoration.underline)),
              ),
            if (m.text.isNotEmpty) Text(m.text, style: TextStyle(color: fg, fontSize: 14)),
          ]),
        ),
      ),
    );
  }
}
