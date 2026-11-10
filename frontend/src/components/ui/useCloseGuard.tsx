import { useCallback, useEffect, useRef, useState } from 'react'

// Shared by every right-hand drawer: closing by an outside click or the browser Back
// button asks "Close?" (Yes/No) first. The × button and in-form Cancel/Save buttons
// call onClose directly and are not intercepted.
//
// Back is intercepted by pushing a throw-away history entry while the drawer is open, so
// Back pops that entry (instead of leaving the page); we restore it and show the prompt.
const stack: symbol[] = []   // open drawers, topmost last — only the top one reacts to Back
let skipPops = 0             // popstate events caused by our own history.back() cleanup

export function useCloseGuard(open: boolean, onClose: () => void) {
  const [confirming, setConfirming] = useState(false)
  const idRef = useRef(Symbol('drawer'))
  const pushedRef = useRef(false)
  const closeRef = useRef(onClose)
  closeRef.current = onClose

  useEffect(() => {
    if (!open) { setConfirming(false); return }
    const id = idRef.current
    // Deferred so StrictMode's mount/unmount/mount never leaves a stray history entry
    const t = setTimeout(() => {
      stack.push(id)
      window.history.pushState({ ...(window.history.state ?? {}), __drawerGuard: true }, '')
      pushedRef.current = true
    }, 0)
    const onPop = () => {
      if (skipPops > 0) { skipPops--; return }
      if (!pushedRef.current || stack[stack.length - 1] !== id) return
      window.history.pushState({ ...(window.history.state ?? {}), __drawerGuard: true }, '')
      setConfirming(true)
    }
    window.addEventListener('popstate', onPop)
    return () => {
      clearTimeout(t)
      window.removeEventListener('popstate', onPop)
      const i = stack.indexOf(id)
      if (i >= 0) stack.splice(i, 1)
      if (pushedRef.current) {
        pushedRef.current = false
        if (window.history.state?.__drawerGuard) { skipPops++; window.history.back() }
      }
    }
  }, [open])

  const requestClose = useCallback(() => setConfirming(true), [])
  const yes = useCallback(() => { setConfirming(false); closeRef.current() }, [])
  const no = useCallback(() => setConfirming(false), [])
  return { confirming, requestClose, yes, no }
}

export function CloseConfirm({ onYes, onNo }: { onYes: () => void; onNo: () => void }) {
  return (
    <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/40"
         onClick={(e) => { e.stopPropagation(); onNo() }}>
      <div className="bg-white rounded-xl shadow-2xl w-[90%] max-w-sm p-5" role="alertdialog"
           onClick={(e) => e.stopPropagation()}>
        <div className="text-sm font-semibold text-gray-900">Close this panel?</div>
        <p className="text-xs text-gray-500 mt-1">Any unsaved changes will be lost.</p>
        <div className="mt-4 flex justify-end gap-2">
          <button className="btn-secondary" onClick={onNo}>No</button>
          <button className="btn-primary" onClick={onYes}>Yes</button>
        </div>
      </div>
    </div>
  )
}
