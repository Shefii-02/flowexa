// Folders can sit inside folders (WA Chat → Campaigns → Diwali → Banners). Pure helpers for turning the flat list the API returns into a tree.

export interface FolderLike {
  id: number; name: string; slug: string; parent_id?: number | null; team_chat_id?: number | null
  is_system: boolean; file_count: number
}
export interface TreeNode<F extends FolderLike = FolderLike> { folder: F; children: TreeNode<F>[]; depth: number; total: number }

/** How deep folders may nest — keep in step with MediaFolderController::MAX_DEPTH. */
export const MAX_DEPTH = 4

/** Roots first (system folders, then by name), children under their parent. A folder whose parent is not in the list (not visible to this user) is shown as a root. */
export function buildTree<F extends FolderLike>(folders: F[]): TreeNode<F>[] {
  const ids = new Set(folders.map(f => f.id))
  const kids = new Map<number, F[]>()
  const roots: F[] = []
  for (const f of folders) {
    if (f.parent_id && ids.has(f.parent_id) && f.parent_id !== f.id) kids.set(f.parent_id, [...(kids.get(f.parent_id) ?? []), f])
    else roots.push(f)
  }
  const order = (a: F, b: F) => Number(b.is_system) - Number(a.is_system) || a.name.localeCompare(b.name)
  const seen = new Set<number>()
  const make = (f: F, depth: number): TreeNode<F> => {
    seen.add(f.id)
    const children = (kids.get(f.id) ?? []).filter(c => !seen.has(c.id)).sort(order).map(c => make(c, depth + 1))     // `seen` guards against a cycle
    return { folder: f, children, depth, total: f.file_count + children.reduce((n, c) => n + c.total, 0) }
  }
  return roots.sort(order).map(r => make(r, 1))
}

/** The folders from the top down to this one — for the path bar ("WA Chat › Campaigns › Diwali"). */
export function pathTo<F extends FolderLike>(folders: F[], id: number | null): F[] {
  const byId = new Map(folders.map(f => [f.id, f]))
  const out: F[] = []
  let cur = id ? byId.get(id) : undefined
  while (cur && out.length < 10 && !out.includes(cur)) { out.unshift(cur); cur = cur.parent_id ? byId.get(cur.parent_id) : undefined }
  return out
}

/** Can a new folder be created inside this one? Not inside the private Team chat tree, and not past the depth limit. */
export function canHaveChildren(f: FolderLike, depth: number): boolean {
  return !(f.team_chat_id || f.slug === 'team-chat') && depth < MAX_DEPTH
}

/** This folder's id and every folder inside it — used to open the path to the selected folder. */
export function ancestorIds<F extends FolderLike>(folders: F[], id: number | null): number[] {
  return pathTo(folders, id).slice(0, -1).map(f => f.id)
}

/** The tree as one ordered list (parents before their children) with each folder's depth — for pickers that show folders as a plain list. */
export function flattenTree<F extends FolderLike>(nodes: TreeNode<F>[]): { folder: F; depth: number }[] {
  return nodes.flatMap(n => [{ folder: n.folder, depth: n.depth }, ...flattenTree(n.children)])
}

/** How many levels a folder spans, itself included (1 = nothing inside it). */
function height<F extends FolderLike>(folders: F[], id: number, guard = 0): number {
  if (guard > 10) return 1
  const kids = folders.filter(f => f.parent_id === id)
  return 1 + (kids.length ? Math.max(...kids.map(k => height(folders, k.id, guard + 1))) : 0)
}

/** Where this folder may be moved: never into itself or something inside it, never into Team chat, and only where it and everything in it still fits the depth limit. Each option carries its depth for indenting. */
export function moveTargets<F extends FolderLike>(folders: F[], folder: F): { folder: F; depth: number }[] {
  const inside = new Set<number>([folder.id])
  for (let grew = true; grew;) { grew = false; for (const f of folders) if (f.parent_id && inside.has(f.parent_id) && !inside.has(f.id)) { inside.add(f.id); grew = true } }
  const h = height(folders, folder.id)
  return flattenTree(buildTree(folders)).filter(({ folder: f, depth }) => !inside.has(f.id) && canHaveChildren(f, depth) && depth + h <= MAX_DEPTH)
}
