import { screen } from '@testing-library/react'
import type { UserEvent } from '@testing-library/user-event'

/** Apre l’accordion Metadati nel workbench / MetadataCard (tab Contenuti se presente). */
export async function openMetadataPanel(user: UserEvent) {
  const contentsTab = screen.queryByRole('tab', { name: /Contenuti/i })
  if (contentsTab) {
    await user.click(contentsTab)
  }
  await user.click(screen.getByRole('button', { name: /Metadati record/i }))
}

/** Tab JSON del workbench. */
export async function openJsonTab(user: UserEvent) {
  await user.click(screen.getByRole('tab', { name: /^JSON$/i }))
}

/** Tab Contenuti del workbench. */
export async function openContentsTab(user: UserEvent) {
  await user.click(screen.getByRole('tab', { name: /Contenuti/i }))
}
