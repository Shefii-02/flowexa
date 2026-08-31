import { useState } from 'react';
import { FileText, Loader2, Plus, Search, Trash2 } from 'lucide-react';
import { api } from '@/api/client';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { PageHeader } from '../components/PageHeader';
import { Modal } from '../components/Modal';

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
};

const emptyForm: TemplateForm = {
  name: '',
  category: 'marketing',
  language: 'en',
  header_type: 'none',
  header_content: '',
  body: '',
  footer: '',
  status: 'draft',
};

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

export default function WaChatTemplatesPage() {
  const { data: templates = [], isLoading } = useTemplates();
  const createMutation = useCreateTemplate();
  const updateMutation = useUpdateTemplate();
  const deleteMutation = useDeleteTemplate();

  const [editing, setEditing] = useState<WaChatTemplate | null>(null);
  const [form, setForm] = useState<TemplateForm>(emptyForm);
  const [deleteTarget, setDeleteTarget] = useState<WaChatTemplate | null>(null);
  const [search, setSearch] = useState('');
  const [showForm, setShowForm] = useState(false);
  const [error, setError] = useState('');

  const filtered = templates.filter(t =>
    [t.name, t.category, t.body, t.footer ?? ''].some(v => v.toLowerCase().includes(search.toLowerCase()))
  );

  const openCreate = () => {
    setEditing(null);
    setForm(emptyForm);
    setError('');
    setShowForm(true);
  };

  const openEdit = (t: WaChatTemplate) => {
    setEditing(t);
    setForm({
      name: t.name,
      category: t.category,
      language: t.language ?? 'en',
      header_type: t.header_type ?? 'none',
      header_content: t.header_content ?? '',
      body: t.body,
      footer: t.footer ?? '',
      status: t.status ?? 'draft',
    });
    setError('');
    setShowForm(true);
  };

  const handleSave = async () => {
    setError('');
    if (!form.name.trim() || !form.body.trim()) {
      setError('Name and body are required.');
      return;
    }
    try {
      const payload: Partial<TemplateForm> = {
        ...form,
        header_content: form.header_content || '',
        footer: form.footer || '',
      };
      if (editing) {
        await updateMutation.mutateAsync({ id: editing.id, data: payload });
      } else {
        await createMutation.mutateAsync(payload);
      }
      setShowForm(false);
    } catch {
      setError('Failed to save template.');
    }
  };

  const handleDelete = async () => {
    if (!deleteTarget) return;
    await deleteMutation.mutateAsync(deleteTarget.id);
    setDeleteTarget(null);
  };

  const isSaving = createMutation.isPending || updateMutation.isPending;

  const statusColor = (s: string) =>
    s === 'active' ? '#16a34a' : s === 'draft' ? '#ca8a04' : '#6b7280';

  return (
    <div style={{ padding: '24px' }}>
      <PageHeader
        title="WA Chat Templates"
        subtitle="Manage message templates for your WhatsApp sessions"
        actions={
          <button className="btn-primary" onClick={openCreate} style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
            <Plus size={16} /> New Template
          </button>
        }
      />

      <div style={{ display: 'flex', gap: 12, marginBottom: 20, alignItems: 'center' }}>
        <Search size={16} style={{ color: 'var(--text-muted, #6b7280)' }} />
        <input
          value={search}
          onChange={e => setSearch(e.target.value)}
          placeholder="Search templates..."
          style={{ flex: 1, padding: '8px 12px', border: '1px solid var(--border, #e5e7eb)', borderRadius: 8, fontSize: 14 }}
        />
      </div>

      {isLoading ? (
        <div style={{ display: 'flex', justifyContent: 'center', padding: 40 }}>
          <Loader2 className="animate-spin" size={32} />
        </div>
      ) : filtered.length === 0 ? (
        <div style={{ textAlign: 'center', padding: 60, color: 'var(--text-muted, #6b7280)' }}>
          <FileText size={48} strokeWidth={1} style={{ marginBottom: 12 }} />
          <p>{search ? 'No templates match your search.' : 'No templates yet. Create your first one.'}</p>
        </div>
      ) : (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(320px, 1fr))', gap: 16 }}>
          {filtered.map(t => (
            <div key={t.id} style={{ border: '1px solid var(--border, #e5e7eb)', borderRadius: 12, padding: 16, background: 'var(--surface, #fff)' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 8 }}>
                <div>
                  <div style={{ fontWeight: 600, marginBottom: 4 }}>{t.name}</div>
                  <div style={{ fontSize: 12, color: '#6b7280' }}>{t.category} · {t.language}</div>
                </div>
                <span style={{ fontSize: 11, padding: '2px 8px', borderRadius: 12, background: statusColor(t.status) + '20', color: statusColor(t.status), fontWeight: 600 }}>
                  {t.status}
                </span>
              </div>
              <p style={{ fontSize: 14, color: 'var(--text-secondary, #374151)', marginBottom: 12, lineHeight: 1.5 }}>
                {t.body.length > 120 ? t.body.slice(0, 120) + '…' : t.body}
              </p>
              <div style={{ display: 'flex', gap: 8 }}>
                <button className="btn-secondary" onClick={() => openEdit(t)} style={{ fontSize: 13, padding: '6px 14px' }}>Edit</button>
                <button
                  style={{ fontSize: 13, padding: '6px 10px', background: 'none', border: 'none', cursor: 'pointer', color: '#ef4444' }}
                  onClick={() => setDeleteTarget(t)}
                  title="Delete"
                >
                  <Trash2 size={16} />
                </button>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Create / Edit modal */}
      {showForm && (
        <Modal
          open
          onClose={() => setShowForm(false)}
          title={editing ? 'Edit Template' : 'New Template'}
          closeLabel="Cancel"
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
            <div style={{ display: 'flex', gap: 12 }}>
              <label style={{ flex: 1 }}>
                <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 4 }}>Category</div>
                <input className="form-control" value={form.category} onChange={e => setForm({ ...form, category: e.target.value })} placeholder="marketing" style={{ width: '100%' }} />
              </label>
              <label style={{ flex: 1 }}>
                <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 4 }}>Language</div>
                <input className="form-control" value={form.language} onChange={e => setForm({ ...form, language: e.target.value })} style={{ width: '100%' }} />
              </label>
            </div>
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
                <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 4 }}>Header Content</div>
                <input className="form-control" value={form.header_content} onChange={e => setForm({ ...form, header_content: e.target.value })} placeholder="Header text or media URL" style={{ width: '100%' }} />
              </label>
            )}
            <label>
              <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 4 }}>Body *</div>
              <textarea className="form-control" value={form.body} onChange={e => setForm({ ...form, body: e.target.value })} rows={5} placeholder="Message body. Use {{name}}, {{phone}}, etc." style={{ width: '100%', resize: 'vertical' }} />
            </label>
            <label>
              <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 4 }}>Footer</div>
              <input className="form-control" value={form.footer} onChange={e => setForm({ ...form, footer: e.target.value })} placeholder="Optional footer text" style={{ width: '100%' }} />
            </label>
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

      {/* Delete confirm */}
      {deleteTarget && (
        <Modal
          open
          onClose={() => setDeleteTarget(null)}
          title="Delete Template"
          className="modal-sm"
          closeLabel="Cancel"
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
