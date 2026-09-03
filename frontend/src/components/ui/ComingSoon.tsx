import { Link } from 'react-router-dom'

export function ComingSoon({ title, icon = '🚧' }: { title: string; icon?: string }) {
  return (
    <div className="flex flex-col items-center justify-center py-24 px-6 text-center">
      <div className="text-5xl mb-4">{icon}</div>
      <h1 className="text-xl font-bold text-gray-900 mb-2">{title}</h1>
      <span className="inline-flex items-center gap-1.5 text-xs font-medium bg-amber-100 text-amber-700 px-3 py-1 rounded-full mb-6">
        Coming soon
      </span>
      <Link to="/dashboard" className="text-sm text-indigo-600 hover:underline">← Back to Dashboard</Link>
    </div>
  )
}
