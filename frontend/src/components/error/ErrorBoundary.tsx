import { Component, type ErrorInfo, type ReactNode } from 'react'
import { buildErrorReport, type ErrorReport } from '@/utils/errorReport'
import { ErrorScreen } from './ErrorScreen'

interface Props {
  children: ReactNode
  /** Reset the boundary when this value changes (e.g. the route pathname). */
  resetKey?: unknown
}

interface State {
  report: ErrorReport | null
}

/**
 * Catches render/runtime errors anywhere below it and shows the shareable
 * ErrorScreen instead of a blank page. Wrap the whole app once, and the
 * per-page <Outlet /> again so a page crash keeps the shell usable.
 */
export class ErrorBoundary extends Component<Props, State> {
  state: State = { report: null }

  static getDerivedStateFromError(error: Error): Partial<State> {
    return {
      report: buildErrorReport({
        kind: 'render',
        title: 'Unhandled UI error',
        message: error?.message || 'The page crashed while rendering.',
        error,
      }),
    }
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    // Re-build with the component stack now that we have it.
    this.setState({
      report: buildErrorReport({
        kind: 'render',
        title: 'Unhandled UI error',
        message: error?.message || 'The page crashed while rendering.',
        error,
        componentStack: info.componentStack ?? undefined,
      }),
    })
    // eslint-disable-next-line no-console
    console.error('[ErrorBoundary]', error, info)
  }

  componentDidUpdate(prev: Props) {
    if (prev.resetKey !== this.props.resetKey && this.state.report) {
      this.setState({ report: null })
    }
  }

  render() {
    if (this.state.report) {
      return (
        <ErrorScreen
          report={this.state.report}
          variant="error"
          primaryAction={{ label: 'Reload page', onClick: () => window.location.reload() }}
        />
      )
    }
    return this.props.children
  }
}
