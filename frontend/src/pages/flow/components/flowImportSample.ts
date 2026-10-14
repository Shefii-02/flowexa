// Sample flow JSON + AI prompt shown in the Import modal. The shape mirrors what
// FlowImportExportController@import validates, so a file built from either imports cleanly.

export const SAMPLE_FLOW = {
  _version: '1.0',
  builder: {
    name: 'Sample Welcome Flow',
    description: 'Greets the customer and offers a small menu',
    trigger_type: 'default', // default | keyword | season
    trigger_keywords: [],    // e.g. ["hi", "hello"] when trigger_type is "keyword"
    active_from: null,
    active_until: null,
  },
  nodes: [
    {
      _ref: 'welcome', parent_ref: null, reply_id: 'welcome', type: 'button', sort_order: 1,
      title: 'Welcome',
      message: 'Hi! 👋 Welcome to our store. How can we help you today?',
    },
    {
      _ref: 'products', parent_ref: 'welcome', reply_id: 'products', type: 'button', sort_order: 1,
      title: 'Our Products', lead_category: 'interested',
      message: 'We have three product lines. Which one are you looking for?',
    },
    {
      _ref: 'support', parent_ref: 'welcome', reply_id: 'support', type: 'text', sort_order: 2,
      title: 'Talk to Support', lead_category: 'support', is_dead_end: true,
      message: 'Our support team will contact you shortly. Thank you! 🙏',
    },
    {
      _ref: 'prod_a', parent_ref: 'products', reply_id: 'prod_a', type: 'text', sort_order: 1,
      title: 'Starter Plan', lead_category: 'hot', is_dead_end: true,
      message: 'Starter Plan — ₹499/month. Reply here and our team will set it up for you.',
    },
  ],
}

export const downloadSampleFlow = () => {
  const blob = new Blob([JSON.stringify(SAMPLE_FLOW, null, 2)], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = 'sample-flow.json'
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

export const AI_PROMPT = `You are a WhatsApp chatbot flow designer. Build a flow for the business described at the bottom and reply with ONE valid JSON object only — no markdown fences, no explanation.

JSON shape:
{
  "builder": {
    "name": "string, max 100 chars",
    "description": "string",
    "trigger_type": "default | keyword | season",
    "trigger_keywords": ["only used when trigger_type is keyword"],
    "active_from": null,
    "active_until": null
  },
  "nodes": [ ...node objects... ]
}

Each node object:
- "_ref": unique string id used only to link parent and child inside this file
- "parent_ref": the "_ref" of the parent node, or null for the first (root) message
- "reply_id": unique string, max 200 chars, lowercase letters/numbers/underscores
- "title": REQUIRED, max 24 characters (it becomes the button / list-row label)
- "message": the text the bot sends
- "type": one of text, button, list, image, video, document, audio, location
- "sort_order": integer, order among siblings starting at 1
- "lead_category": optional string, e.g. "hot", "interested", "support"
- "is_dead_end": true on the last message of a branch, otherwise omit
- for media types add "media_url" and optional "media_caption"
- for location add "location_lat", "location_lng", "location_name", "location_address"

Rules:
1. Exactly one root node (parent_ref null) greets the customer.
2. A node's children are the choices the customer can pick after that message. Use type "button" for up to 3 choices and "list" for 4–10 choices; the parent node's type must be button or list when it has children.
3. Every "_ref" and every "reply_id" must be unique in the file. Every "parent_ref" must match an existing "_ref".
4. Keep messages short and friendly, with at most 1024 characters each.
5. Do not invent media URLs; use text nodes unless the business supplies a link.

Business details:
<describe the business, its services, prices, FAQs and what the flow should do here>`
