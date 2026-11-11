// "How do I use this?" drawer for the flow canvas: creating a flow, building nodes step by step,
// what every option does, and the mistakes that bite most often.
import { useState, type ReactNode } from 'react'

type TabKey = 'start' | 'build' | 'options' | 'tips'
const TABS: { key: TabKey; label: string }[] = [
  { key: 'start', label: 'Create a flow' },
  { key: 'build', label: 'Build nodes' },
  { key: 'options', label: 'Every option' },
  { key: 'tips', label: 'Tips' },
]

const H = ({ children }: { children: ReactNode }) => <h3 className="mb-1.5 mt-4 text-sm font-bold text-gray-900 first:mt-0">{children}</h3>
const P = ({ children }: { children: ReactNode }) => <p className="text-[13px] leading-relaxed text-gray-600">{children}</p>
const Steps = ({ items }: { items: ReactNode[] }) => (
  <ol className="space-y-2">
    {items.map((t, i) => (
      <li key={i} className="flex gap-2.5 text-[13px] leading-relaxed text-gray-600">
        <span className="mt-0.5 flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-full bg-brand-100 text-[11px] font-bold text-brand-700">{i + 1}</span>
        <span>{t}</span>
      </li>
    ))}
  </ol>
)
const Tip = ({ children, tone = 'blue' }: { children: ReactNode; tone?: 'blue' | 'amber' | 'red' }) => {
  const c = { blue: 'border-blue-200 bg-blue-50 text-blue-800', amber: 'border-amber-200 bg-amber-50 text-amber-800', red: 'border-red-200 bg-red-50 text-red-800' }[tone]
  return <div className={`mt-2 rounded-lg border px-3 py-2 text-xs leading-relaxed ${c}`}>{children}</div>
}
const Row = ({ name, children }: { name: string; children: ReactNode }) => (
  <div className="border-b border-gray-100 py-2.5 last:border-0">
    <p className="text-[13px] font-semibold text-gray-900">{name}</p>
    <div className="mt-0.5 text-[13px] leading-relaxed text-gray-600">{children}</div>
  </div>
)

function Start() {
  return (<>
    <H>1. Create the flow</H>
    <Steps items={[
      <>Go to <b>WA Cloud → Flow Builders</b> and click <b>New flow</b>.</>,
      <>Give it a <b>name</b> (e.g. “Main menu”) and a short description.</>,
      <>Pick when it runs: <b>Default</b> (your normal welcome flow), <b>Keyword</b> (only when the customer types one of your keywords) or <b>Season</b> (only between a start and end date).</>,
      <>Save, then open it with <b>🎨 Drag &amp; drop canvas</b> (this screen) or the classic editor. Both edit the same nodes.</>,
      <>When it's ready, <b>Activate</b> it on the Flow Builders list. A flow that isn't active never answers customers.</>,
    ]} />
    <Tip>Not sure how a new flow will behave? Create it as a <b>Keyword</b> flow first and test it by typing the keyword, before you make it the Default.</Tip>

    <H>2. How a flow works</H>
    <P>A flow is a tree of messages. The <b>top node</b> is what the customer sees first (usually a welcome menu). Each <b>option</b> under it is another node that is sent when the customer taps that option. Lines on the canvas show which node leads to which.</P>
    <div className="mt-2 rounded-lg bg-gray-50 p-3 font-mono text-[11px] leading-5 text-gray-600">
      Welcome (List)<br />
      ├─ Courses (List)<br />
      │&nbsp;&nbsp;├─ Python (Text)<br />
      │&nbsp;&nbsp;└─ Java (Text)<br />
      ├─ Fees (Text)<br />
      └─ Talk to us (Buttons)
    </div>
  </>)
}

function Build() {
  return (<>
    <H>Build it in 5 steps</H>
    <Steps items={[
      <>Drag a <b>List</b> from the left onto empty space. That is your welcome menu. Click it and write the message (e.g. “Hi! How can we help?”).</>,
      <>Drag another block <b>onto that List node</b>. It becomes an option under it. Do this once per menu choice. A List holds up to 10 options; Buttons hold up to 3.</>,
      <>Click each option and set its <b>Title</b> (what the customer taps) and <b>Message</b> (what the bot answers).</>,
      <>Need a sub-menu? Make that option a <b>List</b> or <b>Buttons</b> and drop its own options on it. Make it a <b>Text</b> if it is just an answer.</>,
      <>Add a way back: on an answer node, set <b>Jump to → WELCOME</b>, or drag a line from its bottom dot to the menu node.</>,
    ]} />

    <H>Which block should I use?</H>
    <Row name="📋 List">A menu of up to 10 choices (opens as a “View options” list). Best for categories, courses, products.</Row>
    <Row name="🔘 Buttons">2–3 quick tap buttons, e.g. Yes / No / Talk to us. It can also hold a link, call or copy-code button.</Row>
    <Row name="💬 Text">A plain answer. Can include images, videos, documents, audio or a location (turn on “Send multiple messages”).</Row>
    <Row name="📝 Survey">Asks the customer a form's questions one by one and saves the answers. Choose the form in the panel.</Row>
    <Row name="🧠 AI / KB">Hands the chat to your AI agent, which answers from your knowledge base. Choose an agent and documents in the panel.</Row>

    <H>Canvas controls</H>
    <Row name="Drag from the left">Adds a node. Drop on a List/Buttons node = an option under it. Drop on empty space = a new top-level node.</Row>
    <Row name="Drag a node">Moves it. Positions are saved automatically; this never changes how the bot behaves.</Row>
    <Row name="Bottom dot → another node">Makes a <b>jump</b> (dashed line). When the customer reaches this node, the bot goes on to the target. Click the dashed line to remove it.</Row>
    <Row name="Grey lines">The tree structure (option → parent). Delete a node to remove its line.</Row>
    <Row name="Auto layout">Tidies the whole map left to right.</Row>
    <Row name="Scroll / pinch, drag background">Zoom and pan. The mini-map in the corner helps on big flows.</Row>
  </>)
}

function Options() {
  return (<>
    <P>Click any node to open its settings. What each field does:</P>
    <H>Basics</H>
    <Row name="Title (max 24)">The label on the option the customer taps. WhatsApp cuts it at 24 characters (20 on a button).</Row>
    <Row name="Reply ID">The node's unique internal name (letters, numbers, underscores). It is generated for you; change it only if you reference it elsewhere. A ✅ means it is free, ❌ means it is already used.</Row>
    <Row name="Type">List, Buttons, Text, Survey or AI / KB. Changing the type keeps the title and message.</Row>
    <Row name="Message">What the bot sends when the customer reaches this node. Required unless you use multiple messages, a survey or AI.</Row>
    <Row name="Description in the list">Only for options under a List: a small grey line (up to 72 characters) under the title.</Row>

    <H>List and Buttons nodes</H>
    <Row name="Header">Optional line or media above the message. Lists allow a text header only; Buttons also allow image, video or document.</Row>
    <Row name="Footer">Small grey text at the bottom (max 60 characters).</Row>
    <Row name="List button label">The text on the button that opens a list, e.g. “View options” (max 20).</Row>
    <Row name="Options message">If filled, the main message goes first and the list/buttons arrive as a second message with this text, e.g. “Please choose 👇”. Empty = one message.</Row>
    <Row name="“Type M for Main Menu · E to Exit”">Adds that hint under the options. Customers can then type M or E.</Row>
    <Row name="Options (rows / buttons)">Add the choices here. Each one becomes a child node. Button types: <b>Reply</b> (opens a node), <b>Link</b>, <b>Call</b> and <b>Copy code</b> (no child; these count toward the 3-button limit).</Row>

    <H>Sending more than text</H>
    <Row name="Send multiple messages">Turns on message blocks: Text, Image, Video, Document, Audio, Location. They are sent one after another, in order (use ↑ ↓ to reorder). On a List/Buttons node the <b>last text block</b> is the message above the options.</Row>
    <Row name="Upload or URL">Upload a file (images 5 MB, video/audio 16 MB, documents 100 MB) or paste a public link.</Row>

    <H>Routing</H>
    <Row name="Jump to">When the customer reaches this node the bot continues at another node. <b>WELCOME</b> restarts the main menu. Use it for “Back”, “Main menu” and “Start again”.</Row>
    <Row name="Lead category">Tags the customer as a lead in this category when they reach the node (created automatically if new). Great for “Pricing” or “Demo request”.</Row>
    <Row name="Sort order">Position among its siblings. Lower number = shown first.</Row>
    <Row name="Active">Off = the bot skips this node. Use it to hide an option without deleting it.</Row>
    <Row name="🔚 Terminal node">Tick this on a List/Buttons node that is <b>meant</b> to end the conversation. Without it, a node with no options is a “dead end” and the bot sends the welcome menu again so the customer is not stuck. With it, the bot asks “Was your issue resolved?” instead. Text, Survey and AI nodes never need it.</Row>

    <H>Buttons at the bottom</H>
    <Row name="Save">Saves this node and its options. Nothing is saved until you click it.</Row>
    <Row name="Preview">Shows roughly how the node looks in WhatsApp.</Row>
    <Row name="Delete">Removes the node <b>and everything under it</b>. You are asked to confirm.</Row>
  </>)
}

function Tips() {
  return (<>
    <H>Keep it simple</H>
    <Steps items={[
      <>Aim for <b>3 levels or fewer</b>: menu → sub-menu → answer. Deeper flows lose people.</>,
      <>Keep each menu to <b>4–6 options</b>. Use <b>Other / Talk to us</b> as the last one.</>,
      <>Write short messages, one idea each. Use emojis sparingly.</>,
      <>Start from a sketch: first make the top menu and answers, then polish headers, media and jumps.</>,
      <>Add a <b>Back / Main menu</b> jump on every answer so nobody is stranded.</>,
      <>Use the <b>Preview</b> button after each change.</>,
    ]} />

    <H>Common mistakes</H>
    <Tip tone="red"><b>Dead end warning:</b> a List/Buttons node with no options. Add options, or tick <b>Terminal node</b> if it is intentionally the end.</Tip>
    <Tip tone="amber"><b>“Drop on a List or Buttons node”:</b> only Lists and Buttons can have options. Change the parent's type if you need to add under it.</Tip>
    <Tip tone="amber"><b>Limit reached:</b> a List has 10 options max; Buttons 3 in total (reply buttons + link/call/copy-code).</Tip>
    <Tip tone="amber"><b>Reply ID already used:</b> every node in a flow needs a different Reply ID. Change the last word.</Tip>
    <Tip tone="amber"><b>Flow doesn't answer:</b> check it is <b>Activated</b> on the Flow Builders list and that its trigger (Default / Keyword / Season) matches.</Tip>
    <Tip tone="amber"><b>Changed something and don't see it:</b> click <b>Save</b> in the node panel. Moving nodes saves by itself, text edits do not.</Tip>

    <H>Classic editor</H>
    <P>You can switch at any time with <b>Classic editor</b> at the top. It shows the same flow as a list, and also has duplicate, bulk actions, search and the full test chat. Edits in either place appear in both.</P>
  </>)
}

export default function FlowCanvasHelpDrawer({ open, onClose }: { open: boolean; onClose: () => void }) {
  const [tab, setTab] = useState<TabKey>('start')
  if (!open) return null
  return (
    <>
      <div className="absolute inset-0 z-30 bg-black/20" onClick={onClose} />
      <aside className="absolute bottom-0 right-0 top-0 z-40 flex w-[440px] max-w-full flex-col border-l border-gray-200 bg-white shadow-2xl">
        <div className="flex items-center justify-between border-b border-gray-100 px-4 py-3">
          <div>
            <h2 className="font-bold text-gray-900">How to use the canvas</h2>
            <p className="text-xs text-gray-400">Create a flow and build nodes by drag &amp; drop</p>
          </div>
          <button onClick={onClose} aria-label="Close help" className="flex h-8 w-8 items-center justify-center rounded-full text-xl text-gray-400 hover:bg-gray-100">×</button>
        </div>
        <div className="flex gap-1 border-b border-gray-100 px-3 pt-2">
          {TABS.map(t => (
            <button key={t.key} onClick={() => setTab(t.key)}
              className={`rounded-t-lg px-3 py-2 text-xs font-medium ${tab === t.key ? 'border-b-2 border-brand-500 text-brand-700' : 'text-gray-500 hover:text-gray-700'}`}>{t.label}</button>
          ))}
        </div>
        <div className="min-h-0 flex-1 overflow-y-auto p-4">
          {tab === 'start' && <Start />}
          {tab === 'build' && <Build />}
          {tab === 'options' && <Options />}
          {tab === 'tips' && <Tips />}
        </div>
      </aside>
    </>
  )
}
