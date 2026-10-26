// HR Administration → Configuration → Leave Types
import { PageHeader, TypeCrud } from './hrShared'

export default function HrLeaveTypesPage() {
  return (
    <div className="p-6  mx-auto space-y-5">
      <PageHeader icon="🏷️" title="Leave Types" sub="Casual, sick, earned leave — paid or unpaid, with a yearly cap and approval rule" />
      <TypeCrud kind="leave" />
    </div>
  )
}
