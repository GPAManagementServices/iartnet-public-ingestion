import { useEffect, useState } from 'react'
import { createDefaultStory } from './story/defaults'
import { listenFromHost, isEmbedMode } from './integration/hostBridge'
import { StoryWorkbench } from './components/StoryWorkbench'
import type { TStoriesTypeData } from './types/story'

function App() {
  const embedded = isEmbedMode()
  const [initialStory, setInitialStory] = useState<TStoriesTypeData | undefined>(
    embedded ? undefined : createDefaultStory(),
  )
  const [ready, setReady] = useState(!embedded)

  useEffect(() => {
    if (!embedded) {
      return
    }

    return listenFromHost((payload) => {
      setInitialStory(payload)
      setReady(true)
    })
  }, [embedded])

  if (embedded && !ready) {
    return (
      <div className="d-flex justify-content-center align-items-center vh-100 text-muted">
        Caricamento editor…
      </div>
    )
  }

  return <StoryWorkbench embedded={embedded} initialStory={initialStory} />
}

export default App
