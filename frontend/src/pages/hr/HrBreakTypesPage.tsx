// HR Administration → Configuration → Break Types
import { PageHeader, TypeCrud } from './hrShared'

export default function HrBreakTypesPage() {
  return (
    <div className="p-6 max-w-4xl mx-auto space-y-5">
      <PageHeader icon="☕" title="Break Types" sub="Tea break, lunch, prayer break — with an optional time limit and GPS requirement" />
      <TypeCrud kind="break" />
    </div>
  )
}
