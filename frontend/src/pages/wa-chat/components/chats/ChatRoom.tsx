import type { Dispatch, RefObject, SetStateAction } from 'react';
import { useTranslation } from 'react-i18next';
import { ArrowLeft } from 'lucide-react';
import type { Chat } from '../../api/api';
import type { ChatMessageView } from '../../utils/chatMessages';
import type { ScrollDirection } from '../../utils/scrollDecision';
import ChatThread from './ChatThread';
import ChatComposer, { type StagedAttachment } from './ChatComposer';
import KindIcon from './KindIcon';
import ContactInfoPanel from './ContactInfoPanel';
import GroupInfoPanel from './GroupInfoPanel';

interface ChatRoomProps {
  sessionId: string;
  activeChat: Chat;
  onBack: () => void;

  activePp?: string;
  onAvatarError: () => void;
  activePhoneText?: string | null;

  messages: ChatMessageView[];
  loadingMessages: boolean;
  messagesError: boolean;
  messagesContainerRef: RefObject<HTMLDivElement>;
  onMediaLoad: () => void;
  onOpenImage: (messageId: string) => void;
  replyingTo: ChatMessageView | null;
  setReplyingTo: Dispatch<SetStateAction<ChatMessageView | null>>;
  onReact: (message: ChatMessageView, emoji: string) => void;
  onDelete: (message: ChatMessageView) => void;

  setChats: Dispatch<SetStateAction<Chat[]>>;
  onMessageAppended: (direction: ScrollDirection) => void;
  messageInput: string;
  setMessageInput: Dispatch<SetStateAction<string>>;
  attachment: StagedAttachment | null;
  setAttachment: Dispatch<SetStateAction<StagedAttachment | null>>;
  previewUrl: string | null;
  setPreviewUrl: Dispatch<SetStateAction<string | null>>;

  // Profile/group info side panel — toggled by clicking the header avatar/name. Which component
  // renders is decided here from activeChat.isGroup, the argument that picks Contact vs Group info.
  showProfileCard: boolean;
  onToggleProfileCard: () => void;
  onCloseProfileCard: () => void;
  profileContact: any;
  profileGroups: { id: string; name: string }[];
  profileCardLoading: boolean;
  profileGroupsLoading: boolean;
  onRequestGroupsScan: () => void;
  onOpenChatWithParticipant: (participant: { id: string; number: string; name?: string }) => void;
  onContactUpdated: (contact: any) => void;
}

// The single active-chat screen: room header, message thread, composer, and (optionally) the
// contact/group info side panel. Everything else (session/chat lists, channels, status viewer)
// lives in the Chats page; this owns only the "one open conversation" view.
function ChatRoom({
  sessionId,
  activeChat,
  onBack,
  activePp,
  onAvatarError,
  activePhoneText,
  messages,
  loadingMessages,
  messagesError,
  messagesContainerRef,
  onMediaLoad,
  onOpenImage,
  replyingTo,
  setReplyingTo,
  onReact,
  onDelete,
  setChats,
  onMessageAppended,
  messageInput,
  setMessageInput,
  attachment,
  setAttachment,
  previewUrl,
  setPreviewUrl,
  showProfileCard,
  onToggleProfileCard,
  onCloseProfileCard,
  profileContact,
  profileGroups,
  profileCardLoading,
  profileGroupsLoading,
  onRequestGroupsScan,
  onOpenChatWithParticipant,
  onContactUpdated,
}: ChatRoomProps) {
  const { t } = useTranslation();

  return (
    <div className="room-container" style={showProfileCard ? { flexDirection: 'row' } : undefined}>
      {/* Main chat column */}
      <div style={{ flex: 1, display: 'flex', flexDirection: 'column', minWidth: 0, overflow: 'hidden' }}>
        {/* Room header */}
        <header className="room-header">
          <button className="room-back" onClick={onBack} aria-label={t('common.back')}>
            <ArrowLeft size={20} />
          </button>
          {/* Clickable avatar + contact info opens the profile card panel */}
          <div
            className="room-avatar"
            style={{ cursor: 'pointer' }}
            onClick={onToggleProfileCard}
            title="View contact profile"
          >
            {activePp ? (
              <img src={activePp} alt="" onError={onAvatarError} />
            ) : (
              <KindIcon kind={activeChat.kind} />
            )}
          </div>
          <div
            className="room-contact-info"
            style={{ cursor: 'pointer', flex: 1 }}
            onClick={onToggleProfileCard}
            title="View contact profile"
          >
            <h3>{activeChat.name || activeChat.id.split('@')[0]}</h3>
            <span className="room-contact-phone">
              {activePhoneText ?? (activeChat.isGroup ? t('chats.groupSubtitle') : t('chats.privateContactSubtitle'))}
            </span>
            <span className="room-contact-jid" title={activeChat.id}>
              {activeChat.id}
            </span>
          </div>
        </header>

        {/* Messages body */}
        <ChatThread
          sessionId={sessionId}
          activeChat={activeChat}
          messages={messages}
          loadingMessages={loadingMessages}
          messagesError={messagesError}
          messagesContainerRef={messagesContainerRef}
          onMediaLoad={onMediaLoad}
          onOpenImage={onOpenImage}
          onReply={setReplyingTo}
          onReact={onReact}
          onDelete={onDelete}
        />

        {/* Composer */}
        <ChatComposer
          selectedSessionId={sessionId}
          activeChat={activeChat}
          replyingTo={replyingTo}
          setReplyingTo={setReplyingTo}
          onMessageAppended={onMessageAppended}
          setChats={setChats}
          messageInput={messageInput}
          setMessageInput={setMessageInput}
          attachment={attachment}
          setAttachment={setAttachment}
          previewUrl={previewUrl}
          setPreviewUrl={setPreviewUrl}
        />
      </div>

      {/* Profile card panel — opens when avatar/name is clicked. Group vs individual chat (the
          argument) decides which panel component renders. */}
      {showProfileCard && (
        activeChat.isGroup ? (
          <GroupInfoPanel
            activeChat={activeChat}
            activePp={activePp}
            activePhoneText={activePhoneText}
            sessionId={sessionId}
            onClose={onCloseProfileCard}
            onOpenChat={onOpenChatWithParticipant}
          />
        ) : (
          <ContactInfoPanel
            activeChat={activeChat}
            activePp={activePp}
            activePhoneText={activePhoneText}
            profileContact={profileContact}
            profileGroups={profileGroups}
            profileCardLoading={profileCardLoading}
            profileGroupsLoading={profileGroupsLoading}
            onClose={onCloseProfileCard}
            onRequestGroupsScan={onRequestGroupsScan}
            onContactUpdated={onContactUpdated}
          />
        )
      )}
    </div>
  );
}

export default ChatRoom;
