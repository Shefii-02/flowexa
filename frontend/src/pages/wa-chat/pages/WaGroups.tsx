import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import api from '@/api/client'
import { Loader2, Users, Search, RefreshCw, ChevronRight } from 'lucide-react'
import { useSessionsQuery } from '../hooks/queries'

interface WaGroup {
  id: string
  name: string
  description?: string
  participant_count?: number
  subject?: string
  announce?: boolean
  restrict?: boolean
}

interface GroupParticipant {
  id: string
  isAdmin: boolean
  isSuperAdmin: boolean
}

function ParticipantsDrawer({ group, onClose }: { group: WaGroup; onClose: () => void }) {
  const sessionId = localStorage.getItem('wa_session_id') ?? ''

  const { data, isLoading } = useQuery({
    queryKey: ['group-participants', group.id, sessionId],
    queryFn: async () => {
      const r = await api.get('/waha/group/participants', { params: { session_id: sessionId, group_id: group.id } })
      return (r.data?.participants ?? r.data ?? []) as GroupParticipant[]
    },
    enabled: !!sessionId,
  })

  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.3)', zIndex: 50, display: 'flex', alignItems: 'center', justifyContent: 'center' }}
      onClick={onClose}>
      <div style={{ background: '#fff', borderRadius: 12, padding: 24, minWidth: 340, maxWidth: 480, width: '90%', maxHeight: '80vh', overflow: 'hidden', display: 'flex', flexDirection: 'column' }}
        onClick={e => e.stopPropagation()}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 }}>
          <div>
            <div style={{ fontWeight: 700, fontSize: 16 }}>{group.name}</div>
            {group.description && <div style={{ fontSize: 12, color: '#6b7280', marginTop: 2 }}>{group.description}</div>}
          </div>
          <button onClick={onClose} style={{ background: '#f3f4f6', border: 'none', cursor: 'pointer', borderRadius: 8, padding: '6px 12px', fontSize: 12, color: '#374151' }}>
            Close
          </button>
        </div>
        <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 12 }}>
          Group ID: <code style={{ background: '#f3f4f6', padding: '1px 5px', borderRadius: 4 }}>{group.id}</code>
        </div>
        <div style={{ overflowY: 'auto', flex: 1 }}>
          {isLoading ? (
            <div style={{ display: 'flex', justifyContent: 'center', padding: 24 }}>
              <Loader2 size={20} className="animate-spin" style={{ color: '#6b7280' }} />
            </div>
          ) : !data?.length ? (
            <p style={{ color: '#9ca3af', fontSize: 13, textAlign: 'center', padding: 16 }}>No participants found.</p>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
              {data.map(p => (
                <div key={p.id} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '8px 10px', borderRadius: 8, background: '#f9fafb' }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <div style={{ width: 28, height: 28, borderRadius: '50%', background: p.isAdmin ? '#dbeafe' : '#f3f4f6', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 12, fontWeight: 700, color: p.isAdmin ? '#1d4ed8' : '#6b7280' }}>
                      {p.id.replace(/@.*/, '').charAt(0)}
                    </div>
                    <div style={{ fontSize: 13, color: '#374151' }}>{p.id.replace(/@.*/, '')}</div>
                  </div>
                  {(p.isAdmin || p.isSuperAdmin) && (
                    <span style={{ fontSize: 10, padding: '2px 7px', borderRadius: 10, background: p.isSuperAdmin ? '#fef3c7' : '#dbeafe', color: p.isSuperAdmin ? '#92400e' : '#1d4ed8', fontWeight: 600 }}>
                      {p.isSuperAdmin ? 'Owner' : 'Admin'}
                    </span>
                  )}
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}

export default function WaGroupsPage() {
  const { data: sessions = [] } = useSessionsQuery()
  const readySessions = sessions.filter(s => s.status === 'ready')
  const [sessionId, setSessionId] = useState(() => localStorage.getItem('wa_session_id') ?? '')
  const [search, setSearch] = useState('')
  const [selected, setSelected] = useState<WaGroup | null>(null)

  const handleSessionChange = (id: string) => {
    setSessionId(id)
    localStorage.setItem('wa_session_id', id)
  }

  const { data, isLoading, isFetching, refetch } = useQuery({
    queryKey: ['wa-groups', sessionId],
    queryFn: async () => {
      const r = await api.get('/waha/groups', { params: { session_id: sessionId } })
      return (r.data?.data ?? r.data ?? []) as WaGroup[]
    },
    enabled: !!sessionId,
  })

  const groups = (data ?? []).filter(g => {
    if (!search) return true
    const q = search.toLowerCase()
    return (g.name ?? '').toLowerCase().includes(q) || g.id.toLowerCase().includes(q)
  })

  return (
    <div style={{ padding: 24, maxWidth: 900, margin: '0 auto' }}>
      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 20 }}>
        <div>
          <h1 style={{ fontSize: 22, fontWeight: 700, margin: 0, display: 'flex', alignItems: 'center', gap: 8 }}>
            <Users size={22} /> WhatsApp Groups
          </h1>
          <p style={{ fontSize: 13, color: '#6b7280', margin: '4px 0 0' }}>
            All groups for the selected WA session
          </p>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          {/* Session selector */}
          {readySessions.length > 0 ? (
            <select value={sessionId} onChange={e => handleSessionChange(e.target.value)}
              style={{ padding: '7px 12px', border: '1px solid #e5e7eb', borderRadius: 8, fontSize: 13, background: '#fff' }}>
              <option value="">Select session…</option>
              {readySessions.map(s => (
                <option key={s.id} value={s.id}>📱 {s.name || s.id}</option>
              ))}
            </select>
          ) : (
            <input value={sessionId} onChange={e => handleSessionChange(e.target.value)}
              placeholder="Session ID"
              style={{ padding: '7px 12px', border: '1px solid #e5e7eb', borderRadius: 8, fontSize: 13, width: 160 }} />
          )}
          <button onClick={() => refetch()} disabled={isFetching}
            style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 16px', background: '#2563eb', color: '#fff', border: 'none', borderRadius: 8, cursor: 'pointer', fontSize: 13, fontWeight: 500, opacity: isFetching ? 0.7 : 1 }}>
            <RefreshCw size={14} className={isFetching ? 'animate-spin' : ''} />
            Refresh
          </button>
        </div>
      </div>

      {/* Search */}
      <div style={{ position: 'relative', marginBottom: 16, maxWidth: 360 }}>
        <Search size={14} style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: '#9ca3af' }} />
        <input
          value={search}
          onChange={e => setSearch(e.target.value)}
          placeholder="Search groups..."
          style={{ width: '100%', paddingLeft: 32, paddingRight: 12, paddingTop: 8, paddingBottom: 8, border: '1px solid #e5e7eb', borderRadius: 8, fontSize: 13, outline: 'none', boxSizing: 'border-box' }}
        />
      </div>

      {/* Session guard */}
      {!sessionId && (
        <div style={{ padding: 24, background: '#fef3c7', border: '1px solid #fcd34d', borderRadius: 10, color: '#92400e', fontSize: 13 }}>
          ⚠️ No WA session selected. Use the session picker in the sidebar.
        </div>
      )}

      {/* Loading */}
      {isLoading && (
        <div style={{ display: 'flex', justifyContent: 'center', padding: 40 }}>
          <Loader2 size={24} className="animate-spin" style={{ color: '#2563eb' }} />
        </div>
      )}

      {/* Groups grid */}
      {!isLoading && sessionId && (
        <>
          <div style={{ fontSize: 13, color: '#6b7280', marginBottom: 12 }}>
            {groups.length} group{groups.length !== 1 ? 's' : ''} {search ? 'matching search' : 'found'}
          </div>

          {groups.length === 0 ? (
            <div style={{ textAlign: 'center', padding: '40px 0', color: '#9ca3af' }}>
              <Users size={40} style={{ margin: '0 auto 12px', opacity: 0.2 }} />
              <p style={{ fontSize: 14 }}>{search ? 'No groups match your search' : 'No groups found for this session'}</p>
            </div>
          ) : (
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(260px, 1fr))', gap: 12 }}>
              {groups.map(g => (
                <div key={g.id}
                  onClick={() => setSelected(g)}
                  style={{ border: '1px solid #e5e7eb', borderRadius: 10, padding: '14px 16px', cursor: 'pointer', background: '#fff', transition: 'box-shadow 0.15s' }}
                  onMouseEnter={e => (e.currentTarget.style.boxShadow = '0 2px 8px rgba(0,0,0,0.1)')}
                  onMouseLeave={e => (e.currentTarget.style.boxShadow = 'none')}>
                  <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 10, flex: 1, minWidth: 0 }}>
                      <div style={{ width: 38, height: 38, borderRadius: '50%', background: '#dcfce7', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 18, flexShrink: 0 }}>
                        👥
                      </div>
                      <div style={{ minWidth: 0 }}>
                        <div style={{ fontWeight: 600, fontSize: 14, color: '#111827', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{g.name || g.subject || 'Unnamed Group'}</div>
                        <div style={{ fontSize: 11, color: '#9ca3af', fontFamily: 'monospace', marginTop: 2, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{g.id}</div>
                      </div>
                    </div>
                    <ChevronRight size={14} style={{ color: '#9ca3af', flexShrink: 0, marginTop: 4 }} />
                  </div>
                  {g.description && (
                    <div style={{ fontSize: 12, color: '#6b7280', marginTop: 8, overflow: 'hidden', display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical' }}>
                      {g.description}
                    </div>
                  )}
                  <div style={{ display: 'flex', gap: 6, marginTop: 10 }}>
                    {g.participant_count && (
                      <span style={{ fontSize: 11, padding: '2px 7px', borderRadius: 10, background: '#e0f2fe', color: '#0369a1' }}>
                        👤 {g.participant_count} members
                      </span>
                    )}
                    {g.announce && (
                      <span style={{ fontSize: 11, padding: '2px 7px', borderRadius: 10, background: '#fef3c7', color: '#92400e' }}>
                        📢 Announce
                      </span>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </>
      )}

      {/* Participants drawer */}
      {selected && <ParticipantsDrawer group={selected} onClose={() => setSelected(null)} />}
    </div>
  )
}
