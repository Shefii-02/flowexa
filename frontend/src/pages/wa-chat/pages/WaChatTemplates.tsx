import { useState } from 'react';
import { FileText, Loader2, Plus, Search, Trash2, Image, Film, Music, X, Upload } from 'lucide-react';
import { api } from '@/api/client';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { PageHeader } from '../components/PageHeader';
import { Modal } from '../components/Modal';
import MediaPickerModal from '@/components/MediaPickerModal';

// ── Types ──────────────────────────────────────────────────────────────────────

interface MediaBlock {
  id: string;
  type: 'text' | 'image' | 'video' | 'audio' | 'document';
  text?: string;
  mediaUrl?: string;
  caption?: string;
  filename?: string;
}

type WaChatTemplate = {
  id: number;
  name: string;
  category: string;
  language: string;
  header_type: 'none' | 'text' | 'image' | 'video' | 'document';
  header_content: string | null;
  body: string;
  footer: string | null;
  buttons: unknown[] | null;
  media_blocks: MediaBlock[] | null;
  status: 'draft' | 'active' | 'archived';
};

type TemplateForm = {
  name: string;
  category: string;
  language: string;
  header_type: 'none' | 'text' | 'image' | 'video' | 'document';
  header_content: string;
  body: string;
  footer: string;
  status: 'draft' | 'active' | 'archived';
  media_blocks: MediaBlock[];
};

const emptyForm: TemplateForm = {
  name: '', category: 'marketing', language: 'en',
  header_type: 'none', header_content: '',
  body: '', footer: '', status: 'draft',
  media_blocks: [],
};

let _blockId = 1;
const newBlockId = () => `b${_blockId++}`;

// ── Query hooks ────────────────────────────────────────────────────────────────

function useTemplates() {
  return useQuery<WaChatTemplate[]>({
    queryKey: ['wa-chat-templates'],
    queryFn: async () => {
      const res = await api.get('/wa-chat-templates');
      return res.data?.data ?? res.data ?? [];
    },
  });
}

function useCreateTemplate() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: Partial<TemplateForm>) => api.post('/wa-chat-templates', data).then(r => r.data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['wa-chat-templates'] }),
  });
}

function useUpdateTemplate() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: number; data: Partial<TemplateForm> }) =>
      api.patch(`/wa-chat-templates/${id}`, data).then(r => r.data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['wa-chat-templates'] }),
  });
}

function useDeleteTemplate() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => api.delete(`/wa-chat-templates/${id}`).then(r => r.data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['wa-chat-templates'] }),
  });
}

// ── Media blocks editor (reusable inside the form) ─────────────────────────────

function BlocksEditor({
  blocks, onChange,
}: { blocks: MediaBlock[]; onChange: (b: MediaBlock[]) => void }) {
  const [pickerIdx, setPickerIdx] = useState<number | null>(null);
  const [uploading, setUploading] = useState<number | null>(null);

  const addBlock = () => onChange([...blocks, { id: newBlockId(), type: 'text', text: '' }]);
  const removeBlock = (id: string) => onChange(blocks.filter(b => b.id !== id));
  const updateBlock = (id: string, patch: Partial<MediaBlock>) =>
    onChange(blocks.map(b => b.id === id ? { ...b, ...patch } : b));

  const handleUpload = async (idx: number, file: File) => {
    setUploading(idx);
    try {
      const fd = new FormData();
      fd.append('file', file);
      const res = await api.post('/media-library/upload', fd, { headers: { 'Content-Type': 'multipart/form-data' } });
      const url = res.data?.url ?? res.data?.data?.url ?? '';
      if (url) updateBlock(blocks[idx].id, { mediaUrl: url });
    } catch { /* silent */ }
    finally { setUploading(null); }
  };

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
      {blocks.map((block, idx) => (
        <div key={block.id} style={{ border: '1px solid #e5e7eb', borderRadius: 8, padding: 12 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 8 }}>
            <span style={{ fontSize: 12, color: '#9ca3af', fontWeight: 500 }}>Block {idx + 1}</span>
            <select
              value={block.type}
              onChange={e => updateBlock(block.id, { type: e.target.value as MediaBlock['type'] })}
              style={{ fontSize: 12, border: '1px solid #e5e7eb', borderRadius: 6, padding: '3px 8px' }}>
              <option value="text">Text</option>
              <option value="image">Image</option>
              <option value="video">Video</option>
              <option value="audio">Audio</option>
              <option value="document">Document</option>
            </select>
            <button onClick={() => removeBlock(block.id)}
              style={{ marginLeft: 'auto', background: 'none', border: 'none', cursor: 'pointer', color: '#ef4444' }}>
              <X size={14} />
            </button>
          </div>

          {block.type === 'text' ? (
            <textarea
              value={block.text ?? ''}
              onChange={e => updateBlock(block.id, { text: e.target.value })}
              rows={3} placeholder="Text content… {{name}} {{phone}}"
              style={{ width: '100%', fontSize: 13, border: '1px solid #e5e7eb', borderRadius: 6, padding: '6px 8px', resize: 'vertical', boxSizing: 'border-box' }} />
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
              <input
                value={block.mediaUrl ?? ''}
                onChange={e => updateBlock(block.id, { mediaUrl: e.target.value })}
                placeholder="Media URL…"
                style={{ fontSize: 13, border: '1px solid #e5e7eb', borderRadius: 6, padding: '6px 8px' }} />
              <div style={{ display: 'flex', gap: 6 }}>
                <label style={{ display: 'flex', alignItems: 'center', gap: 4, padding: '4px 10px', background: '#f3f4f6', border: '1px solid #e5e7eb', borderRadius: 6, cursor: 'pointer', fontSize: 12 }}>
                  {uploading === idx ? <Loader2 size={11} className="animate-spin" /> : <Upload size={11} />}
                  Upload
                  <input type="file" style={{ display: 'none' }}
                    accept={block.type === 'image' ? 'image/*' : block.type === 'video' ? 'video/*' : block.type === 'audio' ? 'audio/*' : '*/*'}
                    onChange={e => { const f = e.target.files?.[0]; if (f) handleUpload(idx, f); e.target.value = '' }} />
                </label>
                <button onClick={() => setPickerIdx(idx)}
                  style={{ display: 'flex', alignItems: 'center', gap: 4, padding: '4px 10px', background: '#eef2ff', border: '1px solid #c7d2fe', borderRadius: 6, cursor: 'pointer', fontSize: 12, color: '#4338ca', fontWeight: 500 }}>
                  🗂️ Library
                </button>
              </div>
              {block.mediaUrl && block.type === 'image' && (
                <img src={block.mediaUrl} alt="preview" style={{ maxHeight: 80, borderRadius: 6, objectFit: 'contain', border: '1px solid #e5e7eb' }}
                  onError={e => { (e.target as HTMLImageElement).style.display = 'none' }} />
              )}
              {block.type !== 'audio' && (
                <input
                  value={block.caption ?? ''}
                  onChange={e => updateBlock(block.id, { caption: e.target.value })}
                  placeholder="Caption (optional)"
                  style={{ fontSize: 13, border: '1px solid #e5e7eb', borderRadius: 6, padding: '5px 8px' }} />
              )}
            </div>
          )}
        </div>
      ))}

      <button onClick={addBlock}
        style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, color: '#6366f1', background: 'none', border: '1px dashed #c7d2fe', borderRadius: 8, padding: '7px 14px', cursor: 'pointer', fontWeight: 500 }}>
        <Plus size={14} /> Add Media Block
      </button>

      {/* Media picker modal */}
      <MediaPickerModal
        open={pickerIdx !== null}
        onClose={() => setPickerIdx(null)}
        onSelect={(url) => {
          if (pickerIdx !== null) updateBlock(blocks[pickerIdx].id, { mediaUrl: url });
          setPickerIdx(null);
        }}
        title="Pick from Media Library"
      />
    </div>
  );
}

// ── Main page ──────────────────────────────────────────────────────────────────

export default function WaChatTemplatesPage() {
  const { data: templates = [], isLoading } = useTemplates();
  const createMutation = useCreateTemplate();
  const updateMutation = useUpdateTemplate();
  const deleteMutation = useDeleteTemplate();

  const [editing,      setEditing]      = useState<WaChatTemplate | null>(null);
  const [form,         setForm]         = useState<TemplateForm>(emptyForm);
  const [deleteTarget, setDeleteTarget] = useState<WaChatTemplate | null>(null);
  const [search,       setSearch]       = useState('');
  const [showForm,     setShowForm]     = useState(false);
  const [error,        setError]        = useState('');
  const [headerPickerOpen, setHeaderPickerOpen] = useState(false);

  const filtered = templates.filter(t =>
    [t.name, t.category, t.body, t.footer ?? ''].some(v => v.toLowerCase().includes(search.toLowerCase()))
  );

  const openCreate = () => {
    setEditing(null); setForm(emptyForm); setError(''); setShowForm(true);
  };

  const openEdit = (t: WaChatTemplate) => {
    setEditing(t);
    setForm({
      name: t.name, category: t.category, language: t.language ?? 'en',
      header_type: t.header_type ?? 'none', header_content: t.header_content ?? '',
      body: t.body, footer: t.footer ?? '', status: t.status ?? 'draft',
      media_blocks: (t.media_blocks ?? []).map(b => ({ ...b, id: b.id || newBlockId() })),
    });
    setError(''); setShowForm(true);
  };

  const handleSave = async () => {
    setError('');
    if (!form.name.trim() || !form.body.trim()) { setError('Name and body are required.'); return; }
    try {
      const payload = {
        ...form,
        header_content: form.header_content || '',
        footer: form.footer || '',
        media_blocks: form.media_blocks.length > 0 ? form.media_blocks : undefined,
      };
      if (editing) await updateMutation.mutateAsync({ id: editing.id, data: payload });
      else         await createMutation.mutateAsync(payload);
      setShowForm(false);
    } catch { setError('Failed to save template.'); }
  };

  const handleDelete = async () => {
    if (!deleteTarget) return;
    await deleteMutation.mutateAsync(deleteTarget.id);
    setDeleteTarget(null);
  };

  const isSaving    = createMutation.isPending || updateMutation.isPending;
  const statusColor = (s: string) =>
    s === 'active' ? '#16a34a' : s === 'draft' ? '#ca8a04' : '#6b7280';

  const headerIsMedia = form.header_type !== 'none' && form.header_type !== 'text';

  return (
    <div style={{ padding: 24 }}>
      <PageHeader
        title="WA Chat Templates"
        subtitle="Manage message templates — supports text, single media header, and multi-media blocks"
        actions={
          <button className="btn-primary" onClick={openCreate} style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
            <Plus size={16} /> New Template
          </button>
        }
      />

      <div style={{ display: 'flex', gap: 12, marginBottom: 20, alignItems: 'center' }}>
        <Search size={16} style={{ color: 'var(--text-muted,#6b7280)' }} />
        <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search templates…"
          style={{ flex: 1, padding: '8px 12px', border: '1px solid var(--border,#e5e7eb)', borderRadius: 8, fontSize: 14 }} />
      </div>

      {isLoading ? (
        <div style={{ display: 'flex', justifyContent: 'center', padding: 40 }}><Loader2 className="animate-spin" size={32} /></div>
      ) : filtered.length === 0 ? (
        <div style={{ textAlign: 'center', padding: 60, color: 'var(--text-muted,#6b7280)' }}>
          <FileText size={48} strokeWidth={1} style={{ marginBottom: 12 }} />
          <p>{search ? 'No templates match your search.' : 'No templates yet.'}</p>
        </div>
      ) : (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(320px,1fr))', gap: 16 }}>
          {filtered.map(t => (
            <div key={t.id} style={{ border: '1px solid var(--border,#e5e7eb)', borderRadius: 12, padding: 16, background: 'var(--surface,#fff)' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 8 }}>
                <div>
                  <div style={{ fontWeight: 600, marginBottom: 4 }}>{t.name}</div>
                  <div style={{ fontSize: 12, color: '#6b7280', display: 'flex', alignItems: 'center', gap: 8 }}>
                    {t.category} · {t.language}
                    {t.header_type !== 'none' && (
                      <span style={{ fontSize: 10, padding: '1px 6px', background: '#f3f4f6', borderRadius: 8 }}>
                        {t.header_type === 'image' ? '🖼️' : t.header_type === 'video' ? '🎬' : t.header_type === 'text' ? '🔤' : '📄'} {t.header_type}
                      </span>
                    )}
                    {t.media_blocks && t.media_blocks.length > 0 && (
                      <span style={{ fontSize: 10, padding: '1px 6px', background: '#eef2ff', color: '#4338ca', borderRadius: 8 }}>
                        📎 {t.media_blocks.length} blocks
                      </span>
                    )}
                  </div>
                </div>
                <span style={{ fontSize: 11, padding: '2px 8px', borderRadius: 12, background: statusColor(t.status) + '20', color: statusColor(t.status), fontWeight: 600 }}>
                  {t.status}
                </span>
              </div>
              <p style={{ fontSize: 14, color: 'var(--text-secondary,#374151)', marginBottom: 12, lineHeight: 1.5 }}>
                {t.body.length > 120 ? t.body.slice(0, 120) + '…' : t.body}
              </p>
              <div style={{ display: 'flex', gap: 8 }}>
                <button className="btn-secondary" onClick={() => openEdit(t)} style={{ fontSize: 13, padding: '6px 14px' }}>Edit</button>
                <button style={{ fontSize: 13, padding: '6px 10px', background: 'none', border: 'none', cursor: 'pointer', color: '#ef4444' }}
                  onClick={() => setDeleteTarget(t)} title="Delete">
                  <Trash2 size={16} />
                </button>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Create / Edit modal */}
      {showForm && (
        <Modal open onClose={() => setShowForm(false)}
          title={editing ? 'Edit Template' : 'New Template'} closeLabel="Cancel"
          footer={
            <>
              <button className="btn-secondary" onClick={() => setShowForm(false)}>Cancel</button>
              <button className="btn-primary" onClick={handleSave} disabled={isSaving}>
                {isSaving ? <Loader2 size={16} className="animate-spin" /> : null}
                {editing ? 'Save Changes' : 'Create Template'}
              </button>
            </>
          }
        >
          <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
            {error && <div style={{ color: '#ef4444', fontSize: 13 }}>{error}</div>}

            <label>
              <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 4 }}>Name *</div>
              <input className="form-control" value={form.name} onChange={e => setForm({ ...form, name: e.target.value })} placeholder="Template name" style={{ width: '100%' }} />
            </label>

      

            {/* Header (single media) */}
            <label>
              <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 4 }}>Header Type</div>
              <select className="form-control" value={form.header_type} onChange={e => setForm({ ...form, header_type: e.target.value as TemplateForm['header_type'] })} style={{ width: '100%' }}>
                <option value="none">None</option>
                <option value="text">Text</option>
                <option value="image">Image</option>
                <option value="video">Video</option>
                <option value="document">Document</option>
              </select>
            </label>

            {form.header_type !== 'none' && (
              <label>
                <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 4 }}>
                  {form.header_type === 'text' ? 'Header Text' : 'Media URL'}
                </div>
                <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                  <input className="form-control" value={form.header_content}
                    onChange={e => setForm({ ...form, header_content: e.target.value })}
                    placeholder={headerIsMedia ? 'https://… or pick from library' : 'Header text'}
                    style={{ flex: 1 }} />
                  {headerIsMedia && (
                    <button type="button" onClick={() => setHeaderPickerOpen(true)}
                      style={{ padding: '7px 12px', background: '#eef2ff', border: '1px solid #c7d2fe', borderRadius: 8, cursor: 'pointer', fontSize: 12, color: '#4338ca', fontWeight: 500, whiteSpace: 'nowrap' }}>
                      🗂️ Library
                    </button>
                  )}
                </div>
                {form.header_content && headerIsMedia && form.header_type === 'image' && (
                  <img src={form.header_content} alt="header preview"
                    style={{ maxHeight: 80, marginTop: 6, borderRadius: 6, objectFit: 'contain', border: '1px solid #e5e7eb' }}
                    onError={e => { (e.target as HTMLImageElement).style.display = 'none' }} />
                )}
              </label>
            )}

            <label>
              <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 4 }}>Body *</div>
              <textarea className="form-control" value={form.body} onChange={e => setForm({ ...form, body: e.target.value })} rows={5}
                placeholder="Message body. Use {{name}}, {{phone}}, etc." style={{ width: '100%', resize: 'vertical' }} />
            </label>

            <label>
              <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 4 }}>Footer</div>
              <input className="form-control" value={form.footer} onChange={e => setForm({ ...form, footer: e.target.value })} placeholder="Optional footer text" style={{ width: '100%' }} />
            </label>

            {/* Multiple media blocks */}
            <div>
              <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 8, display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                <span>Additional Media Blocks</span>
                <span style={{ fontSize: 11, color: '#9ca3af', fontWeight: 400 }}>
                  Sent as separate messages after the body
                </span>
              </div>
              <BlocksEditor
                blocks={form.media_blocks}
                onChange={blocks => setForm(f => ({ ...f, media_blocks: blocks }))}
              />
            </div>

            <label>
              <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 4 }}>Status</div>
              <select className="form-control" value={form.status} onChange={e => setForm({ ...form, status: e.target.value as TemplateForm['status'] })} style={{ width: '100%' }}>
                <option value="draft">Draft</option>
                <option value="active">Active</option>
                <option value="archived">Archived</option>
              </select>
            </label>
          </div>
        </Modal>
      )}

      {/* Header media picker */}
      <MediaPickerModal
        open={headerPickerOpen}
        onClose={() => setHeaderPickerOpen(false)}
        onSelect={url => { setForm(f => ({ ...f, header_content: url })); setHeaderPickerOpen(false); }}
        accept={form.header_type === 'image' ? 'image' : form.header_type === 'video' ? 'video' : form.header_type === 'document' ? 'document' : 'any'}
        title="Select Header Media"
      />

      {/* Delete confirm */}
      {deleteTarget && (
        <Modal open onClose={() => setDeleteTarget(null)} title="Delete Template" className="modal-sm" closeLabel="Cancel"
          footer={
            <>
              <button className="btn-secondary" onClick={() => setDeleteTarget(null)}>Cancel</button>
              <button className="btn-danger" onClick={handleDelete} disabled={deleteMutation.isPending}>
                {deleteMutation.isPending ? <Loader2 size={16} className="animate-spin" /> : <Trash2 size={16} />}
                Delete
              </button>
            </>
          }
        >
          <p>Delete template <strong>{deleteTarget.name}</strong>? This cannot be undone.</p>
        </Modal>
      )}
    </div>
  );
}
