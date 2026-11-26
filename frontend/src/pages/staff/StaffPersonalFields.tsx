// src/pages/staff/StaffPersonalFields.tsx
// Phone, alternate numbers, personal and parents' details of one staff member — used in the Add / Edit drawer.
import { Input } from '@/components/ui'

export type NumberRow = { label: string; number: string }
export type PersonalForm = {
  phone: string; alternate_phones: NumberRow[]; other_numbers: NumberRow[]
  dob: string; gender: string; blood_group: string; marital_status: string
  address: string; city: string; state: string; pincode: string
  father_name: string; father_phone: string; mother_name: string; mother_phone: string
  emergency_name: string; emergency_phone: string; emergency_relation: string; notes: string
}

export const blankPersonal = (): PersonalForm => ({
  phone: '', alternate_phones: [], other_numbers: [],
  dob: '', gender: '', blood_group: '', marital_status: '', address: '', city: '', state: '', pincode: '',
  father_name: '', father_phone: '', mother_name: '', mother_phone: '', emergency_name: '', emergency_phone: '', emergency_relation: '', notes: '',
})

export const personalFromApi = (d: any): PersonalForm => {
  const b = blankPersonal()
  const out: any = { ...b }
  for (const k of Object.keys(b)) if (d?.[k] !== undefined && d[k] !== null) out[k] = d[k]
  return out
}

function NumberList({ title, hint, rows, onChange }: { title: string; hint?: string; rows: NumberRow[]; onChange: (r: NumberRow[]) => void }) {
  const set = (i: number, patch: Partial<NumberRow>) => onChange(rows.map((r, j) => (j === i ? { ...r, ...patch } : r)))
  return (
    <div className="col-span-2">
      <label className="block text-xs font-medium text-gray-600 mb-1">{title} {hint && <span className="font-normal text-gray-400">— {hint}</span>}</label>
      {rows.map((r, i) => (
        <div key={i} className="flex gap-2 mb-1.5">
          <input className="input w-36" placeholder="Label (Home…)" value={r.label} onChange={(e) => set(i, { label: e.target.value })} />
          <input className="input flex-1" placeholder="Number" value={r.number} onChange={(e) => set(i, { number: e.target.value })} />
          <button type="button" className="text-xs text-red-500 px-1" onClick={() => onChange(rows.filter((_, j) => j !== i))}>Remove</button>
        </div>
      ))}
      <button type="button" className="text-xs text-brand-600 hover:underline" onClick={() => onChange([...rows, { label: '', number: '' }])}>+ Add number</button>
    </div>
  )
}

export default function StaffPersonalFields({ value, onChange }: { value: PersonalForm; onChange: (v: PersonalForm) => void }) {
  const set = (k: keyof PersonalForm, v: any) => onChange({ ...value, [k]: v })
  const sel = (k: keyof PersonalForm, label: string, opts: string[]) => (
    <div>
      <label className="block text-xs font-medium text-gray-600 mb-1">{label}</label>
      <select className="input" value={value[k] as string} onChange={(e) => set(k, e.target.value)}>
        <option value="">Select</option>{opts.map((o) => <option key={o} value={o}>{o}</option>)}
      </select>
    </div>
  )
  return (
    <div className="space-y-5" data-testid="staff-personal">
      <section>
        <p className="text-sm font-semibold text-gray-800 mb-2">Phone numbers</p>
        <div className="grid grid-cols-2 gap-4">
          <Input label="Phone (primary)" value={value.phone} onChange={(e) => set('phone', e.target.value)} />
          <div />
          <NumberList title="Alternative numbers" hint="WhatsApp, home, office…" rows={value.alternate_phones} onChange={(r) => set('alternate_phones', r)} />
          <NumberList title="Other numbers" hint="anyone else worth keeping" rows={value.other_numbers} onChange={(r) => set('other_numbers', r)} />
        </div>
      </section>

      <section>
        <p className="text-sm font-semibold text-gray-800 mb-2">Personal details</p>
        <div className="grid grid-cols-2 gap-4">
          <Input label="Date of birth" type="date" value={value.dob ?? ''} onChange={(e) => set('dob', e.target.value)} />
          {sel('gender', 'Gender', ['Male', 'Female', 'Other'])}
          {sel('blood_group', 'Blood group', ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])}
          {sel('marital_status', 'Marital status', ['Single', 'Married', 'Other'])}
          <div className="col-span-2"><Input label="Address" value={value.address} onChange={(e) => set('address', e.target.value)} /></div>
          <Input label="City" value={value.city} onChange={(e) => set('city', e.target.value)} />
          <Input label="State" value={value.state} onChange={(e) => set('state', e.target.value)} />
          <Input label="Pincode" value={value.pincode} onChange={(e) => set('pincode', e.target.value)} />
        </div>
      </section>

      <section>
        <p className="text-sm font-semibold text-gray-800 mb-2">Parents &amp; emergency contact</p>
        <div className="grid grid-cols-2 gap-4">
          <Input label="Father's name" value={value.father_name} onChange={(e) => set('father_name', e.target.value)} />
          <Input label="Father's phone" value={value.father_phone} onChange={(e) => set('father_phone', e.target.value)} />
          <Input label="Mother's name" value={value.mother_name} onChange={(e) => set('mother_name', e.target.value)} />
          <Input label="Mother's phone" value={value.mother_phone} onChange={(e) => set('mother_phone', e.target.value)} />
          <Input label="Emergency contact name" value={value.emergency_name} onChange={(e) => set('emergency_name', e.target.value)} />
          <Input label="Emergency contact phone" value={value.emergency_phone} onChange={(e) => set('emergency_phone', e.target.value)} />
          <Input label="Relation" placeholder="Spouse, brother…" value={value.emergency_relation} onChange={(e) => set('emergency_relation', e.target.value)} />
          <Input label="Notes" value={value.notes} onChange={(e) => set('notes', e.target.value)} />
        </div>
      </section>
    </div>
  )
}
