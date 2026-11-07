import { useEffect, useState } from 'react'

/** True from the md breakpoint (768px) up. Lets a page mount ONE layout (menu + content, or list → detail) instead of two. */
export function useIsDesktop(): boolean {
  const query = '(min-width: 768px)'
  const [yes, setYes] = useState(() => typeof window !== 'undefined' && window.matchMedia(query).matches)
  useEffect(() => {
    const m = window.matchMedia(query)
    const on = () => setYes(m.matches)
    m.addEventListener('change', on); on()
    return () => m.removeEventListener('change', on)
  }, [])
  return yes
}
