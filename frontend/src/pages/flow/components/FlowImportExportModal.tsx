// src/pages/flow/components/FlowImportExportModal.tsx
// Import/Export modal — used from FlowBuildersPage listing
// Props:
//   mode: 'import' | 'export'
//   builderId?: number  (required for export)
//   builderName?: string
//   onClose: () => void
//   onImported: () => void  (called after successful import)

import { useState, useRef } from 'react'
import { flowBuilderApi } from '@/api'
import { Modal, Button } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { AI_PROMPT, downloadSampleFlow } from './flowImportSample'

// ─── API methods to add to flowBuilderApi ──────────────────────────────────
// export const flowBuilderApi = {
//   ...existing,
//   export: (id: number) =>
//     api.get(`/flow-builders/${id}/export`, { responseType: 'blob' }),
//
//   import: (file: File, activate = false) => {
//     const fd = new FormData()
//     fd.append('file', file)
//     if (activate) fd.append('activate', '1')
//     return api.post('/flow-builders/import', fd, {
//       headers: { 'Content-Type': 'multipart/form-data' },
//     })
//   },
// }

// ─── Client-side check of a flow JSON — same rules the server enforces on import ───
const NODE_TYPES = ['text', 'button', 'list', 'image', 'video', 'document', 'audio', 'location']
function verifyFlowJson(text: string): { ok: boolean; errors: string[]; summary?: { name: string; trigger: string; nodes: number; roots: number } } {
  let json: any
  try { json = JSON.parse(text) }
  catch (e: any) { return { ok: false, errors: ['Not valid JSON: ' + (e?.message || 'parse error')] } }
  const errors: string[] = []
  if (!json || typeof json !== 'object' || Array.isArray(json)) return { ok: false, errors: ['The JSON must be an object with "builder" and "nodes".'] }
  if (!json.builder || typeof json.builder !== 'object') errors.push('Missing "builder" object.')
  if (!Array.isArray(json.nodes) || json.nodes.length === 0) errors.push('"nodes" must be a non-empty array.')
  if (errors.length) return { ok: false, errors }

  const b = json.builder
  if (!b.name || typeof b.name !== 'string') errors.push('builder.name is required.')
  else if (b.name.length > 100) errors.push('builder.name is longer than 100 characters.')
  if (!['default', 'keyword', 'season'].includes(b.trigger_type)) errors.push('builder.trigger_type must be default, keyword or season.')
  if (b.trigger_type === 'keyword' && !(Array.isArray(b.trigger_keywords) && b.trigger_keywords.length)) errors.push('builder.trigger_keywords is needed when trigger_type is "keyword".')

  const refs = new Set<string>(); const replyIds = new Set<string>(); let roots = 0
  json.nodes.forEach((n: any, i: number) => {
    const at = `Node ${i + 1}${n?.title ? ` (“${n.title}”)` : ''}`
    if (!n || typeof n !== 'object') { errors.push(`${at}: must be an object.`); return }
    if (!n._ref) errors.push(`${at}: "_ref" is required.`)
    else if (refs.has(n._ref)) errors.push(`${at}: duplicate _ref “${n._ref}”.`)
    else refs.add(n._ref)
    if (!n.reply_id) errors.push(`${at}: "reply_id" is required.`)
    else if (replyIds.has(n.reply_id)) errors.push(`${at}: duplicate reply_id “${n.reply_id}”.`)
    else replyIds.add(n.reply_id)
    if (!n.title) errors.push(`${at}: "title" is required.`)
    if (!NODE_TYPES.includes(n.type)) errors.push(`${at}: type must be one of ${NODE_TYPES.join(', ')}.`)
    if (!n.parent_ref) roots++
  })
  json.nodes.forEach((n: any, i: number) => {
    if (n?.parent_ref && !refs.has(n.parent_ref)) errors.push(`Node ${i + 1} (“${n.title ?? ''}”): parent_ref “${n.parent_ref}” does not match any node's _ref.`)
  })
  if (roots === 0) errors.push('No root node: at least one node needs an empty parent_ref.')

  return { ok: errors.length === 0, errors, summary: { name: b.name, trigger: b.trigger_type, nodes: json.nodes.length, roots } }
}

interface Props {
  mode:          'import' | 'export'
  builderId?:    number
  builderName?:  string
  onClose:       () => void
  onImported:    () => void
}

export function FlowImportExportModal({
  mode, builderId, builderName, onClose, onImported,
}: Props) {
  const [file,       setFile]       = useState<File | null>(null)
  const [activate,   setActivate]   = useState(false)
  const [loading,    setLoading]    = useState(false)
  const [result,     setResult]     = useState<any>(null)
  const [dragOver,   setDragOver]   = useState(false)
  const inputRef = useRef<HTMLInputElement>(null)
  // Paste mode: the JSON is typed/pasted, verified on submit, then imported as if it were a file
  const [src,        setSrc]        = useState<'file' | 'paste'>('file')
  const [pasteText,  setPasteText]  = useState('')
  const [check,      setCheck]      = useState<ReturnType<typeof verifyFlowJson> | null>(null)

  // ── Export ──────────────────────────────────────────────────────────────
  const handleExport = async () => {
    if (!builderId) return
    setLoading(true)
    try {
      const response = await flowBuilderApi.export(builderId)
      // Trigger browser download
      const blob = new Blob([response.data], { type: 'application/json' })
      const url  = URL.createObjectURL(blob)
      const a    = document.createElement('a')
      a.href     = url
      // Try to get filename from Content-Disposition header
      const cd   = response.headers?.['content-disposition'] || ''
      const match = cd.match(/filename="(.+?)"/)
      a.download = match?.[1] || `flow-${builderName || builderId}.json`
      document.body.appendChild(a)
      a.click()
      document.body.removeChild(a)
      URL.revokeObjectURL(url)
      toast.success('Flow exported successfully.')
      onClose()
    } catch (e) {
      toast.error('Export failed: ' + getError(e))
    } finally {
      setLoading(false)
    }
  }

  // ── Import ──────────────────────────────────────────────────────────────
  const handleImport = async () => {
    let upload: File | null = file
    if (src === 'paste') {
      // Verify first; only a clean result goes to the server
      const v = verifyFlowJson(pasteText.trim())
      setCheck(v)
      if (!v.ok) { toast.error(`Fix ${v.errors.length} problem${v.errors.length !== 1 ? 's' : ''} in the JSON first`); return }
      upload = new File([pasteText.trim()], 'pasted-flow.json', { type: 'application/json' })
    }
    if (!upload) { toast.error('Select a JSON file first'); return }
    setLoading(true)
    setResult(null)
    try {
      const { data } = await flowBuilderApi.import(upload, activate)
      setResult(data.result)
      toast.success(data.message)
      onImported()
    } catch (e) {
      toast.error('Import failed: ' + getError(e))
    } finally {
      setLoading(false)
    }
  }

  const handleDrop = (e: React.DragEvent) => {
    e.preventDefault()
    setDragOver(false)
    const f = e.dataTransfer.files?.[0]
    if (f && f.name.endsWith('.json')) setFile(f)
    else toast.error('Please drop a .json file')
  }

  // ── Render export ───────────────────────────────────────────────────────
  if (mode === 'export') return (
    <Modal
      open
      onClose={onClose}
      title="Export flow builder"
      size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button onClick={handleExport} loading={loading}>
            ⬇️ Download JSON
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <div className="bg-gray-50 border border-gray-200 rounded-xl p-4 text-sm text-gray-600 space-y-2">
          <p className="font-semibold text-gray-800">📋 {builderName}</p>
          <p>This will download a <span className="font-mono text-xs bg-gray-200 px-1 rounded">.json</span> file containing:</p>
          <ul className="text-xs space-y-1 text-gray-500 list-disc list-inside">
            <li>Builder settings (name, trigger type, keywords, dates)</li>
            <li>All nodes with messages, reply IDs, lead categories</li>
            <li>Multi-message blocks and dynamic node config</li>
            <li>Full parent-child relationships</li>
          </ul>
        </div>

        <div className="bg-blue-50 border border-blue-200 rounded-lg p-3 text-xs text-blue-700">
          💡 You can import this file into any company on this platform — or keep it as a backup.
        </div>
      </div>
    </Modal>
  )

  // ── Render import ───────────────────────────────────────────────────────
  return (
    <Modal
      open
      onClose={onClose}
      title="Import flow builder"
      size="md"
      footer={
        result ? (
          <Button onClick={onClose}>Close</Button>
        ) : (
          <>
            <Button variant="secondary" onClick={onClose}>Cancel</Button>
            <Button onClick={handleImport} loading={loading} disabled={src === 'paste' ? !pasteText.trim() : !file}>
              ⬆️ Import
            </Button>
          </>
        )
      }
    >
      <div className="space-y-4">

        {/* Result panel — shown after import */}
        {result && (
          <div className={`rounded-xl border p-4 space-y-3 ${result.skipped > 0 ? 'bg-amber-50 border-amber-300' : 'bg-green-50 border-green-300'}`}>
            <p className={`text-base font-semibold ${result.skipped > 0 ? 'text-amber-700' : 'text-green-700'}`}>
              {result.skipped > 0 ? '⚠️ Import completed with warnings' : '✅ Import successful!'}
            </p>
            <div className="grid grid-cols-2 gap-3 text-sm">
              <div className="bg-white rounded-lg p-3 text-center border">
                <p className="text-2xl font-bold text-green-600">{result.created}</p>
                <p className="text-xs text-gray-500 mt-1">Nodes created</p>
              </div>
              <div className="bg-white rounded-lg p-3 text-center border">
                <p className={`text-2xl font-bold ${result.skipped > 0 ? 'text-amber-600' : 'text-gray-300'}`}>{result.skipped}</p>
                <p className="text-xs text-gray-500 mt-1">Nodes skipped</p>
              </div>
            </div>

            <div className="text-xs text-gray-600">
              <p><strong>Builder name:</strong> {result.name}</p>
              <p><strong>Builder ID:</strong> {result.builder_id}</p>
              <p><strong>Activated:</strong> {result.activated ? '✅ Yes' : '❌ No — activate from dashboard'}</p>
            </div>

            {result.errors?.length > 0 && (
              <div className="bg-white border border-amber-200 rounded-lg p-3">
                <p className="text-xs font-semibold text-amber-700 mb-2">Warnings:</p>
                <div className="space-y-1 max-h-32 overflow-y-auto">
                  {result.errors.map((e: string, i: number) => (
                    <p key={i} className="text-xs text-amber-600 font-mono">• {e}</p>
                  ))}
                </div>
              </div>
            )}
          </div>
        )}

        {/* File drop zone — hidden after result */}
        {!result && (
          <>
            <div className="flex gap-1 text-xs">
              {([['file', '📂 Upload file'], ['paste', '📋 Paste JSON']] as const).map(([m, l]) => (
                <button key={m} type="button" onClick={() => { setSrc(m); setCheck(null) }}
                  className={`px-3 py-1.5 rounded-lg border ${src === m ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-gray-200 text-gray-500'}`}>{l}</button>
              ))}
            </div>

            {src === 'paste' && (
              <div className="space-y-2">
                <textarea rows={12} spellCheck={false}
                  className="form-control border w-full p-2 rounded font-mono text-xs"
                  placeholder={'{\n  "builder": { "name": "My flow", "trigger_type": "default" },\n  "nodes": [ ... ]\n}'}
                  value={pasteText} onChange={e => { setPasteText(e.target.value); setCheck(null) }} />
                <div className="flex items-center justify-between text-xs">
                  <span className="text-gray-400">Paste the exported JSON (or one from the AI prompt). It is checked when you press Import.</span>
                  <button type="button" className="text-brand-600 hover:underline"
                    onClick={() => setCheck(verifyFlowJson(pasteText.trim()))} disabled={!pasteText.trim()}>Verify only</button>
                </div>
                {check && (
                  <div className={`rounded-xl border p-3 text-xs space-y-1 ${check.ok ? 'bg-green-50 border-green-300 text-green-700' : 'bg-red-50 border-red-300 text-red-700'}`}>
                    {check.ok ? (
                      <p className="font-semibold">✅ Valid — “{check.summary?.name}” · {check.summary?.nodes} nodes · {check.summary?.roots} root · trigger: {check.summary?.trigger}</p>
                    ) : (
                      <>
                        <p className="font-semibold">❌ {check.errors.length} problem{check.errors.length !== 1 ? 's' : ''} found</p>
                        <ul className="list-disc pl-5 max-h-32 overflow-y-auto font-mono">{check.errors.map((e, i) => <li key={i}>{e}</li>)}</ul>
                      </>
                    )}
                  </div>
                )}
              </div>
            )}

            {/* Drop zone */}
            {src === 'file' && <div
              className={`border-2 border-dashed rounded-xl p-8 text-center cursor-pointer transition-all ${
                dragOver ? 'border-brand-400 bg-brand-50' :
                file      ? 'border-green-400 bg-green-50' :
                'border-gray-200 hover:border-brand-300'
              }`}
              onDragOver={e => { e.preventDefault(); setDragOver(true) }}
              onDragLeave={() => setDragOver(false)}
              onDrop={handleDrop}
              onClick={() => inputRef.current?.click()}
            >
              <input
                ref={inputRef}
                type="file"
                accept=".json"
                className="hidden"
                onChange={e => setFile(e.target.files?.[0] || null)}
              />

              {file ? (
                <div className="space-y-2">
                  <div className="text-3xl">✅</div>
                  <p className="font-semibold text-green-700">{file.name}</p>
                  <p className="text-xs text-green-600">{(file.size / 1024).toFixed(1)} KB</p>
                  <button
                    type="button"
                    onClick={e => { e.stopPropagation(); setFile(null) }}
                    className="text-xs text-red-500 hover:underline"
                  >
                    Remove
                  </button>
                </div>
              ) : (
                <div className="space-y-2">
                  <div className="text-4xl">📂</div>
                  <p className="font-medium text-gray-600">Drop your JSON file here</p>
                  <p className="text-xs text-gray-400">or click to browse</p>
                  <p className="text-xs text-gray-300 font-mono">.json files only</p>
                </div>
              )}
            </div>}

            {/* Activate toggle */}
            <div className="flex items-center gap-3 bg-gray-50 border border-gray-200 rounded-xl px-4 py-3">
              <div
                className={`w-10 h-6 rounded-full transition-colors flex items-center px-0.5 cursor-pointer flex-shrink-0 ${activate ? 'bg-green-500' : 'bg-gray-300'}`}
                onClick={() => setActivate(a => !a)}
              >
                <div className={`w-5 h-5 bg-white rounded-full shadow transition-transform ${activate ? 'translate-x-4' : ''}`} />
              </div>
              <div>
                <p className="text-sm font-medium text-gray-700">
                  {activate ? '🟢 Activate after import' : '⭕ Import as inactive'}
                </p>
                <p className="text-xs text-gray-400">
                  {activate
                    ? 'This builder will become active immediately after import'
                    : 'You can activate manually from the builders list'}
                </p>
              </div>
            </div>

            {/* Sample file + AI prompt */}
            <div className="bg-indigo-50 border border-indigo-200 rounded-lg p-3 text-xs text-indigo-700 space-y-2">
              <p className="font-semibold">Need help building the file?</p>
              <p>Download a sample to see the format, or copy the AI prompt, paste it into ChatGPT / Claude with your business details, save the reply as a <span className="font-mono">.json</span> file and import it here.</p>
              <div className="flex gap-2">
                <Button variant="secondary" onClick={downloadSampleFlow}>⬇️ Sample JSON</Button>
                <Button
                  variant="secondary"
                  onClick={() => navigator.clipboard.writeText(AI_PROMPT).then(() => toast.success('AI prompt copied'), () => toast.error('Copy failed'))}
                >
                  🤖 Copy AI prompt
                </Button>
              </div>
            </div>

            {/* Info */}
            <div className="bg-blue-50 border border-blue-200 rounded-lg p-3 text-xs text-blue-700 space-y-1">
              <p className="font-semibold">What gets imported:</p>
              <p>✅ Builder settings (name, trigger type, keywords, dates)</p>
              <p>✅ All nodes — messages, reply IDs, lead categories, multi-messages</p>
              <p>✅ Full parent-child tree structure preserved</p>
              <p>⚠️ Media URLs are imported as-is — re-upload files if on a different server</p>
            </div>
          </>
        )}
      </div>
    </Modal>
  )
}


// ════════════════════════════════════════════════════════════════════════════
// HOW TO USE IN FlowBuildersPage.tsx
// ════════════════════════════════════════════════════════════════════════════
//
// 1. Add state:
//    const [importExport, setImportExport] = useState<{
//      mode: 'import'|'export'; builderId?: number; builderName?: string
//    } | null>(null)
//
// 2. Add buttons in the header:
//    <Button variant="secondary" onClick={() => setImportExport({ mode: 'import' })}>
//      ⬆️ Import JSON
//    </Button>
//
// 3. Add export button per builder row:
//    <button onClick={() => setImportExport({ mode:'export', builderId:b.id, builderName:b.name })}>
//      ⬇️ Export
//    </button>
//
// 4. Render modal:
//    {importExport && (
//      <FlowImportExportModal
//        mode={importExport.mode}
//        builderId={importExport.builderId}
//        builderName={importExport.builderName}
//        onClose={() => setImportExport(null)}
//        onImported={() => { setImportExport(null); load() }}
//      />
//    )}