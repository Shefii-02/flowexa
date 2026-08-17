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
    if (!file) { toast.error('Select a JSON file first'); return }
    setLoading(true)
    setResult(null)
    try {
      const { data } = await flowBuilderApi.import(file, activate)
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
            <Button onClick={handleImport} loading={loading} disabled={!file}>
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
            {/* Drop zone */}
            <div
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
            </div>

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